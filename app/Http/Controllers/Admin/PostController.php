<?php
declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Http\Controllers\Controller;
use App\Services\PostService;
use App\Services\TaxonomyService;
use App\Services\MediaService;
use App\Services\SeoService;

class PostController extends Controller
{
    protected string $type = 'post';

    /**
     * Admin base URL for this type.
     *
     * Extracted so ContentController can serve app-registered types from
     * /admin/content/{type} while `post` and `page` keep the dedicated URLs
     * they have always had. Previously this string was built inline in a dozen
     * redirects, which is what made a generic type controller impossible.
     */
    protected function basePath(): string
    {
        return "/admin/{$this->type}s";
    }

    /**
     * Capability name for an action on this type, e.g. edit_others_posts.
     *
     * Also extracted for ContentController: a custom type maps onto the `post`
     * capability family by default, because no role in an existing install
     * holds edit_events, and a type demanding its own capabilities would be
     * invisible to every user on the site.
     */
    protected function capabilityFor(string $action): string
    {
        return $action . '_' . $this->type . 's';
    }

    /** Views used to render this type's screens. */
    protected function viewPrefix(): string
    {
        return 'posts';
    }

    public function index(Request $request): Response
    {
        /** @var PostService $posts */
        $posts = $this->app->make(PostService::class);
        $page = max(1, (int)$request->query('page', 1));
        $search = (string)$request->query('q', '');
        $status = $request->query('status');
        $sort = (string)$request->query('sort', 'newest');
        $trashed = $request->query('view') === 'trash';

        $filters = ['type' => $this->type];
        if ($search !== '') $filters['search'] = $search;
        if ($status && !$trashed) $filters['status'] = $status;
        if ($sort !== '') $filters['sort'] = $sort;
        if ($trashed) $filters['trashed'] = true;

        $result = $posts->paginate($filters, $page, 20);

        return $this->view($this->viewPrefix() . '.index', [
            'title' => ucfirst($this->type) . 's',
            'currentUser' => $this->user(),
            'posts' => $result['data'],
            'meta' => $result['meta'],
            'search' => $search,
            'status' => $status,
            'sort' => $sort,
            'type' => $this->type,
            'trashed' => $trashed,
            'trashCount' => $posts->trashedCount($this->type),
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->renderForm($request, null);
    }

    public function edit(Request $request, string $id): Response
    {
        /** @var PostService $posts */
        $posts = $this->app->make(PostService::class);
        $post = $posts->find((int)$id);
        if (!$post || $post['type'] !== $this->type) {
            return $this->abort(404, ucfirst($this->type) . ' not found');
        }
        if ($guard = $this->guardOwnership($post, 'edit')) return $guard;
        return $this->renderForm($request, $post);
    }

    /**
     * Role guard: authors/contributors may only touch their own records unless
     * their role grants edit_others_/delete_others_ for this content type.
     * Returns a redirect Response when access is denied, null when allowed.
     */
    /**
     * POST /admin/{type}s/bulk — bulk actions on the list page.
     * Body: action (publish|draft|delete), ids[] (post ids of this type).
     * Items the user may not touch (ownership caps) are skipped, not fatal.
     */
    public function bulk(Request $request): Response
    {
        if (!$this->verifyCsrf($request)) {
            $this->flash('error', 'Security check failed.');
            return $this->back();
        }
        $action = (string)$request->input('bulk_action', '');
        $ids = array_filter(array_map('intval', (array)$request->input('ids', [])));
        if (!in_array($action, ['publish', 'draft', 'delete', 'restore', 'delete_forever'], true) || empty($ids)) {
            $this->flash('error', 'Pick a bulk action and at least one item.');
            return $this->redirect($this->basePath());
        }

        /** @var PostService $posts */
        $posts = $this->app->make(PostService::class);
        /** @var \App\Repositories\PostRepository $repo */
        $repo = $this->app->make(\App\Repositories\PostRepository::class);
        $me = $this->user();
        $done = 0;
        $skipped = 0;
        $trashActions = in_array($action, ['restore', 'delete_forever'], true);

        foreach ($ids as $id) {
            // Trash actions must find soft-deleted rows; normal find() excludes them.
            $existing = $trashActions ? $repo->findTrashedOrAny($id) : $posts->find($id);
            if (!$existing || ($existing['type'] ?? '') !== $this->type) { $skipped++; continue; }

            // Per-item ownership check (edit for status changes, delete for delete).
            $own = (int)($existing['author_id'] ?? 0) === (int)($me['id'] ?? -1);
            $capAction = in_array($action, ['delete', 'delete_forever'], true) ? 'delete' : 'edit';
            $cap = $this->capabilityFor($capAction . '_others');
            if (!$own && !\App\Http\Middleware\CheckCapability::userCan($me, $cap)) { $skipped++; continue; }

            try {
                if ($action === 'delete') {
                    $posts->delete($id);
                } elseif ($action === 'restore') {
                    $posts->restore($id);
                } elseif ($action === 'delete_forever') {
                    $posts->forceDelete($id);
                } else {
                    $status = $action === 'publish' ? 'published' : 'draft';
                    // Respect publish capability: downgrade to pending like single edits.
                    if ($status === 'published'
                        && !\App\Http\Middleware\CheckCapability::userCan($me, $this->capabilityFor('publish'))) {
                        $status = 'pending';
                    }
                    $posts->update($id, ['status' => $status]);
                }
                $done++;
            } catch (\Throwable) {
                $skipped++;
            }
        }

        \App\Services\ActivityLogService::record($this->userId(), $this->type . '.bulk_' . $action, $this->type, null,
            sprintf('Bulk %s: %d done, %d skipped', $action, $done, $skipped));
        $this->flash($done > 0 ? 'success' : 'error',
            sprintf('Bulk %s: %d %s(s) processed%s.', str_replace('_', ' ', $action), $done, $this->type, $skipped ? ", {$skipped} skipped" : ''));
        return $this->redirect($this->basePath() . ($trashActions ? '?view=trash' : ''));
    }

    /**
     * GET /admin/posts/editor/templates — JSON list of reusable templates for
     * the block editor inserter: [{id, title, blocks: [...]}, ...].
     * Only block-format templates are returned (that's what can be inserted).
     */
    public function editorTemplates(Request $request): Response
    {
        /** @var PostService $posts */
        $posts = $this->app->make(PostService::class);
        $result = $posts->paginate(['type' => 'template'], 1, 200);
        $out = [];
        foreach ($result['data'] as $t) {
            if (($t['content_format'] ?? '') !== 'blocks') continue;
            $doc = json_decode((string)($t['content'] ?? ''), true);
            $blocks = is_array($doc) && isset($doc['blocks']) && is_array($doc['blocks']) ? $doc['blocks'] : [];
            if (empty($blocks)) continue;
            $out[] = [
                'id'     => (int)$t['id'],
                'title'  => (string)$t['title'],
                'blocks' => $blocks,
            ];
        }
        return Response::json(['ok' => true, 'templates' => $out]);
    }

    private function guardOwnership(array $post, string $action): ?Response
    {
        $me = $this->user();
        $own = (int) ($post['author_id'] ?? 0) === (int) ($me['id'] ?? -1);
        $cap = $this->capabilityFor($action . '_others');
        if ($own || \App\Http\Middleware\CheckCapability::userCan($me, $cap)) {
            return null;
        }
        $this->flash('error', 'Your role only allows you to ' . $action . ' your own ' . $this->type . 's.');
        return $this->redirect($this->basePath());
    }

    private function renderForm(Request $request, ?array $post): Response
    {
        /** @var TaxonomyService $tax */
        $tax = $this->app->make(TaxonomyService::class);
        /** @var PostService $posts */
        $posts = $this->app->make(PostService::class);
        /** @var SeoService $seo */
        $seo = $this->app->make(SeoService::class);

        $session = $this->app->make(\App\Core\Session::class);

        $categories = $tax->termsByTaxonomySlug('category');
        $tags = $tax->termsByTaxonomySlug('tag');
        $selectedTermIds = $post ? array_column($posts->terms((int)$post['id']), 'id') : [];
        $seoData = $post ? $seo->forPost((int)$post['id']) : null;

        return $this->view($this->viewPrefix() . '.edit', [
            'title' => $post ? 'Edit ' . ucfirst($this->type) : 'New ' . ucfirst($this->type),
            'currentUser' => $this->user(),
            'post' => $post,
            'type' => $this->type,
            'categories' => $categories,
            'tags' => $tags,
            'selectedTermIds' => array_map('intval', $selectedTermIds),
            'seoData' => $seoData,
            'csrf' => $session->csrfToken(),
        ]);
    }

    public function store(Request $request): Response
    {
        if (!$this->verifyCsrf($request)) {
            $this->flash('error', 'Security check failed.');
            return $this->back();
        }

        /** @var PostService $posts */
        $posts = $this->app->make(PostService::class);
        /** @var SeoService $seo */
        $seo = $this->app->make(SeoService::class);

        $data = $this->extractData($request);
        $data['type'] = $this->type;
        $data = $this->gatePublishing($data);

        $id = $posts->create($data, $this->userId() ?? 1);
        \App\Services\ActivityLogService::record($this->userId(), $this->type . '.created', $this->type, $id,
            'Created "' . mb_substr((string) ($data['title'] ?? ''), 0, 80) . '"');

        // SEO meta
        $seoFields = $this->extractSeoData($request);
        if (array_filter($seoFields)) {
            $seo->savePostMeta($id, $seoFields);
        }

        $this->flash('success', ucfirst($this->type) . ' created.');
        return $this->redirect($this->basePath() . "/{$id}/edit");
    }

    public function update(Request $request, string $id): Response
    {
        if (!$this->verifyCsrf($request)) {
            $this->flash('error', 'Security check failed.');
            return $this->back();
        }

        /** @var PostService $posts */
        $posts = $this->app->make(PostService::class);
        /** @var SeoService $seo */
        $seo = $this->app->make(SeoService::class);

        $existing = $posts->find((int)$id);
        if (!$existing || $existing['type'] !== $this->type) {
            return $this->abort(404);
        }
        if ($guard = $this->guardOwnership($existing, 'edit')) return $guard;

        $data = $this->extractData($request);
        $data = $this->gatePublishing($data);
        $posts->update((int)$id, $data);

        // SEO meta
        $seoFields = $this->extractSeoData($request);
        $seo->savePostMeta((int)$id, $seoFields);

        \App\Services\ActivityLogService::record($this->userId(), $this->type . '.updated', $this->type, (int)$id,
            'Updated "' . mb_substr((string) ($data['title'] ?? $existing['title'] ?? ''), 0, 80) . '"');
        $this->flash('success', ucfirst($this->type) . ' updated.');
        return $this->redirect($this->basePath() . "/{$id}/edit");
    }

    /**
     * POST /admin/posts/editor/render — render block JSON to front-end HTML.
     * Used by the block editor (and apps) for live server-side previews.
     */
    public function editorRender(Request $request): Response
    {
        if (!$this->verifyCsrf($request)) {
            return Response::json(['ok' => false, 'error' => 'Security check failed.'], 419);
        }
        $content = (string) $request->input('content', '');
        try {
            $hooks = $this->app->make(\App\Core\HookRegistry::class);
            $html = \App\Services\BlockRenderer::render($content, $hooks);
            return Response::json(['ok' => true, 'html' => $html]);
        } catch (\Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    public function destroy(Request $request, string $id): Response
    {
        if (!$this->verifyCsrf($request)) {
            $this->flash('error', 'Security check failed.');
            return $this->back();
        }
        /** @var PostService $posts */
        $posts = $this->app->make(PostService::class);
        $existing = $posts->find((int)$id);
        if ($existing && $existing['type'] === $this->type) {
            if ($guard = $this->guardOwnership($existing, 'delete')) return $guard;
        }
        $posts->delete((int)$id);
        \App\Services\ActivityLogService::record($this->userId(), $this->type . '.deleted', $this->type, (int)$id,
            'Deleted "' . mb_substr((string) ($existing['title'] ?? ('#' . $id)), 0, 80) . '"');
        $this->flash('success', ucfirst($this->type) . ' moved to trash.');
        return $this->redirect($this->basePath());
    }

    /** POST /admin/{type}s/{id}/restore — bring a trashed item back. */
    public function restore(Request $request, string $id): Response
    {
        if (!$this->verifyCsrf($request)) { $this->flash('error', 'Security check failed.'); return $this->back(); }
        /** @var PostService $posts */
        $posts = $this->app->make(PostService::class);
        $existing = $posts->find((int)$id) ?? $this->app->make(\App\Repositories\PostRepository::class)->findTrashedOrAny((int)$id);
        if ($existing && ($existing['type'] ?? '') === $this->type) {
            if ($guard = $this->guardOwnership($existing, 'edit')) return $guard;
            $posts->restore((int)$id);
            \App\Services\ActivityLogService::record($this->userId(), $this->type . '.restored', $this->type, (int)$id,
                'Restored "' . mb_substr((string) ($existing['title'] ?? ('#' . $id)), 0, 80) . '"');
            $this->flash('success', ucfirst($this->type) . ' restored.');
        } else {
            $this->flash('error', 'Item not found.');
        }
        return $this->redirect($this->basePath() . '?view=trash');
    }

    /** POST /admin/{type}s/{id}/force-delete — permanently remove one item. */
    public function forceDelete(Request $request, string $id): Response
    {
        if (!$this->verifyCsrf($request)) { $this->flash('error', 'Security check failed.'); return $this->back(); }
        /** @var PostService $posts */
        $posts = $this->app->make(PostService::class);
        $existing = $this->app->make(\App\Repositories\PostRepository::class)->findTrashedOrAny((int)$id);
        if ($existing && ($existing['type'] ?? '') === $this->type) {
            if ($guard = $this->guardOwnership($existing, 'delete')) return $guard;
            $posts->forceDelete((int)$id);
            \App\Services\ActivityLogService::record($this->userId(), $this->type . '.force_deleted', $this->type, (int)$id,
                'Permanently deleted "' . mb_substr((string) ($existing['title'] ?? ('#' . $id)), 0, 80) . '"');
            $this->flash('success', ucfirst($this->type) . ' permanently deleted.');
        } else {
            $this->flash('error', 'Item not found.');
        }
        return $this->redirect($this->basePath() . '?view=trash');
    }

    /** POST /admin/{type}s/empty-trash — purge the whole trash for this type. */
    public function emptyTrash(Request $request): Response
    {
        if (!$this->verifyCsrf($request)) { $this->flash('error', 'Security check failed.'); return $this->back(); }
        /** @var PostService $posts */
        $posts = $this->app->make(PostService::class);
        // Only users who can delete others' items may empty the whole trash.
        if (!\App\Http\Middleware\CheckCapability::userCan($this->user(), $this->capabilityFor('delete_others'))
            && !\App\Http\Middleware\CheckCapability::userCan($this->user(), $this->capabilityFor('delete'))) {
            $this->flash('error', 'You do not have permission to empty the trash.');
            return $this->redirect($this->basePath() . '?view=trash');
        }
        $n = $posts->emptyTrash($this->type);
        \App\Services\ActivityLogService::record($this->userId(), $this->type . '.trash_emptied', $this->type, null,
            "Emptied trash ({$n} item(s))");
        $this->flash('success', "Trash emptied — {$n} " . $this->type . "(s) permanently deleted.");
        return $this->redirect($this->basePath());
    }

    /**
     * Roles without publish_{type}s (e.g. contributor) cannot set a record
     * live — publish attempts are downgraded to pending review.
     */
    private function gatePublishing(array $data): array
    {
        $cap = $this->capabilityFor('publish');
        if (($data['status'] ?? '') === 'published'
            && !\App\Http\Middleware\CheckCapability::userCan($this->user(), $cap)) {
            $data['status'] = 'pending';
            $this->flash('info', 'Submitted for review — your role cannot publish directly.');
        }
        return $data;
    }

    private function extractData(Request $request): array
    {
        $termIds = $request->input('term_ids', []);
        if (is_string($termIds)) $termIds = [$termIds];
        return [
            'title' => (string)$request->input('title', 'Untitled'),
            'slug' => (string)$request->input('slug', ''),
            'content' => (string)$request->input('content', ''),
            'content_format' => (string)$request->input('content_format', 'html'),
            'excerpt' => (string)$request->input('excerpt', ''),
            'status' => (string)$request->input('status', 'draft'),
            'comment_status' => (string)$request->input('comment_status', 'open'),
            'featured_media_id' => $request->input('featured_media_id') ?: null,
            'term_ids' => array_filter(array_map('intval', (array)$termIds)),
        ];
    }

    private function extractSeoData(Request $request): array
    {
        return [
            'meta_title' => $request->input('seo_meta_title'),
            'meta_description' => $request->input('seo_meta_description'),
            'og_title' => $request->input('seo_og_title'),
            'og_description' => $request->input('seo_og_description'),
            'canonical_url' => $request->input('seo_canonical'),
            'robots' => $request->input('seo_robots', 'index,follow'),
            'focus_keyword' => $request->input('seo_focus'),
        ];
    }
}
