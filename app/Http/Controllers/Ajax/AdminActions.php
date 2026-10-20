<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ajax;

use App\Core\Ajax\AjaxContext;
use App\Core\Ajax\AjaxException;
use App\Core\Application;
use App\Services\MenuService;

/**
 * Small admin-screen actions that used to be hand-written JSON routes:
 * widget previews, the comments badge, the Customizer, the menu builder, a
 * user's activity and the log viewer. Each is registered in routes/ajax.php
 * with the capability and admin area of the screen it serves; the old routes
 * remain for older callers.
 */
final class AdminActions
{
    private Application $app;

    public function __construct()
    {
        $this->app = Application::getInstance();
    }

    /** widgets.render — preview of a widget block in the editor. */
    public function widgetRender(AjaxContext $ctx): array
    {
        /** @var \App\Core\WidgetRegistry $reg */
        $reg = $this->app->make(\App\Core\WidgetRegistry::class);
        $key = (string) $ctx->input('widget');
        if (!$reg->has($key)) {
            throw AjaxException::notFound('That widget is not available.');
        }
        // A widget's own capability applies to previews too (1.2.68): a
        // contributor could otherwise render an admin-only dashboard widget.
        if (!\App\Core\WidgetRegistry::userCan($reg->get($key), $ctx->user())) {
            throw AjaxException::forbidden();
        }
        $settings = self::jsonObject($ctx->input('settings') ?? '{}', 'settings');
        return ['html' => $reg->render($key, $settings, (string) ($ctx->input('surface') ?? 'editor'))];
    }

    /** comments.pending_count — the sidebar's "awaiting moderation" badge. */
    public function pendingComments(AjaxContext $ctx): array
    {
        return ['pending' => $this->app->make(\App\Services\CommentService::class)->pendingCount()];
    }

    /** customizer.save — save the Customizer's changed values. */
    public function customizerSave(AjaxContext $ctx): array
    {
        $values = self::jsonObject($ctx->input('values'), 'values');
        if ($values === []) throw AjaxException::invalid('Nothing to save.');
        $result = $this->app->make(\App\Services\CustomizerService::class)->save($values);
        // Skipped entries are reported rather than silently dropped.
        return [
            'saved'   => $result['saved'],
            'skipped' => $result['skipped'],
            'message' => $result['skipped']
                ? sprintf('Saved %d settings. %d could not be saved: %s',
                    $result['saved'], count($result['skipped']), implode(', ', $result['skipped']))
                : 'Saved.',
        ];
    }

    /** customizer.draft — values to preview without saving (kept in the session). */
    public function customizerDraft(AjaxContext $ctx): array
    {
        $this->app->make(\App\Core\Session::class)->set('customizer_draft', self::jsonObject($ctx->input('values'), 'values'));
        return ['stored' => true];
    }

    /** menus.items_add — add items picked in the menu builder. */
    public function menuItemsAdd(AjaxContext $ctx): array
    {
        $svc = $this->menus();
        $menuId = $this->menuId($ctx);
        $added = 0;
        foreach ((array) $ctx->input('items') as $it) {
            $title = trim((string) ($it['title'] ?? ''));
            $url   = trim((string) ($it['url'] ?? ''));
            if ($title === '' || $url === '') continue;
            $svc->addItem($menuId, [
                'type'      => (string) ($it['type'] ?? 'custom'),
                'object_id' => !empty($it['object_id']) ? (int) $it['object_id'] : null,
                'title'     => mb_substr($title, 0, 200),
                'url'       => self::menuUrl($url, 'items'),
                'target'    => ($it['target'] ?? '_self') === '_blank' ? '_blank' : '_self',
            ]);
            $added++;
        }
        if ($added === 0) throw AjaxException::invalid('Nothing to add: each item needs a label and a URL.');
        return ['added' => $added, 'items' => $svc->items($menuId)];
    }

