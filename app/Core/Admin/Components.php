<?php

declare(strict_types=1);

namespace App\Core\Admin;

/**
 * Small reusable admin cell components.
 *
 * Shared presentation for the things that recur across admin screens — a user
 * with an avatar, a media thumbnail, a status pill — so a data table column, a
 * detail panel, or any other admin view renders them the same way. Each method
 * returns a safe HTML fragment (values escaped; an avatar/thumbnail falls back
 * to initials or a placeholder when there is no image).
 *
 * Also exposed as the globals bh_avatar_html(), bh_user_cell() and bh_thumb()
 * for views (see bootstrap.php).
 */
final class Components
{
    /** Deterministic avatar background colours for the initials fallback. */
    private const COLORS = ['#2563eb', '#7c3aed', '#db2777', '#059669', '#d97706', '#0891b2', '#dc2626', '#4f46e5'];

    /** An avatar: the image when there is one, otherwise initials on a colour. */
    public static function avatar(string $name, ?string $url = null, int $size = 28): string
    {
        $px = max(16, min(96, $size));
        $style = 'width:' . $px . 'px;height:' . $px . 'px';
        if ($url !== null && $url !== '') {
            return '<img class="bh-ava" src="' . htmlspecialchars($url, ENT_QUOTES) . '"'
                . ' alt="' . htmlspecialchars($name, ENT_QUOTES) . '" width="' . $px . '" height="' . $px . '"'
                . ' loading="lazy" decoding="async" style="' . $style . '">';
        }
        $color = self::COLORS[abs(crc32($name)) % count(self::COLORS)];
        $fs = max(10, (int) round($px * 0.42));
        return '<span class="bh-ava bh-ava--initials" aria-hidden="true"'
            . ' style="' . $style . ';background:' . $color . ';font-size:' . $fs . 'px">'
            . htmlspecialchars(self::initials($name), ENT_QUOTES) . '</span>';
    }

    /** An avatar next to a name, with an optional sub-line (e.g. a username). */
    public static function userCell(string $name, ?string $url = null, ?string $sub = null, int $size = 28): string
    {
        $out = '<span class="bh-usercell">' . self::avatar($name, $url, $size)
            . '<span class="bh-usercell__meta"><span class="bh-usercell__name">'
            . htmlspecialchars($name, ENT_QUOTES) . '</span>';
        if ($sub !== null && $sub !== '') {
            $out .= '<span class="bh-usercell__sub">' . htmlspecialchars($sub, ENT_QUOTES) . '</span>';
        }
        return $out . '</span></span>';
    }

    /** A small square media thumbnail, or a placeholder when there is none. */
    public static function thumb(?string $url, string $alt = '', int $size = 38, string $icon = 'photo'): string
    {
        $px = max(20, min(96, $size));
        $style = 'width:' . $px . 'px;height:' . $px . 'px';
        if ($url !== null && $url !== '') {
            return '<span class="bh-thumb" style="' . $style . '"><img src="' . htmlspecialchars($url, ENT_QUOTES) . '"'
                . ' alt="' . htmlspecialchars($alt, ENT_QUOTES) . '" loading="lazy" decoding="async"></span>';
        }
        return '<span class="bh-thumb bh-thumb--empty" style="' . $style . '">'
            . (function_exists('icon') ? icon($icon, 'w-4 h-4') : '') . '</span>';
    }

    /** A coloured status pill. $tones maps a status to Tailwind colour classes. */
    public static function statusPill(string $status, array $tones = []): string
    {
        $cls = $tones[$status] ?? 'bg-slate-100 text-slate-600';
        return '<span class="bh-dt-badge ' . $cls . '">' . htmlspecialchars(ucfirst($status), ENT_QUOTES) . '</span>';
    }

    private static function initials(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '?';
        }
        $parts = preg_split('/\s+/', $name) ?: [$name];
        $first = mb_substr($parts[0], 0, 1);
        $last = count($parts) > 1 ? mb_substr($parts[count($parts) - 1], 0, 1) : '';
        return mb_strtoupper($first . $last);
    }
}
