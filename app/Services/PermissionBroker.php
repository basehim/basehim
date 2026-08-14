<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * PermissionBroker — declares, grants and checks app permissions.
 *
 * WHAT THIS IS NOT
 * ----------------
 * This is not a sandbox. An app is PHP running in the same process as core, on
 * shared hosting with no separate process, no seccomp and no container. Any app
 * can call `new PDO(...)`, read `.env` directly, or invoke a core service it was
 * never granted. Nothing here prevents that, and nothing could without a
 * rewrite of how apps are loaded.
 *
 * What it IS: a policy layer over the App API. An app declares what it intends
 * to touch, the operator sees that list and approves it, and calls through
 * $this->api() are checked against the approved set. That makes an app's
 * intentions legible and its overreach visible in the logs. Combined with
 * AppScanner, which flags apps reaching around the broker, it raises the cost of
 * misbehaviour without pretending to make it impossible.
 *
 * Treat the guarantee as "an honest app cannot exceed its declaration by
 * accident", not "a hostile app is contained".
 *
 * ENFORCEMENT IS OPT-IN
 * ---------------------
 * An app that declares no permissions runs UNRESTRICTED. That is deliberate:
 * every app written before this release declares nothing, and gating them would
 * break all of them on activation. Declaring a permissions array is what opts an
 * app in — from that moment its declaration is also its ceiling. Apps running
 * unrestricted are badged as such in the admin so an operator can see them.
 */
class PermissionBroker
{
    /**
     * The catalogue: permission => [label, description, risk].
     *
     * Risk drives how the consent screen presents each item; "high" ones are
     * the permissions that let an app affect the whole site or reach outside it.
     */
    public const CATALOGUE = [
        'posts.read'       => ['Read posts',          'View posts, including drafts and private ones.', 'low'],
        'posts.write'      => ['Create & edit posts', 'Create posts and change existing ones.', 'medium'],
        'posts.delete'     => ['Delete posts',        'Trash posts and delete them permanently.', 'high'],

        'pages.read'       => ['Read pages',          'View pages, including drafts.', 'low'],
        'pages.write'      => ['Create & edit pages', 'Create pages and change existing ones.', 'medium'],
        'pages.delete'     => ['Delete pages',        'Trash pages and delete them permanently.', 'high'],

        'media.read'       => ['Read media',          'Browse the media library.', 'low'],
        'media.write'      => ['Upload & edit media', 'Add files and change their metadata.', 'medium'],
        'media.delete'     => ['Delete media',        'Remove files and their thumbnails from disk.', 'high'],

        'users.read'       => ['Read users',          'View accounts, roles and email addresses. Password hashes are never exposed.', 'medium'],
        'users.write'      => ['Create & edit users', 'Create accounts and change existing ones, including roles.', 'high'],
        'users.delete'     => ['Delete users',        'Permanently remove accounts.', 'high'],

        'comments.read'     => ['Read comments',      'View comments in every status, including spam.', 'low'],
        'comments.write'    => ['Post comments',      'Create comments, bypassing the spam guard.', 'medium'],
        'comments.moderate' => ['Moderate comments',  'Approve, spam, trash and delete comments.', 'medium'],

        'terms.read'       => ['Read taxonomies',     'View categories, tags and custom taxonomies.', 'low'],
        'terms.write'      => ['Manage taxonomies',   'Create, rename and delete terms.', 'medium'],

        'menus.read'       => ['Read menus',          'View navigation menus and their items.', 'low'],
        'menus.write'      => ['Manage menus',        'Create, change and delete menus and items.', 'medium'],

        'settings.read'    => ['Read site settings',  'View site-wide configuration.', 'medium'],
        'settings.write'   => ['Change site settings','Modify site-wide configuration. Affects the whole site, not just this app.', 'high'],

        'mail.send'        => ['Send email',          'Send email from the site address, to any recipient.', 'high'],
        'http.outbound'    => ['Make network requests','Contact external servers, which can carry site data off-site.', 'high'],

        'schedule'         => ['Run scheduled tasks', 'Run work in the background on a recurring basis.', 'medium'],
        'db.raw'           => ['Direct database access', 'Run arbitrary SQL. This bypasses every other permission on this list.', 'high'],
    ];

    /**
     * Always available, never declared, never consented to.
     *
     * An app's own cache and own settings are its own storage — already
     * namespaced to its slug and dropped on uninstall. Asking an operator to
     * approve an app for access to its own data would be noise that trains
     * people to click through consent screens without reading them.
     */
    public const IMPLICIT = ['cache.own', 'settings.own', 'log'];

    /** Cache of granted sets, keyed by slug. */
    private array $granted = [];

    public function __construct(private Database $db)
    {
    }

    // ------------------------------------------------------------------
    // Declaration & grants
    // ------------------------------------------------------------------

    /** Permissions an app declares in its manifest. */
    public function declared(string $slug): array
    {
        return $this->decodeColumn($slug, 'permissions');
    }

    /** Permissions the operator actually approved. */
    public function grantedFor(string $slug): array
    {
        if (isset($this->granted[$slug])) return $this->granted[$slug];
        return $this->granted[$slug] = $this->decodeColumn($slug, 'granted_permissions');
    }

