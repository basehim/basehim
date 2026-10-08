<?php
declare(strict_types=1);

namespace Basehim\WpMigrator;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use Basehim\WpMigrator\Importers\CommentImporter;
use Basehim\WpMigrator\Importers\ContentRewriter;
use Basehim\WpMigrator\Importers\FeaturedMediaImporter;
use Basehim\WpMigrator\Importers\Importer;
use Basehim\WpMigrator\Importers\MediaImporter;
use Basehim\WpMigrator\Importers\MenuImporter;
use Basehim\WpMigrator\Importers\PostImporter;
use Basehim\WpMigrator\Importers\RedirectImporter;
use Basehim\WpMigrator\Importers\TaxonomyImporter;
use Basehim\WpMigrator\Importers\UserImporter;
use Basehim\WpMigrator\Sources\MysqlSource;
use Basehim\WpMigrator\Sources\Source;
use Basehim\WpMigrator\Sources\WxrSource;

/**
 * Wizard
 *
 * Orchestrates the migration wizard. Renders the landing page (or run
 * page if a job is in progress) and handles all wizard actions: start,
 * run-one-batch, status poll, cancel, reset.
 */
class Wizard
{
    /** Hard cap on total WXR import size, regardless of upload method. */
    private const MAX_IMPORT_BYTES = 500 * 1024 * 1024; // 500 MB

    /** Chunk size bounds suggested to the client for chunked uploads. */
    private const CHUNK_MAX_BYTES = 8 * 1024 * 1024;   // 8 MB
    private const CHUNK_MIN_BYTES = 256 * 1024;         // 256 KB

    /** Abandoned chunked-upload sessions older than this are swept away. */
    private const UPLOAD_TTL_SECONDS = 12 * 3600;

    private State $state;
    private IdMap $idMap;

    /** Roles an imported user may be given. Never an administrator. */
    private const ROLES = ['author', 'editor', 'contributor', 'subscriber'];

    public function __construct(private App $app)
    {
        // Every job-log line is mirrored to the per-app log file
        // (Admin > Apps > Logs); warnings and errors also reach the core log.
        $this->state = new State($app->dbPublic(), static function (string $msg, string $level) use ($app): void {
            $app->appLog($msg, [], $level);
        });
        $this->idMap = new IdMap($app->dbPublic());
    }

    // ------------------------------------------------------------------
    // Pages
    // ------------------------------------------------------------------

    public function render(Request $request): Response
    {
        $current = $this->state->currentJob();
        $last = $this->state->lastJob();
        $session = $this->app->app()->make(Session::class);

        // Reflectively call adminView via a helper closure on the app.
        $html = (function (string $template, string $title, array $data) {
            // Bridge: call the protected adminView on App via Closure binding.
            $closure = \Closure::bind(function ($t, $ti, $d) {
                return $this->adminView($t, $ti, $d);
            }, $this->app, $this->app);
            return $closure($template, $title, $data);
        })('wizard', 'WordPress Migrator', [
            'job'      => State::redact($current),
            'lastJob'  => State::redact($last),
            'lastLog'  => $last ? $this->state->logTail((int) $last['id'], 20000) : '',
            'csrf'     => $session->csrfToken(),
            'maxUpload' => $this->maxUploadBytes(),
        ]);

        return new Response($html);
    }

    // ------------------------------------------------------------------
    // Actions
    // ------------------------------------------------------------------

