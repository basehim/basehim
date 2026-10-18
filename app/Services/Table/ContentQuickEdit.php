<?php

declare(strict_types=1);

namespace App\Services\Table;

use App\Core\Ajax\AjaxException;
use App\Core\Database;
use App\Core\Helpers;
use App\Core\Table\QuickEditor;
use App\Core\Time;
use App\Http\Middleware\CheckCapability;
use App\Services\ActivityLogService;
use App\Services\PostEditorService;
use App\Services\PostPublishing;
use App\Services\PostService;

/**
 * Quick Edit for content in the posts table — Posts and Pages (1.2.61).
 *
 * Each content type lists the fields it offers (fieldNames()); this class
 * knows how to show, check and save each one, using the same pieces as the
 * block editor so nothing is decided twice:
 *
 *  - who may edit the record: PostEditorService::canEdit() (the type's admin
 *    area, own posts, or edit_others_{type}s);
 *  - status and publication time: PostPublishing::resolve(), the rules the
 *    editor's Publish panel uses, then PostService's own invariants;
 *  - saving: PostService::update(), so hooks, slug allocation and caches
 *    behave exactly as for an editor save;
 *  - new tag names: PostEditorService::settleNewTags() (pending until the post
 *    is published by someone who may create tags).
 *
 * A field appears in the form only for a user who may change it, and save()
 * refuses any field that is not in that user's form for that record — the
 * form describes what is allowed, it does not grant it.
 *
 * Concurrent edits: the form carries a version (a hash of every field Quick
 * Edit can change). A save made against an older version is refused instead
 * of overwriting someone else's change, and only the fields the person
 * actually changed are sent and written.
 */
abstract class ContentQuickEdit implements QuickEditor
{
    /** Largest number of authors / parent pages offered in a select. */
    private const OPTION_LIMIT = 500;

    public function __construct(
        protected PostService $posts,
        protected PostEditorService $editor,
        protected Database $db,
    ) {}

    /** The content type this editor edits. */
    abstract protected function type(): string;

    /**
     * The fields this type offers, in order. A field that does not apply to
     * the user or the record is dropped when the form is built.
     *
     * @return string[] from: title, slug, author, status, publish_date, publish_time,
     *                  categories, tags, comments, parent, menu_order
     */
    abstract protected function fieldNames(): array;

    // ------------------------------------------------------------------
    // QuickEditor
    // ------------------------------------------------------------------

    public function form(int $id, array $user): array
    {
        $post = $this->record($id, $user);
        $terms = $this->termsOf((int) $post['id']);
        return [
            'id'      => (int) $post['id'],
            'title'   => (string) $post['title'],
            'version' => $this->version($post, $terms),
            'fields'  => array_values($this->fields($post, $user, $terms)),
        ];
    }

