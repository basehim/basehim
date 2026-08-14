<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Application;
use App\Http\Middleware\CheckCapability;
use App\Repositories\UserRepository;

/**
 * ContentPolicy
 *
 * Answers "may this author's markup be published without sanitization?".
 *
 * Kept separate from HtmlSanitizer so the *decision* and the *mechanism* can be
 * reasoned about independently — and so the per-request lookup cache lives in
 * one place rather than being reinvented at each call site.
 */
final class ContentPolicy
{
    public const RAW_HTML_CAPABILITY = 'unfiltered_html';

    /** @var array<int,bool> author id => may post raw HTML */
    private static array $cache = [];

    /**
     * The content filter runs for every post on every page render, so an
     * uncached role lookup here would add a query per post on an archive page.
     * The result is stable for the life of a request, which is exactly as long
     * as this cache lives.
     */
    public static function authorMayPostRawHtml(int $authorId): bool
    {
        if ($authorId <= 0) return false;
        if (array_key_exists($authorId, self::$cache)) {
            return self::$cache[$authorId];
        }

        $allowed = false;
        try {
            $user = Application::getInstance()->make(UserRepository::class)->find($authorId);
            $allowed = $user !== null && CheckCapability::userCan($user, self::RAW_HTML_CAPABILITY);
        } catch (\Throwable) {
            // Cannot establish the author's role — sanitize. Failing closed is
            // the only safe direction for this particular question.
            $allowed = false;
        }

        return self::$cache[$authorId] = $allowed;
    }

    /** Does the currently signed-in user hold the capability? */
    public static function currentUserMayPostRawHtml(): bool
    {
        try {
            $app = Application::getInstance();
            $user = $app->has('auth.user') ? $app->make('auth.user') : null;
            return CheckCapability::userCan($user, self::RAW_HTML_CAPABILITY);
        } catch (\Throwable) {
            return false;
        }
    }

    /** Test seam — clears the per-request cache. */
    public static function flush(): void
    {
        self::$cache = [];
    }
}
