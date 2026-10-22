<?php

declare(strict_types=1);

namespace App\Core;

/**
 * An app used something it hasn't been allowed (1.2.85): `$this->db()`
 * without 'db.raw', say. Thrown by App::requirePermission().
 *
 * It extends RuntimeException, so code that caught that before still does.
 * What changed is what the visitor sees: an app's admin screen answers with
 * the permission window instead of an error page (an administrator allows it
 * and the screen reloads, working), and an app's AJAX action answers
 * APP_PERMISSION_MISSING (403) instead of a 500. See AppPermissionPage.
 */
final class AppPermissionMissing extends \RuntimeException
{
    public function __construct(
        public readonly string $slug,
        public readonly string $permission,
        string $message,
    ) {
        parent::__construct($message);
    }

    /** This exception, or one in the chain of previous exceptions. */
    public static function in(\Throwable $e): ?self
    {
        for ($i = 0; $e !== null && $i < 10; $i++, $e = $e->getPrevious()) {
            if ($e instanceof self) return $e;
        }
        return null;
    }
}
