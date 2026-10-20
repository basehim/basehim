<?php

declare(strict_types=1);

namespace App\Core\Ajax;

use App\Core\Config;

/**
 * Every AJAX action the gateway can run, registered in PHP and nowhere else.
 *
 * Names are "namespace.action" in lower case: posts.autosave,
 * media.update, my-app.save-settings. Core owns a fixed set of namespaces
 * (RESERVED); an app may register only under its own slug, which the App
 * base class does for it ($this->ajax('save', [...]) → "my-app.save"). So an
 * app cannot replace a core action, cannot take another app's name, and a
 * second registration of any name is refused rather than silently winning.
 *
 * Every option is checked when the action is registered, and the defaults
 * are the strict ones: signed in, POST only, CSRF-checked, rate-limited,
 * accepting no input fields at all. Loosening any of them has to be written
 * down in the registration, where a reviewer can see it.
 *
 * This is a policy layer over trusted code, not a sandbox (see
 * APP-PERMISSIONS.md): an app is PHP in the same process and could call
 * register() with any owner it likes. What the registry guarantees is that an
 * honest mistake — a typo, a name collision, a forgotten capability — fails
 * loudly at registration instead of shipping an open endpoint.
 */
final class AjaxRegistry
{
    public const AUTH_USER   = 'user';
    public const AUTH_PUBLIC = 'public';
    public const OWNER_CORE  = 'core';

    /** Hard ceiling for any action's max_body, whatever it asks for. */
    public const MAX_BODY_LIMIT = 1048576;

    /**
     * Namespaces only core may register in. An app whose slug is one of these
     * cannot register AJAX actions at all (it is told so, in its log).
     */
    public const RESERVED = [
        'core', 'basehim', 'ajax', 'admin', 'api', 'auth', 'session', 'system',
        'posts', 'pages', 'content', 'templates', 'editor', 'blocks',
        'media', 'users', 'profile', 'roles', 'comments', 'terms', 'taxonomies',
        'menus', 'widgets', 'settings', 'customizer', 'seo',
        'apps', 'themes', 'updates', 'schedule', 'cache', 'search', 'mail',
    ];