    public function save(int $id, array $values, array $user, array $options = []): array
    {
        $post = $this->record($id, $user);
        $type = $this->type();
        $terms = $this->termsOf((int) $post['id']);

        // A save against an older view of the record would overwrite whatever
        // changed since: refuse it.
        $version = (string) ($options['version'] ?? '');
        if ($version === '' || !hash_equals($this->version($post, $terms), $version)) {
            throw new AjaxException('EDIT_CONFLICT',
                'This ' . $this->noun() . ' was changed somewhere else after you opened Quick Edit. Your changes are still in the form; cancel and open Quick Edit again to start from the latest version.', 409);
        }

        // Only fields in this user's form for this record may be sent.
        $fields = $this->fields($post, $user, $terms);
        $allowed = [];
        foreach ($fields as $f) $allowed[$f['name']] = $f;
        $errors = [];
        foreach (array_keys($values) as $name) {
            if (!is_string($name) || !isset($allowed[$name])) {
                $errors[is_string($name) ? $name : 'field'][] = 'This field cannot be changed here.';
            }
        }
        if ($errors) {
            throw new AjaxException('FIELD_NOT_ALLOWED', 'Some of these changes are not allowed for your role.', 403, $errors);
        }
        if ($values === []) {
            throw AjaxException::invalid('Nothing was changed.');
        }

        $data = [];
        $notices = [];

        // -- Title ------------------------------------------------------------
        if (array_key_exists('title', $values)) {
            $title = is_string($values['title']) ? trim(preg_replace('/\s+/u', ' ', $values['title']) ?? '') : '';
            if ($title === '') $errors['title'][] = 'Enter a title.';
            elseif (mb_strlen($title) > 1000) $errors['title'][] = 'Keep the title under 1,000 characters.';
            else $data['title'] = $title;
        }

        // -- Slug ---------------------------------------------------------------
        if (array_key_exists('slug', $values)) {
            $raw = is_string($values['slug']) ? trim($values['slug']) : null;
            if ($raw === null) {
                $errors['slug'][] = 'Enter a slug.';
            } else {
                // Empty: made from the title, as for a new post.
                $source = $raw !== '' ? $raw : (string) ($data['title'] ?? $post['title']);
                $slug = Helpers::slug($source);
                $unusable = $slug === '' || $slug === 'n-a';
                if ($unusable && $raw !== '') {
                    $errors['slug'][] = 'Use letters a–z, numbers and hyphens in the slug.';
                    $slug = null;
                } elseif ($unusable) {
                    $slug = Helpers::slug('untitled');    // a title with no Latin letters, as PostService does
                }
            }
            if ($raw !== null && $slug !== null) {
                if (strlen($slug) > 190) $slug = rtrim(substr($slug, 0, 190), '-');
                // Posts and pages share one address space (PostService::resolveSlug).
                $root = in_array($type, ['post', 'page'], true);
                $types = $root ? ['post', 'page'] : [$type];
                if ($root && in_array($slug, Helpers::reservedSlugs(), true)) {
                    $errors['slug'][] = 'This address is reserved by the site. Choose another.';
                } elseif ($slug !== $post['slug'] && $this->slugTaken($slug, $types, (int) $post['id'])) {
                    $errors['slug'][] = 'Another post or page already uses this address (including items in the trash). Choose another.';
                } else {
                    $data['slug'] = $slug;
                }
            }
        }

        // -- Author -------------------------------------------------------------
        if (array_key_exists('author', $values)) {
            $aid = filter_var($values['author'], FILTER_VALIDATE_INT);
            $ok = $aid !== false && $aid > 0 && in_array((int) $aid, array_column($this->authorOptions($post), 'value'), true);
            if (!$ok) $errors['author'][] = 'Choose an author who can edit ' . $this->noun() . 's.';
            else $data['author_id'] = (int) $aid;
        }

        // -- Status and publication time ---------------------------------------
        $wantsTiming = array_key_exists('status', $values) || array_key_exists('publish_date', $values) || array_key_exists('publish_time', $values);
        if ($wantsTiming) {
            $status = array_key_exists('status', $values) ? $values['status'] : $post['status'];
            $statusOpts = array_column($allowed['status']['options'] ?? [], 'value');
            if (!is_string($status) || !in_array($status, $statusOpts, true)) {
                $errors['status'][] = 'Choose one of the statuses offered.';
            } else {
                $date = $values['publish_date'] ?? ($allowed['publish_date']['value'] ?? '');
                $time = $values['publish_time'] ?? ($allowed['publish_time']['value'] ?? '');
                $input = match ($status) {
                    'scheduled' => ['status' => 'published', 'publish_mode' => 'schedule',
                                    'publish_date' => is_string($date) ? $date : '', 'publish_time' => is_string($time) ? $time : ''],
                    'published' => ['status' => 'published', 'publish_mode' => 'now'],
                    default     => ['status' => $status],
                };
                $r = PostPublishing::resolve($post, $input, $this->editor->canPublish($user, $type), Time::zone(), time());
                if (isset($r['error'])) {
                    $errors[$status === 'scheduled' ? 'publish_date' : 'status'][] = $r['error'];
                } else {
                    // Taking a live post off the site is never a side effect.
                    $needsConfirm = $post['status'] === 'published' && $r['status'] !== 'published' && empty($options['confirm']);
                    if ($r['status'] !== $post['status'] || array_key_exists('published_at', $r)) {
                        $data['status'] = $r['status'];
                        if (array_key_exists('published_at', $r)) $data['published_at'] = $r['published_at'];
                    }
                    if (isset($r['notice'])) $notices[] = $r['notice'];
                }
            }
        }

        // -- Categories and tags --------------------------------------------------
        $newTagNames = null;
        if (array_key_exists('categories', $values) || array_key_exists('tags', $values)) {
            $catIds = $terms['category_ids'];
            $tagIds = $terms['tag_ids'];
            if (array_key_exists('categories', $values)) {
                $list = is_array($values['categories']) ? $values['categories'] : null;
                $valid = array_map('intval', array_column($allowed['categories']['options'] ?? [], 'value'));
                $chosen = $list === null ? null : array_values(array_unique(array_map('intval', array_filter($list, 'is_numeric'))));
                if ($chosen === null || array_diff($chosen, $valid)) {
                    $errors['categories'][] = 'Choose from the categories listed.';
                } else {
                    $catIds = $chosen;
                }
            }
            if (array_key_exists('tags', $values)) {
                if (!is_string($values['tags'])) {
                    $errors['tags'][] = 'Separate tags with commas.';
                } else {
                    $names = [];
                    foreach (explode(',', $values['tags']) as $n) {
                        $n = PostEditorService::normaliseTagName($n);
                        if ($n !== '' && !in_array(mb_strtolower($n), array_map('mb_strtolower', $names), true)) $names[] = $n;
                    }
                    if (count($names) > PostEditorService::PENDING_MAX) {
                        $errors['tags'][] = 'Use at most ' . PostEditorService::PENDING_MAX . ' tags.';
                    } else {
                        $tagIds = [];
                        $newTagNames = [];
                        foreach ($names as $n) {
                            $tag = $this->editor->findTagByName($n);
                            if ($tag) $tagIds[] = (int) $tag['id'];
                            else $newTagNames[] = $n;
                        }
                    }
                }
            }
            // PostService replaces every term at once, so send the post's
            // other terms along: changing categories never drops its tags.
            $data['term_ids'] = array_values(array_unique(array_merge($terms['other_ids'], $catIds, $tagIds)));
        }

        // -- Comments ---------------------------------------------------------------
        if (array_key_exists('comments', $values)) {
            $v = $values['comments'];
            if (!is_bool($v)) $errors['comments'][] = 'Choose whether comments are allowed.';
            else $data['comment_status'] = $v ? 'open' : 'closed';
        }

        // -- Parent and order (pages) ---------------------------------------------
        if (array_key_exists('parent', $values)) {
            $pid = $values['parent'];
            if ($pid === '' || $pid === null || $pid === 0 || $pid === '0') {
                $data['parent_id'] = null;
            } else {
                $pid = filter_var($pid, FILTER_VALIDATE_INT);
                $valid = array_map('intval', array_column($allowed['parent']['options'] ?? [], 'value'));
                if ($pid === false || !in_array((int) $pid, $valid, true) || (int) $pid === (int) $post['id']) {
                    $errors['parent'][] = 'Choose another ' . $this->noun() . ' that is not this one or below it.';
                } else {
                    $data['parent_id'] = (int) $pid;
                }
            }
        }
        if (array_key_exists('menu_order', $values)) {
            $o = filter_var($values['menu_order'], FILTER_VALIDATE_INT);
            if ($o === false || $o < -100000 || $o > 100000) $errors['menu_order'][] = 'Enter a whole number between -100000 and 100000.';
            else $data['menu_order'] = (int) $o;
        }

        if ($errors) {
            throw AjaxException::invalid('Some fields need attention.', $errors);
        }
        if (!empty($needsConfirm)) {
            throw new AjaxException('CONFIRM_REQUIRED',
                'This takes a live ' . $this->noun() . ' off the site. Confirm to continue.', 409);
        }

        // -- Save through the editor's own path ---------------------------------
        if ($data !== []) {
            $this->posts->update((int) $post['id'], $data, $user);
        }
        $saved = $this->posts->find((int) $post['id']) ?? $post;
        if ($newTagNames !== null) {
            $settled = $this->editor->settleNewTags((int) $post['id'], $newTagNames, (string) $saved['status'], $user, $type);
            if ($settled['pending'] !== []) {
                $notices[] = count($settled['pending']) . ' new tag(s) will be created when someone who can publish publishes this ' . $this->noun() . '.';
            }
        }
        if (isset($data['slug']) && $saved['slug'] !== $data['slug']) {
            // Taken by another save in the meantime: PostService chose the next free one.
            $notices[] = 'That address was just taken, so the slug is now "' . $saved['slug'] . '".';
        }
        if (isset($data['slug']) && $saved['slug'] !== $post['slug'] && $post['status'] === 'published') {
            $notices[] = 'The address changed: links to the old one will no longer work.';
        }

        ActivityLogService::record((int) $user['id'], $type . '.updated', $type, (int) $post['id'],
            'Quick edit "' . mb_substr((string) $saved['title'], 0, 80) . '": ' . implode(', ', array_keys($values)));

        $savedTerms = $this->termsOf((int) $post['id']);
        return [
            'row'     => $saved,
            'version' => $this->version($saved, $savedTerms),
            'notices' => $notices,
        ];
    }

