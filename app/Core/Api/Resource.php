<?php

declare(strict_types=1);

namespace App\Core\Api;

use App\Core\Application;
use App\Core\Logger;

/**
 * Resource — shared plumbing for every AppApi resource.
 *
 * Holds the container and the owning app's slug, and provides the two things
 * every resource needs: service resolution and failure-tolerant logging.
 *
 * Subclasses deliberately expose the same method names (find, all, paginate,
 * create, update, delete) even though the services underneath are inconsistent
 * about naming and return types. Smoothing that over is most of the value here.
 */
abstract class Resource
{
    public function __construct(
        protected Application $app,
        protected string $slug
    ) {
    }

    /** Resolve a core service. */
    protected function make(string $class): mixed
    {
        return $this->app->make($class);
    }

    /**
     * Log an action taken through the API, tagged with the owning app.
     *
     * Best-effort: a logging failure must never break the call that triggered
     * it, because apps use these methods on request paths.
     */
    protected function log(string $message, array $context = [], string $level = 'info'): void
    {
        try {
            $this->make(\App\Services\AppLogger::class)->log($this->slug, $level, $message, $context);
        } catch (\Throwable) {
        }

        // Mirror anything above info into the core log, so an operator reading
        // the main log still sees an app misbehaving without knowing to look
        // for a per-app file.
        if ($level === 'info' || $level === 'debug') return;
        try {
            $context['app'] = $this->slug;
            $this->make(Logger::class)->log($level, "[app:{$this->slug}] {$message}", $context);
        } catch (\Throwable) {
        }
    }

    /**
     * Run a callable, returning $fallback if it throws OR if the owning app
     * lacks the permission this operation needs.
     *
     * Two jobs in one place, on purpose. Every resource method already routes
     * through attempt() with a name for what it is doing, which makes this the
     * single seam where permissions can be enforced without scattering a check
     * through a hundred methods — and without any resource class having to know
     * the broker exists.
     *
     * Core services also raise on bad input an app can't easily predict (a
     * malformed slug, a missing foreign key). Apps calling a dozen of these
     * shouldn't wrap each one, so the throw is absorbed and recorded.
     */
    protected function attempt(callable $fn, mixed $fallback = null, string $what = 'operation'): mixed
    {
        if (!$this->permitted($what)) {
            return $fallback;
        }
        try {
            return $fn();
        } catch (\Throwable $e) {
            $this->log("API {$what} failed: " . $e->getMessage(), [], 'warning');
            return $fallback;
        }
    }

    /**
     * Check the permission an operation requires, logging any denial.
     *
     * A denial returns false rather than throwing. An exception would take down
     * the page an app is rendering, turning a policy decision into a site
     * outage; the app instead sees the same empty result it would see if there
     * were no data, and the operator sees a clear line in the app's log saying
     * exactly which permission was missing.
     */
    protected function permitted(string $operation): bool
    {
        $permission = $this->permissionFor($operation);
        if ($permission === null) return true;

        try {
            /** @var \App\Services\PermissionBroker $broker */
            $broker = $this->make(\App\Services\PermissionBroker::class);
            if ($broker->allows($this->slug, $permission)) return true;
        } catch (\Throwable) {
            // The broker being unavailable (mid-migration, say) must not break
            // apps. Fail open: this layer is policy, and the pre-1.36 behaviour
            // was to allow everything anyway.
            return true;
        }

        $this->log(
            "Permission denied: '{$permission}' is required for {$operation}() but was not granted. "
            . 'Declare it in the manifest and re-approve the app under Admin > Apps.',
            ['permission' => $permission, 'operation' => $operation],
            'warning'
        );
        return false;
    }

    /**
     * Which permission each operation needs, for every resource.
     *
     * Kept in ONE table rather than scattered across thirteen classes. For a
     * security control, being able to read the whole policy on one screen is
     * worth more than locality — a check hidden in a method is a check nobody
     * audits. Resources with a rule this table can't express (PostsApi, whose
     * prefix depends on its content type) override permissionFor() instead.
     *
     * A null value means the operation needs no permission. An operation absent
     * from its resource's map also needs none, so adding a read-only helper to
     * a resource does not silently become a gated call.
     *
     * @var array<string, array<string, string|null>>
     */
    private const PERMISSION_MAP = [
        'MediaApi' => [
            'find' => 'media.read', 'paginate' => 'media.read', 'stats' => 'media.read',
            'update' => 'media.write', 'uploadFromPath' => 'media.write',
            'delete' => 'media.delete',
        ],
        'UsersApi' => [
            'find' => 'users.read', 'findByEmail' => 'users.read',
            'findByUsername' => 'users.read', 'paginate' => 'users.read',
            'emailExists' => 'users.read', 'usernameExists' => 'users.read',
            'create' => 'users.write', 'update' => 'users.write',
            'delete' => 'users.delete',
            // can() only evaluates a capability against a row the caller
            // already holds, so it reveals nothing new.
            'can' => null,
        ],
        'CommentsApi' => [
            'find' => 'comments.read', 'forPost' => 'comments.read',
            'paginate' => 'comments.read', 'counts' => 'comments.read',
            'recent' => 'comments.read',
            'create' => 'comments.write',
            'setStatus' => 'comments.moderate', 'delete' => 'comments.moderate',
            // guard() only scores untrusted input against the site's spam
            // rules. Gating it would push apps toward skipping it.
            'guard' => null,
        ],
        'TermsApi' => [
            'taxonomies' => 'terms.read', 'taxonomy' => 'terms.read',
            'inTaxonomy' => 'terms.read', 'find' => 'terms.read',
            'findBySlug' => 'terms.read',
            'create' => 'terms.write', 'update' => 'terms.write',
            'delete' => 'terms.write',
        ],
        'MenusApi' => [
            'all' => 'menus.read', 'find' => 'menus.read',
            'findBySlug' => 'menus.read', 'items' => 'menus.read',
            'itemsBySlug' => 'menus.read',
            'create' => 'menus.write', 'update' => 'menus.write',
            'delete' => 'menus.write', 'addItem' => 'menus.write',
            'updateItem' => 'menus.write', 'deleteItem' => 'menus.write',
            'saveTree' => 'menus.write', 'clearItems' => 'menus.write',
        ],
        'SettingsApi' => [
            'get' => 'settings.read', 'group' => 'settings.read',
            'set' => 'settings.write',
            // publicSettings() is what the front end already exposes to any
            // anonymous visitor, so it is not privileged information.
            'publicSettings' => null,
        ],
        'MailApi' => [
            'send' => 'mail.send', 'sendTemplate' => 'mail.send',
            'lastError' => null, 'config' => null,
        ],
        // An app's own cache is its own storage — namespaced to its slug and
        // dropped with it. See PermissionBroker::IMPLICIT.
        'CacheApi' => [],
    ];

    /**
     * Map an operation name to the permission it needs, or null for none.
     *
     * Overridable: PostsApi needs the content type to decide between posts.*
     * and pages.*, which a static table cannot express.
     */
    protected function permissionFor(string $operation): ?string
    {
        $shortName = substr(strrchr(static::class, '\\') ?: static::class, 1);
        return self::PERMISSION_MAP[$shortName][$operation] ?? null;
    }
}
