<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ajax;

use App\Core\Ajax\AjaxContext;
use App\Core\Ajax\AjaxException;
use App\Core\Helpers;
use App\Core\HookRegistry;
use App\Core\Time;
use App\Services\ActivityLogService;
use App\Services\PostEditorService;
use App\Services\PostService;
use App\Services\SeoService;

/**
 * The block editor's AJAX actions (registered in routes/ajax.php).
 *
 * The gateway has checked the session, CSRF, the declared capability and the
 * input's shape. What it cannot know is which post this is and whose: every
 * handler loads the post and asks PostEditorService, which applies the same
 * rules as the editor screens (the type's admin area, own or others').
 */
final class PostActions
{
    /** Statuses Save draft may leave a post in. */
    private const DRAFT_STATUSES = ['draft', 'pending'];

    public function __construct(
        private PostService $posts,
        private PostEditorService $editor,
        private SeoService $seo,
    ) {}

    /**
     * posts.save_draft — save the editor's content without leaving the page.
     *
     * Creates the post on its first save. Only ever leaves it as a draft or
     * pending review: a published, scheduled or private post is changed with
     * Update (the form), never by a draft save, so this cannot unpublish
     * anything by accident.
     */
    public function saveDraft(AjaxContext $ctx): array
    {
        $user = $ctx->user();
        $type = (string) $ctx->input('type');
        if (!$this->editor->typeExists($type)) {
            throw AjaxException::invalid('Unknown content type.', ['type' => ['Unknown content type.']]);
        }
        if (!$this->editor->canUseArea($user, $type)) {
            throw AjaxException::forbidden();
        }

        $id = $ctx->has('id') ? (int) $ctx->input('id') : null;
        $existing = null;
        if ($id !== null) {
            $existing = $this->posts->find($id);
            if (!$existing || ($existing['type'] ?? '') !== $type) {
                throw AjaxException::notFound('This item no longer exists. It may have been moved to the trash.');
            }
            if (!$this->editor->canEdit($user, $existing)) {
                throw AjaxException::forbidden('Your role only allows you to edit your own items.');
            }
            if (!in_array((string) $existing['status'], self::DRAFT_STATUSES, true)) {
                throw new AjaxException('ALREADY_PUBLISHED',
                    'This is already published. Use Update to save your changes.', 409);
            }
        }

        // Only what was sent is written, so a partial save never blanks a field.
        $data = [];
        foreach (['title', 'slug', 'content', 'content_format', 'excerpt', 'comment_status'] as $f) {
            if ($ctx->has($f)) $data[$f] = (string) $ctx->input($f);
        }
        if (array_key_exists('title', $data) && trim($data['title']) === '') $data['title'] = 'Untitled';
        if ($existing === null && !isset($data['title'])) $data['title'] = 'Untitled';
        if ($ctx->has('featured_media_id')) {
            $fid = (int) $ctx->input('featured_media_id');
            $data['featured_media_id'] = $fid > 0 ? $fid : null;
        }
        if ($ctx->has('term_ids')) {
            $data['term_ids'] = $this->editor->existingTermIds((array) $ctx->input('term_ids'));
        }
        $status = (string) ($ctx->input('status') ?? ($existing['status'] ?? 'draft'));
        $data['status'] = in_array($status, self::DRAFT_STATUSES, true) ? $status : 'draft';

        if ($existing === null) {
            $data['type'] = $type;
            $id = $this->posts->create($data, (int) $user['id']);
            ActivityLogService::record((int) $user['id'], $type . '.created', $type, $id,
                'Created "' . mb_substr((string) $data['title'], 0, 80) . '" (draft)');
        } else {
            $this->posts->update($id, $data);
            ActivityLogService::record((int) $user['id'], $type . '.updated', $type, $id,
                'Saved draft "' . mb_substr((string) ($data['title'] ?? $existing['title']), 0, 80) . '"');
        }

        if ($ctx->has('seo')) {
            $this->seo->savePostMeta($id, $this->seoFields((array) $ctx->input('seo')));
        }

        $pending = [];
        if ($ctx->has('new_tags') && $this->editor->hasTags($type)) {
            $pending = $this->editor->settleNewTags($id, (array) $ctx->input('new_tags'), $data['status'], $user, $type)['pending'];
        } elseif ($this->editor->hasTags($type)) {
            $pending = $this->editor->pendingTags($id);
        }

        $post = $this->posts->find($id) ?? [];
        $base = defined('BASEHIM_BASE') ? (string) BASEHIM_BASE : '';
        $listPath = $this->editor->areaPath($type);
        $viewUrl = '';
        if (($post['slug'] ?? '') !== '' && $type !== 'template') {
            try { $viewUrl = Helpers::postUrl($post, $base); } catch (\Throwable) {}
        }

        return [
            'id'           => $id,
            'created'      => $existing === null,
            'status'       => (string) ($post['status'] ?? $data['status']),
            'title'        => (string) ($post['title'] ?? ''),
            'slug'         => (string) ($post['slug'] ?? ''),
            'edit_url'     => $base . $listPath . '/' . $id . '/edit',
            'action_url'   => $base . $listPath . '/' . $id,
            'view_url'     => $viewUrl,
            'saved_at'     => Time::iso(Time::now()),
            'saved_at_text'=> Time::time(Time::now()),
            'tags'         => $this->editor->hasTags($type) ? $this->editor->tagsFor($id) : [],
            'pending_tags' => $pending,
        ];
    }

