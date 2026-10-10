<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Dates and times in the site's timezone.
 *
 * Basehim stores every timestamp in UTC: PHP runs in UTC and every database
 * connection uses time_zone '+00:00' (since 1.2.45). Whatever a page shows is
 * converted on the way out to the timezone chosen under Settings → General.
 * Changing that setting changes every date the site shows straight away,
 * with nothing rewritten in the database.
 *
 * Use these helpers (or the bh_* functions in bootstrap.php) wherever a
 * stored time is shown to a person. date('M j', strtotime($row['created_at']))
 * shows UTC.
 *
 *     Time::format($post['published_at'])          // "Oct 10, 2026 1:32 pm"  (site formats)
 *     Time::date($post['published_at'])            // "October 10, 2026"
 *     Time::date($post['published_at'], 'M j')     // "Oct 10"
 *     Time::ago($comment['created_at'])            // "5 minutes ago"
 *     Time::iso($post['published_at'])             // "2026-10-10T13:32:00+05:00"
 *     Time::toUtc('2026-10-10 13:32')              // "2026-10-10 08:32:00"  (for storing user input)
 *
 * Accepted inputs everywhere: a stored "Y-m-d H:i:s" string (read as UTC), any
 * string with its own offset, a Unix timestamp, or a DateTimeInterface.
 */
final class Time
{
    public const STORAGE_FORMAT = 'Y-m-d H:i:s';
    public const DEFAULT_DATE_FORMAT = 'F j, Y';
    public const DEFAULT_TIME_FORMAT = 'g:i a';

    private static ?string $tzCache = null;
    private static ?array $fmtCache = null;

    // ── The site's settings ─────────────────────────────────────────────────

    /** The site timezone identifier, e.g. "Asia/Karachi". Always valid. */
    public static function timezone(): string
    {
        if (self::$tzCache !== null) return self::$tzCache;
        $tz = '';
        try {
            $tz = (string) Application::getInstance()->make(\App\Services\SettingService::class)->get('general', 'timezone', '');
        } catch (\Throwable) {}
        if (!self::isValidTimezone($tz)) {
            // Before 1.2.45 the only timezone that did anything was APP_TIMEZONE
            // in .env, so a site that set one keeps it until the setting is saved.
            $env = '';
            try { $env = (string) (Env::get('APP_TIMEZONE', '') ?? ''); } catch (\Throwable) {}
            $tz = self::isValidTimezone($env) ? $env : 'UTC';
        }
        return self::$tzCache = $tz;
    }

    public static function zone(): \DateTimeZone
    {
        return new \DateTimeZone(self::timezone());
    }

    /** Forget cached settings (after the timezone or formats are saved). */
    public static function reset(): void
    {
        self::$tzCache = null;
        self::$fmtCache = null;
    }

    /** The site's date format (PHP date() letters). */
    public static function dateFormat(): string
    {
        return self::formats()['date'];
    }

    /** The site's time format (PHP date() letters). */
    public static function timeFormat(): string
    {
        return self::formats()['time'];
    }

    /** 0 = Sunday … 6 = Saturday. */
    public static function weekStartsOn(): int
    {
        return self::formats()['week'];
    }

    private static function formats(): array
    {
        if (self::$fmtCache !== null) return self::$fmtCache;
        $d = self::DEFAULT_DATE_FORMAT; $t = self::DEFAULT_TIME_FORMAT; $w = 1;
        try {
            $s = Application::getInstance()->make(\App\Services\SettingService::class);
            $d = trim((string) $s->get('general', 'date_format', $d)) ?: $d;
            $t = trim((string) $s->get('general', 'time_format', $t)) ?: $t;
            $w = (int) $s->get('general', 'week_starts_on', 1);
        } catch (\Throwable) {}
        return self::$fmtCache = ['date' => $d, 'time' => $t, 'week' => max(0, min(6, $w))];
    }

    // ── Converting ──────────────────────────────────────────────────────────

