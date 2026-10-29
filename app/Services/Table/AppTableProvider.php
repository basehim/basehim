<?php

declare(strict_types=1);

namespace App\Services\Table;

use App\Core\Table\TableProvider;
use App\Core\Table\TableQuery;
use App\Services\AppMeta;
use App\Services\AppService;

/**
 * Data provider for the Apps table (/admin/apps, 1.2.85).
 *
 * Lists the installed apps — the rows core registered from a ZIP upload, the
 * marketplace or Dev Tools — never a folder that merely sits in content/apps.
 * A site has tens of apps, not thousands, so the list is read once and
 * searched, filtered, sorted and paged here; no request string reaches SQL.
 *
 * Each row carries the app's state for the Status column and the declared
 * actions (`status`), its slug (`row_key`) for links and the permission
 * window, and `row_flags` saying which row actions apply.
 */
final class AppTableProvider implements TableProvider
{
    /** state => label, in the order the status menu lists them. */
    public const STATES = [
        'active'   => 'Active',
        'inactive' => 'Inactive',
        'consent'  => 'Needs approval',
        'invalid'  => 'Can\'t activate',
        'failed'   => 'Switched off',
        'missing'  => 'Files missing',
    ];

    public function __construct(private AppService $apps)
    {
    }

    public function sortKeys(): array
    {
        return ['name_az', 'name_za', 'newest', 'oldest'];
    }

    public function filterKeys(): array
    {
        return ['status'];
    }

    public function query(TableQuery $q): array
    {
        $rows = $this->rows();

        // BH Search's query rules (1.2.92): every word, in any order, "phrases" and -exclusions.
        $search = \App\Core\Search\SearchQuery::parse((string) $q->search);
        if (!$search->isBlank()) {
            $rows = array_values(array_filter($rows, static fn(array $r) =>
                $search->matches($r['name'] . ' ' . $r['slug'] . ' ' . $r['description'] . ' ' . $r['developer'])));
        }
        $state = $q->filter('status');
        if (is_string($state) && isset(self::STATES[$state])) {
            $rows = array_values(array_filter($rows, static fn(array $r) => $r['status'] === $state));
        }

        $sort = $q->sort ?: 'name_az';
        usort($rows, static function (array $a, array $b) use ($sort): int {
            return match ($sort) {
                'name_za' => strcasecmp($b['name'], $a['name']),
                'newest'  => strcmp((string) $b['created_at'], (string) $a['created_at']) ?: strcasecmp($a['name'], $b['name']),
                'oldest'  => strcmp((string) $a['created_at'], (string) $b['created_at']) ?: strcasecmp($a['name'], $b['name']),
                default   => strcasecmp($a['name'], $b['name']),
            };
        });

        $total = count($rows);
        $per = max(1, $q->perPage);
        $last = max(1, (int) ceil($total / $per));
        $page = min(max(1, $q->page), $last);

        return [
            'data' => array_slice($rows, ($page - 1) * $per, $per),
            'meta' => ['page' => $page, 'per_page' => $per, 'total' => $total, 'last_page' => $last],
        ];
    }

    /**
     * Every installed app as a table row.
     *
     * @return array<int,array<string,mixed>>
     */
    public function rows(): array
    {
        $health = [];
        try { $health = $this->apps->guard()?->store()->all() ?? []; } catch (\Throwable) {}
        $onDisk = $this->apps->scan();
        // Active apps that couldn't start this request for want of a
        // permission show as needing approval (1.2.85).
        $blocked = $this->apps->permissionBlocked();

        $out = [];
        foreach ($this->apps->installed() as $row) {
            $slug = (string) $row['slug'];
            $manifest = $onDisk[$slug] ?? null;
            $meta = $manifest !== null ? AppMeta::from($manifest, $slug, (string) ($manifest['_path'] ?? '')) : null;
            $declared = self::json($row['permissions'] ?? null);
            $granted = self::json($row['granted_permissions'] ?? null);
            $scan = json_decode((string) ($row['scan_result'] ?? ''), true);
            $rec = $health[$slug] ?? null;
            $hstate = (string) ($rec['state'] ?? '');
            $status = (string) ($row['status'] ?? 'inactive');

            $state = match (true) {
                $manifest === null => 'missing',
                $status === 'error' || $hstate === 'quarantined' => 'failed',
                $status === 'active' && isset($blocked[$slug]) => 'consent',
                $status === 'active' => 'active',
                $hstate === 'invalid' => 'invalid',
                $declared !== [] && empty($row['consented_at']) => 'consent',
                default => 'inactive',
            };

            $flags = [];
            if ($declared !== [] && $state !== 'missing') $flags[] = 'permissions';
            if ($rec !== null && ($state === 'failed' || $state === 'invalid')) $flags[] = 'report';
            if ($state === 'failed' || $state === 'invalid') $flags[] = 'retry';
            if ($state !== 'missing') $flags[] = 'files';

            $out[] = [
                'id'          => (int) $row['id'],
                'row_key'     => $slug,
                'row_flags'   => $flags,
                'status'      => $state,
                'slug'        => $slug,
                'name'        => (string) ($meta['name'] ?? $row['name'] ?? $slug),
                'description' => (string) (($meta['description'] ?? '') !== '' ? $meta['description'] : ($row['description'] ?? '')),
                'version'     => (string) ($row['version'] ?? ''),
                'developer'   => (string) ($meta['developer']['name'] ?? $row['author'] ?? ''),
                'icon'        => $meta['icon'] ?? ['type' => 'none', 'value' => ''],
                'initial'     => (string) ($meta['initial'] ?? mb_strtoupper(mb_substr((string) ($row['name'] ?? $slug), 0, 1))),
                'source'      => (string) ($row['source'] ?? 'legacy'),
                'declared'    => $declared,
                'granted'     => $granted,
                'consented'   => !empty($row['consented_at']),
                'scan_high'   => is_array($scan) ? (int) ($scan['high'] ?? 0) : 0,
                'scan_medium' => is_array($scan) ? (int) ($scan['medium'] ?? 0) : 0,
                'scanned'     => is_array($scan),
                'problem'     => isset($blocked[$slug]) && $status === 'active'
                    ? 'It isn\'t running: it needs the "' . $blocked[$slug]->permission . '" permission. Open its Permissions to allow it.'
                    : ($state === 'failed'
                    ? (string) ($rec['failure']['message'] ?? 'It failed while loading.')
                    : ($state === 'invalid' ? (string) ($rec['validation']['errors'][0]['message'] ?? 'Its code did not pass validation.') : '')),
                'created_at'  => (string) ($row['created_at'] ?? ''),
            ];
        }
        return $out;
    }

    /** @return string[] */
    private static function json(mixed $v): array
    {
        $d = is_string($v) ? json_decode($v, true) : $v;
        return is_array($d) ? array_values(array_map('strval', $d)) : [];
    }
}
