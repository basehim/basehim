<?php

declare(strict_types=1);

namespace App\Core\Http;

use App\Core\Icon;
use App\Services\AppearanceService;

/**
 * One page for every answer drawn outside the admin layout (1.3.0): server
 * errors, Access denied, a route's plain error, the "One moment" page while
 * a broken app is switched off, an OAuth error.
 *
 * Until 1.3.0 each wrote its own HTML, five of them with inline CSS and fixed
 * colours, so they ignored the person's light/dark mode and accent (the 500
 * page CloudHim showed after the 1.3.0 update was light and blue whatever the
 * person had chosen). This one uses core's stylesheet (versioned, so an update
 * reaches browsers), its components and the person's appearance, and never
 * throws: it is what answers when something else did.
 */
final class StatusPage
{
    /**
     * @param array{
     *     icon?: string,
     *     tone?: 'danger'|'warning'|'info'|'accent',
     *     actions?: list<array{label: string, href: string, primary?: bool, icon?: string}>,
     *     details?: string,
     *     wide?: bool,
     *     head?: string,
     * } $o details is trusted HTML from core (the debug trace); head is extra
     *     trusted <head> markup.
     */
    public static function html(int $status, string $title, string $message, array $o = []): string
    {
        $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $base = defined('BASEHIM_BASE') ? rtrim((string) BASEHIM_BASE, '/') : '';
        $ver = rawurlencode(defined('BASEHIM_VERSION') ? (string) BASEHIM_VERSION : '1');

        $attrs = AppearanceService::htmlAttributes(AppearanceService::forRequest());
        // Literal class names (Tailwind finds them by scanning this file).
        $tile = match ($o['tone'] ?? 'danger') {
            'warning' => 'bg-warning-subtle text-warning',
            'info'    => 'bg-info-subtle text-info',
            'accent'  => 'bg-accent-50 text-accent-600',
            default   => 'bg-danger-subtle text-danger',
        };
        $icon = self::icon((string) ($o['icon'] ?? 'exclamation-triangle'), 'h-6 w-6');

        $actions = '';
        foreach ($o['actions'] ?? [] as $a) {
            $cls = !empty($a['primary']) ? 'bh-btn bh-btn--primary' : 'bh-btn bh-btn--secondary';
            $actions .= '<a href="' . $e((string) $a['href']) . '" class="' . $cls . '">'
                . (isset($a['icon']) ? self::icon((string) $a['icon'], 'h-4 w-4') : '') . $e((string) $a['label']) . '</a>';
        }
        $width = !empty($o['wide']) ? 'max-w-4xl text-left' : 'max-w-md text-center';
        $align = !empty($o['wide']) ? '' : ' mx-auto';

        return '<!DOCTYPE html><html lang="en" ' . $attrs . '><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">'
            . '<title>' . $e($status . ' · ' . $title) . '</title>'
            . '<link rel="icon" type="image/png" sizes="32x32" href="' . $e($base) . '/admin/assets/img/favicon-32.png">'
            . AppearanceService::systemScript()
            . '<link rel="stylesheet" href="' . $e($base) . '/admin/assets/css/tailwind.min.css?v=' . $ver . '">'
            . ($o['head'] ?? '')
            . '</head><body class="grid min-h-screen place-items-center bg-slate-50 p-4 font-sans text-slate-800 antialiased">'
            . '<main class="bh-card w-full ' . $width . ' p-6 sm:p-8">'
            . '<div class="mb-4 grid h-12 w-12 place-items-center rounded-xl ' . $tile . $align . '">' . $icon . '</div>'
            . '<p class="text-sm font-semibold text-slate-500">' . $status . '</p>'
            . '<h1 class="mt-1 text-xl font-semibold text-slate-900 [overflow-wrap:anywhere]">' . $e($title) . '</h1>'
            . ($message !== '' ? '<p class="mt-2 text-sm leading-relaxed text-slate-500">' . $e($message) . '</p>' : '')
            . ($o['details'] ?? '')
            . ($actions !== '' ? '<div class="mt-6 flex flex-wrap gap-3' . (!empty($o['wide']) ? '' : ' justify-center') . '">' . $actions . '</div>' : '')
            . '</main></body></html>';
    }

    private static function icon(string $name, string $class): string
    {
        try { return Icon::svg($name, $class); } catch (\Throwable) { return ''; }
    }
}