    // ------------------------------------------------------------------
    // The record and who may edit it
    // ------------------------------------------------------------------

    /** The record, if it is this type, not trashed, and this user's to edit. */
    protected function record(int $id, array $user): array
    {
        $post = $id > 0 ? $this->posts->find($id) : null;
        // Another type answers exactly like a missing record, so the request
        // cannot be used to probe or touch other content.
        if (!$post || ($post['type'] ?? '') !== $this->type()) {
            throw AjaxException::notFound('This ' . $this->noun() . ' no longer exists. It may have been moved to the trash.');
        }
        if (!$this->editor->canEdit($user, $post)) {
            throw AjaxException::forbidden('Your role only allows you to edit your own ' . $this->noun() . 's.');
        }
        // Without publish rights, live content is not changed here: an edit
        // would have to pull it back into review, which belongs in the editor.
        if (in_array((string) $post['status'], PostPublishing::OUT, true) && !$this->editor->canPublish($user, $this->type())) {
            throw AjaxException::forbidden('This ' . $this->noun() . ' is ' . $post['status'] . '. Your role cannot change it here; open it in the editor to submit changes for review.');
        }
        return $post;
    }

    // ------------------------------------------------------------------
    // Fields
    // ------------------------------------------------------------------

    /** @return array<int,array> the fields this user may change on this record */
    protected function fields(array $post, array $user, array $terms): array
    {
        $type = $this->type();
        $canPublish = $this->editor->canPublish($user, $type);
        $out = [];
        foreach ($this->fieldNames() as $name) {
            $f = match ($name) {
                'title' => ['type' => 'text', 'label' => 'Title', 'value' => (string) $post['title'], 'required' => true, 'max' => 1000, 'wide' => true],
                'slug' => ['type' => 'slug', 'label' => 'Slug', 'value' => (string) $post['slug'], 'max' => 190,
                           'help' => 'The last part of the address. Leave empty to make it from the title.'],
                'author' => $this->editor->canEditOthers($user, $type) && $this->editor->supports($type, 'author')
                    ? ['type' => 'select', 'label' => 'Author', 'value' => (string) $post['author_id'], 'options' => $this->authorOptions($post)]
                    : null,
                'status' => ['type' => 'select', 'label' => 'Status', 'value' => (string) $post['status'],
                             'options' => $this->statusOptions($post, $canPublish), 'help' => $this->statusHelp($post),
                             'confirm' => ['from' => ['published'], 'to' => ['draft', 'pending', 'private', 'scheduled'],
                                           'message' => 'This takes the live ' . $this->noun() . ' off the site until it is published again. Continue?']],
                'publish_date', 'publish_time' => $canPublish ? $this->timeField($name, $post) : null,
                'categories' => $this->editor->hasCategories($type)
                    ? ['type' => 'checklist', 'label' => 'Categories', 'value' => array_map('strval', $terms['category_ids']),
                       'options' => $this->categoryOptions(), 'wide' => true]
                    : null,
                'tags' => $this->editor->hasTags($type)
                    ? ['type' => 'tags', 'label' => 'Tags', 'value' => implode(', ', array_merge($terms['tag_names'], $this->editor->pendingTags((int) $post['id']))),
                       'max' => 2000, 'wide' => true,
                       'help' => $this->editor->canCreateTags($user, $type)
                           ? 'Separate with commas. New tags are created when the ' . $this->noun() . ' is published.'
                           : 'Separate with commas. New tags are created when an editor publishes the ' . $this->noun() . '.']
                    : null,
                'comments' => $this->editor->supports($type, 'comments')
                    ? ['type' => 'toggle', 'label' => 'Allow comments', 'value' => ($post['comment_status'] ?? 'open') === 'open']
                    : null,
                'parent' => $this->editor->isHierarchical($type)
                    ? ['type' => 'select', 'label' => 'Parent', 'value' => (string) ($post['parent_id'] ?? ''),
                       'options' => array_merge([['value' => '', 'label' => '(no parent)']], $this->parentOptions($post))]
                    : null,
                'menu_order' => $this->editor->isHierarchical($type)
                    ? ['type' => 'number', 'label' => 'Order', 'value' => (int) ($post['menu_order'] ?? 0), 'min' => -100000, 'max' => 100000,
                       'help' => 'Lower numbers come first.']
                    : null,
                default => null,
            };
            if ($f !== null) $out[] = ['name' => $name] + $f;
        }
        return $out;
    }

