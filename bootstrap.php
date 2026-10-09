<?php
/**
 * Basehim Bootstrap
 *
 * Loads the environment, autoloader, config, and registers core services.
 */

declare(strict_types=1);

// PHP version guard
if (PHP_VERSION_ID < 80100) {
    die('Basehim requires PHP 8.1 or higher. Your version: ' . PHP_VERSION);
}

// Error reporting (production = log only)
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', BASEHIM_ROOT . '/storage/logs/php-error.log');

// Timezone default (overridden by config)
date_default_timezone_set('UTC');

// Default character encoding
mb_internal_encoding('UTF-8');
ini_set('default_charset', 'UTF-8');

// Autoloader (PSR-4 inspired, no Composer required)
require BASEHIM_ROOT . '/app/Core/Autoloader.php';
\App\Core\Autoloader::register();

/**
 * Render a Heroicon (outline) as inline SVG.
 *
 * Accepts a Heroicon name ('trash') or a legacy Font Awesome name
 * ('fa-trash' / 'fa-solid fa-trash'), so app- and theme-supplied icon
 * strings keep working. Size and colour come from the CSS classes.
 */
if (!function_exists('icon')) {
    function icon(string $name, string $class = 'w-5 h-5', array $attrs = []): string
    {
        return \App\Core\Icon::svg($name, $class, $attrs);
    }
}

/**
 * The Basehim mark.
 *
 * Defined once here rather than pasted into each view, so the brand can be
 * changed in one place. A site that has uploaded its own logo under
 * Settings → General gets that instead — an admin panel showing someone else's
 * logo above their own site name reads as a mistake.
 *
 * $px is the rendered size; the file served is the next size up, so the mark
 * stays sharp on a high-density screen without shipping 512px for a 36px slot.
 */
if (!function_exists('brand_logo')) {
    function brand_logo(int $px = 36, string $class = '', string $alt = 'Basehim'): string
    {
        $base = defined('BASEHIM_BASE') ? BASEHIM_BASE : '';

        $custom = '';
        try {
            $settings = \App\Core\Application::getInstance()->make(\App\Services\SettingService::class);
            $custom = trim((string) $settings->get('general', 'logo_url', ''));
        } catch (\Throwable) {
            // Settings may not be available this early; fall back to the mark.
        }

        if ($custom !== '') {
            $src = $custom;
        } else {
            $file = $px <= 32 ? 'logo-64.png' : ($px <= 64 ? 'logo-128.png' : ($px <= 128 ? 'logo-256.png' : 'logo.png'));
            $src = $base . '/admin/assets/img/' . $file;
        }

        return sprintf(
            '<img src="%s" alt="%s" width="%d" height="%d" class="%s" style="width:%dpx;height:%dpx;object-fit:contain" decoding="async">',
            htmlspecialchars($src, ENT_QUOTES),
            htmlspecialchars($alt, ENT_QUOTES),
            $px, $px,
            htmlspecialchars($class, ENT_QUOTES),
            $px, $px
        );
    }
}

// Load environment variables from .env
\App\Core\Env::load(BASEHIM_ROOT . '/.env');

// Set timezone from config
date_default_timezone_set(\App\Core\Env::get('APP_TIMEZONE', 'UTC'));

// Session config (file-based, cPanel-friendly)
$sessionPath = BASEHIM_ROOT . '/storage/sessions';
if (!is_dir($sessionPath)) {
    @mkdir($sessionPath, 0755, true);
}
ini_set('session.save_path', $sessionPath);
ini_set('session.cookie_httponly', '1');
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_samesite', 'Lax');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    ini_set('session.cookie_secure', '1');
}
session_name('BASEHIMSESS');

// Initialize application
\App\Core\Application::boot();

/**
 * Global helper for views to build base-aware URLs.
 *   link('/admin/posts')         -> '/basehim/admin/posts'  (subdir install)
 *   link('/admin/posts')         -> '/admin/posts'          (root install)
 *   link('https://example.com')  -> 'https://example.com'   (untouched)
 */
if (!function_exists('link_to')) {
    function link_to(string $path): string {
        return \App\Core\Helpers::link($path);
    }
}

/**
 * Render a widget area ("sidebar") by key for use in theme templates:
 *   <?= widget_area('sidebar') ?>
 * Returns '' when the area is unknown or empty, so themes can call it freely.
 */
if (!function_exists('widget_area')) {
    function widget_area(string $key): string {
        try {
            return \App\Core\Application::getInstance()
                ->make(\App\Services\WidgetAreaService::class)
                ->render($key);
        } catch (\Throwable) {
            return '';
        }
    }
}

/** True when a widget area exists and has at least one widget placed in it. */
if (!function_exists('has_widget_area')) {
    function has_widget_area(string $key): bool {
        try {
            return \App\Core\Application::getInstance()
                ->make(\App\Services\WidgetAreaService::class)
                ->isActive($key);
        } catch (\Throwable) {
            return false;
        }
    }
}

/* ═══════════════════════════════════════════════════════════════════════════
   The front-end contract between core, themes and apps.

   Before these existed, core fired thirty-four hooks and not one of them was on
   the front end. An analytics app had nowhere to put a tracking script, a
   consent banner had nowhere to render, and a theme had no way to receive
   either — so every app that touched the public site had to tell people to
   paste something into a template by hand.

   A theme calls two functions. Everything core and every app needs on the front
   end arrives through them:

       <head>  … <?= bh_head() ?>  </head>
       …       <?= bh_footer() ?>  </body>

   An app registers what it needs and never touches a template:

       $this->addAction('bh.head',   fn() => '<script>…</script>');
       $this->enqueueStyle('my-app', $this->asset('css/front.css'));

   A theme that calls neither still works — it simply does not receive anything.
   ═══════════════════════════════════════════════════════════════════════════ */

/**
 * Assets registered for the front end, by apps or by the theme.
 *
 * Kept in one place so the same file cannot be added twice by two apps that
 * both depend on it, and so order is predictable rather than a matter of which
 * app happened to boot first.
 */
if (!function_exists('bh_assets')) {
    function &bh_assets(): array {
        static $reg = ['styles' => [], 'scripts' => []];
        return $reg;
    }
}

/**
 * Register a stylesheet for the front end.
 *
 * The handle makes it idempotent: two apps asking for the same library get one
 * tag. Priority orders the output — a theme's own reset wants to come before an
 * app's overrides.
 */
if (!function_exists('bh_enqueue_style')) {
    function bh_enqueue_style(string $handle, string $url, int $priority = 10, array $attrs = []): void {
        $reg = &bh_assets();
        $reg['styles'][$handle] = ['url' => $url, 'priority' => $priority, 'attrs' => $attrs];
    }
}

/**
 * Register a script for the front end.
 *
 * Scripts go before </body> by default. `$inHead` is for the few that genuinely
 * cannot wait — a consent gate that must run before anything else loads.
 */
if (!function_exists('bh_enqueue_script')) {
    function bh_enqueue_script(string $handle, string $url, int $priority = 10, bool $inHead = false, array $attrs = []): void {
        $reg = &bh_assets();
        $reg['scripts'][$handle] = [
            'url' => $url, 'priority' => $priority, 'head' => $inHead,
            'attrs' => $attrs + ['defer' => true],
        ];
    }
}

/** Build one tag, escaping every attribute. An app supplies these, so none is trusted. */
if (!function_exists('bh_asset_tag')) {
    function bh_asset_tag(string $kind, array $a): string {
        $url = filter_var($a['url'], FILTER_VALIDATE_URL) || str_starts_with($a['url'], '/')
            ? $a['url'] : '';
        if ($url === '') return '';      // not a usable URL; drop it silently

        $extra = '';
        foreach ($a['attrs'] ?? [] as $k => $v) {
            if (!preg_match('/^[a-z][a-z0-9-]*$/i', (string) $k)) continue;
            if ($v === true)  { $extra .= ' ' . $k; continue; }
            if ($v === false || $v === null) continue;
            $extra .= ' ' . $k . '="' . htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8') . '"';
        }
        $u = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        return $kind === 'style'
            ? '<link rel="stylesheet" href="' . $u . '"' . $extra . '>'
            : '<script src="' . $u . '"' . $extra . '></script>';
    }
}

/**
 * Everything that belongs in <head>.
 *
 * In order: the Customizer's variables and custom CSS, registered stylesheets,
 * head scripts, then whatever apps add through the `bh.head` action.
 *
 * Each app's contribution is caught separately. One app throwing must not take
 * the head — and therefore the styling — of every page on the site.
 */
