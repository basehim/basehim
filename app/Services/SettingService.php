<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Cache;

class SettingService
{
    private const CACHE_KEY = 'settings.all';
    private const GEN_KEY = 'settings.generation';

    public function __construct(
        private Database $db,
        private Cache $cache
    ) {}

    /**
     * Every setting, from the cache when it is current.
     *
     * The cached snapshot carries the generation it was built under, and is
     * used only while that is still the current generation (1.2.75, CR-09).
     * Before, a request that read the table just before another request
     * saved a setting could write its older snapshot back into the cache
     * after the save had cleared it, and the old value was served for up to
     * an hour.
     */
    public function all(): array
    {
        $gen = $this->generation();
        $cached = $this->cache->get(self::CACHE_KEY);
        if (is_array($cached) && isset($cached['__gen'], $cached['data']) && $cached['__gen'] === $gen && is_array($cached['data'])) {
            return $cached['data'];
        }

        $rows = $this->db->select('SELECT * FROM {settings}');
        $out = [];
        foreach ($rows as $r) {
            $value = $r['is_json'] ? json_decode($r['setting_value'], true) : $r['setting_value'];
            $out[$r['setting_group']][$r['setting_key']] = $value;
        }
        $this->cache->set(self::CACHE_KEY, ['__gen' => $gen, 'data' => $out], 3600);
        return $out;
    }

    /** The current settings generation: a random token, new after every write. */
    private function generation(): string
    {
        $g = $this->cache->get(self::GEN_KEY);
        if (is_string($g) && $g !== '') return $g;
        $g = bin2hex(random_bytes(8));
        $this->cache->set(self::GEN_KEY, $g, 0);
        return $g;
    }

    /** A write happened: no snapshot built before it may be used again. */
    private function invalidate(): void
    {
        $this->cache->set(self::GEN_KEY, bin2hex(random_bytes(8)), 0);
        $this->cache->delete(self::CACHE_KEY);
    }

