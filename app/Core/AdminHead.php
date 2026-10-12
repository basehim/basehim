<?php
declare(strict_types=1);

namespace App\Core;

/**
 * The admin's browser-tab title and icon (1.2.50).
 *
 *   title()   "{Page} - {Site name} - Basehim"; the dashboard is "Admin
 *             Dashboard". With several sites open, each tab says which site
 *             it is. The site name is left out while it is still "Basehim".
 *   favicon() the site's own icon (Customizer → Site identity → Site icon)
 *             when one is set, otherwise '' and the Basehim mark is used.
 *
 * Used by admin/views/partials/admin-head.php, and by screens that rename the
 * site without a page load (settings.save) to retitle the open tab.
 */
final class AdminHead
{
    public static function siteName(): string
    {
        try {
            $s = Application::getInstance()->make(\App\Services\SettingService::class);
            return trim(html_entity_decode((string) $s->get('general', 'site_title', ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        } catch (\Throwable) {
            return '';
        }
    }

    public static function title(?string $page): string
    {
        $page = trim(html_entity_decode((string) ($page ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($page === '') $page = 'Admin';
        elseif (strcasecmp($page, 'Dashboard') === 0) $page = 'Admin Dashboard';
        $parts = [$page];
        $site = self::siteName();
        if ($site !== '' && strcasecmp($site, 'Basehim') !== 0 && strcasecmp($site, $page) !== 0) $parts[] = $site;
        $parts[] = 'Basehim';
        return implode(' - ', $parts);
    }

    /** The site icon's URL, or '' when the site has none. */
    public static function favicon(string $base = ''): string
    {
        try {
            $s = Application::getInstance()->make(\App\Services\SettingService::class);
            $icon = trim((string) $s->get('appearance', 'favicon_url', ''));
        } catch (\Throwable) {
            return '';
        }
        if ($icon === '') return '';
        // A stored icon is a site path (/uploads/…) or a full URL; only those shapes are used.
        if (preg_match('#^(https?:)?//#i', $icon)) return $icon;
        if ($icon[0] !== '/') return '';
        if ($base !== '' && !str_starts_with($icon, rtrim($base, '/') . '/')) $icon = rtrim($base, '/') . $icon;
        return $icon;
    }
}
