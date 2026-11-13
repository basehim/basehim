<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Each person's admin appearance (1.3.0): light, dark or the device's
 * setting, and an accent colour. Saved on their account (user meta
 * "appearance"), so it follows them to every browser.
 *
 * The colours themselves live in tailwind.theme.js; this class only knows the
 * names. <html> carries the choice:
 *   data-appearance  what they chose: light | dark | system
 *   data-theme       what is drawn: light | dark (for "system", the head
 *                    script picks one from the device before anything paints)
 *   data-accent      the accent's name
 */
final class AppearanceService
{
    public const META_KEY = 'appearance';

    /** @var array<string, string> */
    public const MODES = ['light' => 'Light', 'dark' => 'Dark', 'system' => 'System'];

    /**
     * Keep in step with ACCENTS in tailwind.theme.js (a test checks). Each is a
     * four-colour palette there; an accent saved under a name no longer listed
     * falls back to the default.
     * @var array<string, string>
     */
    public const ACCENTS = [
        'blue'       => 'Default',
        'sunset'     => 'Sunset',
        'rose'       => 'Rose',
        'amphibian'  => 'Amphibian',
        'strawberry' => 'Strawberry',
        'kiwi'       => 'Kiwi',
        'pink-la'    => 'Pink LA',
    ];

    /** Until someone chooses. */
    // System by default (1.3.0): someone who never chooses gets what their
    // device is set to, light or dark.
    public const DEFAULTS = ['mode' => 'system', 'accent' => 'blue'];

    public function __construct(private UserService $users) {}

    /** @return array{mode: string, accent: string} */
    public function forUser(?int $userId): array
    {
        if (!$userId) return self::DEFAULTS;
        return self::clean($this->users->meta($userId, self::META_KEY, []));
    }

    /**
     * Save what was sent (either or both), keeping the other as it was.
     * @return array{mode: string, accent: string}
     */
    public function save(int $userId, array $input): array
    {
        $now = $this->forUser($userId);
        if (is_string($input['mode'] ?? null) && isset(self::MODES[$input['mode']])) $now['mode'] = $input['mode'];
        if (is_string($input['accent'] ?? null) && isset(self::ACCENTS[$input['accent']])) $now['accent'] = $input['accent'];
        $this->users->setMeta($userId, self::META_KEY, $now);
        return $now;
    }

    /** @return array{mode: string, accent: string} */
    public static function clean(mixed $value): array
    {
        $v = is_array($value) ? $value : [];
        // Only strings can be keys: anything else stored here (an app's write,
        // a bad import) falls back to the default instead of throwing.
        $mode = is_string($v['mode'] ?? null) ? $v['mode'] : '';
        $accent = is_string($v['accent'] ?? null) ? $v['accent'] : '';
        return [
            'mode'   => isset(self::MODES[$mode]) ? $mode : self::DEFAULTS['mode'],
            'accent' => isset(self::ACCENTS[$accent]) ? $accent : self::DEFAULTS['accent'],
        ];
    }

    /**
     * The <html> attributes for a choice. For "system" the server can't know
     * the device's setting, so data-theme starts light and the head script
     * (partials/appearance-head.php) corrects it before the first paint.
     */
    /**
     * The signed-in person's appearance, for a page drawn outside the admin
     * layout: an error page, Access denied (1.3.0). Never throws: a page
     * answering a failure (the database may be the failure) falls back to
     * the defaults (System, Default) instead of failing in turn.
     *
     * @return array{mode: string, accent: string}
     */
    public static function forRequest(): array
    {
        try {
            $app = \App\Core\Application::getInstance();
            $id = $app->make(AuthService::class)->currentUserId();
            return $id ? $app->make(self::class)->forUser($id) : self::DEFAULTS;
        } catch (\Throwable) {
            return self::DEFAULTS;
        }
    }

    /**
     * For "System": set data-theme from the device before the page paints and
     * follow it after. The admin layout has a fuller one (appearance-head.php).
     * Carries this response's CSP nonce, so the public site's policy allows it.
     */
    public static function systemScript(): string
    {
        $nonce = '';
        try { $nonce = ' nonce="' . \App\Services\SecurityHeaders::nonce() . '"'; } catch (\Throwable) {}
        return '<script' . $nonce . '>(function(){var d=document.documentElement;if(d.getAttribute("data-appearance")!=="system"||!window.matchMedia)return;'
            . 'var q=matchMedia("(prefers-color-scheme: dark)"),f=function(){d.setAttribute("data-theme",q.matches?"dark":"light")};f();'
            . 'if(q.addEventListener)q.addEventListener("change",f)})();</script>';
    }

    public static function htmlAttributes(array $a): string
    {
        $a = self::clean($a);
        $theme = $a['mode'] === 'dark' ? 'dark' : 'light';
        return sprintf(
            'data-appearance="%s" data-theme="%s" data-accent="%s"',
            htmlspecialchars($a['mode'], ENT_QUOTES), $theme, htmlspecialchars($a['accent'], ENT_QUOTES)
        );
    }
}