    /** menus.item_update — label, URL, target and classes of one item. */
    public function menuItemUpdate(AjaxContext $ctx): array
    {
        $menuId = $this->menuId($ctx);
        $itemId = $this->itemOf($menuId, (int) $ctx->input('item_id'));
        $ok = $this->menus()->updateItem($itemId, [
            'title'   => mb_substr(trim((string) $ctx->input('title')), 0, 200),
            'url'     => self::menuUrl(trim((string) $ctx->input('url')), 'url'),
            'target'  => ($ctx->input('target') ?? '_self') === '_blank' ? '_blank' : '_self',
            'classes' => mb_substr(trim((string) ($ctx->input('classes') ?? '')), 0, 200),
        ]);
        return ['updated' => $ok];
    }

    /** menus.item_remove — remove an item (and, with it, its sub-items). */
    public function menuItemRemove(AjaxContext $ctx): array
    {
        $menuId = $this->menuId($ctx);
        $this->menus()->deleteItem($this->itemOf($menuId, (int) $ctx->input('item_id')));
        return ['items' => $this->menus()->items($menuId)];
    }

    /** menus.reorder — save the builder's order and nesting. */
    public function menuReorder(AjaxContext $ctx): array
    {
        $menuId = $this->menuId($ctx);
        return ['saved' => $this->menus()->saveTree($menuId, (array) $ctx->input('tree'))];
    }

    /** users.activity — one page of a user's activity log. */
    public function userActivity(AjaxContext $ctx): array
    {
        /** @var \App\Services\ActivityLogService $log */
        $log = $this->app->make(\App\Services\ActivityLogService::class);
        $filter = (string) ($ctx->input('filter') ?? 'all');
        if (!array_key_exists($filter, \App\Services\ActivityLogService::FILTERS)) $filter = 'all';
        return $log->forUser((int) $ctx->input('user_id'), $filter, (int) ($ctx->input('page') ?? 1), 30);
    }

    /** system.log — the end of one log file, for System → Logs. */
    public function systemLog(AjaxContext $ctx): array
    {
        $r = $this->app->make(\App\Services\SystemInfoService::class)
            ->readLog((string) $ctx->input('name'), (int) ($ctx->input('lines') ?? 300));
        if (isset($r['error'])) throw AjaxException::notFound((string) $r['error']);
        return $r;
    }

    // ------------------------------------------------------------------

    private function menus(): MenuService
    {
        return $this->app->make(MenuService::class);
    }

    private function menuId(AjaxContext $ctx): int
    {
        $id = (int) $ctx->input('menu_id');
        if (!$this->menus()->find($id)) throw AjaxException::notFound('That menu no longer exists.');
        return $id;
    }

    /** The item, if it is in this menu: an item ID alone could name any menu's item. */
    private function itemOf(int $menuId, int $itemId): int
    {
        $row = $this->app->make(\App\Core\Database::class)->selectOne(
            'SELECT id FROM {menu_items} WHERE id = :i AND menu_id = :m', ['i' => $itemId, 'm' => $menuId]);
        if (!$row) throw AjaxException::notFound('That menu item no longer exists.');
        return $itemId;
    }

    /**
     * A menu link: relative, #fragment, http(s), mailto: or tel:. Anything
     * else (javascript:, data:) would run on the public site when clicked.
     */
    private static function menuUrl(string $url, string $field): string
    {
        $url = mb_substr($url, 0, 500);
        // Browsers drop tabs, newlines and other controls inside a URL, so
        // "java\tscript:" is javascript: — judge the URL without them.
        $probe = preg_replace('/[\x00-\x20\x7F]+/', '', $url) ?? '';
        if (preg_match('#^(/|\#|\?|https?://|mailto:|tel:)#i', $probe) || !preg_match('#^[a-z][a-z0-9+.\-]*:#i', $probe)) {
            return $url;
        }
        throw AjaxException::invalid('A menu link must be a web address, a path, mailto: or tel:.',
            [$field => ['Use https://…, /path, #anchor, mailto: or tel:.']]);
    }

    /** A JSON object sent as a string field (free-form keys a schema cannot list). */
    private static function jsonObject(mixed $raw, string $field): array
    {
        $v = is_string($raw) ? json_decode($raw, true, 32) : null;
        if (!is_array($v) || ($v !== [] && array_is_list($v))) {
            throw AjaxException::invalid('Send ' . $field . ' as a JSON object.', [$field => ['Must be a JSON object.']]);
        }
        return $v;
    }
}