if (!function_exists('bh_head')) {
    function bh_head(): string {
        // Names the platform for tools and directories that look for it. No
        // version number: that would tell an attacker exactly which fixes a
        // site is missing.
        $out = '<meta name="generator" content="Basehim CMS">' . "\n";

        try {
            $out .= \App\Core\Application::getInstance()
                ->make(\App\Services\CustomizerService::class)->headMarkup();
        } catch (\Throwable) {}

        $reg = &bh_assets();

        $styles = $reg['styles'];
        uasort($styles, fn($a, $b) => $a['priority'] <=> $b['priority']);
        foreach ($styles as $a) $out .= bh_asset_tag('style', $a);

        $scripts = array_filter($reg['scripts'], fn($a) => !empty($a['head']));
        uasort($scripts, fn($a, $b) => $a['priority'] <=> $b['priority']);
        foreach ($scripts as $a) $out .= bh_asset_tag('script', $a);

        // AI agents: origin-trial token, discovery links, WebSite structured data.
        try { $out .= bh_ai()->headMarkup(); } catch (\Throwable) {}

        return $out . bh_hook_output('bh.head');
    }
}

/**
 * Everything that belongs before </body>.
 *
 * Registered scripts, then whatever apps add through `bh.footer` — analytics,
 * chat widgets, anything that should not delay the page.
 */
if (!function_exists('bh_footer')) {
    function bh_footer(): string {
        $out = '';
        $reg = &bh_assets();

        $scripts = array_filter($reg['scripts'], fn($a) => empty($a['head']));
        uasort($scripts, fn($a, $b) => $a['priority'] <=> $b['priority']);
        foreach ($scripts as $a) $out .= bh_asset_tag('script', $a);

        $out .= bh_hook_output('bh.footer');
        // AI agents: WebMCP tools and form annotations. Last, so forms that
        // apps add in bh.footer are annotated too.
        try { $out .= bh_ai()->footerMarkup(); } catch (\Throwable) {}
        return $out;
    }
}

/**
 * Collect markup from every listener on an action.
 *
 * Two ways to contribute, because both are natural and neither is wrong: a
 * listener may echo its markup, or add it with a `bh.head`/`bh.footer` filter
 * and return it. Echoes are captured by buffering around doAction, which
 * already isolates each listener — one app throwing cannot stop the others, and
 * the failure is logged by the registry rather than swallowed here.
 */
if (!function_exists('bh_hook_output')) {
    function bh_hook_output(string $tag, array $args = []): string {
        try {
            $hooks = \App\Core\Application::getInstance()->make(\App\Core\HookRegistry::class);
        } catch (\Throwable) {
            return '';
        }

        $depth = ob_get_level();
        ob_start();
        try {
            $hooks->doAction($tag, ...$args);
            $echoed = (string) ob_get_clean();
        } catch (\Throwable) {
            // doAction guards each listener itself, so reaching here means
            // something outside them failed. Discard the partial buffer rather
            // than emitting half a tag into the page.
            while (ob_get_level() > $depth) ob_end_clean();
            $echoed = '';
        }

        /*
         * An action listener that returns markup instead of echoing it.
         *
         * doAction discards return values, so a listener written as
         * `fn() => '<meta …>'` produced nothing at all — which looks exactly
         * like the hook not firing. Rather than make apps remember which style
         * this hook wants, the same listeners are invoked again through
         * applyFilters, which does collect what they return.
         *
         * A listener that echoes returns null, so it contributes nothing here
         * and is not doubled.
         */
        $returned = '';
        try {
            $v = $hooks->applyFilters($tag, '', ...$args);
            if (is_string($v)) $returned = $v;
        } catch (\Throwable) {}

        return $echoed . $returned;
    }
}

/**
 * Classes describing the current page, for themes to style against.
 *
 *     <body class="<?= bh_body_class('my-theme') ?>">
 *
 * Saves every theme reinventing "am I on the home page" in its own way, and
 * gives an app something stable to target.
 */
if (!function_exists('bh_body_class')) {
    function bh_body_class(string $extra = ''): string {
        $c = [];
        $path = strtok((string) ($_SERVER['REQUEST_URI'] ?? '/'), '?');

        $c[] = ($path === '/' || $path === '') ? 'is-home' : 'is-inner';

        try {
            $themes = \App\Core\Application::getInstance()->make(\App\Services\ThemeService::class);
            $c[] = 'theme-' . preg_replace('/[^a-z0-9-]/', '', strtolower($themes->activeSlug()));
        } catch (\Throwable) {}

        if (bh_is_preview()) $c[] = 'is-customizer-preview';

        $c[] = trim($extra);
        return trim(implode(' ', array_filter(array_unique($c))));
    }
}

/** Is this the Customizer's preview frame? Themes can suppress things in it. */
if (!function_exists('bh_is_preview')) {
    function bh_is_preview(): bool {
        try {
            return \App\Core\Application::getInstance()
                ->make(\App\Services\CustomizerService::class)->isPreview();
        } catch (\Throwable) { return false; }
    }
}

/**
 * A theme option, with the Customizer's pending value inside a preview.
 *
 *     <?= bh_theme_option('accent', '#000') ?>
 *
 * Themes were reaching into the container and building this closure themselves
 * in every partial that needed a setting.
 */
if (!function_exists('bh_theme_option')) {
    function bh_theme_option(string $key, mixed $default = null): mixed {
        try {
            $app = \App\Core\Application::getInstance();
            $slug = $app->make(\App\Services\ThemeService::class)->activeSlug();
            return $app->make(\App\Services\SettingService::class)
                ->get('theme:' . $slug, $key, $default);
        } catch (\Throwable) { return $default; }
    }
}

/**
 * A URL for a file in the active theme's assets directory.
 *
 *     <link rel="stylesheet" href="<?= theme_asset('my-theme.css') ?>">
 *
 * Version-stamped with the core version, because a browser holding the previous
 * stylesheet after an update looks exactly like the update not having worked.
 * Every theme was building this string by hand and most forgot the stamp.
 */
if (!function_exists('theme_asset')) {
    function theme_asset(string $relative, bool $version = true): string {
        try {
            $url = \App\Core\Application::getInstance()
                ->make(\App\Services\ThemeService::class)->assetUrl($relative);
        } catch (\Throwable) {
            $base = defined('BASEHIM_BASE') ? BASEHIM_BASE : '';
            $url = $base . '/content/themes/' . ltrim($relative, '/');
        }
        if ($version && defined('BASEHIM_VERSION')) {
            $url .= (str_contains($url, '?') ? '&' : '?') . 'v=' . urlencode(BASEHIM_VERSION);
        }
        return $url;
    }
}

/** A site setting, for the handful a theme legitimately needs. */
if (!function_exists('bh_setting')) {
    function bh_setting(string $group, string $key, mixed $default = null): mixed {
        try {
            return \App\Core\Application::getInstance()
                ->make(\App\Services\SettingService::class)->get($group, $key, $default);
        } catch (\Throwable) { return $default; }
    }
}

/**
 * Approved comments on a post — the number a visitor should see.
 *
 *   Comments <span data-bh-comment-count="<?= $post['id'] ?>"><?= bh_comment_count($post) ?></span>
 *
 * The `data-bh-comment-count` attribute is optional. With it, the standard
 * comment form updates the number in place when a comment is published
 * straight away.
 */
if (!function_exists('bh_comment_count')) {
    function bh_comment_count(array $post): int {
        static $cache = [];
        $id = (int) ($post['id'] ?? 0);
        if ($id <= 0) return 0;
        if (!isset($cache[$id])) {
            try {
                $cache[$id] = \App\Core\Application::getInstance()
                    ->make(\App\Services\CommentService::class)->approvedCount($id);
            } catch (\Throwable) { $cache[$id] = 0; }
        }
        return $cache[$id];
    }
}

/**
 * The standard comment form.
 *
 *   <?= bh_comment_form($post) ?>
 *
 * One call, anywhere a template has the post. Core supplies everything the
 * comment handler checks — the CSRF token, the post id, the reply target, the
 * honeypot — and the submission script, so a theme cannot get any of them
 * wrong or leave one out.
 *
 * Signed-in members are not asked who they are. The name, email and website
 * fields appear only for guests; a member sees "Commenting as <name>" and the
 * comment is recorded under their account. The server enforces the same rule
 * (CommentController::store), so it does not depend on the form.
 *
 * Returns the "closed" text instead of a form when comments are off for the
 * site or the post, or the post is not published.
 *
 * Replies: any element with `data-bh-reply` and `data-id` / `data-name`
 * attributes turns the form into a reply to that comment when clicked.
 *
 *   <button type="button" data-bh-reply data-id="<?= $c['id'] ?>"
 *           data-name="<?= htmlspecialchars($c['author_name']) ?>">Reply</button>
 *
 * Styling: the form carries `bh-comment-form__*` classes and a small default
 * stylesheet at zero specificity, so any rule a theme writes wins. Pass
 * `'styles' => false` to drop it, and `form_class`, `input_class`,
 * `textarea_class`, `button_class` and friends to put the theme's own classes
 * on each element.
 *
 * Events: the form's wrapper dispatches `bh:comment-posted` (cancelable, with
 * detail {status, pending, message, comment, count, postId}) after a
 * successful submission. When a comment is published straight away the page
 * reloads to show it in the theme's own markup; call preventDefault() on the
 * event to handle it yourself instead.
 *
 * Filters: `comment_form.args` (array $args, array $post) and
 * `comment_form.html` (string $html, array $post, array $args).
 */
