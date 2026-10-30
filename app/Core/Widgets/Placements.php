<?php

declare(strict_types=1);

namespace App\Core\Widgets;

use App\Services\SettingService;

/**
 * Where widget instances are placed (1.2.93).
 *
 * Stored where they always were: one settings row (group 'widgets', key
 * 'areas'), a map of area key => ordered list of instances:
 *
 *   {"sidebar": [{"id": "w_87c19799", "widget": "core.search", "settings": {...},
 *                 "v": "1.1.0", "class": "", "hide_title": false, "disabled": false}]}
 *
 * Records written before 1.2.93 have only id, widget and settings; the other
 * keys are optional and read with their defaults, so no migration is needed.
 * Areas of a theme that is no longer active keep their instances.
 *
 * Every change runs under a row lock (SettingService::update), so two
 * administrators saving at once both keep their change.
 */
final class Placements
{
    public const GROUP = 'widgets';
    public const KEY = 'areas';

    /** Instances in one area, at most. */
    public const MAX_PER_AREA = 50;

    private ?array $cache = null;

    public function __construct(private SettingService $settings) {}

    /** @return array<string, list<array>> area => normalised instances */
    public function all(): array
    {
        if ($this->cache !== null) return $this->cache;
        $raw = $this->settings->get(self::GROUP, self::KEY, []);
        return $this->cache = self::normaliseAll($raw);
    }

    /** @return list<array> */
    public function area(string $area): array
    {
        return $this->all()[$area] ?? [];
    }

    /** @return null|array{0:string,1:int,2:array} [area, index, instance] */
    public function find(string $id): ?array
    {
        foreach ($this->all() as $area => $list) {
            foreach ($list as $i => $inst) if ($inst['id'] === $id) return [$area, $i, $inst];
        }
        return null;
    }

    /**
     * Change the stored map under a row lock. $fn gets the current map
     * (normalised) and returns the new one; throwing aborts without saving.
     */
    public function change(callable $fn): array
    {
        $result = null;
        $this->settings->update(self::GROUP, self::KEY, function ($cur) use ($fn, &$result) {
            $all = self::normaliseAll($cur);
            $result = $fn($all);
            $clean = [];
            foreach ($result as $area => $list) {
                if (is_array($list) && $list !== []) $clean[(string) $area] = array_values($list);
            }
            return $clean;
        }, [], true);
        $this->cache = null;
        return $this->all();
    }

    public static function newId(): string
    {
        return 'w_' . bin2hex(random_bytes(4));
    }

    /** @return array<string, list<array>> */
    public static function normaliseAll(mixed $raw): array
    {
        if (is_string($raw)) $raw = json_decode($raw, true);
        if (!is_array($raw)) return [];
        $out = [];
        $seen = [];
        foreach ($raw as $area => $list) {
            if (!is_string($area) || !is_array($list)) continue;
            foreach ($list as $inst) {
                $n = self::normalise($inst);
                if ($n === null) continue;
                // An id appears once: a copied record gets a fresh one.
                if (isset($seen[$n['id']])) $n['id'] = self::newId();
                $seen[$n['id']] = true;
                $out[$area][] = $n;
            }
        }
        return $out;
    }

    public static function normalise(mixed $inst): ?array
    {
        if (!is_array($inst)) return null;
        $id = (string) ($inst['id'] ?? '');
        $widget = strtolower(trim((string) ($inst['widget'] ?? '')));
        if (!preg_match('/^[A-Za-z0-9_-]{1,40}$/', $id) || $widget === '') return null;
        return [
            'id' => $id,
            'widget' => $widget,
            'settings' => is_array($inst['settings'] ?? null) ? $inst['settings'] : [],
            'v' => (string) ($inst['v'] ?? ''),
            'class' => self::cleanClass((string) ($inst['class'] ?? '')),
            'hide_title' => !empty($inst['hide_title']),
            'disabled' => !empty($inst['disabled']),
        ];
    }

    /** CSS class names only: letters, digits, - and _, at most 8 of them. */
    public static function cleanClass(string $s): string
    {
        $out = [];
        foreach (preg_split('/\s+/', trim($s)) ?: [] as $c) {
            if (preg_match('/^-?[A-Za-z_][A-Za-z0-9_-]{0,59}$/', $c) && !in_array($c, $out, true)) $out[] = $c;
            if (count($out) >= 8) break;
        }
        return implode(' ', $out);
    }
}
