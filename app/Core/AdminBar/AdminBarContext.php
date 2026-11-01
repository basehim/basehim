<?php

declare(strict_types=1);

namespace App\Core\AdminBar;

/**
 * What the visitor is looking at, for the admin bar (1.2.96).
 *
 * Built from what the page was rendered with, not by parsing the address:
 * RendersTheme::renderTheme() and notFound() call capture() with the
 * template and the data they hand the theme, so the post, term, author or
 * search query is the one core actually loaded. Pages core didn't render
 * (an app's own route, an error page) fall back to the matched route and
 * the response status.
 *
 * Types: single (a post, page or item of an app's type), home, archive
 * (archive_kind: category, tag, taxonomy, post_type, author), search, 404,
 * app (an app page shown in the theme), route (anything else).
 *
 * Components ask with matches(): 'post', 'page', 'event' (content types),
 * 'single', 'archive', 'home', 'search', '404', 'app', 'route',
 * 'taxonomy:category', 'archive:author', 'app:shop' (an app's pages and
 * routes), 'route:shop.index' (a route name; '*' at the end matches a prefix).
 *
 * The same context is rebuilt for the bar's AJAX calls from a signed
 * descriptor (descriptor() / fromDescriptor()), and the post or term is
 * read from the database again, so a call can't ask about content the page
 * wasn't showing.
 */
final class AdminBarContext
{
    private static ?array $captured = null;

    /** @param array<string,mixed> $data */
    private function __construct(private array $data) {}

    /**
     * Remember what the page is rendering. Called by core's renderers; cheap
     * (no queries), and harmless when no admin bar is shown.
     *
     * @param string $template the general template (single, page, archive, search, index, 404, app)
     */
    public static function capture(string $template, array $data): void
    {
        try {
            self::$captured = self::fromRender($template, $data);
        } catch (\Throwable) {
            self::$captured = null;
        }
    }

    /** Forget the captured page (tests; and between sub-renders). */
    public static function reset(): void
    {
        self::$captured = null;
    }

    /**
     * The context of the response being sent: what was captured, or the
     * route and status when nothing was.
     */
    public static function current(string $path, int $status, ?array $route = null, string $query = ''): self
    {
        $d = self::$captured ?? [];
        if ($d === []) {
            $owner = is_array($route) ? (string) ($route['owner'] ?? '') : '';
            $d = ['type' => $status === 404 ? '404' : ($owner !== '' ? 'app' : 'route')];
            if ($owner !== '') $d['app'] = $owner;
        }
        // A page rendered fine but answered 404 (a theme's 404 template for a
        // missing post) is a 404.
        if ($status === 404) $d['type'] = '404';
        $d['path'] = $path;
        $d['status'] = $status;
        if (is_array($route)) {
            $d['route'] = (string) (($route['options']['name'] ?? '') ?: ($route['pattern'] ?? ''));
            $d['route_pattern'] = (string) ($route['pattern'] ?? '');
            if (!empty($route['owner']) && empty($d['app'])) $d['app'] = (string) $route['owner'];
        }
        if ($query !== '' && !isset($d['query_string'])) $d['query_string'] = mb_substr($query, 0, 300);
        return new self($d);
    }

    /** @internal for tests and fromDescriptor() */
    public static function make(array $data): self
    {
        return new self($data + ['type' => 'route', 'path' => '/', 'status' => 200]);
    }

    private static function fromRender(string $template, array $data): array
    {
        $post = is_array($data['post'] ?? null) ? $data['post'] : null;
        $d = ['template' => $template];

        if (!empty($data['app_page'])) {
            $d['type'] = 'app';
            if (!empty($data['app_slug'])) $d['app'] = (string) $data['app_slug'];
            $d['title'] = (string) ($data['title'] ?? '');
            return $d;
        }
        if ($template === '404') {
            return ['type' => '404', 'template' => '404'];
        }
        if ($template === 'search') {
            $d['type'] = 'search';
            $d['search'] = mb_substr((string) ($data['query'] ?? ''), 0, 200);
            $d['total'] = (int) ($data['meta']['total'] ?? 0);
            return $d;
        }
        if ($post !== null && (int) ($post['id'] ?? 0) > 0) {
            $d['type'] = 'single';
            $d['content_type'] = (string) ($post['type'] ?? 'post');
            $d['id'] = (int) $post['id'];
            $d['status_post'] = (string) ($post['status'] ?? '');
            $d['author_id'] = (int) ($post['author_id'] ?? 0);
            $d['title'] = (string) ($post['title'] ?? '');
            $d['preview'] = !empty($data['is_preview']);
            if (!empty($data['is_home'])) $d['home'] = true;
            return $d;
        }
        if (!empty($data['is_home'])) {
            $d['type'] = 'home';
            $d['home'] = true;
            return $d;
        }
        if (isset($data['archive_type'])) {
            $d['type'] = 'archive';
            $kind = (string) $data['archive_type'];
            if ($kind === 'taxonomy') {
                $d['archive_kind'] = 'taxonomy';
                $d['taxonomy'] = (string) ($data['taxonomy']['slug'] ?? '');
            } elseif ($kind === 'post_type') {
                $d['archive_kind'] = 'post_type';
                $d['content_type'] = (string) ($data['post_type']['slug'] ?? '');
            } elseif ($kind === 'author') {
                $d['archive_kind'] = 'author';
                $d['author_id'] = (int) ($data['author']['id'] ?? 0);
            } else {
                // Core's category and tag archives pass the taxonomy itself.
                $d['archive_kind'] = 'taxonomy';
                $d['taxonomy'] = $kind;
            }
            if (is_array($data['term'] ?? null)) {
                $d['term_id'] = (int) ($data['term']['id'] ?? 0);
                $d['title'] = (string) ($data['term']['name'] ?? '');
            }
            return $d;
        }
        $d['type'] = 'route';
        return $d;
    }