    private const NAME_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,79}(\.[a-z][a-z0-9_-]{0,63}){1,3}$/';
    private const ALLOWED_OPTIONS = [
        'handler', 'methods', 'auth', 'capability', 'area', 'csrf', 'input', 'allow_unknown',
        'rate_limit', 'max_body', 'idempotent', 'description',
    ];

    /** @var array<string,AjaxAction> */
    private array $actions = [];

    public function __construct(private Config $config) {}

    /**
     * Register an action. Throws \InvalidArgumentException on anything wrong,
     * with a message that names the action and the problem.
     *
     * @param string $owner 'core', or the slug of the app registering it
     */
    public function register(string $name, array $definition, string $owner = self::OWNER_CORE): AjaxAction
    {
        if (!preg_match(self::NAME_PATTERN, $name)) {
            throw new \InvalidArgumentException(
                "Invalid AJAX action name '{$name}'. Use lower-case 'namespace.action', e.g. 'my-app.save'."
            );
        }
        $namespace = explode('.', $name, 2)[0];

        if ($owner === self::OWNER_CORE) {
            if (!in_array($namespace, self::RESERVED, true)) {
                throw new \InvalidArgumentException(
                    "Core AJAX action '{$name}' must use a reserved core namespace."
                );
            }
        } else {
            $appNs = self::namespaceForApp($owner);
            if ($appNs === null) {
                throw new \InvalidArgumentException(
                    "App '{$owner}' cannot register AJAX actions: its slug is reserved by core or is not a valid namespace."
                );
            }
            if ($namespace !== $appNs) {
                throw new \InvalidArgumentException(
                    "App '{$owner}' may only register AJAX actions under '{$appNs}.', not '{$name}'."
                );
            }
        }

        if (isset($this->actions[$name])) {
            $by = $this->actions[$name]->owner;
            throw new \InvalidArgumentException("AJAX action '{$name}' is already registered (by {$by}).");
        }

        return $this->actions[$name] = $this->build($name, $definition, $owner);
    }

    /**
     * The namespace an app's actions live under: its slug, lower-cased.
     * Null when the slug cannot be one (reserved, or characters a name
     * cannot hold).
     */
    public static function namespaceForApp(string $slug): ?string
    {
        $ns = strtolower($slug);
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,79}$/', $ns)) return null;
        if (in_array($ns, self::RESERVED, true)) return null;
        return $ns;
    }

    /**
     * Remove every action an app registered, so an app whose boot() failed
     * part-way exposes none (1.2.71, AppGuard). Returns how many were removed.
     */
    public function forgetOwner(string $owner): int
    {
        $n = 0;
        foreach ($this->actions as $name => $action) {
            if ($action->owner === $owner && $owner !== self::OWNER_CORE) {
                unset($this->actions[$name]);
                $n++;
            }
        }
        return $n;
    }

    public function get(string $name): ?AjaxAction
    {
        return $this->actions[$name] ?? null;
    }

    public function has(string $name): bool
    {
        return isset($this->actions[$name]);
    }

    /** @return array<string,AjaxAction> */
    public function all(): array
    {
        return $this->actions;
    }

    // ------------------------------------------------------------------

    private function build(string $name, array $d, string $owner): AjaxAction
    {
        $fail = static function (string $msg) use ($name): never {
            throw new \InvalidArgumentException("AJAX action '{$name}': {$msg}");
        };

        foreach (array_keys($d) as $key) {
            if (!in_array($key, self::ALLOWED_OPTIONS, true)) {
                $fail("unknown option '{$key}'.");
            }
        }

        // Handler: a closure, [$object, 'method'] or [ClassName::class, 'method'].
        // Never a string: 'Class::method', 'Class@method' and function names are
        // refused, so no string from anywhere can be turned into code here.
        $h = $d['handler'] ?? null;
        if ($h instanceof \Closure) {
            // ok
        } elseif (is_array($h) && count($h) === 2 && array_is_list($h)
            && (is_object($h[0]) || is_string($h[0])) && is_string($h[1])) {
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $h[1]) || str_starts_with($h[1], '__')) {
                $fail("handler method '{$h[1]}' is not allowed.");
            }
            if (is_string($h[0]) && !preg_match('/^\\\\?[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/', $h[0])) {
                $fail('handler class name is not valid.');
            }
            if (is_object($h[0]) && !is_callable($h)) {
                $fail('handler is not callable.');
            }
        } else {
            $fail("'handler' must be a closure, [\$object, 'method'] or [ClassName::class, 'method'].");
        }

        // Methods: POST by default. GET is for reads; it never checks CSRF, so
        // a handler behind GET must not change anything.
        $methods = $d['methods'] ?? ['POST'];
        if (!is_array($methods) || $methods === []) $fail("'methods' must be a non-empty list.");
        $methods = array_values(array_unique(array_map(fn($m) => strtoupper((string) $m), $methods)));
        foreach ($methods as $m) {
            if (!in_array($m, ['GET', 'POST'], true)) $fail("method '{$m}' is not supported (use GET or POST).");
        }

        // Auth: signed in unless the registration says 'public' in so many words.
        $auth = $d['auth'] ?? self::AUTH_USER;
        if (!in_array($auth, [self::AUTH_USER, self::AUTH_PUBLIC], true)) {
            $fail("'auth' must be 'user' or 'public'.");
        }

        // Capability: required on every signed-in action, so "any signed-in
        // user" is a decision ('read', which every role holds) rather than an
        // omission. Public actions take none.
        $caps = [];
        if ($auth === self::AUTH_USER) {
            if (!array_key_exists('capability', $d)) {
                $fail("signed-in actions must declare a 'capability' (use 'read' for any signed-in user).");
            }
            $caps = is_array($d['capability']) ? $d['capability'] : [$d['capability']];
            if ($caps === []) $fail("'capability' cannot be empty.");
            foreach ($caps as $cap) {
                if (!is_string($cap) || !preg_match('/^[a-z][a-z0-9_:.-]{1,99}$/', $cap)) {
                    $fail("capability names are lower-case strings like 'edit_posts'.");
                }
            }
            $caps = array_values(array_unique($caps));
        } elseif (array_key_exists('capability', $d)) {
            $fail("public actions cannot require a capability; use 'auth' => 'user'.");
        }

        // Area: the admin screen this action belongs to ('/admin/media'). The
        // gateway then also requires whatever AdminAreaPolicy requires for that
        // path, including changes a site made with the admin.area_policy filter,
        // so an action can never be more open than the screen it serves.
        $area = $d['area'] ?? null;
        if ($area !== null) {
            if ($auth !== self::AUTH_USER) $fail("'area' applies to signed-in actions only.");
            if (!is_string($area) || !preg_match('#^/admin(/[a-z0-9_-]+)*$#', $area)) {
                $fail("'area' must be an admin path like '/admin/media'.");
            }
        }

        // CSRF: on by default. Only a public action may switch it off, and the
        // gateway then runs it as a visitor — it never sees who is signed in —
        // so another site cannot make it act with a user's session.
        $csrf = $d['csrf'] ?? true;
        if (!is_bool($csrf)) $fail("'csrf' must be true or false.");
        if (!$csrf && $auth === self::AUTH_USER) {
            $fail("signed-in actions cannot switch CSRF protection off.");
        }

        // Input
        $schema = $d['input'] ?? [];
        if (!is_array($schema)) $fail("'input' must be an array of field rules.");
        $allowUnknown = $d['allow_unknown'] ?? false;
        if (!is_bool($allowUnknown)) $fail("'allow_unknown' must be true or false.");
        try {
            $input = InputSchema::compile($schema, $allowUnknown);
        } catch (\InvalidArgumentException $e) {
            $fail($e->getMessage());
        }

        // Rate limit: a default always applies. A signed-in action may opt out
        // with false; a public one may not, because nothing else stands
        // between it and every client on the internet.
        $rl = $d['rate_limit'] ?? null;
        if ($rl === false) {
            if ($auth === self::AUTH_PUBLIC) $fail('public actions must keep a rate limit.');
            $rateLimit = null;
        } else {
            [$defMax, $defPer] = (array) $this->config->get(
                $auth === self::AUTH_PUBLIC ? 'ajax.default_limit_public' : 'ajax.default_limit_user',
                $auth === self::AUTH_PUBLIC ? [30, 60] : [120, 60]
            );
            if ($rl !== null && !is_array($rl)) $fail("'rate_limit' must be an array like ['max' => 10, 'per' => 60] or false.");
            $rl = $rl ?? [];
            foreach (array_keys($rl) as $k) {
                if (!in_array($k, ['max', 'per', 'by', 'fail'], true)) $fail("unknown rate_limit option '{$k}'.");
            }
            $max = $rl['max'] ?? $defMax;
            $per = $rl['per'] ?? $defPer;
            $by  = $rl['by'] ?? ($auth === self::AUTH_PUBLIC ? 'ip' : 'user');
            $failMode = $rl['fail'] ?? null;
            if (!is_int($max) || $max < 1 || $max > 100000) $fail("rate_limit 'max' must be 1–100000.");
            if (!is_int($per) || $per < 1 || $per > 86400) $fail("rate_limit 'per' must be 1–86400 seconds.");
            if (!in_array($by, ['user', 'ip', 'session'], true)) $fail("rate_limit 'by' must be user, ip or session.");
            if ($by === 'user' && $auth === self::AUTH_PUBLIC) $fail("public actions cannot rate-limit by 'user'; use ip or session.");
            if ($failMode !== null && !in_array($failMode, ['open', 'closed'], true)) $fail("rate_limit 'fail' must be open or closed.");
            $rateLimit = ['max' => $max, 'per' => $per, 'by' => $by, 'fail' => $failMode];
        }

        $maxBody = $d['max_body'] ?? (int) $this->config->get('ajax.max_body', 65536);
        if (!is_int($maxBody) || $maxBody < 256 || $maxBody > self::MAX_BODY_LIMIT) {
            $fail("'max_body' must be between 256 bytes and " . self::MAX_BODY_LIMIT . '.');
        }

        $idempotent = $d['idempotent'] ?? false;
        if (!is_bool($idempotent)) $fail("'idempotent' must be true or false.");
        if ($idempotent && !in_array('POST', $methods, true)) $fail("'idempotent' applies to POST actions only.");

        $description = (string) ($d['description'] ?? '');

        return new AjaxAction(
            name: $name,
            owner: $owner,
            handler: $h,
            methods: $methods,
            auth: $auth,
            capabilities: $caps,
            area: $area,
            csrf: $csrf,
            input: $input,
            rateLimit: $rateLimit,
            maxBody: $maxBody,
            idempotent: $idempotent,
            description: $description,
        );
    }
}
