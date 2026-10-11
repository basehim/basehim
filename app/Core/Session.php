<?php

declare(strict_types=1);

namespace App\Core;

final class Session
{
    public function __construct()
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    public function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public function all(): array
    {
        return $_SESSION;
    }

    public function flush(): void
    {
        $_SESSION = [];
    }

    public function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public function destroy(): void
    {
        $this->flush();
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    // Flash messages -------------------------------------------------------

    public function flash(string $key, mixed $value): void
    {
        $_SESSION['_flash'][$key] = $value;
    }

    public function getFlash(string $key, mixed $default = null): mixed
    {
        $value = $_SESSION['_flash'][$key] ?? $default;
        unset($_SESSION['_flash'][$key]);
        return $value;
    }

    public function hasFlash(string $key): bool
    {
        return isset($_SESSION['_flash'][$key]);
    }

    // CSRF -----------------------------------------------------------------

    public function csrfToken(): string
    {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }

    /**
     * Drop the token so the next csrfToken() call mints a new one.
     *
     * Called whenever the session changes hands — sign-in and sign-out — so a
     * token issued to an anonymous visitor (or read by someone before the
     * user signed in) does not go on to authorise the signed-in session.
     * Pages rendered after the change carry the new token; an open tab from
     * before it gets CSRF_INVALID and has to reload, which is the point.
     */
    public function rotateCsrf(): void
    {
        unset($_SESSION['_csrf']);
    }

    public function verifyCsrf(?string $token): bool
    {
        return is_string($token) && isset($_SESSION['_csrf'])
            && hash_equals($_SESSION['_csrf'], $token);
    }
}