    // ------------------------------------------------------------------

    public function type(): string { return (string) ($this->data['type'] ?? 'route'); }
    public function path(): string { return (string) ($this->data['path'] ?? '/'); }
    public function status(): int { return (int) ($this->data['status'] ?? 200); }
    public function route(): string { return (string) ($this->data['route'] ?? ''); }
    public function app(): string { return (string) ($this->data['app'] ?? ''); }
    public function contentType(): string { return (string) ($this->data['content_type'] ?? ''); }
    public function contentId(): int { return (int) ($this->data['id'] ?? 0); }
    public function postStatus(): string { return (string) ($this->data['status_post'] ?? ''); }
    public function authorId(): int { return (int) ($this->data['author_id'] ?? 0); }
    public function taxonomy(): string { return (string) ($this->data['taxonomy'] ?? ''); }
    public function termId(): int { return (int) ($this->data['term_id'] ?? 0); }
    public function archiveKind(): string { return (string) ($this->data['archive_kind'] ?? ''); }
    public function search(): string { return (string) ($this->data['search'] ?? ''); }
    public function title(): string { return (string) ($this->data['title'] ?? ''); }
    public function isHome(): bool { return !empty($this->data['home']); }
    public function isPreview(): bool { return !empty($this->data['preview']); }
    public function template(): string { return (string) ($this->data['template'] ?? ''); }

    /** Any value, for components that need one this class has no getter for. */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    /** Everything, for app callbacks and the AJAX descriptor. */
    public function toArray(): array
    {
        return $this->data;
    }

    /**
     * Whether the page is one of these. Each condition is a context type,
     * a content type, or kind:value (see the class comment). Empty: every page.
     *
     * @param list<string> $conditions
     */
    public function matches(array $conditions): bool
    {
        if ($conditions === []) return true;
        foreach ($conditions as $c) {
            if ($this->matchesOne((string) $c)) return true;
        }
        return false;
    }

    private function matchesOne(string $c): bool
    {
        $c = trim($c);
        if ($c === '' ) return false;
        if ($c === '*') return true;
        if (str_contains($c, ':')) {
            [$kind, $value] = explode(':', $c, 2);
            return match ($kind) {
                'taxonomy' => $this->type() === 'archive' && $this->archiveKind() === 'taxonomy' && $this->taxonomy() === $value,
                'archive'  => $this->type() === 'archive' && ($this->archiveKind() === $value
                                || ($value === $this->contentType() && $this->archiveKind() === 'post_type')),
                'app'      => $this->app() === $value,
                'route'    => self::wildcard($value, $this->route()),
                'type'     => $this->contentType() === $value,
                default    => false,
            };
        }
        if (in_array($c, ['single', 'home', 'archive', 'search', '404', 'app', 'route'], true)) {
            if ($c === 'home') return $this->isHome();
            return $this->type() === $c;
        }
        // A content type: its items (and, for 'post', nothing else).
        return $this->type() === 'single' && $this->contentType() === $c;
    }

    private static function wildcard(string $pattern, string $value): bool
    {
        if ($value === '') return false;
        if (str_ends_with($pattern, '*')) return str_starts_with($value, substr($pattern, 0, -1));
        return $pattern === $value;
    }

    // ------------------------------------------------------------------
    // The signed descriptor the bar's AJAX calls send back.
    // ------------------------------------------------------------------

    private const DESCRIPTOR_KEYS = ['type', 'path', 'status', 'route', 'app', 'content_type', 'id', 'archive_kind',
        'taxonomy', 'term_id', 'author_id', 'search', 'home', 'preview', 'template', 'total'];

    /**
     * The context as a compact string, signed with a key only this session
     * knows. The bar sends it with its AJAX calls; fromDescriptor() refuses
     * anything changed, so a call can only ask about the page that was shown
     * to this user.
     */
    public function descriptor(string $secret): string
    {
        $d = array_intersect_key($this->data, array_flip(self::DESCRIPTOR_KEYS));
        $json = (string) json_encode($d, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $payload = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
        return $payload . '.' . substr(hash_hmac('sha256', $payload, 'bh-admin-bar|' . $secret), 0, 32);
    }

    public static function fromDescriptor(string $value, string $secret): ?self
    {
        if ($value === '' || strlen($value) > 4000 || !str_contains($value, '.')) return null;
        [$payload, $sig] = explode('.', $value, 2);
        $expected = substr(hash_hmac('sha256', $payload, 'bh-admin-bar|' . $secret), 0, 32);
        if (!hash_equals($expected, $sig)) return null;
        $json = base64_decode(strtr($payload, '-_', '+/'), true);
        $d = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($d)) return null;
        return self::make(array_intersect_key($d, array_flip(self::DESCRIPTOR_KEYS)));
    }
}