    public function start(Request $request): Response
    {
        $session = $this->app->app()->make(Session::class);
        if (!$session->verifyCsrf((string)$request->input('_csrf'))) {
            return $this->jsonError('Security check failed.', 403);
        }

        // Refuse to start if a job is already running.
        $existing = $this->state->currentJob();
        if ($existing) {
            return $this->jsonError("A migration job (#{$existing['id']}) is already running.", 409);
        }

        $sourceType = (string)$request->input('source');
        if (!in_array($sourceType, ['wxr', 'mysql'], true)) {
            return $this->jsonError('Please choose a source type.', 422);
        }

        // Build config from request.
        $config = [];
        if ($sourceType === 'wxr') {
            $uploadId = preg_replace('/[^a-f0-9]/', '', (string)$request->input('upload_id', ''));

            if ($uploadId !== '') {
                // File arrived via the chunked-upload flow (assets/js/wizard.js
                // splits anything into small chunks before POSTing), used so a
                // large WXR file never has to fit inside a single request's
                // post_max_size/upload_max_filesize.
                $cacheDir = $this->cacheDir();
                if ($cacheDir === null) {
                    return $this->jsonError('Could not access storage/cache directory. Check permissions on storage/.', 500);
                }
                $partPath = $cacheDir . '/wpmig_up_' . $uploadId . '.part';
                $metaPath = $cacheDir . '/wpmig_up_' . $uploadId . '.json';
                $meta = $this->readUploadMeta($metaPath);
                if ($meta === null || !is_file($partPath)) {
                    return $this->jsonError('Unknown or expired upload session. Please re-select the file and try again.', 422);
                }
                clearstatcache(true, $partPath);
                $actualSize = filesize($partPath);
                if ($actualSize === false || $actualSize !== (int)$meta['total_size']) {
                    return $this->jsonError('Upload is incomplete — please try again.', 422);
                }
                if ($actualSize > self::MAX_IMPORT_BYTES) {
                    @unlink($partPath);
                    @unlink($metaPath);
                    return $this->jsonError($this->tooLargeMessage(), 422);
                }
                @unlink($metaPath);
                $config['file'] = $partPath;
            } else {
                $file = $request->file('wxr_file');
                if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    return $this->jsonError('Please upload a WXR (.xml) file.', 422);
                }
                // Persist the uploaded file outside tmp so it survives across batches.
                $cacheDir = $this->cacheDir();
                if ($cacheDir === null) {
                    return $this->jsonError('Could not create storage/cache directory. Check permissions on storage/.', 500);
                }
                $dest = $cacheDir . '/wpmig_' . bin2hex(random_bytes(6)) . '.xml';
                if (!@move_uploaded_file($file['tmp_name'], $dest)) {
                    // Fall back to a regular copy + unlink — some hosts disallow
                    // move_uploaded_file across filesystems.
                    if (!@copy($file['tmp_name'], $dest)) {
                        return $this->jsonError('Could not store uploaded file. Check permissions on storage/cache/.', 500);
                    }
                    @unlink($file['tmp_name']);
                }
                clearstatcache(true, $dest);
                if ((int)(filesize($dest) ?: 0) > self::MAX_IMPORT_BYTES) {
                    @unlink($dest);
                    return $this->jsonError($this->tooLargeMessage(), 422);
                }
                $config['file'] = $dest;
            }
        } else {
            $config = [
                'host'     => trim((string)$request->input('mysql_host', '127.0.0.1')),
                'port'     => (int)$request->input('mysql_port', 3306),
                'database' => trim((string)$request->input('mysql_database', '')),
                'username' => (string)$request->input('mysql_username', ''),
                'password' => (string)$request->input('mysql_password', ''),
                'prefix'   => trim((string)$request->input('mysql_prefix', 'wp_')),
            ];
            // These end up inside the PDO DSN and inside SQL as identifiers,
            // where no placeholder can protect them (1.3.1 used them as typed).
            $bad = MysqlSource::validateConfig($config);
            if ($bad !== null) {
                return $this->jsonError($bad, 422);
            }
        }

        // Build options.
        //
        // IMPORTANT: HTML browsers omit unchecked checkboxes from the form data
        // entirely. If we default to `true` when the field is missing, unchecking
        // a box has no effect — every step runs anyway. Default to `false` so an
        // absent field correctly means "the user does NOT want this step".
        //
        // We detect "no opt_* fields were submitted at all" (e.g. a programmatic
        // call) and fall back to enabling everything in that case, so the wizard
        // form remains the source of truth without breaking API-style callers.
        $optKeys = ['opt_users','opt_taxonomies','opt_media','opt_posts',
                    'opt_featured_media','opt_comments','opt_menus',
                    'opt_redirects','opt_rewrite_content'];
        $anyOptSubmitted = false;
        foreach ($optKeys as $k) {
            if ($request->input($k, null) !== null) { $anyOptSubmitted = true; break; }
        }
        $optDefault = $anyOptSubmitted ? false : true;

        $role = (string)$request->input('default_role', 'author');
        if (!in_array($role, self::ROLES, true)) {
            return $this->jsonError('Unknown default role.', 422);
        }

        // One password for the whole job, fixed now. Generated here (not per
        // batch, as up to 1.3.1) when the field was left blank.
        $password = (string)$request->input('default_password', '');
        $generated = false;
        if ($password === '') {
            $password = 'ChangeMe-' . bin2hex(random_bytes(6));
            $generated = true;
        }