    /**
     * posts.render — block JSON (or Markdown) to HTML, for switching the
     * editor between the visual and the source view.
     */
    public function render(AjaxContext $ctx): array
    {
        $content = (string) ($ctx->input('content') ?? '');
        if ($ctx->input('from') === 'markdown') {
            // For the visual editor, bold-only lines become headings and
            // deliberate line breaks are kept (Markdown::FOR_BLOCKS, 1.2.48).
            $opts = $ctx->input('for') === 'blocks' ? \App\Services\Markdown::FOR_BLOCKS : [];
            return ['html' => \App\Services\Markdown::toHtml($content, $opts)];
        }
        $hooks = \App\Core\Application::getInstance()->make(HookRegistry::class);
        return ['html' => \App\Services\BlockRenderer::render($content, $hooks)];
    }

    /** posts.templates — reusable block templates for the inserter's Patterns tab. */
    public function templates(AjaxContext $ctx): array
    {
        $result = $this->posts->paginate(['type' => 'template'], 1, 200);
        $out = [];
        foreach ($result['data'] as $t) {
            if (($t['content_format'] ?? '') !== 'blocks') continue;
            $doc = json_decode((string) ($t['content'] ?? ''), true);
            $blocks = is_array($doc) && isset($doc['blocks']) && is_array($doc['blocks']) ? $doc['blocks'] : [];
            if ($blocks === []) continue;
            $out[] = ['id' => (int) $t['id'], 'title' => (string) $t['title'], 'blocks' => $blocks];
        }
        return ['templates' => $out];
    }

    /**
     * posts.tag_add — put a tag on a post straight away.
     *
     * term_id: an existing tag. name: a typed name; when a tag of that name
     * exists it is attached, otherwise the name is kept as pending and the
     * tag is created when the post is published (PostEditorService).
     */
    public function tagAdd(AjaxContext $ctx): array
    {
        [$post, $user] = $this->taggablePost($ctx);
        $id = (int) $post['id'];

        if ($ctx->has('term_id')) {
            $tag = $this->editor->findTag((int) $ctx->input('term_id'));
            if (!$tag) throw AjaxException::notFound('That tag no longer exists.');
            $this->editor->attachTerm($id, (int) $tag['id']);
            $added = ['id' => (int) $tag['id'], 'name' => (string) $tag['name'], 'new' => false];
        } else {
            $name = PostEditorService::normaliseTagName((string) $ctx->input('name'));
            if ($name === '') throw AjaxException::invalid('Type a tag name.', ['name' => ['Type a tag name.']]);
            $tag = $this->editor->findTagByName($name);
            if ($tag) {
                $this->editor->attachTerm($id, (int) $tag['id']);
                $added = ['id' => (int) $tag['id'], 'name' => (string) $tag['name'], 'new' => false];
            } else {
                $pending = $this->editor->pendingTags($id);
                if (count($pending) >= PostEditorService::PENDING_MAX) {
                    throw AjaxException::invalid('This post has as many new tags as it can hold. Publish it, or remove some.');
                }
                $pending[] = $name;
                $this->editor->setPendingTags($id, $pending);
                $added = ['id' => null, 'name' => $name, 'new' => true];
            }
        }
        $ctx->log('Tag added', ['post_id' => $id, 'tag' => $added['name'], 'new' => $added['new'], 'user_id' => (int) $user['id']]);
        return ['added' => $added] + $this->tagState($id);
    }

    /** posts.tag_remove — take a tag (or a pending name) off a post. */
    public function tagRemove(AjaxContext $ctx): array
    {
        [$post] = $this->taggablePost($ctx);
        $id = (int) $post['id'];
        if ($ctx->has('term_id')) {
            $tag = $this->editor->findTag((int) $ctx->input('term_id'));
            if ($tag) $this->editor->detachTerm($id, (int) $tag['id']);
        } else {
            $name = mb_strtolower(PostEditorService::normaliseTagName((string) $ctx->input('name')), 'UTF-8');
            $this->editor->setPendingTags($id, array_values(array_filter(
                $this->editor->pendingTags($id), fn($n) => mb_strtolower($n, 'UTF-8') !== $name)));
        }
        return $this->tagState($id);
    }

    // ------------------------------------------------------------------

    /** @return array{0:array,1:array} the post and the user, or an AjaxException */
    private function taggablePost(AjaxContext $ctx): array
    {
        if (!$ctx->has('term_id') && !$ctx->has('name')) {
            throw AjaxException::invalid('Send a term_id or a name.', ['term_id' => ['Send a term_id or a name.']]);
        }
        $user = $ctx->user();
        $post = $this->posts->find((int) $ctx->input('post_id'));
        if (!$post) throw AjaxException::notFound('This post no longer exists.');
        if (!$this->editor->hasTags((string) $post['type'])) {
            throw AjaxException::invalid('This kind of content has no tags.');
        }
        if (!$this->editor->canEdit($user, $post)) {
            throw AjaxException::forbidden('Your role only allows you to edit your own posts.');
        }
        return [$post, $user];
    }

    private function tagState(int $postId): array
    {
        return ['tags' => $this->editor->tagsFor($postId), 'pending_tags' => $this->editor->pendingTags($postId)];
    }

    /** The editor's SEO fields, as SeoService stores them. Only what was sent. */
    private function seoFields(array $in): array
    {
        $out = [];
        foreach (['meta_title', 'meta_description', 'focus_keyword', 'robots', 'canonical_url'] as $k) {
            if (array_key_exists($k, $in)) $out[$k] = $in[$k] === '' ? null : $in[$k];
        }
        if (isset($out['canonical_url']) && !preg_match('#^https?://#i', (string) $out['canonical_url'])) {
            throw AjaxException::invalid('The canonical URL must start with http:// or https://.',
                ['seo.canonical_url' => ['Must start with http:// or https://.']]);
        }
        if (array_key_exists('robots', $out) && $out['robots'] === null) $out['robots'] = 'index,follow';
        return $out;
    }
}