    /**
     * The same values with credentials masked: any key that names a password,
     * secret, key, token or DSN reads "[redacted]". For handing settings to an
     * API or MCP client, whatever scope it holds. Works on one group
     * (key => value) or on all() (group => key => value).
     */
    public static function redact(array $values): array
    {
        $out = [];
        foreach ($values as $k => $v) {
            if (is_array($v)) {
                $out[$k] = self::redact($v);
            } elseif (self::isCredential((string) $k)) {
                $out[$k] = ($v === null || $v === '') ? $v : self::REDACTED;
            } else {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    /** What redact() puts in place of a credential. */
    public const REDACTED = '[redacted]';

    /**
     * One group's values without the credentials a client sent back as
     * "[redacted]": a client that reads the settings, changes one and writes
     * the group back must not overwrite the stored password with the mask.
     */
    public static function withoutRedacted(array $values): array
    {
        return array_filter(
            $values,
            static fn($v, $k) => !($v === self::REDACTED && self::isCredential((string) $k)),
            ARRAY_FILTER_USE_BOTH
        );
    }

    private static function isCredential(string $key): bool
    {
        return (bool) preg_match('/(pass|secret|key|token|dsn|smtp_pw)/i', $key);
    }

    public function get(string $group, string $key, $default = null)
    {
        $all = $this->all();
        return $all[$group][$key] ?? $default;
    }

    /**
     * Write one setting.
     *
     * One INSERT … ON DUPLICATE KEY UPDATE (1.2.75, CR-09): the old read-then-
     * insert failed with a duplicate-key error when two requests saved the
     * same new setting at once.
     */
    public function set(string $group, string $key, $value, bool $autoload = true): void
    {
        $isJson = is_array($value) || is_object($value);
        $stored = $isJson ? json_encode($value) : (string)$value;

        $this->db->execute(
            'INSERT INTO {settings} (setting_group, setting_key, setting_value, is_json, autoload)
             VALUES (:g, :k, :v, :j, :a)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), is_json = VALUES(is_json), autoload = VALUES(autoload)',
            ['g' => $group, 'k' => $key, 'v' => $stored, 'j' => $isJson ? 1 : 0, 'a' => $autoload ? 1 : 0]
        );

        $this->invalidate();
    }

    /**
     * Change one setting from its current value, safely when requests overlap
     * (1.2.75, CR-09):
     *
     *     $settings->update('app:shop', 'orders_today', fn($n) => (int) $n + 1, 0);
     *
     * The row is locked (SELECT … FOR UPDATE) while $fn runs, so two requests
     * appending to the same list both keep their item; get() then set() lost
     * one of them. $fn may run again if the database reports a deadlock, so it
     * must only compute the new value. Returns the value stored.
     */
    public function update(string $group, string $key, callable $fn, mixed $default = null, bool $autoload = true): mixed
    {
        $encode = static fn($v): array => [is_array($v) || is_object($v) ? json_encode($v) : (string) $v, is_array($v) || is_object($v) ? 1 : 0];
        [$dv, $dj] = $encode($default);
        // The row exists before it is locked, so a first update has a row to lock.
        $this->db->execute(
            'INSERT IGNORE INTO {settings} (setting_group, setting_key, setting_value, is_json, autoload) VALUES (:g, :k, :v, :j, :a)',
            ['g' => $group, 'k' => $key, 'v' => $dv, 'j' => $dj, 'a' => $autoload ? 1 : 0]
        );
        $new = $this->db->transaction(function () use ($group, $key, $fn, $encode) {
            $row = $this->db->selectOne(
                'SELECT setting_value, is_json FROM {settings} WHERE setting_group = :g AND setting_key = :k FOR UPDATE',
                ['g' => $group, 'k' => $key]
            );
            $current = $row === null ? null : ((int) $row['is_json'] ? json_decode((string) $row['setting_value'], true) : $row['setting_value']);
            $value = $fn($current);
            [$v, $j] = $encode($value);
            $this->db->execute(
                'UPDATE {settings} SET setting_value = :v, is_json = :j WHERE setting_group = :g AND setting_key = :k',
                ['v' => $v, 'j' => $j, 'g' => $group, 'k' => $key]
            );
            return $value;
        });
        $this->invalidate();
        return $new;
    }

    /**
     * Delete one setting.
     *
     * The service had no delete method at all, so every caller that needed one
     * ran its own DELETE — and none of them invalidated the `settings.all`
     * cache, which has a 3600s TTL. The row went, the value kept being served
     * for up to an hour. Deletion belongs here, next to set(), precisely so the
     * invalidation cannot be forgotten.
     *
     * @return bool True when a row was actually removed.
     */
    public function delete(string $group, string $key): bool
    {
        $affected = $this->db->execute(
            'DELETE FROM {settings} WHERE setting_group = :g AND setting_key = :k',
            ['g' => $group, 'k' => $key]
        );
        $this->invalidate();
        return (bool) $affected;
    }

    /** Delete every setting in one or more groups. Returns rows removed. */
    public function deleteGroups(array $groups): int
    {
        $groups = array_values(array_filter($groups, 'is_string'));
        if (!$groups) return 0;

        $placeholders = [];
        $params = [];
        foreach ($groups as $i => $group) {
            $placeholders[] = ':g' . $i;
            $params['g' . $i] = $group;
        }

        $affected = $this->db->execute(
            'DELETE FROM {settings} WHERE setting_group IN (' . implode(', ', $placeholders) . ')',
            $params
        );
        $this->invalidate();
        return (int) $affected;
    }

    /** Drop the cached snapshot — for callers that wrote settings by other means. */
    public function flushCache(): void
    {
        $this->invalidate();
    }

    public function setMany(string $group, array $values, bool $autoload = true): void
    {
        foreach ($values as $key => $value) {
            $this->set($group, (string)$key, $value, $autoload);
        }
    }

    public function getGroup(string $group): array
    {
        $all = $this->all();
        $saved = $all[$group] ?? [];

        // Merge config-file defaults so the admin form always has a starting
        // value even before the operator saves anything (otherwise the fields
        // show blank on a fresh install because the DB rows don't exist yet).
        $configDefaults = $this->configDefaults($group);

        return array_merge($configDefaults, $saved);
    }

    /**
     * Pull the flat defaults for a group from the cms config file.
     * Only 'general' has defaults right now, but the method is future-proof.
     */
    private function configDefaults(string $group): array
    {
        if ($group !== 'general') return [];

        try {
            $cms = require dirname(__DIR__, 2) . '/config/cms.php';
        } catch (\Throwable) {
            return [];
        }

        return [
            'site_title'   => $cms['site_title']  ?? '',
            'tagline'      => $cms['tagline']      ?? '',
            'admin_email'  => $cms['admin_email']  ?? '',
        ];
    }

    /**
     * Settings exposed publicly via /api/v1/settings/public
     */
    public function publicSettings(): array
    {
        $all = $this->all();
        // Whole groups used to be returned, which published the administrator's
        // email address (general.admin_email) — the usual sign-in name — to
        // anyone. General settings are now limited to what a site shows anyway.
        $general = array_intersect_key($all['general'] ?? [], array_flip([
            'site_title', 'tagline', 'language', 'timezone', 'date_format', 'time_format', 'week_starts_on', 'logo_url', 'favicon_url',
        ]));
        return [
            'general' => $general,
            'appearance' => $all['appearance'] ?? [],
            'reading' => $all['reading'] ?? [],
        ];
    }
}