        // Media filter. Absent from older/API callers: import everything.
        $types = $request->input('media_types', null);
        $mediaOpts = [
            'types'          => is_array($types) ? array_values(array_intersect(MediaFilter::TYPES, array_map('strval', $types)))
                                                 : MediaFilter::TYPES,
            'originals_only' => $request->boolean('media_originals_only', false),
            'exclude'        => implode("\n", MediaFilter::parsePatterns((string)$request->input('media_exclude', ''))),
            'allow_svg'      => $request->boolean('media_allow_svg', false),
            'max_mb'         => max(1, min(512, (int)$request->input('media_max_mb', 25) ?: 25)),
            'allow_private'  => $request->boolean('media_allow_private', false),
        ];
        if (is_array($types) && !$mediaOpts['types'] && $request->boolean('opt_media', false)) {
            return $this->jsonError('Media is selected but no media type is — tick at least one type, or untick Media.', 422);
        }

        $options = [
            'default_password'  => $password,
            'default_role'      => $role,
            'default_author_id' => $this->defaultAuthorId(),
            'media'             => $mediaOpts,
            'enabled' => [
                'users'           => $request->boolean('opt_users', $optDefault),
                'taxonomies'      => $request->boolean('opt_taxonomies', $optDefault),
                'media'           => $request->boolean('opt_media', $optDefault),
                'posts'           => $request->boolean('opt_posts', $optDefault),
                'featured_media'  => $request->boolean('opt_featured_media', $optDefault),
                'comments'        => $request->boolean('opt_comments', $optDefault),
                'menus'           => $request->boolean('opt_menus', $optDefault),
                'redirects'       => $request->boolean('opt_redirects', $optDefault),
                'rewrite_content' => $request->boolean('opt_rewrite_content', $optDefault),
            ],
        ];

        // Smoke-test the source before persisting the job.
        try {
            $source = $this->makeSource($sourceType, $config);
            $totals = [
                'users'       => $source->countUsers(),
                'taxonomies'  => $source->countTerms(),
                'media'       => $source->countAttachments(),
                'posts'       => $source->countPosts(),
                'comments'    => $source->countComments(),
            ];
        } catch (\Throwable $e) {
            return $this->jsonError('Could not read source: ' . $e->getMessage(), 422);
        }

        $jobId = $this->state->create($sourceType, $config, $options);
        $this->state->update($jobId, ['status' => 'running', 'totals' => $totals]);
        $this->state->appendLog($jobId, "Job started — source={$sourceType}, site=" . $source->siteUrl()
            . ', totals=' . json_encode($totals));
        $steps = array_keys(array_filter($options['enabled']));
        $this->state->appendLog($jobId, 'Steps: ' . ($steps ? implode(', ', $steps) : '(none)'));
        if ($options['enabled']['media']) {
            $this->state->appendLog($jobId, 'Media filter: types=' . implode('/', $mediaOpts['types'])
                . ($mediaOpts['originals_only'] ? ', originals only' : '')
                . ($mediaOpts['exclude'] !== '' ? ', exclude=' . str_replace("\n", ' ', $mediaOpts['exclude']) : '')
                . ', max ' . $mediaOpts['max_mb'] . ' MB'
                . ($mediaOpts['allow_svg'] ? ', SVG allowed' : '')
                . ($mediaOpts['allow_private'] ? ', private addresses allowed' : ''));
        }
        // Shown once here and in the job log (which only admins can read);
        // the stored copy is removed when the job ends.
        $notice = null;
        if ($generated && $options['enabled']['users']) {
            $notice = "No default password was given. Imported users get: {$password} — note it now; it is removed from the job when the migration ends.";
            // Database job log only — never the log files, which outlive the job.
            $this->state->appendLog($jobId, 'Generated default password for imported users: ' . $password, 'warning', false);
            $this->app->appLog("job #{$jobId}: a default password was generated for imported users (shown in the job log)", [], 'warning');
        }