if (!function_exists('bh_comment_form')) {
    function bh_comment_form(array $post, array $args = []): string {
        $e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES);

        try {
            $app = \App\Core\Application::getInstance();
            $settings = $app->make(\App\Services\SettingService::class);
            $hooks = $app->make(\App\Core\HookRegistry::class);
        } catch (\Throwable) {
            return '';
        }

        $defaults = [
            'id'               => 'comment-form',
            'title'            => 'Leave a comment',
            'title_tag'        => 'h3',
            'label_name'       => 'Name',
            'label_email'      => 'Email',
            'label_url'        => 'Website',
            'label_comment'    => 'Comment',
            'placeholder'      => 'Share your thoughts…',
            'label_submit'     => 'Post comment',
            'label_submitting' => 'Posting…',
            'label_reply'      => 'Replying to',
            'label_cancel'     => 'Cancel',
            'logged_in_text'   => 'Commenting as %s.',
            'show_logout'      => false,
            'label_logout'     => 'Log out',
            'closed_text'      => 'Comments are closed.',
            'show_url'         => true,
            'rows'             => 4,
            'class'            => '',
            'title_class'      => '',
            'form_class'       => '',
            'field_class'      => '',
            'label_class'      => '',
            'input_class'      => '',
            'textarea_class'   => '',
            'button_class'     => '',
            'styles'           => true,
            'reload_on_publish'=> true,
            'show_avatar'      => true,
            'avatar_size'      => 32,
        ];
        $args = array_merge($defaults, $args);
        try {
            $filtered = $hooks->applyFilters('comment_form.args', $args, $post);
            if (is_array($filtered)) $args = array_merge($defaults, $filtered);
        } catch (\Throwable) {}

        $postId = (int) ($post['id'] ?? 0);
        $open = $postId > 0
            && ($post['status'] ?? '') === 'published'
            && ($post['comment_status'] ?? 'closed') === 'open'
            && (bool) $settings->get('discussion', 'allow_comments', true);
        if (!$open) {
            return $args['closed_text'] === '' || $args['closed_text'] === null
                ? ''
                : '<p class="bh-comments-closed">' . $e($args['closed_text']) . '</p>';
        }

        // Who is commenting — the same rule the handler applies.
        $user = null;
        try {
            $user = $app->make(\App\Services\AuthService::class)->currentUser();
            if ($user && ($user['status'] ?? '') !== 'active') $user = null;
        } catch (\Throwable) {}
        $userName = $user ? (trim((string) ($user['display_name'] ?? '')) ?: (string) ($user['username'] ?? '')) : '';

        try {
            $csrf = $app->make(\App\Core\Session::class)->csrfToken();
        } catch (\Throwable) { $csrf = ''; }

        $base     = defined('BASEHIM_BASE') ? rtrim((string) BASEHIM_BASE, '/') : '';
        $required = (bool) $settings->get('discussion', 'require_email', true);
        $id       = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $args['id']) ?: 'comment-form';
        $tag      = in_array($args['title_tag'], ['h2', 'h3', 'h4', 'h5', 'h6', 'p', 'div'], true) ? $args['title_tag'] : 'h3';
        $cls      = static fn(string $core, $extra): string => trim($core . ' ' . (string) $extra);

        // What each field is for, for AI agents that fill the form in (WebMCP).
        $agentHelp = [
            'name'  => 'Your name, shown with the comment.',
            'email' => 'Your email address. It is not published.',
            'url'   => 'Your website address, if you want your name to link to it. Optional.',
        ];
        $field = function (string $name, string $type, string $label, string $auto, bool $req) use ($args, $e, $id, $cls, $agentHelp): string {
            $fid = $id . '-' . $name;
            return '<p class="' . $e($cls('bh-comment-form__field bh-comment-form__field--' . $name, $args['field_class'])) . '">'
                 . '<label for="' . $e($fid) . '" class="' . $e($cls('bh-comment-form__label', $args['label_class'])) . '">'
                 . $e($label) . ($req ? ' <span class="bh-comment-form__req" aria-hidden="true">*</span>' : '') . '</label>'
                 . '<input id="' . $e($fid) . '" type="' . $type . '" name="author_' . $name . '" autocomplete="' . $auto . '"'
                 . ($req ? ' required' : '') . bh_webmcp_param($agentHelp[$name] ?? $label)
                 . ' class="' . $e($cls('bh-comment-form__input', $args['input_class'])) . '">'
                 . '</p>';
        };

        $h  = '<div class="' . $e($cls('bh-comment-form', $args['class'])) . '" id="' . $e($id) . '" data-bh-comment-form'
            . ($args['reload_on_publish'] ? ' data-bh-reload' : '') . '>';
        if ((string) $args['title'] !== '') {
            $h .= '<' . $tag . ' class="' . $e($cls('bh-comment-form__title', $args['title_class'])) . '">' . $e($args['title']) . '</' . $tag . '>';
        }
        $h .= '<div class="bh-comment-form__reply" data-bh-reply-notice hidden>'
            . '<span>' . $e($args['label_reply']) . ' <strong data-bh-reply-name></strong></span>'
            . '<button type="button" class="bh-comment-form__cancel" data-bh-reply-cancel>' . $e($args['label_cancel']) . '</button>'
            . '</div>';
        $h .= '<div class="bh-comment-form__status" data-bh-comment-status role="status" aria-live="polite" hidden></div>';
        $h .= '<form method="post" action="' . $e($base . '/comments') . '" class="' . $e($cls('bh-comment-form__form', $args['form_class'])) . '"'
            . ' data-bh-comment-form-el data-label-busy="' . $e($args['label_submitting']) . '"'
            . (function_exists('bh_ai_on') && bh_ai_on('webmcp_comments')
                ? bh_webmcp_form('post_comment', 'Post a comment on "' . html_entity_decode((string) ($post['title'] ?? 'this article'), ENT_QUOTES) . '". Comments may be held for moderation. The visitor reviews the comment and sends it.')
                : '')
            . '>';
        $h .= '<input type="hidden" name="_csrf" value="' . $e($csrf) . '">'
            . '<input type="hidden" name="post_id" value="' . $postId . '">'
            . '<input type="hidden" name="redirect_to" value="' . $e($base . \App\Core\Helpers::postUrl($post)) . '">'
            . '<input type="hidden" name="parent_id" value="" data-bh-parent>';
        // Hidden from people; a bot that fills it is silently dropped by CommentService::guard().
        $h .= '<div aria-hidden="true" style="position:absolute;left:-9999px;top:auto;width:1px;height:1px;overflow:hidden;">'
            . '<label>Leave this field empty<input type="text" name="hp_comment_field" tabindex="-1" autocomplete="off" value=""'
            . bh_webmcp_param('Anti-spam check that must stay empty. Never put anything in this field.') . '></label></div>';

        if ($user) {
            // The commenter's current profile photo beside "Commenting as …".
            $face = '';
            if ($args['show_avatar'] && function_exists('bh_avatar')) {
                $thumb = null;
                try {
                    $av = $app->make(\App\Services\AvatarService::class)->forUser($user);
                    $thumb = $av['thumbnail_url'] ?? null;
                } catch (\Throwable) {}
                $face = bh_avatar(['display_name' => $userName, 'avatar_thumbnail_url' => $thumb, 'avatar_url' => $thumb],
                                  max(16, min(96, (int) $args['avatar_size'])), ['class' => 'bh-comment-form__avatar']);
            }
            $h .= '<p class="bh-comment-form__identity"' . ($face !== '' ? ' style="display:flex;align-items:center;gap:.6rem"' : '') . '>'
                . $face . ($face !== '' ? '<span>' : '')
                . str_replace('%s', '<strong>' . $e($userName) . '</strong>', $e($args['logged_in_text']));
            if ($args['show_logout']) {
                $h .= ' <a href="' . $e($base . '/admin/logout') . '">' . $e($args['label_logout']) . '</a>';
            }
            $h .= ($face !== '' ? '</span>' : '') . '</p>';
        } else {
            $h .= '<div class="bh-comment-form__fields">'
                . $field('name', 'text', (string) $args['label_name'], 'name', $required)
                . $field('email', 'email', (string) $args['label_email'], 'email', $required)
                . '</div>';
            if ($args['show_url']) {
                $h .= $field('url', 'url', (string) $args['label_url'], 'url', false);
            }
        }

        $h .= '<p class="' . $e($cls('bh-comment-form__field bh-comment-form__field--comment', $args['field_class'])) . '">'
            . '<label for="' . $e($id . '-content') . '" class="' . $e($cls('bh-comment-form__label', $args['label_class'])) . '">'
            . $e($args['label_comment']) . ' <span class="bh-comment-form__req" aria-hidden="true">*</span></label>'
            . '<textarea id="' . $e($id . '-content') . '" name="content" rows="' . max(2, (int) $args['rows']) . '" required'
            . ' placeholder="' . $e($args['placeholder']) . '"' . bh_webmcp_param('The text of the comment.')
            . ' class="' . $e($cls('bh-comment-form__textarea', $args['textarea_class'])) . '"></textarea>'
            . '</p>';
        $h .= '<p class="bh-comment-form__actions"><button type="submit" class="' . $e($cls('bh-comment-form__submit', $args['button_class'])) . '"'
            . ' data-bh-comment-submit>' . $e($args['label_submit']) . '</button></p>';
        $h .= '</form></div>';

        $h .= bh_comment_form_assets((bool) $args['styles']);

        try {
            $out = $hooks->applyFilters('comment_form.html', $h, $post, $args);
            if (is_string($out)) $h = $out;
        } catch (\Throwable) {}
        return $h;
    }
}