    /** Statuses offered: what the user may choose, plus the current one. */
    private function statusOptions(array $post, bool $canPublish): array
    {
        $labels = ['draft' => 'Draft', 'pending' => 'Pending review', 'published' => 'Published',
                   'scheduled' => 'Scheduled', 'private' => 'Private'];
        $keys = $canPublish ? ['draft', 'pending', 'published', 'scheduled', 'private'] : ['draft', 'pending'];
        $opts = [];
        foreach ($keys as $k) {
            $label = $labels[$k];
            if ($k === 'published' && $post['status'] !== 'published') $label = 'Published (now)';
            $opts[] = ['value' => $k, 'label' => $label];
        }
        return $opts;
    }

    private function statusHelp(array $post): string
    {
        $at = !empty($post['published_at']) ? Time::local($post['published_at']) : null;
        $when = $at ? $at->format(Time::dateFormat()) . ' at ' . $at->format(Time::timeFormat()) : '';
        return match ((string) $post['status']) {
            'published' => $when !== '' ? 'Published ' . $when . '. Editing other fields keeps this date.' : '',
            'scheduled' => 'Goes live ' . $when . ' (' . Time::abbreviation($post['published_at']) . ').',
            default => 'Choose Scheduled to pick a date and time.',
        };
    }

    /** Date or time of the schedule, in the site timezone; shown for Scheduled only. */
    private function timeField(string $name, array $post): array
    {
        $at = $post['status'] === 'scheduled' && !empty($post['published_at']) ? Time::local($post['published_at']) : null;
        $tz = Time::timezone() . ', UTC' . Time::offset();
        return $name === 'publish_date'
            ? ['type' => 'date', 'label' => 'Date', 'value' => $at ? $at->format('Y-m-d') : '',
               'show_when' => ['field' => 'status', 'in' => ['scheduled']], 'help' => 'Site time (' . $tz . ').']
            : ['type' => 'time', 'label' => 'Time', 'value' => $at ? $at->format('H:i') : '',
               'show_when' => ['field' => 'status', 'in' => ['scheduled']]];
    }

