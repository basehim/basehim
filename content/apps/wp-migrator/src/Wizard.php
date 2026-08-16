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
    private State $state;
    private IdMap $idMap;

    public function __construct(private App $app)
    {
        $this->state = new State($app->dbPublic());
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
            'job'      => $current,
            'lastJob'  => $last,
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
            $file = $request->file('wxr_file');
            if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                return $this->jsonError('Please upload a WXR (.xml) file.', 422);
            }
            // Persist the uploaded file outside tmp so it survives across batches.
            $cacheDir = (defined('BASEHIM_ROOT') ? BASEHIM_ROOT : dirname(__DIR__, 4)) . '/storage/cache';
            if (!is_dir($cacheDir) && !@mkdir($cacheDir, 0775, true) && !is_dir($cacheDir)) {
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
            $config['file'] = $dest;
        } else {
            $config = [
                'host'     => (string)$request->input('mysql_host', '127.0.0.1'),
                'port'     => (int)$request->input('mysql_port', 3306),
                'database' => (string)$request->input('mysql_database', ''),
                'username' => (string)$request->input('mysql_username', ''),
                'password' => (string)$request->input('mysql_password', ''),
                'prefix'   => (string)$request->input('mysql_prefix', 'wp_'),
            ];
            if (!$config['database']) {
                return $this->jsonError('Database name is required.', 422);
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

        $options = [
            'default_password'  => (string)$request->input('default_password', '') ?: null,
            'default_role'      => (string)$request->input('default_role', 'author'),
            'default_author_id' => 1,
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
        $this->state->appendLog($jobId, "Job started — source={$sourceType}, totals=" . json_encode($totals));

        return $this->json(['ok' => true, 'job_id' => $jobId]);
    }

    public function run(Request $request): Response
    {
        $session = $this->app->app()->make(Session::class);
        if (!$session->verifyCsrf((string)$request->input('_csrf'))) {
            return $this->jsonError('Security check failed.', 403);
        }

        $job = $this->state->currentJob();
        if (!$job) return $this->jsonError('No active job.', 404);

        try {
            $source = $this->makeSource($job['source'], $job['config']);
        } catch (\Throwable $e) {
            $this->state->update($job['id'], ['status' => 'failed']);
            $this->state->appendLog($job['id'], 'FAILED to open source: ' . $e->getMessage());
            return $this->jsonError($e->getMessage(), 500);
        }

        $step = $job['step'];
        $cursor = (int)$job['cursor'];

        // Allow the user to skip a step by toggling it off in options.
        $enabled = $job['options']['enabled'] ?? [];
        if (isset($enabled[$step]) && !$enabled[$step]) {
            return $this->advanceStep($job, $step);
        }

        $importer = $this->makeImporter($step, $source, $job);
        if (!$importer) {
            return $this->advanceStep($job, $step);
        }

        $total = $importer->total();
        $this->state->setTotal($job['id'], $step, $total);

        if ($total === 0) {
            return $this->advanceStep($job, $step);
        }

        $processed = $importer->runBatch($cursor, $importer->batchSize());
        $newCursor = $cursor + max($processed, $importer->batchSize());

        if ($processed === 0 || $newCursor >= $total) {
            // Step is done. Move on.
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
            $this->state->appendLog($job['id'], 'All steps completed.');
            $this->cleanupTempFiles($job);
            return $this->json([
                'ok'       => true,
                'finished' => true,
                'counts'   => $this->state->find($job['id'])['counts'] ?? [],
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
        ]);
    }

    public function status(Request $request): Response
    {
        $job = $this->state->currentJob() ?? $this->state->lastJob();
        if (!$job) return $this->json(['job' => null]);
        return $this->json(['job' => $job]);
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
        // Wipe all migration state.
        $this->app->dbPublic()->execute('DELETE FROM app_wpmig_idmap');
        $this->app->dbPublic()->execute('DELETE FROM app_wpmig_jobs');
        $this->app->dbPublic()->execute('DELETE FROM app_wpmig_redirects');
        return $this->json(['ok' => true]);
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
    }
}