/**
 * The comment form's stylesheet and script, once per page. Called by
 * bh_comment_form(); a theme never needs to call it.
 */
if (!function_exists('bh_comment_form_assets')) {
    function bh_comment_form_assets(bool $styles = true): string {
        static $scriptDone = false, $styleDone = false;
        $out = '';

        if ($styles && !$styleDone) {
            $styleDone = true;
            // :where() keeps every rule at zero specificity: a theme's own
            // class on the same element always wins.
            $out .= '<style id="bh-comment-form-css">'
                . ':where(.bh-comment-form [hidden]){display:none!important}'
                . ':where(.bh-comment-form__form){display:grid;gap:.9rem;margin:0}'
                . ':where(.bh-comment-form__title){margin:0 0 .9rem}'
                . ':where(.bh-comment-form__fields){display:grid;gap:.9rem;grid-template-columns:repeat(auto-fit,minmax(14rem,1fr))}'
                . ':where(.bh-comment-form__field){display:grid;gap:.35rem;margin:0}'
                . ':where(.bh-comment-form__label){font-size:.85em;font-weight:600}'
                . ':where(.bh-comment-form__req){opacity:.6}'
                . ':where(.bh-comment-form__input,.bh-comment-form__textarea){box-sizing:border-box;width:100%;font:inherit;color:inherit;'
                .   'background:transparent;border:1px solid rgba(127,127,127,.45);border-radius:.5rem;padding:.6em .75em}'
                . ':where(.bh-comment-form__textarea){resize:vertical;min-height:6rem}'
                . ':where(.bh-comment-form__identity){margin:0;font-size:.9em;opacity:.85}'
                . ':where(.bh-comment-form__actions){margin:0}'
                . ':where(.bh-comment-form__submit){font:inherit;font-weight:600;cursor:pointer;border:0;border-radius:.5rem;'
                .   'padding:.65em 1.2em;color:#fff;background:var(--bh-accent,#2563eb)}'
                . ':where(.bh-comment-form__submit:disabled){opacity:.6;cursor:progress}'
                . ':where(.bh-comment-form__reply,.bh-comment-form__status){display:flex;align-items:center;justify-content:space-between;'
                .   'gap:.75rem;margin:0 0 .9rem;padding:.6em .85em;border-radius:.5rem;font-size:.9em;border:1px solid rgba(127,127,127,.3)}'
                . ':where(.bh-comment-form__cancel){font:inherit;background:none;border:0;padding:0;cursor:pointer;text-decoration:underline}'
                . ':where(.bh-comment-form__status--success){background:rgba(22,163,74,.1);border-color:rgba(22,163,74,.35)}'
                . ':where(.bh-comment-form__status--pending){background:rgba(37,99,235,.1);border-color:rgba(37,99,235,.35)}'
                . ':where(.bh-comment-form__status--error){background:rgba(220,38,38,.1);border-color:rgba(220,38,38,.35)}'
                . '</style>';
        }

        if (!$scriptDone) {
            $scriptDone = true;
            $out .= <<<'JS'
<script id="bh-comment-form-js">
(function () {
 if (window.__bhCommentForm) return; window.__bhCommentForm = true;
 function wrapOf(el) { return el && el.closest('[data-bh-comment-form]'); }
 function status(wrap, type, msg) {
  var box = wrap.querySelector('[data-bh-comment-status]'); if (!box) return;
  box.className = 'bh-comment-form__status bh-comment-form__status--' + type;
  box.textContent = msg; box.hidden = false;
 }
 function clearReply(wrap) {
  var p = wrap.querySelector('[data-bh-parent]'); if (p) p.value = '';
  var n = wrap.querySelector('[data-bh-reply-notice]'); if (n) n.hidden = true;
 }
 document.addEventListener('click', function (e) {
  var cancel = e.target.closest('[data-bh-reply-cancel]');
  if (cancel) { var w0 = wrapOf(cancel); if (w0) clearReply(w0); return; }
  var btn = e.target.closest('[data-bh-reply]'); if (!btn) return;
  var target = btn.getAttribute('data-bh-reply-form');
  var wrap = target ? document.getElementById(target) : document.querySelector('[data-bh-comment-form]');
  if (!wrap) return;
  e.preventDefault();
  var p = wrap.querySelector('[data-bh-parent]'); if (p) p.value = btn.getAttribute('data-id') || '';
  var name = wrap.querySelector('[data-bh-reply-name]'); if (name) name.textContent = btn.getAttribute('data-name') || '';
  var n = wrap.querySelector('[data-bh-reply-notice]'); if (n) n.hidden = false;
  wrap.scrollIntoView({ behavior: 'smooth', block: 'center' });
  var ta = wrap.querySelector('textarea[name="content"]'); if (ta) ta.focus({ preventScroll: true });
 });
 document.addEventListener('submit', function (e) {
  var form = e.target;
  if (!form.matches || !form.matches('[data-bh-comment-form-el]') || !window.fetch || !window.FormData) return;
  e.preventDefault();
  var wrap = wrapOf(form); var btn = form.querySelector('[data-bh-comment-submit]');
  var idle = btn ? btn.textContent : '';
  if (btn) { btn.disabled = true; btn.textContent = form.getAttribute('data-label-busy') || idle; }
  fetch(form.action, { method: 'POST', body: new FormData(form), credentials: 'same-origin',
                       headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
   .then(function (r) { return r.json().catch(function () { return {}; }).then(function (d) { return { ok: r.ok, d: d }; }); })
   .then(function (res) {
    var d = res.d || {};
    if (!res.ok || d.success === false) { status(wrap, 'error', d.error || d.message || 'Your comment could not be posted. Please try again.'); return; }
    status(wrap, d.pending ? 'pending' : 'success', d.message || (d.pending ? 'Your comment is awaiting moderation.' : 'Comment posted.'));
    var postId = (form.querySelector('[name="post_id"]') || {}).value || '';
    if (typeof d.count === 'number') {
     document.querySelectorAll('[data-bh-comment-count]').forEach(function (el) {
      var for_ = el.getAttribute('data-bh-comment-count');
      if (for_ === '' || for_ === postId) el.textContent = String(d.count);
     });
    }
    var content = form.querySelector('textarea[name="content"]'); if (content) content.value = '';
    clearReply(wrap);
    var ev = new CustomEvent('bh:comment-posted', { bubbles: true, cancelable: true,
      detail: { status: d.status, pending: !!d.pending, message: d.message, comment: d.comment || null, count: d.count, postId: postId } });
    var go = wrap.dispatchEvent(ev);
    if (go && !d.pending && d.comment && d.comment.id && wrap.hasAttribute('data-bh-reload')) {
     setTimeout(function () { location.hash = 'comment-' + d.comment.id; location.reload(); }, 900);
    }
   })
   .catch(function () { status(wrap, 'error', 'Network error. Please try again.'); })
   .then(function () { if (btn) { btn.disabled = false; btn.textContent = idle; } });
 });
})();
</script>
JS;
        }
        return $out;
    }
}

/**
 * The public profile of a post's author (or of a user row): display name, bio,
 * public slug, archive URL, avatar URL, published-post count. Never the email,
 * role or login name. Null when there is no such author.
 */
if (!function_exists('bh_author')) {
    function bh_author(array $postOrUser): ?array {
        static $cache = [];
        // A post names its author in author_id; a user row or a profile from
        // bh_author() is identified by id (and has no post title).
        if (isset($postOrUser['author_id'])) {
            $id = (int) $postOrUser['author_id'];
        } elseif (!isset($postOrUser['title']) && (isset($postOrUser['username']) || isset($postOrUser['display_name']))) {
            $id = (int) ($postOrUser['id'] ?? 0);
        } else {
            $id = 0;
        }
        if ($id <= 0) return null;
        if (!array_key_exists($id, $cache)) {
            try {
                $svc  = \App\Core\Application::getInstance()->make(\App\Services\AuthorService::class);
                $user = $svc->find($id);
                $cache[$id] = $user ? $svc->publicProfile($user) : null;
            } catch (\Throwable) { $cache[$id] = null; }
        }
        return $cache[$id];
    }
}

/**
 * Link to an author's archive, /author/{slug}: for a post, its author.
 * Empty when author archives are switched off or the author has no published
 * posts — so a theme can write:
 *
 *   <?php if ($u = bh_author_url($post)): ?><a href="<?= $base . $u ?>">…</a><?php endif; ?>
 */
if (!function_exists('bh_author_url')) {
    function bh_author_url(array $postOrUser): string {
        $a = bh_author($postOrUser);
        return $a ? (string) $a['url'] : '';
    }
}

/**
 * The author box for a single post:
 *
 *   <?= bh_author_box($post) ?>
 *
 * Avatar (the uploaded one, else initials), name linked to the author's
 * archive, bio, and a link to their other posts. Returns '' when Settings →
 * Reading → Author box is off, or the post has no author.
 *
 * Styling works like bh_comment_form(): `bh-author-box__*` classes, a small
 * default stylesheet at zero specificity, `'styles' => false` to drop it, and
 * `class`, `avatar_class`, `name_class`, `bio_class`, `link_class` for the
 * theme's own. Other arguments: `title` (e.g. 'About the author'; empty by
 * default), `title_tag`, `show_bio`, `show_link`, `link_text` (%d is the post
 * count), `avatar_size` (px). Filter: `author_box.html` ($html, $author, $post).
 */
/**
 * An author's profile photo as <img>, or their initials when they have none.
 *
 * Takes a post (every core post query provides `author_avatar_url` and
 * `author_name`), a user row, or a profile from bh_author():
 *
 *     <?= bh_avatar($post, 28) ?>
 *     <?= bh_avatar($post, 40, ['class' => 'ring-2 ring-white', 'alt' => $post['author_name']]) ?>
 *
 * Args: class, alt (default '' — the name is usually printed beside it),
 * styles (false to skip the small default stylesheet).
 */
if (!function_exists('bh_avatar')) {
    function bh_avatar(array $subject, int $size = 32, array $args = []): string {
        $e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES);
        $size = max(12, min(256, $size));
        $isPost = isset($subject['author_id']) || isset($subject['title']);
        $name = (string) ($isPost ? ($subject['author_name'] ?? '') : ($subject['display_name'] ?? ''));
        if ($isPost) {
            $src = $subject['author_avatar_url'] ?? null;
            // A post from somewhere other than core's queries: ask once.
            if (!array_key_exists('author_avatar_url', $subject) && function_exists('bh_author') && ($a = bh_author($subject))) {
                $src = $size <= 75 ? ($a['avatar_thumbnail_url'] ?? $a['avatar_url']) : $a['avatar_url'];
                if ($name === '') $name = (string) $a['display_name'];
            }
        } else {
            $src = $size <= 75 ? ($subject['avatar_thumbnail_url'] ?? $subject['avatar_url'] ?? null) : ($subject['avatar_url'] ?? null);
        }

        $class = trim('bh-avatar ' . (string) ($args['class'] ?? ''));
        $css = '';
        static $styled = false;
        if (($args['styles'] ?? true) && !$styled) {
            $styled = true;
            $css = '<style id="bh-avatar-css">'
                 . ':where(.bh-avatar){display:inline-block;flex:0 0 auto;border-radius:50%;object-fit:cover;vertical-align:middle}'
                 . ':where(.bh-avatar--initials){display:inline-grid;place-items:center;font-weight:700;line-height:1;color:#fff;background:var(--bh-accent,#2563eb)}'
                 . '</style>';
        }
        if ($src) {
            return $css . '<img src="' . $e($src) . '" alt="' . $e($args['alt'] ?? '') . '" width="' . $size . '" height="' . $size
                 . '" loading="lazy" decoding="async" class="' . $e($class) . '" style="width:' . $size . 'px;height:' . $size . 'px;max-width:none;flex:none;object-fit:cover;border-radius:50%">';
        }
        $initials = '';
        foreach (preg_split('/\s+/u', trim($name)) ?: [] as $w) {
            if ($w !== '' && mb_strlen($initials) < 2) $initials .= mb_strtoupper(mb_substr($w, 0, 1));
        }
        return $css . '<span class="' . $e($class . ' bh-avatar--initials') . '" aria-hidden="true" style="width:' . $size . 'px;height:' . $size
             . 'px;font-size:' . round($size * 0.4) . 'px;max-width:none;flex:none">' . $e($initials ?: '?') . '</span>';
    }
}

/**
 * A user's photo for admin screens, or their initial when they have none,
 * marked with data-user-avatar="{id}" so avatar-editor.js can swap every copy
 * on the page the moment the photo changes.
 *
 *     <?= bh_user_avatar($user, 'w-8 h-8 text-sm') ?>
 *
 * $thumb: the photo's URL when already known ('' for none); null looks it up.
 */
if (!function_exists('bh_user_avatar')) {
    function bh_user_avatar(array $user, string $sizeClass = 'w-8 h-8 text-sm', ?string $thumb = null): string {
        $e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES);
        if ($thumb === null) {
            try {
                $av = \App\Core\Application::getInstance()->make(\App\Services\AvatarService::class)->forUser($user);
                $thumb = (string) ($av['thumbnail_url'] ?? '');
            } catch (\Throwable) {
                $thumb = '';
            }
        }
        $name = trim((string) ($user['display_name'] ?? '')) ?: (string) ($user['username'] ?? 'U');
        $initial = mb_strtoupper(mb_substr($name, 0, 1)) ?: 'U';
        $attrs = ' data-user-avatar="' . (int) ($user['id'] ?? 0) . '" data-avatar-class="' . $e($sizeClass) . '" data-initial="' . $e($initial) . '"';
        if ($thumb !== '') {
            return '<img src="' . $e($thumb) . '" alt="" class="' . $e('rounded-full object-cover shrink-0 ' . $sizeClass) . '"' . $attrs . '>';
        }
        return '<span class="' . $e('rounded-full bg-gradient-to-br from-blue-400 to-blue-600 grid place-items-center text-white font-semibold shrink-0 ' . $sizeClass) . '"'
             . $attrs . '>' . $e($initial) . '</span>';
    }
}

