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
        $out = '';

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

        return $out . bh_hook_output('bh.footer');
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
