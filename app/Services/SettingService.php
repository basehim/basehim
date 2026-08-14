<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Cache;

class SettingService
{
    private const CACHE_KEY = 'settings.all';

    public function __construct(
        private Database $db,
        private Cache $cache
    ) {}

    public function all(): array
    {
        $cached = $this->cache->get(self::CACHE_KEY);
        if (is_array($cached)) return $cached;

        $rows = $this->db->select('SELECT * FROM {settings}');
        $out = [];
        foreach ($rows as $r) {
            $value = $r['is_json'] ? json_decode($r['setting_value'], true) : $r['setting_value'];
            $out[$r['setting_group']][$r['setting_key']] = $value;
        }
        $this->cache->set(self::CACHE_KEY, $out, 3600);
        return $out;
    }

    public function get(string $group, string $key, $default = null)
    {
        $all = $this->all();
        return $all[$group][$key] ?? $default;
    }

    public function set(string $group, string $key, $value, bool $autoload = true): void
    {
        $isJson = is_array($value) || is_object($value);
        $stored = $isJson ? json_encode($value) : (string)$value;

        $existing = $this->db->selectOne(
            'SELECT id FROM {settings} WHERE setting_group = :g AND setting_key = :k',
            ['g' => $group, 'k' => $key]
        );

        if ($existing) {
            $this->db->update('settings', [
                'setting_value' => $stored,
                'is_json' => $isJson ? 1 : 0,
                'autoload' => $autoload ? 1 : 0,
            ], ['id' => $existing['id']]);
        } else {
            $this->db->insert('settings', [
                'setting_group' => $group,
                'setting_key' => $key,
                'setting_value' => $stored,
                'is_json' => $isJson ? 1 : 0,
                'autoload' => $autoload ? 1 : 0,
            ]);
        }

        $this->cache->delete(self::CACHE_KEY);
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
        $this->cache->delete(self::CACHE_KEY);
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
        $this->cache->delete(self::CACHE_KEY);
        return (int) $affected;
    }

    /** Drop the cached snapshot — for callers that wrote settings by other means. */
    public function flushCache(): void
    {
        $this->cache->delete(self::CACHE_KEY);
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
    /**
     * Keys whose values are credentials and must never leave the server.
     *
     * Matched by name so a setting an app invents (`stripe_secret`,
     * `webhook_token`) is covered without anyone remembering to add it.
     */
    private const SECRET_KEY_PATTERN = '/(pass|passwd|password|secret|token|api_?key|private_?key|dsn|credential|smtp_pw)/i';

    /**
     * Every setting, with credential values replaced by a placeholder.
     *
     * The MCP tool already did this; the REST endpoint returned all()
     * unfiltered, so GET /api/v1/settings handed back the SMTP password in
     * clear. Read paths should default to this and call all() only when the
     * raw value is actually needed (i.e. when about to use it).
     */
    public function allRedacted(): array
    {
        $out = [];
        foreach ($this->all() as $group => $values) {
            if (!is_array($values)) { $out[$group] = $values; continue; }
            foreach ($values as $key => $value) {
                $out[$group][$key] = self::isSecretKey((string) $key) && $value !== '' && $value !== null
                    ? '[redacted]'
                    : $value;
            }
        }
        return $out;
    }

    public static function isSecretKey(string $key): bool
    {
        return (bool) preg_match(self::SECRET_KEY_PATTERN, $key);
    }

    public function publicSettings(): array
    {
        $all = $this->all();
        return [
            'general' => $all['general'] ?? [],
            'appearance' => $all['appearance'] ?? [],
            'reading' => $all['reading'] ?? [],
        ];
    }
}