if (!function_exists('bh_author_box')) {
    function bh_author_box(array $post, array $args = []): string {
        $e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES);
        try {
            $app = \App\Core\Application::getInstance();
            if (!$app->make(\App\Services\AuthorService::class)->boxEnabled()) return '';
        } catch (\Throwable) { return ''; }

        $a = bh_author($post);
        if (!$a) return '';

        $args = array_merge([
            'title'        => '',
            'title_tag'    => 'h2',
            'show_bio'     => true,
            'show_link'    => true,
            'link_text'    => 'View all %d posts',
            'avatar_size'  => 64,
            'class'        => '',
            'avatar_class' => '',
            'name_class'   => '',
            'bio_class'    => '',
            'link_class'   => '',
            'styles'       => true,
        ], $args);

        $base = defined('BASEHIM_BASE') ? rtrim((string) BASEHIM_BASE, '/') : '';
        $cls  = static fn(string $core, $extra): string => trim($core . ' ' . (string) $extra);
        $size = max(24, min(256, (int) $args['avatar_size']));
        $name = (string) $a['display_name'];
        $url  = $a['url'] !== '' ? $base . $a['url'] : '';
        $tag  = in_array($args['title_tag'], ['h2', 'h3', 'h4', 'h5', 'h6', 'p', 'div'], true) ? $args['title_tag'] : 'h2';

        // Initials: first letter of the first two words.
        $initials = '';
        foreach (preg_split('/\s+/u', trim($name)) ?: [] as $w) {
            if ($w !== '' && mb_strlen($initials) < 2) $initials .= mb_strtoupper(mb_substr($w, 0, 1));
        }

        $avSrc  = ($size <= 75 && !empty($a['avatar_thumbnail_url'])) ? $a['avatar_thumbnail_url'] : $a['avatar_url'];
        // Inline, so a theme's `img { max-width:100%; height:auto }` (Tailwind's
        // reset, for one) cannot shrink the photo when a long bio squeezes the row.
        $fix    = 'width:' . $size . 'px;height:' . $size . 'px;max-width:none;flex:none;object-fit:cover;border-radius:50%';
        $avatar = $avSrc
            ? '<img src="' . $e($avSrc) . '" alt="" width="' . $size . '" height="' . $size . '" loading="lazy" class="' . $e($cls('bh-author-box__avatar', $args['avatar_class'])) . '" style="' . $fix . '">'
            : '<span class="' . $e($cls('bh-author-box__avatar bh-author-box__avatar--initials', $args['avatar_class'])) . '" aria-hidden="true" style="' . $fix . '">' . $e($initials ?: '?') . '</span>';

        $h  = '<aside class="' . $e($cls('bh-author-box', $args['class'])) . '" style="--bh-author-avatar:' . $size . 'px">';
        if ((string) $args['title'] !== '') {
            $h .= '<' . $tag . ' class="bh-author-box__title">' . $e($args['title']) . '</' . $tag . '>';
        }
        $h .= '<div class="bh-author-box__inner">' . ($url ? '<a href="' . $e($url) . '" tabindex="-1" aria-hidden="true" style="flex:none;display:block;line-height:0">' . $avatar . '</a>' : $avatar);
        $h .= '<div class="bh-author-box__body">';
        $nameHtml = $url ? '<a href="' . $e($url) . '" rel="author">' . $e($name) . '</a>' : $e($name);
        $h .= '<p class="' . $e($cls('bh-author-box__name', $args['name_class'])) . '">' . $nameHtml . '</p>';
        if ($args['show_bio'] && trim($a['bio']) !== '') {
            $h .= '<p class="' . $e($cls('bh-author-box__bio', $args['bio_class'])) . '">' . nl2br($e(trim($a['bio']))) . '</p>';
        }
        if ($args['show_link'] && $url) {
            $h .= '<a class="' . $e($cls('bh-author-box__link', $args['link_class'])) . '" href="' . $e($url) . '">'
                . $e(str_replace('%d', (string) $a['post_count'], (string) $args['link_text'])) . '</a>';
        }
        $h .= '</div></div></aside>';

        static $styled = false;
        if ($args['styles'] && !$styled) {
            $styled = true;
            $h .= '<style id="bh-author-box-css">'
                . ':where(.bh-author-box){margin:2.5rem 0;padding:1.25rem;border:1px solid rgba(127,127,127,.25);border-radius:1rem}'
                . ':where(.bh-author-box__title){margin:0 0 .85rem;font-size:.8em;text-transform:uppercase;letter-spacing:.06em;opacity:.7}'
                . ':where(.bh-author-box__inner){display:flex;gap:1rem;align-items:flex-start}'
                . ':where(.bh-author-box__avatar){flex:0 0 auto;width:var(--bh-author-avatar,64px);height:var(--bh-author-avatar,64px);border-radius:50%;object-fit:cover;display:block}'
                . ':where(.bh-author-box__avatar--initials){display:grid;place-items:center;font-weight:700;font-size:calc(var(--bh-author-avatar,64px) * .36);color:#fff;background:var(--bh-accent,#2563eb)}'
                . ':where(.bh-author-box__body){min-width:0}'
                . ':where(.bh-author-box__name){margin:0;font-weight:700}'
                . ':where(.bh-author-box__name a){color:inherit;text-decoration:none}'
                . ':where(.bh-author-box__bio){margin:.35rem 0 0;opacity:.85}'
                . ':where(.bh-author-box__link){display:inline-block;margin-top:.6rem;font-size:.9em}'
                . '</style>';
        }

        try {
            $out = $app->make(\App\Core\HookRegistry::class)->applyFilters('author_box.html', $h, $a, $post);
            if (is_string($out)) $h = $out;
        } catch (\Throwable) {}
        return $h;
    }
}