    /**
     * Record the operator's decision.
     *
     * Anything not in the catalogue, and anything not declared, is dropped: an
     * app cannot be granted more than it asked for, even by a mistaken POST.
     */
    public function grant(string $slug, array $permissions): bool
    {
        $declared = $this->declared($slug);
        $clean = array_values(array_intersect(
            array_unique(array_filter($permissions, 'is_string')),
            array_keys(self::CATALOGUE),
            $declared
        ));

        try {
            $this->db->update('apps', [
                'granted_permissions' => json_encode($clean, JSON_UNESCAPED_SLASHES),
                'consented_at'        => date('Y-m-d H:i:s'),
            ], ['slug' => $slug]);
            unset($this->granted[$slug]);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Forget an app's grants — used on deactivate/uninstall. */
    public function revokeAll(string $slug): void
    {
        try {
            $this->db->update('apps', [
                'granted_permissions' => null,
                'consented_at'        => null,
            ], ['slug' => $slug]);
        } catch (\Throwable) {
        }
        unset($this->granted[$slug]);
    }

    /**
     * True when the app declares permissions and so is subject to enforcement.
     * An app declaring nothing is unrestricted — see the class docblock.
     */
    public function isEnforced(string $slug): bool
    {
        return $this->declared($slug) !== [];
    }

    /**
     * True when an app is running with permissions it declared but that nobody
     * has approved — an upgrade widened its list while it was active.
     * Enforcement is suspended for these until the operator reviews them.
     */
    public function needsReview(string $slug): bool
    {
        return $this->isEnforced($slug) && !$this->hasConsented($slug);
    }

    /** True when consent has been recorded, whatever was granted. */
    public function hasConsented(string $slug): bool
    {
        try {
            $row = $this->db->selectOne(
                'SELECT consented_at FROM {apps} WHERE slug = :s', ['s' => $slug]
            );
            return !empty($row['consented_at']);
        } catch (\Throwable) {
            return false;
        }
    }

    /** Declared but not granted — what the operator withheld. */
    public function withheld(string $slug): array
    {
        return array_values(array_diff($this->declared($slug), $this->grantedFor($slug)));
    }

    // ------------------------------------------------------------------
    // Checking
    // ------------------------------------------------------------------

    /**
     * May this app use this permission?
     *
     * Order matters. Implicit permissions pass first so an app's own storage is
     * never gated. Unenforced apps pass next, which is what keeps every
     * pre-existing app working. Only then is the granted set consulted.
     */
    public function allows(string $slug, string $permission): bool
    {
        if ($permission === '') return true;
        if (in_array($permission, self::IMPLICIT, true)) return true;
        if (!$this->isEnforced($slug)) return true;

        // Declared but never approved -> do not enforce yet.
        //
        // Consent is normally taken at activation, so a newly activated app
        // always has it. The gap is an app that was ALREADY ACTIVE and gained
        // permissions in an upgrade: it keeps running, but nothing has asked
        // the operator to approve the new list. Enforcing there would break a
        // working app mid-request — for the hub's own tooling, that means the
        // whole site — as a side effect of an update the operator did not know
        // changed anything.
        //
        // So enforcement begins once consent exists. Admin > Apps flags these
        // as needing review, which is the prompt rather than an outage.
        if (!$this->hasConsented($slug)) return true;

        $granted = $this->grantedFor($slug);
        if (in_array($permission, $granted, true)) return true;

        // A read is implied by the matching write: an app granted posts.write
        // that could not read a post to update it would be useless, and every
        // author would work around it by declaring both. Better to make the
        // implication explicit than to have the declaration lie.
        if (str_ends_with($permission, '.read')) {
            $write = substr($permission, 0, -5) . '.write';
            if (in_array($write, $granted, true)) return true;
        }

        return false;
    }

    /** Catalogue entry, or a generated one for an unknown permission. */
    public function describe(string $permission): array
    {
        $entry = self::CATALOGUE[$permission] ?? null;
        if ($entry === null) {
            return [
                'key' => $permission, 'label' => $permission,
                'description' => 'Not a recognised Basehim permission — it grants nothing.',
                'risk' => 'unknown',
            ];
        }
        return [
            'key' => $permission, 'label' => $entry[0],
            'description' => $entry[1], 'risk' => $entry[2],
        ];
    }

    /** Describe a list, preserving order. */
    public function describeAll(array $permissions): array
    {
        return array_map([$this, 'describe'], array_values($permissions));
    }

    /** Declared permissions that aren't in the catalogue. */
    public function unknownDeclared(string $slug): array
    {
        return array_values(array_diff($this->declared($slug), array_keys(self::CATALOGUE)));
    }

    private function decodeColumn(string $slug, string $column): array
    {
        try {
            $row = $this->db->selectOne(
                "SELECT `{$column}` AS v FROM {apps} WHERE slug = :s", ['s' => $slug]
            );
            $decoded = json_decode((string) ($row['v'] ?? ''), true);
            return is_array($decoded)
                ? array_values(array_filter($decoded, 'is_string'))
                : [];
        } catch (\Throwable) {
            return [];
        }
    }
}