    /**
     * A stored or given time as a DateTimeImmutable in the site timezone, or
     * null for empty / unreadable input.
     */
    public static function local(mixed $value): ?\DateTimeImmutable
    {
        $dt = self::parse($value);
        return $dt?->setTimezone(self::zone());
    }

    /** The same instant in UTC. */
    public static function utc(mixed $value): ?\DateTimeImmutable
    {
        return self::parse($value)?->setTimezone(new \DateTimeZone('UTC'));
    }

    /**
     * Read a value as an instant. A bare "Y-m-d H:i:s" is UTC (how Basehim
     * stores times); a string with an offset or zone keeps it.
     */
    public static function parse(mixed $value): ?\DateTimeImmutable
    {
        if ($value === null || $value === '' || $value === false) return null;
        try {
            if ($value instanceof \DateTimeInterface) return \DateTimeImmutable::createFromInterface($value);
            if (is_int($value) || (is_string($value) && preg_match('/^-?\d{9,11}$/', $value))) {
                return (new \DateTimeImmutable('@' . (int) $value))->setTimezone(new \DateTimeZone('UTC'));
            }
            $s = trim((string) $value);
            if ($s === '' || str_starts_with($s, '0000-00-00')) return null;
            return new \DateTimeImmutable($s, new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Turn a time a person entered in the site timezone ("2026-10-10 13:32",
     * from a datetime-local field, say) into the UTC string to store.
     */
    public static function toUtc(mixed $localValue, string $format = self::STORAGE_FORMAT): string
    {
        if ($localValue instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($localValue)->setTimezone(new \DateTimeZone('UTC'))->format($format);
        }
        $s = trim((string) $localValue);
        if ($s === '') return '';
        try {
            $dt = new \DateTimeImmutable(str_replace('T', ' ', $s), self::zone());
            return $dt->setTimezone(new \DateTimeZone('UTC'))->format($format);
        } catch (\Throwable) {
            return '';
        }
    }

    // ── Showing ─────────────────────────────────────────────────────────────

    /** Date and time in the site's formats (or $format), in the site timezone. */
    public static function format(mixed $value, ?string $format = null): string
    {
        $dt = self::local($value);
        if ($dt === null) return '';
        return $dt->format($format ?? (self::dateFormat() . ' ' . self::timeFormat()));
    }

    public static function date(mixed $value, ?string $format = null): string
    {
        return self::format($value, $format ?? self::dateFormat());
    }

    public static function time(mixed $value, ?string $format = null): string
    {
        return self::format($value, $format ?? self::timeFormat());
    }

    /** ISO 8601 with the site's offset, for <time datetime>, JSON-LD, feeds. */
    public static function iso(mixed $value): string
    {
        return self::local($value)?->format(\DateTimeInterface::ATOM) ?? '';
    }

    /** "just now", "5 minutes ago", "in 2 days"; older than $maxDays shows the date. */
    public static function ago(mixed $value, int $maxDays = 7): string
    {
        $dt = self::parse($value);
        if ($dt === null) return '';
        $diff = time() - $dt->getTimestamp();
        $future = $diff < 0;
        $abs = abs($diff);
        if ($abs < 45) return $future ? 'in a moment' : 'just now';
        if ($abs >= $maxDays * 86400) return self::date($dt);
        foreach ([[86400, 'day'], [3600, 'hour'], [60, 'minute']] as [$unit, $name]) {
            if ($abs >= $unit) {
                $n = (int) round($abs / $unit);
                $s = $n . ' ' . $name . ($n === 1 ? '' : 's');
                return $future ? 'in ' . $s : $s . ' ago';
            }
        }
        return 'just now';
    }

    /** <time datetime="ISO" title="full date">shown text</time>. */
    public static function tag(mixed $value, ?string $format = null, bool $relative = false): string
    {
        $dt = self::local($value);
        if ($dt === null) return '';
        $text = $relative ? self::ago($dt) : self::format($dt, $format ?? self::dateFormat());
        return '<time datetime="' . htmlspecialchars($dt->format(\DateTimeInterface::ATOM), ENT_QUOTES, 'UTF-8')
            . '" title="' . htmlspecialchars(self::format($dt), ENT_QUOTES, 'UTF-8') . '">'
            . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</time>';
    }

    // ── Now ─────────────────────────────────────────────────────────────────

    /** Now in UTC, ready to store. */
    public static function now(): string
    {
        return gmdate(self::STORAGE_FORMAT);
    }

    /** Now in the site timezone, formatted (default: site date + time format). */
    public static function nowLocal(?string $format = null): string
    {
        return self::format(time(), $format);
    }

    // ── About the zone ──────────────────────────────────────────────────────

    /** "+05:00" for the site timezone at $at (default now; DST-aware). */
    public static function offset(mixed $at = null): string
    {
        return (self::local($at ?? time()) ?? new \DateTimeImmutable('now', self::zone()))->format('P');
    }

    public static function offsetSeconds(mixed $at = null): int
    {
        return (self::local($at ?? time()) ?? new \DateTimeImmutable('now', self::zone()))->getOffset();
    }

    /** "PKT", "CEST" — or the offset where the zone has no abbreviation. */
    public static function abbreviation(mixed $at = null): string
    {
        return (self::local($at ?? time()) ?? new \DateTimeImmutable('now', self::zone()))->format('T');
    }

    public static function isValidTimezone(string $tz): bool
    {
        if ($tz === '') return false;
        if ($tz === 'UTC') return true;
        return in_array($tz, \DateTimeZone::listIdentifiers(), true);
    }

    /**
     * Every timezone, grouped by region, with its current offset, for a select:
     *   ['Asia' => ['Asia/Karachi' => '(UTC+05:00) Karachi', …], …, 'UTC' => ['UTC' => 'UTC']]
     */
    public static function grouped(): array
    {
        $out = [];
        $now = new \DateTimeImmutable('now');
        foreach (\DateTimeZone::listIdentifiers() as $id) {
            $parts = explode('/', $id, 2);
            if (count($parts) < 2) continue;
            [$region, $city] = $parts;
            $off = $now->setTimezone(new \DateTimeZone($id))->format('P');
            $out[$region][$id] = '(UTC' . $off . ') ' . str_replace(['_', '/'], [' ', ' / '], $city);
        }
        ksort($out);
        $out['UTC'] = ['UTC' => '(UTC+00:00) UTC'];
        return $out;
    }

    /** Flat list for the API: [{id, region, city, offset, offset_seconds}]. */
    public static function all(): array
    {
        $now = new \DateTimeImmutable('now');
        $list = [];
        foreach (\DateTimeZone::listIdentifiers() as $id) {
            $z = $now->setTimezone(new \DateTimeZone($id));
            $parts = explode('/', $id, 2);
            $list[] = [
                'id' => $id,
                'region' => count($parts) === 2 ? $parts[0] : 'UTC',
                'city' => str_replace('_', ' ', $parts[1] ?? $parts[0]),
                'offset' => $z->format('P'),
                'offset_seconds' => $z->getOffset(),
            ];
        }
        return $list;
    }

    /** What /api/v1/time answers and the admin hands to its scripts. */
    public static function info(): array
    {
        $now = time();
        return [
            'timezone' => self::timezone(),
            'offset' => self::offset($now),
            'offset_seconds' => self::offsetSeconds($now),
            'abbreviation' => self::abbreviation($now),
            'date_format' => self::dateFormat(),
            'time_format' => self::timeFormat(),
            'week_starts_on' => self::weekStartsOn(),
            'now_utc' => gmdate('Y-m-d\TH:i:s\Z', $now),
            'now_local' => self::iso($now),
            'now_formatted' => self::format($now),
            'unix' => $now,
        ];
    }
}