/**
 * Items for a menu location, for themes that declare their own.
 *
 * Core passes `$primary_menu` and `$footer_menu` into every template, which
 * covers a simple theme. A theme that declares five locations in theme.json
 * had no way to reach the other three — the variables simply did not exist,
 * and the menus rendered empty with nothing to say why.
 *
 *   <?= menu_html(menu_at('utility')) ?>
 *
 * Returns an empty array for a location with no menu assigned, so a template
 * can call it unconditionally.
 */
if (!function_exists('menu_at')) {
    function menu_at(string $location): array {
        try {
            return \App\Core\Application::getInstance()
                ->make(\App\Services\MenuService::class)
                ->itemsByLocation($location) ?: [];
        } catch (\Throwable) {
            return [];
        }
    }
}

/**
 * Render a menu as nested lists, ready for a dropdown.
 *
 * MenuService already returns a tree — each item may carry a `children` array —
 * but every bundled theme looped it flat and never looked at `children`. The
 * consequence was worse than a missing dropdown: nesting an item in the admin
 * made it vanish from the site altogether, because the flat loop only ever saw
 * top-level rows. A page could silently stop being reachable.
 *
 * This exists so that logic lives in one place. A theme that wants its own
 * markup can still walk the tree itself; a theme that just wants a working
 * dropdown calls this.
 *
 *   <?= menu_html($primary_menu, ['class' => 'bh-menu']) ?>
 *
 * Options:
 *   class     wrapper <ul> class            (default 'bh-menu')
 *   depth     how many levels to render      (default 3, hard maximum 3)
 *   aria      aria-label on the wrapper
 *   icons     render item icons when present (default true)
 *
 * Beyond `depth`, deeper items are lifted to the last rendered level rather
 * than dropped. Hiding them would repeat the bug this function exists to fix.
 */
if (!function_exists('menu_html')) {
    function menu_html(array $items, array $opts = []): string {
        if (!$items) return '';

        $maxDepth = max(1, min(3, (int) ($opts['depth'] ?? 3)));
        $icons    = $opts['icons'] ?? true;
        $esc      = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

        $render = function (array $nodes, int $level) use (&$render, $maxDepth, $icons, $esc, $opts): string {
            $isRoot = $level === 1;
            $ulClass = $isRoot
                ? ($opts['class'] ?? 'bh-menu')
                : 'bh-submenu bh-submenu--' . $level;

            $html = '<ul class="' . $esc($ulClass) . '"'
                  . ($isRoot && !empty($opts['aria']) ? ' aria-label="' . $esc($opts['aria']) . '"' : '')
                  . '>';

            /*
             * At the deepest level we render, descendants are lifted to sit
             * beside their parent rather than dropped — hiding them would
             * repeat the very bug this function exists to fix.
             *
             * This is done before the loop, building a new list. An earlier
             * version appended to $nodes from inside its own foreach, and PHP
             * iterates the array as it was at the start: the appended items
             * were never visited and vanished silently.
             */
            if ($level >= $maxDepth) {
                $flattened = [];
                $collect = function (array $ns) use (&$collect, &$flattened) {
                    foreach ($ns as $n) {
                        $kids = $n['children'] ?? [];
                        unset($n['children']);
                        $flattened[] = $n;
                        if ($kids) $collect($kids);
                    }
                };
                $collect($nodes);
                $nodes = $flattened;
            }

            foreach ($nodes as $item) {
                $children = $item['children'] ?? [];
                $hasKids = !empty($children);
                $url = function_exists('link_to') ? link_to($item['url'] ?? '#') : ($item['url'] ?? '#');

                $liClass = 'bh-menu__item' . ($hasKids ? ' bh-menu__item--parent' : '');
                if (!empty($item['classes'])) $liClass .= ' ' . $item['classes'];

                $html .= '<li class="' . $esc($liClass) . '">';
                $html .= '<a class="bh-menu__link" href="' . $esc($url) . '"'
                       . ' target="' . $esc($item['target'] ?? '_self') . '"'
                       . ($hasKids ? ' aria-haspopup="true" aria-expanded="false"' : '')
                       . '>';

                if ($icons && !empty($item['icon']) && function_exists('icon')) {
                    $html .= icon((string) $item['icon'], 'bh-menu__icon');
                }
                $html .= '<span>' . $esc($item['title'] ?? '') . '</span>';

                if ($hasKids) {
                    // A caret marks the parent. aria-hidden because the state is
                    // already announced by aria-expanded on the link.
                    $html .= '<svg class="bh-menu__caret" width="12" height="12" viewBox="0 0 24 24"'
                           . ' fill="none" stroke="currentColor" stroke-width="2.5"'
                           . ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
                           . '<path d="m6 9 6 6 6-6"/></svg>';
                }
                $html .= '</a>';

                if ($hasKids) $html .= $render($children, $level + 1);
                $html .= '</li>';
            }

            return $html . '</ul>';
        };

        return $render(array_values($items), 1);
    }
}

