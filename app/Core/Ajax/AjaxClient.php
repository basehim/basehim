<?php

declare(strict_types=1);

namespace App\Core\Ajax;

use App\Core\Application;
use App\Core\HookRegistry;
use App\Core\Request;

/**
 * Puts the AJAX client (Basehim.ajax) on public pages that ask for it.
 *
 * Used by bh_ajax_client() (themes) and App::useAjaxClient() (apps). It adds
 * its tags through the bh.head filter, which runs when the page renders, so
 * it works from an app's boot() even though boot() runs before bootstrap.php
 * defines the bh_enqueue_*() helpers.
 *
 * Only the endpoint's URL is printed — never a CSRF token. A token in the
 * page would start a session for every visitor and would be wrong in any
 * cached copy of the page. The client fetches the token (core.csrf) just
 * before its first POST, so only visitors who use an AJAX feature get a
 * session. The admin layouts load the client themselves, with the token.
 */
final class AjaxClient
{
    private static bool $enabled = false;

    public static function enable(): void
    {
        if (self::$enabled) return;
        try {
            Application::getInstance()->make(HookRegistry::class)
                ->addFilter('bh.head', static fn($carry) => (string) $carry . self::headMarkup(), 1, 1);
            self::$enabled = true;
        } catch (\Throwable) {}
    }

    public static function headMarkup(): string
    {
        $base = defined('BASEHIM_BASE') ? (string) BASEHIM_BASE : '';
        $ver = urlencode(defined('BASEHIM_VERSION') ? (string) BASEHIM_VERSION : '1');
        $cfg = json_encode(['url' => $base . Request::AJAX_PATH],
            JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $src = htmlspecialchars($base . '/admin/assets/js/ajax.js?v=' . $ver, ENT_QUOTES, 'UTF-8');
        return '<script>window.BASEHIM_AJAX = ' . $cfg . ';</script>'
             . '<script src="' . $src . '"></script>';
    }
}
