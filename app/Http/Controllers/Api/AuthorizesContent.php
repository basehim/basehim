<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\Response;
use App\Http\Middleware\CheckCapability;

/**
 * AuthorizesContent
 *
 * Capability + ownership checks for the REST content endpoints.
 *
 * These rules used to live only in Admin\PostController, which meant the API
 * enforced authentication but never authorization: any signed-in account could
 * edit, publish or delete anyone's content. The checks are collected here so
 * every API controller applies the same rules, and so adding an endpoint means
 * calling one method rather than remembering to re-implement the policy.
 *
 * The capability names match config/capabilities.php exactly, so the admin UI
 * and the API grant identical powers to a given role.
 */
trait AuthorizesContent
{
    /**
     * Can the current user act on this row?
     *
     * $action is 'edit' or 'delete'. Ownership decides which capability applies:
     * touching your own row needs edit_posts/delete_posts, touching someone
     * else's needs the _others_ variant.
     *
     * @param array $row       The existing post/page row (must contain author_id).
     * @param string $action   'edit' | 'delete'
     * @param string $type     'post' | 'page' | a registered custom type
     */
    protected function canActOn(?array $user, array $row, string $action, string $type = 'post'): bool
    {
        if (!$user) return false;

        $own = (int) ($row['author_id'] ?? 0) === (int) ($user['id'] ?? -1);
        $cap = $this->contentCapability($action . ($own ? '' : '_others'), $type);

        return CheckCapability::userCan($user, $cap);
    }

    /**
     * Capability name for an action on a content type, e.g. edit_others_posts.
     *
     * Pages have their own capability family; app-registered types map onto the
     * post family, which is what PostTypeRegistry already assumes.
     */
    protected function contentCapability(string $action, string $type = 'post'): string
    {
        $family = $type === 'page' ? 'pages' : 'posts';

        // 'edit_others' => edit_others_posts ; 'edit' => edit_posts
        return str_contains($action, '_others')
            ? str_replace('_others', '', $action) . '_others_' . $family
            : $action . '_' . $family;
    }

    /**
     * Force a status the user is not allowed to set back to a safe value.
     *
     * A contributor may write but not publish. Rather than rejecting the whole
     * request — which would lose their draft — the status is downgraded to
     * 'pending' so an editor can review it, matching what the admin UI does.
     */
    protected function enforcePublishCapability(?array $user, array $data, string $type = 'post'): array
    {
        if (($data['status'] ?? '') !== 'published') {
            return $data;
        }
        $cap = $type === 'page' ? 'publish_pages' : 'publish_posts';
        if (!CheckCapability::userCan($user, $cap)) {
            $data['status'] = 'pending';
        }
        return $data;
    }

    /** Standard 403 for a failed capability check. */
    protected function forbidden(string $capability): Response
    {
        return Response::json([
            'type'   => 'https://basehim.io/errors/forbidden',
            'title'  => 'Forbidden',
            'status' => 403,
            'detail' => "Your account does not have the '{$capability}' capability.",
        ], 403);
    }

    /**
     * Enforce an API key's scope when the request authenticated with one.
     *
     * A key is a delegation of its owner's power, so it can never exceed the
     * owner's capabilities — but it must also be able to hold LESS. Without
     * this, a read-only key had exactly the same reach as its owner's password.
     *
     * Session- and JWT-authenticated requests carry no scopes and are unaffected.
     */
    protected function requireScope(string $scope): ?Response
    {
        if (!$this->app->has('auth.api_key')) {
            return null; // not an API-key request — capability checks still apply
        }
        $scopes = $this->app->has('auth.scopes') ? (array) $this->app->make('auth.scopes') : [];
        if (in_array($scope, $scopes, true)) {
            return null;
        }
        return Response::json([
            'type'   => 'https://basehim.io/errors/forbidden',
            'title'  => 'Forbidden',
            'status' => 403,
            'detail' => "This API key lacks the required scope: {$scope}.",
        ], 403);
    }
}