/** True when any item in a menu tree has children — for themes that branch on it. */
if (!function_exists('menu_has_children')) {
    function menu_has_children(array $items): bool {
        foreach ($items as $i) {
            if (!empty($i['children'])) return true;
        }
        return false;
    }
}

/**
 * The stylesheet and behaviour a menu_html() dropdown needs.
 *
 * Inlined rather than served as a file. A theme's assets are plain files under
 * its own directory, so a shared stylesheet would mean either copying it into
 * every theme — where it immediately drifts — or adding a route and cache
 * headers for two kilobytes. Inlining costs one small block in the <head> and
 * means a theme gets working dropdowns by calling one function.
 *
 * Deliberately unopinionated about colour and font: it positions, sizes and
 * animates, and inherits everything else from the theme. Call it once, in the
 * <head>, before the menu is rendered.
 */
if (!function_exists('menu_assets')) {
    function menu_assets(): string {
        static $done = false;
        if ($done) return '';          // once per request, however many menus
        $done = true;

        $css = <<<'CSS'
.bh-menu,.bh-submenu{list-style:none;margin:0;padding:0}
.bh-menu{display:flex;align-items:center;gap:.25rem}
.bh-menu__item{position:relative}
.bh-menu__link{display:inline-flex;align-items:center;gap:.35rem;white-space:nowrap;color:inherit;text-decoration:none}
.bh-menu__caret{flex-shrink:0;transition:transform .18s ease;opacity:.65}
.bh-menu__item--parent:hover>.bh-menu__link>.bh-menu__caret,
.bh-menu__item--parent.is-open>.bh-menu__link>.bh-menu__caret{transform:rotate(180deg)}
.bh-submenu{position:absolute;top:100%;left:0;z-index:60;min-width:12rem;padding:.35rem;
 background:#fff;border:1px solid rgba(15,23,42,.1);border-radius:.6rem;
 box-shadow:0 10px 30px rgba(15,23,42,.12);
 opacity:0;visibility:hidden;transform:translateY(.35rem);
 transition:opacity .16s ease,transform .16s ease,visibility .16s}
/* Third level opens sideways; a second stacked dropdown would run off-screen. */
.bh-submenu .bh-submenu{top:-.35rem;left:100%;transform:translateX(.35rem)}
.bh-menu__item:hover>.bh-submenu,
.bh-menu__item:focus-within>.bh-submenu,
.bh-menu__item.is-open>.bh-submenu{opacity:1;visibility:visible;transform:none}
.bh-submenu .bh-menu__item{display:block}
.bh-submenu .bh-menu__link{display:flex;width:100%;padding:.45rem .6rem;border-radius:.4rem;font-size:.875rem}
.bh-submenu .bh-menu__link:hover,.bh-submenu .bh-menu__link:focus-visible{background:rgba(15,23,42,.06)}
.bh-submenu .bh-menu__caret{margin-left:auto;transform:rotate(-90deg)}
.bh-submenu .bh-menu__item--parent:hover>.bh-menu__link>.bh-menu__caret{transform:rotate(-90deg)}
/* Opens leftward when it would otherwise leave the viewport. */
.bh-submenu.bh-submenu--flip{left:auto;right:0}
.bh-submenu .bh-submenu.bh-submenu--flip{left:auto;right:100%}
.bh-menu__icon{width:1rem;height:1rem;flex-shrink:0}
/* Stacked on small screens: a hover dropdown is unusable without a pointer. */
.bh-menu--stack{display:block}
.bh-menu--stack .bh-submenu{position:static;opacity:1;visibility:visible;transform:none;
 border:0;box-shadow:none;padding:0 0 0 .9rem;min-width:0;background:transparent;
 display:none}
.bh-menu--stack .bh-menu__item.is-open>.bh-submenu{display:block}
.bh-menu--stack .bh-menu__link{padding:.5rem 0}
.bh-menu--stack .bh-menu__caret{margin-left:auto;transform:none}
.bh-menu--stack .bh-menu__item.is-open>.bh-menu__link>.bh-menu__caret{transform:rotate(180deg)}
@media (prefers-reduced-motion:reduce){.bh-submenu,.bh-menu__caret{transition:none}}
CSS;

        $js = <<<'JS'
(function(){
 var OPEN='is-open';
 function parentsOf(el){var o=[];while(el&&el.classList){if(el.classList.contains('bh-menu__item'))o.push(el);el=el.parentElement;}return o;}
 function closeAll(except){
  document.querySelectorAll('.bh-menu__item.'+OPEN).forEach(function(li){
   if(except&&except.indexOf(li)!==-1)return;
   li.classList.remove(OPEN);
   var a=li.querySelector(':scope > .bh-menu__link');
   if(a)a.setAttribute('aria-expanded','false');
  });
 }
 function toggle(li,on){
  li.classList.toggle(OPEN,on);
  var a=li.querySelector(':scope > .bh-menu__link');
  if(a)a.setAttribute('aria-expanded',on?'true':'false');
  if(on)flip(li);
 }
 /* Open leftward when the submenu would otherwise run past the window edge. */
 function flip(li){
  var sub=li.querySelector(':scope > .bh-submenu');
  if(!sub)return;
  sub.classList.remove('bh-submenu--flip');
  var r=sub.getBoundingClientRect();
  if(r.right>window.innerWidth-8)sub.classList.add('bh-submenu--flip');
 }
 document.addEventListener('click',function(e){
  var link=e.target.closest('.bh-menu__item--parent > .bh-menu__link');
  if(link){
   var li=link.parentElement;
   var stacked=!!link.closest('.bh-menu--stack');
   /* A parent that is only a label has nowhere to go, so the tap opens it.
      A parent with a real URL still navigates on a device with a pointer —
      taking that away would make whole sections unreachable. */
   var href=link.getAttribute('href')||'';
   var isLabel=href===''||href==='#';
   var touch=window.matchMedia('(hover:none)').matches;
   if(stacked||touch||isLabel){
    if(!li.classList.contains(OPEN)){e.preventDefault();closeAll(parentsOf(li));toggle(li,true);return;}
    if(isLabel){e.preventDefault();toggle(li,false);return;}
   }
   return;
  }
  if(!e.target.closest('.bh-menu'))closeAll();
 });
 document.addEventListener('keydown',function(e){
  if(e.key==='Escape'){
   var open=document.querySelector('.bh-menu__item.'+OPEN);
   if(open){closeAll();var a=open.querySelector(':scope > .bh-menu__link');if(a)a.focus();}
  }
 });
 /* Focus moving out of a menu closes it, so tabbing away does not leave a
    dropdown hanging open over the page. */
 document.addEventListener('focusin',function(e){
  if(!e.target.closest('.bh-menu'))closeAll();
  else{var li=e.target.closest('.bh-menu__item--parent');if(li)flip(li);}
 });
 window.addEventListener('resize',function(){closeAll();});
})();
JS;

        return "\n<style id=\"bh-menu-css\">" . $css . "</style>\n"
             . "<script id=\"bh-menu-js\">" . $js . "</script>\n";
    }
}


// ── Featured image sizes ─────────────────────────────────────────────────────
//
// Core makes a thumbnail (square crop), medium and large version of every
// uploaded image. Posts carry them in featured_sizes; these helpers pick one
// and build a responsive <img> from them.