        return $this->json(['ok' => true, 'job_id' => $jobId, 'notice' => $notice]);
    }

    /**
     * Start a chunked-upload session for a WXR file. Returns an upload_id
     * and the chunk size the client should use — kept under whatever
     * upload_max_filesize/post_max_size the server currently allows, so
     * each individual chunk request comfortably fits regardless of the
     * overall 500 MB import cap.
     */
    public function uploadInit(Request $request): Response
    {
        $session = $this->app->app()->make(Session::class);
        if (!$session->verifyCsrf((string)$request->input('_csrf'))) {
            return $this->jsonError('Security check failed.', 403);
        }

        $this->sweepStaleUploads();

        $totalSize = (int)$request->input('total_size', 0);
        if ($totalSize <= 0) {
            return $this->jsonError('Missing or invalid file size.', 422);
        }
        if ($totalSize > self::MAX_IMPORT_BYTES) {
            return $this->jsonError($this->tooLargeMessage(), 422);
        }

        $cacheDir = $this->cacheDir();
        if ($cacheDir === null) {
            return $this->jsonError('Could not create storage/cache directory. Check permissions on storage/.', 500);
        }

        $uploadId = bin2hex(random_bytes(12));
        $partPath = $cacheDir . '/wpmig_up_' . $uploadId . '.part';
        $metaPath = $cacheDir . '/wpmig_up_' . $uploadId . '.json';

        // Pre-create an empty part file so upload/chunk can fseek into it
        // regardless of what order chunks arrive/retry in.
        if (@file_put_contents($partPath, '') === false) {
            return $this->jsonError('Could not create upload file. Check permissions on storage/cache/.', 500);
        }

        $chunkSize = max(self::CHUNK_MIN_BYTES, min(self::CHUNK_MAX_BYTES, $this->maxUploadBytes() - (256 * 1024)));

        $meta = [
            'total_size' => $totalSize,
            'chunk_size' => $chunkSize,
            'created_at' => time(),
        ];
        if (@file_put_contents($metaPath, json_encode($meta)) === false) {
            @unlink($partPath);
            return $this->jsonError('Could not create upload metadata. Check permissions on storage/cache/.', 500);
        }

        return $this->json([
            'ok'         => true,
            'upload_id'  => $uploadId,
            'chunk_size' => $chunkSize,
        ]);
    }

    /**
     * Receive one chunk of a file started with uploadInit(). Chunks are
     * written at their byte offset (chunk_index * chunk_size), so a
     * retried/duplicated chunk just overwrites the same bytes instead of
     * corrupting the file.
     */
    public function uploadChunk(Request $request): Response
    {
        $session = $this->app->app()->make(Session::class);
        if (!$session->verifyCsrf((string)$request->input('_csrf'))) {
            return $this->jsonError('Security check failed.', 403);
        }

        $uploadId = preg_replace('/[^a-f0-9]/', '', (string)$request->input('upload_id', ''));
        $chunkIndex = (int)$request->input('chunk_index', -1);
        if ($uploadId === '' || $chunkIndex < 0) {
            return $this->jsonError('Invalid upload request.', 422);
        }

        $cacheDir = $this->cacheDir();
        if ($cacheDir === null) {
            return $this->jsonError('storage/cache is not writable.', 500);
        }
        $partPath = $cacheDir . '/wpmig_up_' . $uploadId . '.part';
        $metaPath = $cacheDir . '/wpmig_up_' . $uploadId . '.json';

        $meta = $this->readUploadMeta($metaPath);
        if ($meta === null || !is_file($partPath)) {
            return $this->jsonError('Unknown or expired upload session. Please re-select the file and try again.', 404);
        }

        $file = $request->file('chunk');
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return $this->jsonError('Missing chunk data.', 422);
        }

        $chunkSize = (int)$meta['chunk_size'];
        $offset = $chunkIndex * $chunkSize;
        $len = (int)($file['size'] ?? 0);

        // Defensive bounds check — the declared total_size was already
        // capped at MAX_IMPORT_BYTES in uploadInit(), so a chunk landing
        // outside [0, total_size + one chunk] means a stale/bogus request.
        $totalSize = (int)$meta['total_size'];
        $expected = min($chunkSize, $totalSize - $offset);
        if ($chunkSize <= 0 || $offset < 0 || $offset >= $totalSize || $len !== $expected) {
            return $this->jsonError('Upload chunk out of range.', 422);
        }

        $data = @file_get_contents($file['tmp_name']);
        if ($data === false) {
            return $this->jsonError('Could not read uploaded chunk.', 500);
        }

        $fh = @fopen($partPath, 'r+b');
        if ($fh === false) {
            return $this->jsonError('Could not open upload file for writing.', 500);
        }
        try {
            fseek($fh, $offset);
            fwrite($fh, $data);
        } finally {
            fclose($fh);
        }

        return $this->json(['ok' => true]);
    }

    public function run(Request $request): Response
    {
        $session = $this->app->app()->make(Session::class);
        if (!$session->verifyCsrf((string)$request->input('_csrf'))) {
            return $this->jsonError('Security check failed.', 403);
        }

        // One batch at a time. With the page open in two tabs, 1.3.1 ran
        // batches side by side: the same records twice, cursors racing.
        $lock = $this->acquireRunLock();
        if ($lock === false) {
            return $this->json(['ok' => true, 'busy' => true]);
        }

        try {
            return $this->runLocked();
        } finally {
            if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
        }
    }

    private function runLocked(): Response
    {
        @set_time_limit(120);
        $job = $this->state->currentJob();
        if (!$job) return $this->jsonError('No active job.', 404);

        try {
            $source = $this->makeSource($job['source'], $job['config']);
        } catch (\Throwable $e) {
            $this->state->update($job['id'], ['status' => 'failed', 'finished_at' => date('Y-m-d H:i:s')]);
            $this->state->appendLog($job['id'], 'FAILED to open source: ' . $e->getMessage(), 'error');
            $this->state->scrubSecrets($job['id']);
            return $this->jsonError($e->getMessage(), 500);
        }

        $step = $job['step'];
        $cursor = (int)$job['cursor'];

        // Allow the user to skip a step by toggling it off in options.
        $enabled = $job['options']['enabled'] ?? [];
        if (isset($enabled[$step]) && !$enabled[$step]) {
            $this->state->appendLog($job['id'], "Step '{$step}' skipped (not selected).");
            return $this->advanceStep($job, $step);
        }

        $importer = $this->makeImporter($step, $source, $job);
        if (!$importer) {
            return $this->advanceStep($job, $step);
        }

        try {
            $total = $importer->total();
            $this->state->setTotal($job['id'], $step, $total);

            if ($total === 0) {
                $this->state->appendLog($job['id'], "Step '{$step}': nothing to do.");
                return $this->advanceStep($job, $step);
            }
            if ($cursor === 0) {
                $this->state->appendLog($job['id'], "Step '{$step}' started: {$total} records.");
            }

            $t0 = microtime(true);
            $processed = $importer->runBatch($cursor, $importer->batchSize());
        } catch (\Throwable $e) {
            // Logged where the operator will look; the browser retries, and
            // stops after three failures in a row.
            $this->state->appendLog($job['id'], "Step '{$step}' batch at {$cursor} failed: " . $e->getMessage()
                . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')', 'error');
            return $this->jsonError("Step '{$step}' failed: " . $e->getMessage(), 500);
        }

        // Advance by what was actually done. Batches may stop early to stay
        // inside PHP's time limit.
        $newCursor = $cursor + $processed;
        if ($step !== 'media' && $processed > 0) {
            // (Media logs its own per-batch summary.)
            $this->state->appendLog($job['id'], sprintf("[%s] %d–%d of %d (%.1fs)",
                $step, $cursor + 1, min($newCursor, $total), $total, microtime(true) - $t0));
        }

        if ($processed === 0 || $newCursor >= $total) {
            return $this->advanceStep($job, $step);
        }

        $this->state->update($job['id'], ['cursor' => $newCursor]);

        return $this->json([
            'ok'        => true,
            'step'      => $step,
            'cursor'    => $newCursor,
            'total'     => $total,
            'done'      => false,
            'counts'    => $this->state->find($job['id'])['counts'] ?? [],
            'log'       => $this->state->logTail($job['id']),
        ]);
    }

    /** @return resource|false|null  a held lock, false when another batch holds it, null when locking is unavailable */
    private function acquireRunLock()
    {
        $dir = $this->cacheDir();
        if ($dir === null) return null;
        $fh = @fopen($dir . '/wpmig_run.lock', 'c');
        if ($fh === false) return null;
        if (!flock($fh, LOCK_EX | LOCK_NB)) { fclose($fh); return false; }
        return $fh;
    }

    /**
     * POST /admin/wp-migrator/repair — fix image links in posts that are
     * already on the site: galleries, resized copies, srcset, and the
     * `/wp-content/uploads/...` paths 1.2.0 and earlier left behind.
     *
     * Batched like the import: the browser calls it until `done`. With
     * apply=0 it only reports what would change.
     *
     * Every post and page is examined, not only those in the import map — an
     * older migration's map may be gone or, from 1.2.0, cut short. So images
     * are also matched by file name, when exactly one media item has it.
     */
    public function repair(Request $request): Response
    {
        $session = $this->app->app()->make(Session::class);
        if (!$session->verifyCsrf((string) $request->input('_csrf'))) {
            return $this->jsonError('Security check failed. Reload the page and try again.', 403);
        }
        if ($this->state->currentJob()) {
            return $this->jsonError('A migration is running. Repair image links after it finishes.', 409);
        }
        @set_time_limit(120);

        $db = $this->app->dbPublic();
        $apply = (string) $request->input('apply', '0') === '1';
        $after = max(0, (int) $request->input('cursor', 0));
        $oldSite = trim((string) $request->input('old_site', ''));
        if ($oldSite !== '' && !preg_match('#^https?://#i', $oldSite)) $oldSite = 'https://' . $oldSite;

        $hosts = UrlMapper::hostsFrom($oldSite, (string) ($_SERVER['HTTP_HOST'] ?? ''));
        foreach ($db->select("SELECT old_id FROM {app_wpmig_idmap} WHERE entity_type = 'media_host'") as $r) $hosts[] = (string) $r['old_id'];

        $mapper = new UrlMapper($db, $this->idMap, array_values(array_unique($hosts)), $oldSite, $apply && $oldSite !== '', true);
        $fixer = new ContentFixer($db, $this->idMap, $mapper, false);

        $total = (int) ($db->selectOne("SELECT COUNT(*) c FROM {posts} WHERE type IN ('post','page') AND deleted_at IS NULL")['c'] ?? 0);
        $rows = $db->select(
            "SELECT id, title, content FROM {posts}
              WHERE type IN ('post','page') AND deleted_at IS NULL AND id > :a
              ORDER BY id LIMIT 25",
            ['a' => $after]
        );

        $changed = 0; $samples = []; $cursor = $after; $started = microtime(true); $stoppedEarly = false;
        foreach ($rows as $r) {
            $cursor = (int) $r['id'];
            $content = (string) $r['content'];
            if ($content === '' || (stripos($content, 'wp-content/uploads') === false && stripos($content, '[gallery') === false && stripos($content, '[caption') === false)) continue;
            $fixed = $fixer->fix($content, null);
            if ($fixed === $content) continue;
            $changed++;
            if (count($samples) < 3 && preg_match('#[^\s"\']*wp-content/uploads/[^\s"\'<>]+#i', $content, $before)) {
                $samples[] = ['post' => (string) $r['title'], 'before' => $before[0], 'after' => $mapper->map($before[0]) ?? '(unchanged)'];
            }
            if ($apply) {
                try { $db->update('posts', ['content' => $fixed], ['id' => (int) $r['id']]); }
                catch (\Throwable $e) { $this->app->appLog('repair failed for post ' . $r['id'] . ': ' . $e->getMessage(), [], 'warning'); }
            }
            if (microtime(true) - $started > 20) { $stoppedEarly = true; break; }
        }

        // Finished when this batch reached the end of the table.
        $done = !$stoppedEarly && count($rows) < 25;
        $scanned = (int) ($db->selectOne("SELECT COUNT(*) c FROM {posts} WHERE type IN ('post','page') AND deleted_at IS NULL AND id <= :c", ['c' => $cursor])['c'] ?? 0);

        return $this->json([
            'ok'        => true,
            'apply'     => $apply,
            'cursor'    => $cursor,
            'done'      => $done,
            'scanned'   => $scanned,
            'total'     => $total,
            'changed'   => $changed,
            'urls'      => $fixer->stats['urls'],
            'unmapped'  => $fixer->stats['unmapped'],
            'galleries' => $fixer->stats['galleries'],
            'captions'  => $fixer->stats['captions'],
            'fetched'   => $mapper->fetched,
            'unmapped_samples' => $fixer->unmappedSamples,
            'samples'   => $samples,
        ]);
    }

    private function advanceStep(array $job, string $current): Response
    {
        $next = $this->state->nextStep($current);
        if ($next === null) {
            // All steps done.
            $this->state->update($job['id'], [
                'status' => 'completed',
                'step' => $current,
                'cursor' => 0,
                'finished_at' => date('Y-m-d H:i:s'),
            ]);
            $this->state->appendLog($job['id'], 'All steps completed. Counts: '
                . json_encode($this->state->find($job['id'])['counts'] ?? [], JSON_UNESCAPED_SLASHES));
            $this->state->scrubSecrets($job['id']);
            $this->cleanupTempFiles($job);
            return $this->json([
                'ok'       => true,
                'finished' => true,
                'counts'   => $this->state->find($job['id'])['counts'] ?? [],
                'log'      => $this->state->logTail($job['id']),
            ]);
        }
        $this->state->advanceToStep($job['id'], $next);
        $this->state->appendLog($job['id'], "Step '{$current}' done; advancing to '{$next}'.");
        return $this->json([
            'ok' => true,
            'step' => $next,
            'cursor' => 0,
            'advanced' => true,
            'counts' => $this->state->find($job['id'])['counts'] ?? [],
            'log'    => $this->state->logTail($job['id']),
        ]);
    }

    public function status(Request $request): Response
    {
        $job = $this->state->currentJob() ?? $this->state->lastJob();
        if (!$job) return $this->json(['job' => null]);
        // Never the stored MySQL or user password (1.3.1 returned both).
        return $this->json(['job' => State::redact($job), 'log' => $this->state->logTail($job['id'])]);
    }

    /** GET /admin/wp-migrator/log — the whole log of the latest (or ?job=) job, as a text file. */
    public function downloadLog(Request $request): Response
    {
        $id = (int) $request->input('job', 0);
        $job = $id > 0 ? $this->state->find($id) : ($this->state->currentJob() ?? $this->state->lastJob());
        $text = $job ? $this->state->fullLog((int) $job['id']) : '';
        $response = new Response($text !== '' ? $text : "No log.\n");
        $response->header('Content-Type', 'text/plain; charset=utf-8');
        $response->header('X-Content-Type-Options', 'nosniff');
        $response->header('Content-Disposition', 'attachment; filename="wp-migrator-job-' . (int) ($job['id'] ?? 0) . '.log"');
        return $response;
    }

    public function cancel(Request $request): Response
    {
        $session = $this->app->app()->make(Session::class);
        if (!$session->verifyCsrf((string)$request->input('_csrf'))) {
            return $this->jsonError('Security check failed.', 403);
        }
        $job = $this->state->currentJob();
        if ($job) {
            $this->state->update($job['id'], [
                'status' => 'cancelled',
                'finished_at' => date('Y-m-d H:i:s'),
            ]);
            $this->state->appendLog($job['id'], "Cancelled by the user during step '{$job['step']}' at record {$job['cursor']}.", 'warning');
            $this->state->scrubSecrets($job['id']);
            $this->cleanupTempFiles($job);
        }
        return $this->json(['ok' => true]);
    }

    public function reset(Request $request): Response
    {
        $session = $this->app->app()->make(Session::class);
        if (!$session->verifyCsrf((string)$request->input('_csrf'))) {
            return $this->jsonError('Security check failed.', 403);
        }
        if ($job = $this->state->currentJob()) {
            return $this->jsonError("A migration (#{$job['id']}) is running. Cancel it first.", 409);
        }
        if ($last = $this->state->lastJob()) $this->cleanupTempFiles($last);
        $db = $this->app->dbPublic();
        $db->execute('DELETE FROM {app_wpmig_idmap}');
        $db->execute('DELETE FROM {app_wpmig_jobs}');
        $db->execute('DELETE FROM {app_wpmig_redirects}');
        $this->app->appLog('migration data reset (ID map, jobs, redirects)', [], 'warning');
        return $this->json(['ok' => true]);
    }

    /** The first administrator: owner of imported media and of posts whose author is unknown. */
    private function defaultAuthorId(): int
    {
        try {
            $row = $this->app->dbPublic()->selectOne(
                "SELECT id FROM {users}
                  WHERE role IN ('super_admin', 'admin', 'administrator') AND status = 'active' AND deleted_at IS NULL
                  ORDER BY id LIMIT 1"
            );
            if ($row) return (int) $row['id'];
        } catch (\Throwable) {}
        return 1;
    }

    // ------------------------------------------------------------------
    // Builders
    // ------------------------------------------------------------------

    private function makeSource(string $type, array $config): Source
    {
        return match ($type) {
            'wxr' => new WxrSource($config['file'] ?? ''),
            'mysql' => new MysqlSource($config),
            default => throw new \RuntimeException('Unknown source type'),
        };
    }

    private function makeImporter(string $step, Source $source, array $job): ?Importer
    {
        $deps = [
            $this->app->app(),
            $this->app->dbPublic(),
            $source,
            $this->idMap,
            $this->state,
            (int)$job['id'],
            $job['options'] ?? [],
        ];
        return match ($step) {
            'users'           => new UserImporter(...$deps),
            'taxonomies'      => new TaxonomyImporter(...$deps),
            'media'           => new MediaImporter(...$deps),
            'posts'           => new PostImporter(...$deps),
            'featured_media'  => new FeaturedMediaImporter(...$deps),
            'comments'        => new CommentImporter(...$deps),
            'menus'           => new MenuImporter(...$deps),
            'redirects'       => new RedirectImporter(...$deps),
            'rewrite_content' => new ContentRewriter(...$deps),
            default           => null,
        };
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function json(array $payload, int $status = 200): Response
    {
        $response = new Response(json_encode($payload, JSON_UNESCAPED_SLASHES), $status);
        $response->header('Content-Type', 'application/json');
        return $response;
    }

    private function jsonError(string $msg, int $status = 400): Response
    {
        return $this->json(['ok' => false, 'error' => $msg], $status);
    }

    private function maxUploadBytes(): int
    {
        $upload = $this->toBytes((string)ini_get('upload_max_filesize'));
        $post = $this->toBytes((string)ini_get('post_max_size'));
        return min($upload ?: PHP_INT_MAX, $post ?: PHP_INT_MAX);
    }

    private function toBytes(string $val): int
    {
        $val = trim($val);
        if ($val === '') return 0;
        $unit = strtolower(substr($val, -1));
        $num = (int)$val;
        return match ($unit) {
            'g' => $num * 1024 * 1024 * 1024,
            'm' => $num * 1024 * 1024,
            'k' => $num * 1024,
            default => $num,
        };
    }

    private function cleanupTempFiles(array $job): void
    {
        $file = $job['config']['file'] ?? null;
        if ($file && str_contains($file, '/storage/cache/wpmig_') && is_file($file)) {
            @unlink($file);
        }
        // The parsed index beside it (see WxrSource).
        $dir = $file ? $file . '.index' : '';
        if ($dir !== '' && str_contains($dir, '/storage/cache/wpmig_') && is_dir($dir)) {
            foreach (glob($dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) if (is_file($f)) @unlink($f);
            @rmdir($dir);
        }
    }

    private function cacheDir(): ?string
    {
        $cacheDir = (defined('BASEHIM_ROOT') ? BASEHIM_ROOT : dirname(__DIR__, 4)) . '/storage/cache';
        if (!is_dir($cacheDir) && !@mkdir($cacheDir, 0775, true) && !is_dir($cacheDir)) {
            return null;
        }
        return $cacheDir;
    }

    /** @return array{total_size:int,chunk_size:int,created_at:int}|null */
    private function readUploadMeta(string $path): ?array
    {
        if (!is_file($path)) return null;
        $raw = @file_get_contents($path);
        if ($raw === false) return null;
        $meta = json_decode($raw, true);
        if (!is_array($meta) || !isset($meta['total_size'], $meta['chunk_size'])) return null;
        return $meta;
    }

    /** Deletes chunked-upload sessions nobody finished within the TTL. */
    private function sweepStaleUploads(): void
    {
        $cacheDir = $this->cacheDir();
        if ($cacheDir === null) return;
        foreach (glob($cacheDir . '/wpmig_up_*.json') ?: [] as $metaPath) {
            $meta = $this->readUploadMeta($metaPath);
            $createdAt = (int)($meta['created_at'] ?? 0);
            if ($meta === null || (time() - $createdAt) > self::UPLOAD_TTL_SECONDS) {
                $id = basename($metaPath, '.json');
                @unlink($metaPath);
                @unlink($cacheDir . '/' . $id . '.part');
            }
        }
    }

    private function tooLargeMessage(): string
    {
        return 'File is too large — the maximum import size is ' . (int)(self::MAX_IMPORT_BYTES / 1048576) . ' MB.';
    }
}