    /**
     * Active users who can edit this type, by name — one query, then the
     * capability check in PHP (roles, custom roles and per-user overrides).
     * The current author is always offered.
     */
    protected function authorOptions(array $post): array
    {
        static $cache = [];
        $key = $this->type() . ':' . (int) $post['author_id'];
        if (isset($cache[$key])) return $cache[$key];
        $cap = $this->editor->capability($this->type(), 'edit');
        $rows = $this->db->select(
            "SELECT id, username, display_name, role, meta FROM {users}
              WHERE deleted_at IS NULL AND (status = 'active' OR id = :cur)
           ORDER BY COALESCE(NULLIF(display_name, ''), username) LIMIT " . self::OPTION_LIMIT,
            ['cur' => (int) $post['author_id']]
        );
        $out = [];
        foreach ($rows as $u) {
            if ((int) $u['id'] !== (int) $post['author_id'] && !CheckCapability::userCan($u, $cap)) continue;
            $name = trim((string) ($u['display_name'] ?? '')) ?: (string) $u['username'];
            $out[] = ['value' => (int) $u['id'], 'label' => $name];
        }
        return $cache[$key] = $out;
    }

    /** Every category, by name (one query). */
    protected function categoryOptions(): array
    {
        static $cache = null;
        if ($cache !== null) return $cache;
        $rows = $this->db->select(
            "SELECT t.id, t.name FROM {terms} t JOIN {taxonomies} x ON x.id = t.taxonomy_id
              WHERE x.slug = 'category' ORDER BY t.name LIMIT 1000"
        );
        return $cache = array_map(fn($r) => ['value' => (int) $r['id'], 'label' => (string) $r['name']], $rows);
    }