if (!function_exists('bh_image_sizes')) {
    /**
     * A post's featured image in every available size, smallest first:
     * name => ['url', 'width', 'height']. "full" is the original upload.
     * Returns [] when the post has no featured image.
     */
    function bh_image_sizes(array $post): array
    {
        static $cache = [];
        $sizes = $post['featured_sizes'] ?? null;
        $full  = ['url' => (string) ($post['featured_url'] ?? ''), 'width' => (int) ($post['featured_width'] ?? 0), 'height' => (int) ($post['featured_height'] ?? 0)];
        if (!is_array($sizes) || ($full['url'] !== '' && $full['width'] === 0)) {
            // A post loaded some other way: look its image up once per request.
            $mid = (int) ($post['featured_media_id'] ?? 0);
            if ($mid > 0) {
                if (!array_key_exists($mid, $cache)) {
                    $cache[$mid] = null;
                    try {
                        $row = \App\Core\Application::getInstance()->make(\App\Core\Database::class)
                            ->selectOne('SELECT url, width, height, sizes FROM {media} WHERE id = :id', ['id' => $mid]);
                        if ($row) {
                            $list = [];
                            foreach ((array) json_decode((string) ($row['sizes'] ?? ''), true) as $n => $v) {
                                if (is_array($v) && !empty($v['url'])) $list[(string) $n] = ['url' => \App\Repositories\PostRepository::mediaUrl((string) $v['url']), 'width' => (int) ($v['width'] ?? 0), 'height' => (int) ($v['height'] ?? 0)];
                            }
                            $cache[$mid] = ['sizes' => $list, 'full' => ['url' => \App\Repositories\PostRepository::mediaUrl((string) $row['url']), 'width' => (int) $row['width'], 'height' => (int) $row['height']]];
                        }
                    } catch (\Throwable) {}
                }
                if (is_array($cache[$mid])) {
                    if (!is_array($sizes)) $sizes = $cache[$mid]['sizes'];
                    if ($full['url'] === '') $full['url'] = $cache[$mid]['full']['url'];
                    if ($full['width'] === 0) { $full['width'] = $cache[$mid]['full']['width']; $full['height'] = $cache[$mid]['full']['height']; }
                }
            }
        }
        $out = [];
        foreach (is_array($sizes) ? $sizes : [] as $n => $v) if (!empty($v['url'])) $out[(string) $n] = $v;
        if ($full['url'] !== '') $out['full'] = $full;
        uasort($out, fn($a, $b) => ($a['width'] ?: PHP_INT_MAX) <=> ($b['width'] ?: PHP_INT_MAX));
        return $out;
    }
}

if (!function_exists('bh_image_url')) {
    /**
     * The URL of a post's featured image in one size: thumbnail, medium,
     * large or full. A size that was never made (the original was smaller, or
     * thumbnails have not been generated yet) falls back to the original.
     * Returns '' when the post has no featured image.
     */
    function bh_image_url(array $post, string $size = 'full'): string
    {
        $all = bh_image_sizes($post);
        return (string) (($all[$size] ?? $all['full'] ?? [])['url'] ?? '');
    }
}

if (!function_exists('bh_post_image')) {
    /**
     * A responsive <img> for a post's featured image, or '' if it has none.
     *
     * The chosen $size is the src; the other sizes with the same shape go into
     * srcset, so the browser downloads the smallest file that is sharp enough
     * for the slot. The square-cropped "thumbnail" never mixes with the others.
     *
     * $args: class, alt (default: the image's alt text), sizes (the srcset
     * "sizes" attribute, default "100vw"), loading (default "lazy"),
     * fetchpriority, and any other attribute as key => value.
     */
    function bh_post_image(array $post, string $size = 'medium', array $args = []): string
    {
        $all = bh_image_sizes($post);
        if (!$all) return '';
        $pick = $all[$size] ?? $all['full'] ?? reset($all);

        $srcset = [];
        if ($size !== 'thumbnail' && !empty($pick['width']) && !empty($pick['height'])) {
            $ratio = $pick['width'] / $pick['height'];
            foreach ($all as $name => $v) {
                if ($name === 'thumbnail' || empty($v['width']) || empty($v['height'])) continue;
                if (abs($v['width'] / $v['height'] - $ratio) / $ratio > 0.02) continue; // a different shape
                $srcset[$v['width']] = $v['url'] . ' ' . $v['width'] . 'w';
            }
            ksort($srcset);
        }

        $attrs = [
            'src'      => $pick['url'],
            'srcset'   => count($srcset) > 1 ? implode(', ', $srcset) : null,
            'sizes'    => count($srcset) > 1 ? (string) ($args['sizes'] ?? '100vw') : null,
            'width'    => !empty($pick['width']) ? (int) $pick['width'] : null,
            'height'   => !empty($pick['height']) ? (int) $pick['height'] : null,
            'alt'      => (string) ($args['alt'] ?? $post['featured_alt'] ?? ''),
            'loading'  => (string) ($args['loading'] ?? 'lazy'),
            'decoding' => 'async',
        ];
        foreach ($args as $k => $v) if (!in_array($k, ['sizes', 'alt', 'loading'], true)) $attrs[$k] = $v;
        $html = '<img';
        foreach ($attrs as $k => $v) {
            if ($v === null || $v === false || ($v === '' && $k !== 'alt')) continue;
            $html .= ' ' . $k . '="' . htmlspecialchars((string) $v, ENT_QUOTES) . '"';
        }
        return $html . '>';
    }
}


// ── AI agents (Settings → AI Agents) ─────────────────────────────────────────
//
// Core serves /robots.txt, /llms.txt, /llms-full.txt and the Agentic Resource
// Discovery manifest (/.well-known/ard.json, /.well-known/ai-catalog.json),
// and bh_head()/bh_footer() add the discovery links and WebMCP. These helpers
// let a theme or app take part. See docs/AI-AGENTS.md.

if (!function_exists('bh_ai')) {
    /** The AiAccessService: settings and builders for everything agent-facing. */
    function bh_ai(): \App\Services\AiAccessService
    {
        return \App\Core\Application::getInstance()->make(\App\Services\AiAccessService::class);
    }
}

if (!function_exists('bh_ai_on')) {
    /**
     * Whether an AI-agent setting is on (see Settings → AI Agents). Never
     * throws: if anything is wrong it answers false, so a page that asks
     * always renders.
     */
    function bh_ai_on(string $key): bool
    {
        try { return bh_ai()->on($key); } catch (\Throwable) { return false; }
    }
}

if (!function_exists('bh_webmcp_form')) {
    /**
     * Declarative WebMCP attributes for a <form>, ready to print inside the
     * tag: ` toolname="…" tooldescription="…"`. Returns '' while WebMCP is off.
     *
     *   <form action="/subscribe"<?= bh_webmcp_form('subscribe_newsletter', 'Subscribe an email address to the newsletter.') ?>>
     *
     * $opts['autosubmit'] = true adds toolautosubmit: the agent's call submits
     * the form. Use it only for harmless, read-only forms such as search —
     * never for anything that sends, buys, posts or signs in.
     */
    function bh_webmcp_form(string $name, string $description, array $opts = []): string
    {
        if (!bh_ai_on('webmcp_enabled')) return '';
        $name = preg_replace('/[^A-Za-z0-9_.-]+/', '_', $name) ?: 'form';
        if (!preg_match('/^[A-Za-z]/', $name)) $name = 'f_' . $name;
        $e = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        return ' toolname="' . $e(substr($name, 0, 64)) . '" tooldescription="' . $e($description) . '"'
             . (!empty($opts['autosubmit']) ? ' toolautosubmit' : '');
    }
}

if (!function_exists('bh_webmcp_param')) {
    /**
     * ` toolparamdescription="…"` for a form field, telling an agent what to
     * put in it. Returns '' while WebMCP is off.
     */
    function bh_webmcp_param(string $description): string
    {
        if (!bh_ai_on('webmcp_enabled')) return '';
        return ' toolparamdescription="' . htmlspecialchars($description, ENT_QUOTES, 'UTF-8') . '"';
    }
}

if (!function_exists('bh_webmcp_tool')) {
    /**
     * Register a WebMCP tool on every public page. Call it from an app's boot
     * (or anywhere before bh_footer() runs):
     *
     *   bh_webmcp_tool([
     *       'name'        => 'check_stock',
     *       'description' => 'Check whether a product is in stock.',
     *       'inputSchema' => ['type' => 'object',
     *                         'properties' => ['sku' => ['type' => 'string', 'description' => 'Product code']],
     *                         'required' => ['sku']],
     *       'annotations' => ['readOnlyHint' => true],
     *       'endpoint'    => '/shop/stock.json',   // GET, same origin; input → query string; returns its JSON
     *   ]);
     *
     * Instead of 'endpoint', 'result' returns a fixed value. A definition that
     * is not valid (see AiAccessService::validTool) is left out, so a mistake
     * cannot break the page's other tools. Keep tools few and read-only where
     * you can; anything consequential should say so with
     * 'annotations' => ['consequentialHint' => true].
     */
    function bh_webmcp_tool(array $definition): void
    {
        $GLOBALS['__bh_webmcp_tools'][] = $definition;
    }
}

if (!function_exists('bh_webmcp_tools_registered')) {
    /** Tools registered with bh_webmcp_tool() during this request. */
    function bh_webmcp_tools_registered(): array
    {
        return (array) ($GLOBALS['__bh_webmcp_tools'] ?? []);
    }
}

