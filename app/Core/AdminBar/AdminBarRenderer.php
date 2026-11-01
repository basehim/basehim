<?php

declare(strict_types=1);

namespace App\Core\AdminBar;

use App\Core\Icon;

/**
 * Draws the admin bar (1.2.96): the markup, one stylesheet, one script and
 * the configuration the script needs. Every label, link and attribute is
 * escaped here; nothing from a component reaches the page unescaped.
 *
 * The same markup serves both display modes. The horizontal bar shows the
 * menus in a row with dropdowns; the floating control shows them stacked in
 * a panel that opens from a round button. Phones get the row as icons, with
 * dropdowns as full-width sheets.
 */
final class AdminBarRenderer
{
    /** Icons the script draws itself (search results, notices, controls). */
    private const SCRIPT_ICONS = ['x-mark', 'chevron-down', 'chevron-right', 'magnifying-glass', 'bell', 'arrow-path',
        'exclamation-triangle', 'exclamation-circle', 'check-circle', 'information-circle', 'document-text', 'newspaper',
        'photo', 'user-circle', 'tag', 'arrow-top-right-on-square', 'bolt', 'chevron-up', 'squares-2x2'];

    public function __construct(private string $base = '') {}

    /**
     * @param list<array> $tree     AdminBar::tree()
     * @param array       $settings AdminBarSettings::effective()
     */
    public function render(array $tree, array $settings, array $user, AdminBarContext $ctx, string $descriptor): string
    {
        $mode = $settings['mode'];
        $lazy = [];
        $searchIcons = [];
        $main = '';
        $end = '';
        foreach ($tree as $menu) {
            foreach ($this->lazyIds($menu) as $id) $lazy[] = $id;
            $html = $this->menu($menu, $settings, $user);
            if ($menu['region'] === 'end') $end .= $html; else $main .= $html;
        }

        $cfg = [
            'url'          => $this->base . \App\Core\Request::AJAX_PATH,
            'base'         => $this->base,
            'ctx'          => $descriptor,
            'mode'         => $mode,
            'userMode'     => $settings['user_mode'],
            'position'     => $settings['position'],
            'collapsed'    => (bool) $settings['collapsed'],
            'mobile'       => $settings['mobile'],
            'openOn'       => $settings['open_on'],
            'shortcuts'    => (bool) $settings['shortcuts'],
            'remember'     => (bool) $settings['remember'],
            'autoHide'     => (int) $settings['auto_hide'],
            'userOptions'  => array_values($settings['user_options']),
            'hidden'       => array_values($settings['hidden']),
            'lazy'         => array_values(array_unique($lazy)),
            'user'         => (int) $user['id'],
            'notifications'=> $this->has($tree, 'notifications'),
            'search'       => $this->has($tree, 'search'),
            'icons'        => array_combine(self::SCRIPT_ICONS, array_map(fn($n) => Icon::svg($n, 'bhab-ico'), self::SCRIPT_ICONS)),
            'optional'     => $this->optionalSections($tree),
        ];
        $json = json_encode($cfg, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        $classes = ['bhab', 'bhab--' . $mode, 'bhab--' . $settings['density'], 'bhab--color-' . $settings['color'],
                    'bhab--labels-' . $settings['labels'], 'bhab--pos-' . $settings['position'], 'bhab--align-' . $settings['align'],
                    'bhab--mobile-' . $settings['mobile']];
        if ($settings['collapsed'] && $mode === 'horizontal') $classes[] = 'is-collapsed';
        if (!$settings['branding']) $classes[] = 'bhab--no-brand';
        $style = '--bhab-h:' . (int) $settings['height'] . 'px;--bhab-fab:' . (int) $settings['float_size'] . 'px;--bhab-panel:' . (int) $settings['panel_width'] . 'px';

        $ver = rawurlencode(defined('BASEHIM_VERSION') ? (string) BASEHIM_VERSION : '1');
        $e = fn($v) => self::e($v);
        $fabLabel = 'Site management';

        return "\n<!-- Basehim admin bar: only signed-in users who manage this site see it. -->\n"
            . '<link rel="stylesheet" href="' . $e($this->base . '/admin/assets/css/admin-bar.css?v=' . $ver) . '" id="bhab-css">'
            . '<div id="bh-admin-bar" class="' . $e(implode(' ', $classes)) . '" style="' . $e($style) . '" data-bhab-mode="' . $e($mode) . '">'
            .   '<button type="button" class="bhab-fab" aria-expanded="false" aria-controls="bhab-bar" aria-label="' . $e($fabLabel) . '">'
            .     Icon::svg('squares-2x2', 'bhab-ico bhab-fab__ico') . '<span class="bhab-badge bhab-fab__badge" hidden></span></button>'
            .   '<button type="button" class="bhab-tab" aria-label="Show the admin bar" hidden>' . Icon::svg('chevron-down', 'bhab-ico') . '</button>'
            .   '<nav class="bhab-bar" id="bhab-bar" aria-label="Admin bar">'
            .     '<div class="bhab-sheet-head"><span class="bhab-sheet-title">' . $e($fabLabel) . '</span>'
            .       '<button type="button" class="bhab-sheet-close" aria-label="Close">' . Icon::svg('x-mark', 'bhab-ico') . '</button></div>'
            .     '<ul class="bhab-menus bhab-menus--main">' . $main . '</ul>'
            .     '<ul class="bhab-menus bhab-menus--end">' . $end . '</ul>'
            .   '</nav>'
            .   '<div class="bhab-live" aria-live="polite" role="status"></div>'
            . '</div>'
            . '<script>window.BASEHIM_AJAX = window.BASEHIM_AJAX || ' . json_encode(['url' => $cfg['url']], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) . ';'
            . 'window.BASEHIM_ADMIN_BAR = ' . $json . ';</script>'
            . '<script src="' . $e($this->base . '/admin/assets/js/ajax.js?v=' . $ver) . '" defer></script>'
            . '<script src="' . $e($this->base . '/admin/assets/js/admin-bar.js?v=' . $ver) . '" defer></script>'
            . "\n";
    }

    /** A first, inline style so the page doesn't jump while admin-bar.css loads. */
    public function earlyStyle(array $settings): string
    {
        if ($settings['mode'] !== 'horizontal' || $settings['collapsed'] || $settings['auto_hide'] > 0) return '';
        $h = (int) $settings['height'];
        $css = 'html{margin-top:' . $h . 'px!important;scroll-padding-top:' . $h . 'px}';
        if ($settings['mobile'] !== 'same') {
            $css = '@media (min-width:783px){' . $css . '}';
        } else {
            $css .= '@media (max-width:782px){html{margin-top:46px!important;scroll-padding-top:46px}}';
        }
        return '<style id="bhab-early">' . $css . '</style>';
    }

    private function menu(array $m, array $settings, array $user): string
    {
        $id = $m['id'];
        $hasDrop = $m['children'] !== [] || $m['panels'] !== [] || in_array($m['action'], ['notifications', 'display'], true);
        $label = self::e($m['label']);
        $icon = $this->menuIcon($m, $settings, $user);
        $badges = $this->badges($m['indicators'], true);
        $dropId = 'bhab-drop-' . self::slug($id);
        $title = $m['title'] !== '' ? $m['title'] : $m['label'];
        $cls = 'bhab-menu bhab-menu--' . self::slug($id) . ($m['optional'] ? ' is-optional' : '') . (!empty($m['hidden']) ? ' is-user-hidden' : '') . (!empty($m['attrs']['brand']) ? ' bhab-menu--brand' : '');
        $inner = $icon . '<span class="bhab-label">' . $label . '</span>' . $badges;

        $out = '<li class="' . self::e($cls) . '" data-bhab-id="' . self::e($id) . '">';
        if ($m['action'] === 'search') {
            $out .= '<button type="button" class="bhab-top" data-bhab-action="search" title="' . self::e($title . ($settings['shortcuts'] ? ' (Ctrl+K)' : '')) . '" aria-label="' . self::e($m['label']) . '">'
                  . $inner . '</button>';
        } elseif ($hasDrop) {
            $out .= '<button type="button" class="bhab-top" aria-expanded="false" aria-haspopup="true" aria-controls="' . $dropId . '" title="' . self::e($title) . '"'
                  . ($m['action'] !== '' ? ' data-bhab-action="' . self::e($m['action']) . '"' : '') . '>'
                  . $inner . Icon::svg('chevron-down', 'bhab-ico bhab-caret') . '</button>'
                  . '<div class="bhab-drop" id="' . $dropId . '" hidden>'
                  . $this->dropHead($m)
                  . $this->panels($m['panels'])
                  . ($m['action'] === 'display' ? $this->displayItems($settings) : '')
                  . ($m['action'] === 'notifications' ? '<div class="bhab-notices" data-bhab-notices><p class="bhab-muted">Loading…</p></div>' : '')
                  . $this->items($m['children'], 1)
                  . '</div>';
        } else {
            $out .= '<a class="bhab-top" href="' . self::e($this->href($m['href'])) . '" title="' . self::e($title) . '"' . ($m['target'] ? ' target="_blank" rel="noopener"' : '') . '>' . $inner . '</a>';
        }
        return $out . '</li>';
    }

    /** The dropdown's heading: shown in the floating panel and on phones, where the label is hidden. */
    private function dropHead(array $m): string
    {
        $h = '<div class="bhab-drop__head"><span class="bhab-drop__title">' . self::e($m['label']) . '</span>';
        if ($m['href'] !== null && $m['children'] === []) {
            $h .= '<a class="bhab-drop__link" href="' . self::e($this->href($m['href'])) . '">Open</a>';
        }
        return $h . '</div>';
    }

    private function menuIcon(array $m, array $settings, array $user): string
    {
        if (!empty($m['attrs']['avatar']) && $settings['avatar']) {
            $thumb = '';
            try {
                $av = \App\Core\Application::getInstance()->make(\App\Services\AvatarService::class)->forUser($user);
                $thumb = (string) ($av['thumbnail_url'] ?? '');
            } catch (\Throwable) {}
            $name = trim((string) ($user['display_name'] ?? '')) ?: (string) ($user['username'] ?? 'U');
            if ($thumb !== '') return '<img class="bhab-avatar" src="' . self::e($thumb) . '" alt="" width="24" height="24">';
            return '<span class="bhab-avatar bhab-avatar--initial" aria-hidden="true">' . self::e(mb_strtoupper(mb_substr($name, 0, 1)) ?: 'U') . '</span>';
        }
        if (!empty($m['attrs']['brand'])) {
            // The Basehim mark, shown white on the dark bar (admin-bar.css).
            return '<img class="bhab-logo" src="' . self::e($this->base . '/admin/assets/img/logo-64.png') . '" alt="" width="22" height="22" decoding="async">';
        }
        return Icon::svg($m['icon'] ?: 'squares-2x2', 'bhab-ico');
    }

    private function panels(array $panels): string
    {
        $out = '';
        foreach ($panels as $p) {
            $out .= '<section class="bhab-panel" data-bhab-panel="' . self::e($p['id']) . '" aria-busy="true">'
                  . '<div class="bhab-panel__head">'
                  . ($p['title'] !== '' ? '<h3 class="bhab-panel__title">' . self::e($p['title']) . '</h3>' : '<span></span>')
                  . ($p['refresh'] ? '<button type="button" class="bhab-icon-btn" data-bhab-refresh aria-label="Refresh ' . self::e($p['title'] ?: 'panel') . '">' . Icon::svg('arrow-path', 'bhab-ico') . '</button>' : '')
                  . '</div><div class="bhab-panel__body"><p class="bhab-muted">Loading…</p></div></section>';
        }
        return $out;
    }

    private function items(array $items, int $depth): string
    {
        if ($items === []) return '';
        $out = '';
        $group = null;
        $open = false;
        foreach ($items as $it) {
            if ($it['group'] !== $group) {
                if ($open) $out .= '</ul>';
                $group = $it['group'];
                $out .= ($group !== '' ? '<div class="bhab-group">' . self::e($group) . '</div>' : '') . '<ul class="bhab-list">';
                $open = true;
            }
            $out .= $this->item($it, $depth);
        }
        if ($open) $out .= '</ul>';
        return $out;
    }

    private function item(array $it, int $depth): string
    {
        $icon = $it['icon'] !== '' ? Icon::svg($it['icon'], 'bhab-ico') : '<span class="bhab-ico bhab-ico--blank"></span>';
        $text = '<span class="bhab-item__text"><span class="bhab-item__label">' . self::e($it['label']) . '</span>'
              . ($it['meta'] !== '' ? '<span class="bhab-item__meta">' . self::e($it['meta']) . '</span>' : '') . '</span>';
        $badges = $this->badges($it['indicators'], false);
        $title = $it['title'] !== '' ? ' title="' . self::e($it['title']) . '"' : '';
        $out = '<li class="bhab-li" data-bhab-id="' . self::e($it['id']) . '">';

        if ($it['children'] !== [] || $it['panels'] !== []) {
            $sub = 'bhab-sub-' . self::slug($it['id']);
            $out .= '<button type="button" class="bhab-item" aria-expanded="false" aria-controls="' . $sub . '"' . $title . '>' . $icon . $text . $badges
                  . Icon::svg('chevron-right', 'bhab-ico bhab-item__chev') . '</button>'
                  . '<div class="bhab-sub" id="' . $sub . '" hidden>' . $this->panels($it['panels']) . $this->items($it['children'], $depth + 1) . '</div>';
        } elseif ($it['action'] !== '' && $it['action'] !== 'logout') {
            $data = '';
            foreach ($it['attrs'] as $k => $v) {
                if (preg_match('/^[a-z][a-z0-9-]{0,30}$/', (string) $k) && is_scalar($v)) $data .= ' data-' . $k . '="' . self::e((string) $v) . '"';
            }
            $out .= '<button type="button" class="bhab-item" data-bhab-action="' . self::e($it['action']) . '"' . $data . $title . '>' . $icon . $text . $badges . '</button>';
        } else {
            $extra = $it['action'] === 'logout' ? ' data-bhab-action="logout"' : '';
            $target = $it['target'] ? ' target="_blank" rel="noopener"' : '';
            $out .= '<a class="bhab-item" href="' . self::e($this->href($it['href'])) . '"' . $extra . $target . $title . '>' . $icon . $text . $badges
                  . ($it['target'] ? Icon::svg('arrow-top-right-on-square', 'bhab-ico bhab-item__ext') : '') . '</a>';
        }
        return $out . '</li>';
    }

    /** The Display menu: how the bar is shown, as far as the site lets this user choose. */
    private function displayItems(array $s): string
    {
        $can = array_flip($s['user_options']);
        $rows = [];
        if (isset($can['mode'])) {
            foreach (['horizontal' => ['Top bar', 'bars-3'], 'floating' => ['Floating button', 'squares-2x2']] as $mode => [$label, $icon]) {
                $rows[] = '<li class="bhab-li"><button type="button" class="bhab-item" role="menuitemradio" aria-checked="' . ($s['mode'] === $mode ? 'true' : 'false') . '" data-bhab-action="mode" data-mode="' . $mode . '">'
                    . Icon::svg($icon, 'bhab-ico') . '<span class="bhab-item__text"><span class="bhab-item__label">' . $label . '</span></span>'
                    . Icon::svg('check-circle', 'bhab-ico bhab-item__check') . '</button></li>';
            }
        }
        if ($s['mode'] === 'horizontal') {
            $rows[] = '<li class="bhab-li"><button type="button" class="bhab-item" data-bhab-action="collapse">' . Icon::svg('chevron-up', 'bhab-ico')
                . '<span class="bhab-item__text"><span class="bhab-item__label">Tuck the bar away</span><span class="bhab-item__meta">A small tab stays at the top</span></span></button></li>';
        }
        if (isset($can['position']) && $s['mode'] === 'floating') {
            $rows[] = '</ul><div class="bhab-group">Button position</div><ul class="bhab-list bhab-list--grid">';
            foreach (AdminBarSettings::POSITIONS as $pos => $label) {
                $rows[] = '<li class="bhab-li"><button type="button" class="bhab-item bhab-item--compact" role="menuitemradio" aria-checked="' . ($s['position'] === $pos ? 'true' : 'false') . '" data-bhab-action="position" data-position="' . $pos . '">'
                    . '<span class="bhab-item__text"><span class="bhab-item__label">' . $label . '</span></span></button></li>';
            }
        }
        if (isset($can['sections'])) {
            $rows[] = '</ul><div class="bhab-group">Show</div><ul class="bhab-list" data-bhab-sections></ul><ul class="bhab-list">';
        }
        if (isset($can['mode'])) {
            $rows[] = '<li class="bhab-li"><button type="button" class="bhab-item" data-bhab-action="mode" data-mode="off">' . Icon::svg('eye-slash', 'bhab-ico')
                . '<span class="bhab-item__text"><span class="bhab-item__label">Turn off on the site</span><span class="bhab-item__meta">Turn it back on in your profile</span></span></button></li>';
        }
        $rows[] = '<li class="bhab-li"><button type="button" class="bhab-item" data-bhab-action="hide">' . Icon::svg('x-mark', 'bhab-ico')
            . '<span class="bhab-item__text"><span class="bhab-item__label">Hide until the next page</span></span></button></li>';
        return '<ul class="bhab-list">' . implode('', $rows) . '</ul>';
    }

    private function badges(array $indicators, bool $top): string
    {
        $out = '';
        foreach ($indicators as $b) {
            $attrs = ' data-bhab-ind="' . self::e($b['id']) . '" data-type="' . self::e($b['type']) . '"';
            if (!empty($b['lazy'])) {
                $out .= '<span class="bhab-badge"' . $attrs . ' hidden></span>';
                continue;
            }
            $label = $b['label'] !== '' ? ' ' . $b['label'] : '';
            $out .= '<span class="bhab-badge bhab-badge--' . self::e($b['tone'] ?: 'neutral') . '"' . $attrs . '>'
                  . '<span aria-hidden="true">' . self::e((string) $b['value']) . '</span><span class="bhab-sr">' . self::e((string) $b['value'] . $label) . '</span></span>';
        }
        return $out;
    }

    private function lazyIds(array $n): array
    {
        $ids = [];
        foreach ($n['indicators'] as $b) if (!empty($b['lazy'])) $ids[] = $b['id'];
        foreach ($n['children'] as $c) foreach ($this->lazyIds($c) as $id) $ids[] = $id;
        return $ids;
    }

    private function has(array $tree, string $id): bool
    {
        foreach ($tree as $m) if ($m['id'] === $id) return true;
        return false;
    }

    /** Optional top-level sections a user may hide: id => label. */
    private function optionalSections(array $tree): array
    {
        $out = [];
        foreach ($tree as $m) if ($m['optional']) $out[$m['id']] = $m['label'];
        return $out;
    }

    private function href(?string $href): string
    {
        if ($href === null || $href === '') return '#';
        if ($href[0] === '/' && $this->base !== '' && !str_starts_with($href, $this->base . '/')) return $this->base . $href;
        return $href;
    }

    private static function slug(string $id): string
    {
        return (string) preg_replace('/[^a-z0-9_-]/', '-', $id);
    }

    private static function e(mixed $v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