    /**
     * Pages that can be this one's parent: same type, not trashed, not this
     * page and not one of its descendants (that would make a loop). One query.
     */
    protected function parentOptions(array $post): array
    {
        $rows = $this->db->select(
            'SELECT id, title, parent_id FROM {posts} WHERE type = :t AND deleted_at IS NULL ORDER BY title LIMIT ' . self::OPTION_LIMIT,
            ['t' => $this->type()]
        );
        $children = [];
        foreach ($rows as $r) $children[(int) ($r['parent_id'] ?? 0)][] = (int) $r['id'];
        $blocked = [(int) $post['id'] => true];
        $stack = [(int) $post['id']];
        while ($stack) {
            foreach ($children[array_pop($stack)] ?? [] as $c) {
                if (!isset($blocked[$c])) { $blocked[$c] = true; $stack[] = $c; }
            }
        }
        $out = [];
        foreach ($rows as $r) {
            if (isset($blocked[(int) $r['id']])) continue;
            $out[] = ['value' => (int) $r['id'], 'label' => (string) $r['title'] !== '' ? (string) $r['title'] : '(no title)'];
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * The post's terms split by taxonomy (one query).
     *
     * @return array{category_ids:int[], tag_ids:int[], tag_names:string[], other_ids:int[]}
     */
    protected function termsOf(int $postId): array
    {
        $rows = $this->db->select(
            'SELECT t.id, t.name, x.slug AS taxonomy FROM {post_term} pt
               JOIN {terms} t ON t.id = pt.term_id JOIN {taxonomies} x ON x.id = t.taxonomy_id
              WHERE pt.post_id = :p ORDER BY t.name',
            ['p' => $postId]
        );
        $out = ['category_ids' => [], 'tag_ids' => [], 'tag_names' => [], 'other_ids' => []];
        foreach ($rows as $r) {
            if ($r['taxonomy'] === 'category') $out['category_ids'][] = (int) $r['id'];
            elseif ($r['taxonomy'] === 'tag') { $out['tag_ids'][] = (int) $r['id']; $out['tag_names'][] = (string) $r['name']; }
            else $out['other_ids'][] = (int) $r['id'];
        }
        return $out;
    }

    /** A fingerprint of everything Quick Edit can change on the record. */
    protected function version(array $post, array $terms): string
    {
        $ids = array_merge($terms['category_ids'], $terms['tag_ids'], $terms['other_ids']);
        sort($ids);
        return substr(hash('sha256', json_encode([
            (string) $post['title'], (string) $post['slug'], (int) $post['author_id'], (string) $post['status'],
            (string) ($post['published_at'] ?? ''), (string) ($post['comment_status'] ?? ''),
            (int) ($post['parent_id'] ?? 0), (int) ($post['menu_order'] ?? 0), $ids,
            $this->editor->pendingTags((int) $post['id']),
        ])), 0, 32);
    }

    private function slugTaken(string $slug, array $types, int $excludeId): bool
    {
        $params = ['slug' => $slug, 'ex' => $excludeId];
        $in = [];
        foreach (array_values($types) as $i => $t) { $in[] = ':t' . $i; $params['t' . $i] = $t; }
        return $this->db->selectOne(
            'SELECT id FROM {posts} WHERE slug = :slug AND id <> :ex AND type IN (' . implode(',', $in) . ') LIMIT 1', $params
        ) !== null;
    }

    protected function noun(): string
    {
        return $this->type();
    }
}
