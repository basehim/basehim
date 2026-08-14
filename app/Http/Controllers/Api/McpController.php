<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\Request;
use App\Core\Response;
use App\Core\Application;
use App\Services\ApiKeyService;
use App\Services\PostService;
use App\Services\TaxonomyService;
use App\Services\SettingService;

/**
 * McpController — a Model Context Protocol (MCP) server for Basehim.
 *
 * Transport: JSON-RPC 2.0 over a single HTTP POST endpoint (/mcp). This is the
 * "streamable HTTP" style but with plain JSON responses, which is all shared
 * hosting can support (no stdio, no WebSockets, no long-running processes).
 *
 * Auth: the existing API-key system. Send the key as either
 *   Authorization: Bearer basehim_xxx     (preferred)
 *   or  ?key=basehim_xxx                  (query fallback)
 * The key's scopes gate which tools can run (posts:read, posts:write, …).
 *
 * Implemented methods: initialize, notifications/initialized, ping,
 * tools/list, tools/call, resources/list, resources/read, prompts/list.
 */
final class McpController
{
    private const PROTOCOL_VERSION = '2025-06-18';
    private const SERVER_NAME = 'basehim-mcp';

    public function handle(Request $request): Response
    {
        // MCP clients may probe with GET; advertise that we're POST/JSON only.
        if (($_SERVER['REQUEST_METHOD'] ?? 'POST') === 'GET') {
            $issuer = '';
            try { $issuer = $this->app()->make(\App\Services\McpOAuthService::class)->issuer(); } catch (\Throwable) {}
            return Response::json([
                'name'        => self::SERVER_NAME,
                'transport'   => 'http-jsonrpc',
                'auth'        => ['oauth2', 'bearer-api-key'],
                'oauth_metadata' => $issuer !== '' ? $issuer . '/.well-known/oauth-protected-resource' : null,
                'hint'        => 'POST JSON-RPC 2.0 here. Add as a Claude custom connector using this URL — '
                               . 'it registers itself via OAuth dynamic client registration, so no client id/secret is needed.',
            ]);
        }

        $body = $this->readJson($request);

        // Batch requests: an array of calls (a list, i.e. sequential int keys).
        if (is_array($body) && $body !== [] && array_keys($body) === range(0, count($body) - 1)) {
            $out = [];
            foreach ($body as $one) {
                $res = $this->dispatch(is_array($one) ? $one : []);
                if ($res !== null) $out[] = $res;
            }
            return $this->raw($out);
        }

        $res = $this->dispatch(is_array($body) ? $body : []);
        // Notifications return null → 202 Accepted with empty body.
        if ($res === null) return new Response('', 202, ['Content-Type' => 'application/json']);
        return $this->raw($res);
    }

    // ==================================================================
    // JSON-RPC dispatch
    // ==================================================================

    private function dispatch(array $msg): ?array
    {
        $id = $msg['id'] ?? null;
        $method = (string) ($msg['method'] ?? '');
        $params = is_array($msg['params'] ?? null) ? $msg['params'] : [];

        // Notifications (no id) get no response.
        $isNotification = !array_key_exists('id', $msg);

        try {
            switch ($method) {
                case 'initialize':
                    return $this->ok($id, $this->initialize());

                case 'notifications/initialized':
                case 'notifications/cancelled':
                    return null; // notifications — no reply

                case 'ping':
                    return $this->ok($id, (object) []);

                case 'tools/list':
                    return $this->ok($id, ['tools' => $this->toolDefinitions()]);

                case 'tools/call':
                    return $this->ok($id, $this->callTool($params));

                case 'resources/list':
                    return $this->ok($id, $this->listResources());

                case 'resources/read':
                    return $this->ok($id, $this->readResource($params));

                case 'prompts/list':
                    return $this->ok($id, ['prompts' => []]);

                default:
                    if ($isNotification) return null;
                    return $this->err($id, -32601, "Method not found: {$method}");
            }
        } catch (McpError $e) {
            return $isNotification ? null : $this->err($id, $e->rpcCode, $e->getMessage());
        } catch (\Throwable $e) {
            return $isNotification ? null : $this->err($id, -32603, 'Internal error: ' . $e->getMessage());
        }
    }

    private function initialize(): array
    {
        return [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities' => [
                'tools'     => ['listChanged' => false],
                'resources' => ['listChanged' => false, 'subscribe' => false],
                'prompts'   => ['listChanged' => false],
            ],
            'serverInfo' => [
                'name'    => self::SERVER_NAME,
                'title'   => $this->siteName() . ' (Basehim)',
                'version' => defined('BASEHIM_VERSION') ? (string) BASEHIM_VERSION : '1.0',
            ],
            'instructions' => 'Content tools for a Basehim site. Use search_content to find things, '
                . 'get_post/get_page to read, and (with a posts:write key) create_post/update_post to author. '
                . 'Post content is HTML unless you set content_format to "markdown" or "blocks".',
        ];
    }

    // ==================================================================
    // Tools
    // ==================================================================

    /** Tool definitions (JSON Schema for inputs), filtered by the key's scopes. */
    private function toolDefinitions(): array
    {
        $key = $this->authKey();
        $scopes = $key['scopes'] ?? [];
        $has = fn(string $s) => in_array($s, $scopes, true);

        $all = [];

        $all[] = [
            'name' => 'get_site_info',
            'description' => 'Get basic information about this Basehim site (title, tagline, URL, counts).',
            'inputSchema' => ['type' => 'object', 'properties' => (object) [], 'additionalProperties' => false],
            '_scope' => null,
        ];

        $all[] = [
            'name' => 'search_content',
            'description' => 'Full-text search across published posts and pages. Returns matching items with title, slug, type and excerpt.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'Search terms.'],
                    'limit' => ['type' => 'integer', 'description' => 'Max results (1–50).', 'default' => 10],
                ],
                'required' => ['query'],
                'additionalProperties' => false,
            ],
            '_scope' => 'posts:read',
        ];

        $all[] = [
            'name' => 'list_posts',
            'description' => 'List posts, most recent first. Optional status filter (published/draft/pending). Requires posts:read.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'status' => ['type' => 'string', 'enum' => ['published', 'draft', 'pending', 'any'], 'default' => 'published'],
                    'page'   => ['type' => 'integer', 'default' => 1],
                    'per_page' => ['type' => 'integer', 'description' => '1–50', 'default' => 10],
                ],
                'additionalProperties' => false,
            ],
            '_scope' => 'posts:read',
        ];

        $all[] = [
            'name' => 'get_post',
            'description' => 'Get a single post by slug or numeric id, including full content. Requires posts:read.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'slug' => ['type' => 'string', 'description' => 'Post slug.'],
                    'id'   => ['type' => 'integer', 'description' => 'Post id (alternative to slug).'],
                ],
                'additionalProperties' => false,
            ],
            '_scope' => 'posts:read',
        ];

        $all[] = [
            'name' => 'get_page',
            'description' => 'Get a single page by slug or numeric id, including full content. Requires posts:read.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'slug' => ['type' => 'string'],
                    'id'   => ['type' => 'integer'],
                ],
                'additionalProperties' => false,
            ],
            '_scope' => 'posts:read',
        ];

        $all[] = [
            'name' => 'create_post',
            'description' => 'Create a new post. Defaults to draft status. content_format may be html (default), markdown, or blocks. Requires posts:write.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'title'   => ['type' => 'string'],
                    'content' => ['type' => 'string', 'description' => 'Body. HTML unless content_format says otherwise.'],
                    'status'  => ['type' => 'string', 'enum' => ['draft', 'pending', 'published'], 'default' => 'draft'],
                    'excerpt' => ['type' => 'string'],
                    'slug'    => ['type' => 'string', 'description' => 'Optional; generated from title if omitted.'],
                    'content_format' => ['type' => 'string', 'enum' => ['html', 'markdown', 'blocks'], 'default' => 'html'],
                ],
                'required' => ['title'],
                'additionalProperties' => false,
            ],
            '_scope' => 'posts:write',
        ];

        $all[] = [
            'name' => 'update_post',
            'description' => 'Update an existing post by id. Only provided fields change. Requires posts:write.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'id'      => ['type' => 'integer'],
                    'title'   => ['type' => 'string'],
                    'content' => ['type' => 'string'],
                    'status'  => ['type' => 'string', 'enum' => ['draft', 'pending', 'published']],
                    'excerpt' => ['type' => 'string'],
                ],
                'required' => ['id'],
                'additionalProperties' => false,
            ],
            '_scope' => 'posts:write',
        ];

        $all[] = [
            'name' => 'list_pages',
            'description' => 'List pages, newest first. Requires posts:read.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'status'   => ['type' => 'string', 'enum' => ['published', 'draft', 'pending', 'any'], 'default' => 'published'],
                    'page'     => ['type' => 'integer', 'default' => 1],
                    'per_page' => ['type' => 'integer', 'description' => '1–50', 'default' => 10],
                ],
                'additionalProperties' => false,
            ],
            '_scope' => 'posts:read',
        ];

        $all[] = [
            'name' => 'create_page',
            'description' => 'Create a page. Defaults to draft. Requires posts:write.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'title'   => ['type' => 'string'],
                    'content' => ['type' => 'string'],
                    'status'  => ['type' => 'string', 'enum' => ['draft', 'pending', 'published'], 'default' => 'draft'],
                    'excerpt' => ['type' => 'string'],
                    'slug'    => ['type' => 'string'],
                    'content_format' => ['type' => 'string', 'enum' => ['html', 'markdown', 'blocks'], 'default' => 'html'],
                ],
                'required' => ['title'],
                'additionalProperties' => false,
            ],
            '_scope' => 'posts:write',
        ];

        $all[] = [
            'name' => 'update_page',
            'description' => 'Update a page by id. Only supplied fields change. Requires posts:write.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'id'      => ['type' => 'integer'],
                    'title'   => ['type' => 'string'],
                    'content' => ['type' => 'string'],
                    'status'  => ['type' => 'string', 'enum' => ['draft', 'pending', 'published']],
                    'excerpt' => ['type' => 'string'],
                ],
                'required' => ['id'],
                'additionalProperties' => false,
            ],
            '_scope' => 'posts:write',
        ];

        $all[] = [
            'name' => 'trash_post',
            'description' => 'Move a post or page to the trash. Reversible from the admin — this never deletes permanently. Requires posts:write.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => ['id' => ['type' => 'integer']],
                'required' => ['id'],
                'additionalProperties' => false,
            ],
            '_scope' => 'posts:write',
        ];

        $all[] = [
            'name' => 'set_post_terms',
            'description' => 'Replace the categories/tags on a post. Pass term ids from list_taxonomies. Requires posts:write.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'id'       => ['type' => 'integer'],
                    'term_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Full replacement set.'],
                ],
                'required' => ['id', 'term_ids'],
                'additionalProperties' => false,
            ],
            '_scope' => 'posts:write',
        ];

        $all[] = [
            'name' => 'create_term',
            'description' => 'Create a category or tag. Requires taxonomies:write.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'taxonomy'    => ['type' => 'string', 'description' => 'e.g. category or tag.'],
                    'name'        => ['type' => 'string'],
                    'slug'        => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                ],
                'required' => ['taxonomy', 'name'],
                'additionalProperties' => false,
            ],
            '_scope' => 'taxonomies:write',
        ];

        $all[] = [
            'name' => 'list_media',
            'description' => 'Browse the media library. Returns urls you can embed in post content. Requires media:read.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'search'   => ['type' => 'string'],
                    'type'     => ['type' => 'string', 'enum' => ['image', 'video', 'audio', 'document', 'any'], 'default' => 'any'],
                    'page'     => ['type' => 'integer', 'default' => 1],
                    'per_page' => ['type' => 'integer', 'description' => '1–50', 'default' => 20],
                ],
                'additionalProperties' => false,
            ],
            '_scope' => 'media:read',
        ];

        $all[] = [
            'name' => 'get_media',
            'description' => 'Get one media item by id — url, dimensions, alt text and caption. Requires media:read.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => ['id' => ['type' => 'integer']],
                'required' => ['id'],
                'additionalProperties' => false,
            ],
            '_scope' => 'media:read',
        ];

        $all[] = [
            'name' => 'list_comments',
            'description' => 'List comments, newest first. Filter by status to find what needs moderating. Requires comments:read.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'status'   => ['type' => 'string', 'enum' => ['pending', 'approved', 'spam', 'trash', 'any'], 'default' => 'pending'],
                    'search'   => ['type' => 'string'],
                    'page'     => ['type' => 'integer', 'default' => 1],
                    'per_page' => ['type' => 'integer', 'description' => '1–50', 'default' => 20],
                ],
                'additionalProperties' => false,
            ],
            '_scope' => 'comments:read',
        ];

        $all[] = [
            'name' => 'moderate_comment',
            'description' => 'Approve, unapprove, spam or trash a comment. Requires comments:write.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'id'     => ['type' => 'integer'],
                    'status' => ['type' => 'string', 'enum' => ['approved', 'pending', 'spam', 'trash']],
                ],
                'required' => ['id', 'status'],
                'additionalProperties' => false,
            ],
            '_scope' => 'comments:write',
        ];

        $all[] = [
            'name' => 'get_settings',
            'description' => 'Read a group of site settings (general, reading, writing, discussion, seo…). Secrets such as SMTP passwords and API keys are never returned. Requires settings:read.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'group' => ['type' => 'string', 'description' => 'e.g. general, reading, seo.', 'default' => 'general'],
                ],
                'additionalProperties' => false,
            ],
            '_scope' => 'settings:read',
        ];

        $all[] = [
            'name' => 'list_users',
            'description' => 'List site users (id, name, role). Email addresses are only included with users:read. Requires users:read.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'search'   => ['type' => 'string'],
                    'per_page' => ['type' => 'integer', 'description' => '1–50', 'default' => 20],
                ],
                'additionalProperties' => false,
            ],
            '_scope' => 'users:read',
        ];

        $all[] = [
            'name' => 'list_taxonomies',
            'description' => 'List categories and tags (taxonomies and their terms). Requires taxonomies:read.',
            'inputSchema' => ['type' => 'object', 'properties' => (object) [], 'additionalProperties' => false],
            '_scope' => 'taxonomies:read',
        ];

        $all[] = [
            'name' => 'list_widget_areas',
            'description' => 'List the widget areas (sidebars) the active theme registers, each with its widget count. Requires settings:read.',
            'inputSchema' => ['type' => 'object', 'properties' => (object) [], 'additionalProperties' => false],
            '_scope' => 'settings:read',
        ];

        $all[] = [
            'name' => 'get_widget_area',
            'description' => 'Get one widget area with its ordered widgets. Each widget includes its type, settings and server-rendered HTML. Requires settings:read.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'area' => ['type' => 'string', 'description' => 'Area key, e.g. sidebar or footer (from list_widget_areas).'],
                ],
                'required' => ['area'],
                'additionalProperties' => false,
            ],
            '_scope' => 'settings:read',
        ];

        $all[] = [
            'name' => 'list_menus',
            'description' => 'List navigation menus (id, name, slug, location, item count). Requires menus:read.',
            'inputSchema' => ['type' => 'object', 'properties' => (object) [], 'additionalProperties' => false],
            '_scope' => 'menus:read',
        ];

        $all[] = [
            'name' => 'delete_menu',
            'description' => 'Delete a navigation menu and all of its items. This is permanent. Pass an id from list_menus. Requires menus:write.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => ['id' => ['type' => 'integer', 'description' => 'Menu id to delete.']],
                'required' => ['id'],
                'additionalProperties' => false,
            ],
            '_scope' => 'menus:write',
        ];

        $all[] = [
            'name' => 'regenerate_thumbnails',
            'description' => 'Rebuild image thumbnails for the whole media library using the current Media settings (thumbnail/medium/large). Old variants are replaced. Returns a counts summary. Requires media:write.',
            'inputSchema' => ['type' => 'object', 'properties' => (object) [], 'additionalProperties' => false],
            '_scope' => 'media:write',
        ];

        // Apps contribute their own tools through the `mcp.tools` filter, the
        // same way they contribute admin menu items. Until 1.41.0 this list was
        // hardcoded, so an app could extend the site in every direction except
        // the one an AI assistant could see.
        //
        // Each contributed tool is an array shaped exactly like the core ones:
        //   ['name' =>, 'description' =>, 'inputSchema' =>, '_scope' => ?string]
        //
        // '_scope' is honoured identically, so an app tool can be gated behind
        // a scope the operator has to grant on the API key. A tool declaring a
        // scope nobody holds simply never appears.
        try {
            $contributed = $this->app()->make(\App\Core\HookRegistry::class)
                ->applyFilters('mcp.tools', [], $scopes);

            foreach ((array) $contributed as $tool) {
                if (!is_array($tool) || empty($tool['name'])) continue;

                // Core tools win a name collision. An app must not be able to
                // silently redefine what create_post means.
                foreach ($all as $existing) {
                    if ($existing['name'] === $tool['name']) continue 2;
                }

                $all[] = [
                    'name'        => (string) $tool['name'],
                    'description' => (string) ($tool['description'] ?? ''),
                    'inputSchema' => $tool['inputSchema'] ?? [
                        'type' => 'object', 'properties' => (object) [], 'additionalProperties' => false,
                    ],
                    '_scope'      => $tool['_scope'] ?? null,
                    '_app'        => true,
                ];
            }
        } catch (\Throwable $e) {
            $this->logQuietly('mcp.tools filter failed: ' . $e->getMessage());
        }

        // Filter by scope, and strip the internal markers.
        $out = [];
        foreach ($all as $tool) {
            $scope = $tool['_scope'];
            if ($scope !== null && !$has($scope)) continue;
            unset($tool['_scope'], $tool['_app']);
            $out[] = $tool;
        }
        return $out;
    }

    /** Names of tools contributed by apps this request. */
    private function appToolNames(): array
    {
        $names = [];
        try {
            $scopes = $this->authKey()['scopes'] ?? [];
            $contributed = $this->app()->make(\App\Core\HookRegistry::class)
                ->applyFilters('mcp.tools', [], $scopes);
            foreach ((array) $contributed as $tool) {
                if (is_array($tool) && !empty($tool['name'])) $names[] = (string) $tool['name'];
            }
        } catch (\Throwable) {
        }
        return $names;
    }

    /** Best-effort log that must never break a JSON-RPC response. */
    private function logQuietly(string $message): void
    {
        try {
            $this->app()->make(\App\Core\Logger::class)->warning('[mcp] ' . $message);
            return;
        } catch (\Throwable) {
            // Fall through — the container itself is the thing that failed.
        }

        // Last resort. This method exists to record why an MCP filter blew up,
        // and it previously swallowed its own failure: if the container could
        // not resolve, the diagnostic vanished and the fault erased its own
        // evidence. That is exactly what hid the 1.42.0 tools/list bug for a
        // full release cycle — the log line that would have named it was
        // written by code broken in the same way as the code it was reporting
        // on. error_log() needs nothing from the application.
        try {
            error_log('[basehim-mcp] ' . $message);
        } catch (\Throwable) {
        }
    }

    private function callTool(array $params): array
    {
        $name = (string) ($params['name'] ?? '');
        $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        // Ensure the tool exists AND is allowed for this key's scopes.
        $allowed = array_column($this->toolDefinitions(), 'name');
        if (!in_array($name, $allowed, true)) {
            // Distinguish "unknown" from "not permitted".
            $everything = [
                'get_site_info','search_content','list_posts','list_pages','get_post','get_page',
                'create_post','create_page','update_post','update_page','trash_post','set_post_terms',
                'create_term','list_media','get_media','list_comments','moderate_comment',
                'get_settings','list_users','list_taxonomies',
                'list_widget_areas','get_widget_area','list_menus','delete_menu','regenerate_thumbnails',
            ];
            if (in_array($name, $everything, true)) {
                return $this->toolError('This API key is not permitted to use the "' . $name . '" tool (missing scope).');
            }
            // App-contributed tools are not in the hardcoded list above, so
            // check the live registration before calling a name unknown — an
            // app tool gated by a scope should report the scope, not vanish.
            if (in_array($name, $this->appToolNames(), true)) {
                return $this->toolError('This API key is not permitted to use the "' . $name . '" tool (missing scope).');
            }
            return $this->toolError('Unknown tool: ' . $name);
        }

        return match ($name) {
            'get_site_info'    => $this->toolText($this->doSiteInfo()),
            'search_content'   => $this->toolText($this->doSearch($args)),
            'list_posts'       => $this->toolText($this->doListPosts($args, 'post')),
            'list_pages'       => $this->toolText($this->doListPosts($args, 'page')),
            'get_post'         => $this->toolText($this->doGetItem($args, 'post')),
            'get_page'         => $this->toolText($this->doGetItem($args, 'page')),
            'create_post'      => $this->toolText($this->doCreate($args, 'post')),
            'create_page'      => $this->toolText($this->doCreate($args, 'page')),
            'update_post'      => $this->toolText($this->doUpdate($args, 'post')),
            'update_page'      => $this->toolText($this->doUpdate($args, 'page')),
            'trash_post'       => $this->toolText($this->doTrash($args)),
            'set_post_terms'   => $this->toolText($this->doSetTerms($args)),
            'create_term'      => $this->toolText($this->doCreateTerm($args)),
            'list_media'       => $this->toolText($this->doListMedia($args)),
            'get_media'        => $this->toolText($this->doGetMedia($args)),
            'list_comments'    => $this->toolText($this->doListComments($args)),
            'moderate_comment' => $this->toolText($this->doModerateComment($args)),
            'get_settings'     => $this->toolText($this->doGetSettings($args)),
            'list_users'       => $this->toolText($this->doListUsers($args)),
            'list_taxonomies'  => $this->toolText($this->doListTaxonomies()),
            'list_widget_areas' => $this->toolText($this->doListWidgetAreas()),
            'get_widget_area'  => $this->toolText($this->doGetWidgetArea($args)),
            'list_menus'       => $this->toolText($this->doListMenus()),
            'delete_menu'      => $this->toolText($this->doDeleteMenu($args)),
            'regenerate_thumbnails' => $this->toolText($this->doRegenerateThumbnails()),
            default            => $this->callAppTool($name, $args),
        };
    }

    /**
     * Hand a tool call to whichever app registered it.
     *
     * The handler is invoked through the `mcp.call.{name}` filter. It receives
     * null and returns either a string, or an array to be JSON-encoded — the
     * app never has to know the MCP envelope format.
     *
     * A handler that throws produces a tool error rather than a JSON-RPC
     * transport error: the assistant should be told the tool failed and why,
     * not have the whole connection look broken.
     */
    private function callAppTool(string $name, array $args): array
    {
        if (!in_array($name, $this->appToolNames(), true)) {
            return $this->toolError('Unhandled tool: ' . $name);
        }

        try {
            $result = $this->app()->make(\App\Core\HookRegistry::class)
                ->applyFilters('mcp.call.' . $name, null, $args);

            if ($result === null) {
                return $this->toolError(
                    "The app registering \"{$name}\" did not return a result. "
                    . 'Its mcp.call handler may not be registered.'
                );
            }
            // Handlers may return structured data (arrays) — encode it, since
            // toolText() is string-typed. The previous ternary returned $result
            // on both branches, so any array-returning app tool fatalled with a
            // TypeError under strict_types.
            if (!is_string($result)) {
                $result = (string) json_encode(
                    $result,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR
                );
            }
            return $this->toolText($result);
        } catch (\Throwable $e) {
            $this->logQuietly("tool {$name} threw: " . $e->getMessage());
            return $this->toolError(get_class($e) . ': ' . $e->getMessage());
        }
    }

    // ---- tool implementations ----------------------------------------

    private function doSiteInfo(): string
    {
        $settings = $this->app()->make(SettingService::class);
        $posts = $this->app()->make(PostService::class);
        $counts = [];
        try { $counts = $posts->counts(); } catch (\Throwable) {}
        return $this->json([
            'site_title' => $settings->get('general', 'site_title', 'Basehim'),
            'tagline'    => $settings->get('general', 'tagline', ''),
            'url'        => $this->siteUrl(),
            'cms'        => 'Basehim ' . (defined('BASEHIM_VERSION') ? BASEHIM_VERSION : ''),
            'post_counts' => $counts,
        ]);
    }

    private function doSearch(array $args): string
    {
        $q = trim((string) ($args['query'] ?? ''));
        if ($q === '') throw new McpError('query is required', -32602);
        $limit = $this->clampInt($args['limit'] ?? 10, 1, 50, 10);

        $posts = $this->app()->make(PostService::class);
        $results = [];
        foreach (['post', 'page'] as $type) {
            $res = $posts->paginate(['search' => $q, 'status' => 'published', 'type' => $type], 1, $limit);
            foreach (($res['data'] ?? $res['items'] ?? []) as $p) {
                $results[] = [
                    'id'      => (int) ($p['id'] ?? 0),
                    'type'    => $type,
                    'title'   => $p['title'] ?? '',
                    'slug'    => $p['slug'] ?? '',
                    'excerpt' => $this->plain((string) ($p['excerpt'] ?? ''), 200),
                ];
                if (count($results) >= $limit) break 2;
            }
        }
        return $this->json(['query' => $q, 'count' => count($results), 'results' => $results]);
    }

    private function doListPosts(array $args, string $type = 'post'): string
    {
        $status = (string) ($args['status'] ?? 'published');
        $page = $this->clampInt($args['page'] ?? 1, 1, 100000, 1);
        $perPage = $this->clampInt($args['per_page'] ?? 10, 1, 50, 10);

        $filters = ['type' => $type];
        if ($status !== 'any') $filters['status'] = $status;

        $posts = $this->app()->make(PostService::class);
        $res = $posts->paginate($filters, $page, $perPage);
        $items = [];
        foreach (($res['data'] ?? $res['items'] ?? []) as $p) {
            $items[] = [
                'id'     => (int) ($p['id'] ?? 0),
                'title'  => $p['title'] ?? '',
                'slug'   => $p['slug'] ?? '',
                'status' => $p['status'] ?? '',
                'date'   => $p['published_at'] ?? $p['created_at'] ?? null,
            ];
        }
        return $this->json([
            'page' => $page,
            'per_page' => $perPage,
            'total' => $res['meta']['total'] ?? ($res['total'] ?? count($items)),
            ($type === 'page' ? 'pages' : 'posts') => $items,
        ]);
    }

    private function doGetItem(array $args, string $type): string
    {
        $posts = $this->app()->make(PostService::class);
        $item = null;
        if (!empty($args['id'])) {
            $item = $posts->find((int) $args['id']);
            if ($item && ($item['type'] ?? 'post') !== $type) $item = null;
        } elseif (!empty($args['slug'])) {
            $item = $posts->findBySlug((string) $args['slug'], $type);
        } else {
            throw new McpError('Provide either slug or id', -32602);
        }
        if (!$item) throw new McpError(ucfirst($type) . ' not found', -32602);

        return $this->json([
            'id'      => (int) $item['id'],
            'type'    => $item['type'] ?? $type,
            'title'   => $item['title'] ?? '',
            'slug'    => $item['slug'] ?? '',
            'status'  => $item['status'] ?? '',
            'content_format' => $item['content_format'] ?? 'html',
            'content' => $item['content'] ?? '',
            'excerpt' => $item['excerpt'] ?? '',
            'date'    => $item['published_at'] ?? $item['created_at'] ?? null,
        ]);
    }

    private function doCreate(array $args, string $type = 'post'): string
    {
        $title = trim((string) ($args['title'] ?? ''));
        if ($title === '') throw new McpError('title is required', -32602);
        $status = (string) ($args['status'] ?? 'draft');
        if (!in_array($status, ['draft', 'pending', 'published'], true)) $status = 'draft';

        // Creating writes a row you own, so the base capability applies; the
        // publish check is separate because writing and publishing are
        // different privileges.
        $user = $this->requireCapability($type === 'page' ? 'edit_pages' : 'edit_posts');
        $status = $this->clampStatus($status, $type);

        $posts = $this->app()->make(PostService::class);
        $authorId = (int) $user['id'];

        $id = $posts->create([
            'type'    => $type,
            'title'   => $title,
            'content' => (string) ($args['content'] ?? ''),
            'content_format' => in_array(($args['content_format'] ?? 'html'), ['html','markdown','blocks'], true) ? $args['content_format'] : 'html',
            'excerpt' => (string) ($args['excerpt'] ?? ''),
            'slug'    => (string) ($args['slug'] ?? ''),
            'status'  => $status,
        ], $authorId);

        $created = $posts->find($id);
        return $this->json([
            'created' => true,
            'type' => $type,
            'id' => $id,
            'slug' => $created['slug'] ?? '',
            'status' => $created['status'] ?? $status,
            'url' => $this->siteUrl() . '/' . ($created['slug'] ?? ''),
        ]);
    }

    private function doUpdate(array $args, string $type = 'post'): string
    {
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) throw new McpError('id is required', -32602);
        $posts = $this->app()->make(PostService::class);
        $existing = $posts->find($id);
        if (!$existing) throw new McpError(ucfirst($type) . ' not found', -32602);
        if (($existing['type'] ?? 'post') !== $type) {
            throw new McpError("That id is a {$existing['type']}, not a {$type} — use the matching tool.", -32602);
        }

        $this->requireRowCapability($existing, 'edit', $type);

        $patch = [];
        foreach (['title', 'content', 'excerpt', 'status'] as $f) {
            if (array_key_exists($f, $args)) $patch[$f] = $args[$f];
        }
        if (isset($patch['status'])) {
            if (!in_array($patch['status'], ['draft', 'pending', 'published'], true)) {
                unset($patch['status']);
            } else {
                $patch['status'] = $this->clampStatus((string) $patch['status'], $type);
            }
        }
        if (!$patch) throw new McpError('Nothing to update', -32602);

        $posts->update($id, $patch);
        $updated = $posts->find($id);
        return $this->json([
            'updated' => true,
            'id' => $id,
            'status' => $updated['status'] ?? '',
            'slug' => $updated['slug'] ?? '',
        ]);
    }

    private function doTrash(array $args): string
    {
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) throw new McpError('id is required', -32602);
        $posts = $this->app()->make(PostService::class);
        $item = $posts->find($id);
        if (!$item) throw new McpError('Not found', -32602);

        $this->requireRowCapability($item, 'delete', (string) ($item['type'] ?? 'post'));

        // Soft delete only. An assistant should never be able to destroy content
        // irrecoverably — the trash is emptied deliberately from the admin.
        $ok = $posts->delete($id);
        return $this->json([
            'trashed' => $ok,
            'id' => $id,
            'title' => $item['title'] ?? '',
            'note' => 'Moved to trash — restorable from the admin.',
        ]);
    }

    private function doSetTerms(array $args): string
    {
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) throw new McpError('id is required', -32602);
        if (!isset($args['term_ids']) || !is_array($args['term_ids'])) {
            throw new McpError('term_ids must be an array of term ids', -32602);
        }
        $posts = $this->app()->make(PostService::class);
        $existing = $posts->find($id);
        if (!$existing) throw new McpError('Post not found', -32602);

        $this->requireRowCapability($existing, 'edit', (string) ($existing['type'] ?? 'post'));

        $ids = array_values(array_filter(array_map('intval', $args['term_ids']), fn($n) => $n > 0));
        $posts->update($id, ['term_ids' => $ids]);
        return $this->json(['ok' => true, 'id' => $id, 'terms' => $posts->terms($id)]);
    }

    private function doCreateTerm(array $args): string
    {
        $tax = trim((string) ($args['taxonomy'] ?? ''));
        $name = trim((string) ($args['name'] ?? ''));
        if ($tax === '' || $name === '') throw new McpError('taxonomy and name are required', -32602);

        $this->requireCapability('manage_taxonomies');

        $svc = $this->app()->make(TaxonomyService::class);
        if (!$svc->findTaxonomyBySlug($tax)) {
            $known = implode(', ', array_column($svc->allTaxonomies(), 'slug'));
            throw new McpError("Unknown taxonomy '{$tax}'. Available: {$known}", -32602);
        }
        $id = $svc->createTerm($tax, [
            'name'        => $name,
            'slug'        => (string) ($args['slug'] ?? ''),
            'description' => (string) ($args['description'] ?? ''),
        ]);
        if (!$id) throw new McpError('Could not create the term — a term with that slug may already exist.', -32602);
        return $this->json(['created' => true, 'id' => $id, 'taxonomy' => $tax, 'term' => $svc->findTerm($id)]);
    }

    private function doListMedia(array $args): string
    {
        $filters = [];
        $type = (string) ($args['type'] ?? 'any');
        if ($type !== 'any' && $type !== '') $filters['type'] = $type;
        $search = trim((string) ($args['search'] ?? ''));
        if ($search !== '') $filters['search'] = $search;

        $perPage = $this->clampInt($args['per_page'] ?? 20, 1, 50, 20);
        $page = $this->clampInt($args['page'] ?? 1, 1, 100000, 1);
        $svc = $this->app()->make(\App\Services\MediaService::class);
        $res = $svc->paginate($filters, $page, $perPage);

        $items = [];
        foreach (($res['data'] ?? $res['items'] ?? []) as $m) {
            $items[] = $this->mediaShape($m);
        }
        return $this->json([
            'page' => $page, 'per_page' => $perPage,
            'total' => $res['meta']['total'] ?? count($items),
            'media' => $items,
        ]);
    }

    private function doGetMedia(array $args): string
    {
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) throw new McpError('id is required', -32602);
        $m = $this->app()->make(\App\Services\MediaService::class)->find($id);
        if (!$m) throw new McpError('Media not found', -32602);
        return $this->json($this->mediaShape($m));
    }

    /** One shape for media, so list and get agree. */
    private function mediaShape(array $m): array
    {
        $url = (string) ($m['url'] ?? '');
        return [
            'id'        => (int) ($m['id'] ?? 0),
            'title'     => $m['title'] ?? ($m['original_name'] ?? ''),
            'file_name' => $m['file_name'] ?? '',
            'mime_type' => $m['mime_type'] ?? '',
            'url'       => str_starts_with($url, 'http') ? $url : $this->siteUrl() . $url,
            'width'     => isset($m['width']) ? (int) $m['width'] : null,
            'height'    => isset($m['height']) ? (int) $m['height'] : null,
            'size'      => (int) ($m['file_size'] ?? 0),
            'alt'       => $m['alt_text'] ?? '',
            'caption'   => $m['caption'] ?? '',
            'uploaded'  => $m['created_at'] ?? null,
        ];
    }

    private function doListComments(array $args): string
    {
        $filters = [];
        $status = (string) ($args['status'] ?? 'pending');
        if ($status !== 'any' && $status !== '') $filters['status'] = $status;
        $search = trim((string) ($args['search'] ?? ''));
        if ($search !== '') $filters['search'] = $search;

        $perPage = $this->clampInt($args['per_page'] ?? 20, 1, 50, 20);
        $page = $this->clampInt($args['page'] ?? 1, 1, 100000, 1);
        $svc = $this->app()->make(\App\Services\CommentService::class);
        $res = $svc->paginate($filters, $page, $perPage);

        $items = [];
        foreach (($res['data'] ?? $res['items'] ?? []) as $c) {
            $items[] = [
                'id'          => (int) ($c['id'] ?? 0),
                'post_id'     => (int) ($c['post_id'] ?? 0),
                'post_title'  => $c['post_title'] ?? null,
                'author_name' => $c['author_name'] ?? '',
                'status'      => $c['status'] ?? '',
                'content'     => $this->plain((string) ($c['content'] ?? ''), 500),
                'created_at'  => $c['created_at'] ?? null,
            ];
        }
        $counts = [];
        try { $counts = $svc->counts(); } catch (\Throwable) {}
        return $this->json([
            'page' => $page, 'per_page' => $perPage,
            'total' => $res['meta']['total'] ?? count($items),
            'counts' => $counts,
            'comments' => $items,
        ]);
    }

    private function doModerateComment(array $args): string
    {
        $id = (int) ($args['id'] ?? 0);
        $status = (string) ($args['status'] ?? '');
        if ($id <= 0) throw new McpError('id is required', -32602);
        if (!in_array($status, ['approved', 'pending', 'spam', 'trash'], true)) {
            throw new McpError('status must be approved, pending, spam or trash', -32602);
        }
        $this->requireCapability('moderate_comments');

        $svc = $this->app()->make(\App\Services\CommentService::class);
        if (!$svc->find($id)) throw new McpError('Comment not found', -32602);
        $ok = $svc->setStatus($id, $status);
        return $this->json(['ok' => $ok, 'id' => $id, 'status' => $status]);
    }

    private function doGetSettings(array $args): string
    {
        $group = trim((string) ($args['group'] ?? 'general')) ?: 'general';
        $values = $this->app()->make(SettingService::class)->getGroup($group);
        // Never hand credentials to a client, whatever scope it holds.
        $redacted = [];
        foreach ($values as $k => $v) {
            $redacted[$k] = preg_match('/(pass|secret|key|token|dsn|smtp_pw)/i', (string) $k)
                ? '[redacted]'
                : $v;
        }
        return $this->json(['group' => $group, 'settings' => $redacted]);
    }

    private function doListUsers(array $args): string
    {
        // Personal data: the users:read scope is necessary but not sufficient.
        $this->requireCapability('manage_users');

        $perPage = $this->clampInt($args['per_page'] ?? 20, 1, 50, 20);
        $search = trim((string) ($args['search'] ?? ''));
        $svc = $this->app()->make(\App\Services\UserService::class);
        $res = $svc->paginate($search !== '' ? ['search' => $search] : [], 1, $perPage);
        $items = [];
        foreach (($res['data'] ?? $res['items'] ?? []) as $u) {
            $items[] = [
                'id'           => (int) ($u['id'] ?? 0),
                'username'     => $u['username'] ?? '',
                'display_name' => $u['display_name'] ?? '',
                'email'        => $u['email'] ?? '',
                'role'         => $u['role'] ?? '',
                'status'       => $u['status'] ?? '',
            ];
        }
        return $this->json(['total' => $res['meta']['total'] ?? count($items), 'users' => $items]);
    }

    private function doListTaxonomies(): string
    {
        $tax = $this->app()->make(TaxonomyService::class);
        $out = [];
        foreach ($tax->allTaxonomies() as $t) {
            $slug = $t['slug'] ?? '';
            $terms = [];
            foreach ($tax->termsByTaxonomySlug($slug) as $term) {
                $terms[] = ['id' => (int) ($term['id'] ?? 0), 'name' => $term['name'] ?? '', 'slug' => $term['slug'] ?? '', 'count' => (int) ($term['count'] ?? 0)];
            }
            $out[] = ['taxonomy' => $slug, 'label' => $t['label'] ?? $slug, 'terms' => $terms];
        }
        return $this->json(['taxonomies' => $out]);
    }

    private function doListWidgetAreas(): string
    {
        $reg = $this->app()->make(\App\Core\WidgetAreaRegistry::class);
        $svc = $this->app()->make(\App\Services\WidgetAreaService::class);
        $out = [];
        foreach ($reg->all() as $area) {
            $out[] = [
                'key'          => $area['key'],
                'name'         => $area['name'],
                'description'  => $area['description'],
                'source'       => $area['source'],
                'widget_count' => count($svc->assignmentsFor($area['key'])),
            ];
        }
        return $this->json(['widget_areas' => $out]);
    }

    private function doGetWidgetArea(array $args): string
    {
        $key = trim((string) ($args['area'] ?? ''));
        if ($key === '') return $this->json(['error' => 'An "area" key is required.']);

        $reg = $this->app()->make(\App\Core\WidgetAreaRegistry::class);
        $def = $reg->get($key);
        if (!$def) return $this->json(['error' => 'No widget area registered with key "' . $key . '".']);

        $svc     = $this->app()->make(\App\Services\WidgetAreaService::class);
        $widgets = $this->app()->make(\App\Core\WidgetRegistry::class);

        $items = [];
        foreach ($svc->assignmentsFor($key) as $inst) {
            $wk   = (string) ($inst['widget'] ?? '');
            $meta = $widgets->get($wk);
            if (!$meta || !in_array('frontend', $meta['surfaces'], true)) continue;
            $settings = is_array($inst['settings'] ?? null) ? $inst['settings'] : [];
            $items[] = [
                'id'       => (string) ($inst['id'] ?? ''),
                'widget'   => $wk,
                'title'    => $meta['title'],
                'settings' => $settings,
                'html'     => $widgets->render($wk, $settings, 'frontend'),
            ];
        }

        return $this->json([
            'key'         => $def['key'],
            'name'        => $def['name'],
            'description' => $def['description'],
            'items'       => $items,
        ]);
    }

    private function doListMenus(): string
    {
        $menus = $this->app()->make(\App\Services\MenuService::class);
        $out = [];
        foreach ($menus->all() as $m) {
            $id = (int) ($m['id'] ?? 0);
            $out[] = [
                'id'         => $id,
                'name'       => $m['name'] ?? '',
                'slug'       => $m['slug'] ?? '',
                'location'   => $m['location'] ?? null,
                'item_count' => count($menus->items($id)),
            ];
        }
        return $this->json(['menus' => $out]);
    }

    private function doDeleteMenu(array $args): string
    {
        $id = (int) ($args['id'] ?? 0);
        if ($id < 1) return $this->json(['error' => 'A numeric menu "id" is required.']);

        $this->requireCapability('manage_menus');

        $menus = $this->app()->make(\App\Services\MenuService::class);
        $menu = $menus->find($id);
        if (!$menu) return $this->json(['error' => 'No menu with id ' . $id . '.']);

        $ok = $menus->delete($id);
        return $this->json([
            'deleted' => $ok,
            'id'      => $id,
            'name'    => $menu['name'] ?? '',
        ]);
    }

    private function doRegenerateThumbnails(): string
    {
        // Rewrites every derivative in the library — an expensive, destructive
        // maintenance action, not a content edit.
        $this->requireCapability('upload_media');

        $media = $this->app()->make(\App\Services\MediaService::class);
        $r = $media->regenerateAll();
        return $this->json([
            'ok'        => true,
            'processed' => $r['processed'],
            'variants'  => $r['variants'],
            'skipped'   => $r['skipped'],
            'failed'    => $r['failed'],
        ]);
    }

    private function listResources(): array
    {
        // Advertise recent published posts as readable resources.
        $resources = [[
            'uri'  => 'basehim://site/info',
            'name' => 'Site information',
            'description' => 'Basic metadata about this Basehim site.',
            'mimeType' => 'application/json',
        ]];
        try {
            if (in_array('posts:read', $this->authKey()['scopes'] ?? [], true)) {
                $posts = $this->app()->make(PostService::class);
                foreach ($posts->recent(15, 'post') as $p) {
                    $resources[] = [
                        'uri'  => 'basehim://post/' . ($p['slug'] ?? $p['id']),
                        'name' => $p['title'] ?? ('Post ' . ($p['id'] ?? '')),
                        'description' => $this->plain((string) ($p['excerpt'] ?? ''), 140),
                        'mimeType' => 'text/html',
                    ];
                }
            }
        } catch (\Throwable) {}
        return ['resources' => $resources];
    }

    private function readResource(array $params): array
    {
        $uri = (string) ($params['uri'] ?? '');
        // Clients discover every URI from resources/list, which only ever emits
        // the basehim:// scheme, so nothing else needs accepting here.
        if ($uri === 'basehim://site/info') {
            return ['contents' => [['uri' => $uri, 'mimeType' => 'application/json', 'text' => $this->doSiteInfo()]]];
        }
        if (preg_match('#^basehim://post/(.+)$#', $uri, $m)) {
            $this->requireScope('posts:read');
            $posts = $this->app()->make(PostService::class);
            $ident = $m[1];
            $post = ctype_digit($ident) ? $posts->find((int) $ident) : $posts->findBySlug($ident, 'post');
            if (!$post) throw new McpError('Resource not found', -32602);
            return ['contents' => [[
                'uri' => $uri,
                'mimeType' => 'text/html',
                'text' => (string) ($post['content'] ?? ''),
            ]]];
        }
        throw new McpError('Unknown resource URI', -32602);
    }

    // ==================================================================
    // Auth
    // ==================================================================

    private ?array $keyRecord = null;
    private bool $keyResolved = false;

    private function authKey(): array
    {
        if ($this->keyResolved) {
            if ($this->keyRecord === null) throw new McpError('Unauthorized: a valid API key is required.', -32001);
            return $this->keyRecord;
        }
        $this->keyResolved = true;

        $raw = '';
        $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if ($hdr === '' && function_exists('apache_request_headers')) {
            $h = apache_request_headers();
            $hdr = $h['Authorization'] ?? $h['authorization'] ?? '';
        }
        if (stripos($hdr, 'bearer ') === 0) {
            $raw = trim(substr($hdr, 7));
        }
        // NOTE: no ?key= fallback. The MCP authorization spec forbids credentials
        // in the query string (they leak via logs, proxies and history).

        if ($raw === '') {
            throw new McpError('Unauthorized: authenticate with OAuth, or send an API key as "Authorization: Bearer <key>".', -32001);
        }

        // 1. OAuth access token (how Claude's web connector authenticates).
        try {
            $oauth = $this->app()->make(\App\Services\McpOAuthService::class)->validateAccessToken($raw);
        } catch (\Throwable) { $oauth = null; }
        if ($oauth) {
            $this->keyRecord = [
                'user_id' => $oauth['user_id'],
                'scopes'  => $oauth['scopes'],
                'name'    => 'OAuth client',
                'via'     => 'oauth',
            ];
            return $this->keyRecord;
        }

        // 2. Basehim API key (direct/programmatic clients: Claude Code, curl…).
        try {
            $rec = $this->app()->make(ApiKeyService::class)->validate($raw);
        } catch (\Throwable) {
            $rec = null;
        }
        if (!$rec) throw new McpError('Unauthorized: invalid or expired credentials.', -32001);

        $this->keyRecord = $rec;
        return $rec;
    }

    private function requireScope(string $scope): void
    {
        if (!in_array($scope, $this->authKey()['scopes'] ?? [], true)) {
            throw new McpError('This API key lacks the required scope: ' . $scope, -32002);
        }
    }

    // ==================================================================
    // Capability enforcement
    //
    // A scope says which API surface a credential may touch. It does NOT say
    // which rows. Those are two different questions and the tools used to ask
    // only the first: `posts:write` maps to edit_posts, which a contributor
    // holds, so a contributor's token could edit, publish and trash anyone's
    // work — none of which they can do in the admin UI.
    // ==================================================================

    /** The user the current credential acts for, or null. */
    private function actingUser(): ?array
    {
        $id = (int) ($this->authKey()['user_id'] ?? 0);
        if ($id <= 0) return null;
        try {
            return $this->app()->make(\App\Repositories\UserRepository::class)->find($id);
        } catch (\Throwable) {
            return null;
        }
    }

    private function requireCapability(string $cap): array
    {
        $user = $this->actingUser();
        if (!$user) {
            throw new McpError('The account behind this credential no longer exists.', -32002);
        }
        if (!\App\Http\Middleware\CheckCapability::userCan($user, $cap)) {
            throw new McpError("This account does not have the '{$cap}' capability.", -32002);
        }
        return $user;
    }

    /**
     * Capability gate for acting on one existing row. Ownership picks between
     * the base capability and its _others_ variant, exactly as the admin does.
     */
    private function requireRowCapability(array $row, string $action, string $type = 'post'): array
    {
        $user = $this->actingUser();
        if (!$user) {
            throw new McpError('The account behind this credential no longer exists.', -32002);
        }
        $family = $type === 'page' ? 'pages' : 'posts';
        $own = (int) ($row['author_id'] ?? 0) === (int) ($user['id'] ?? -1);
        $cap = $own ? "{$action}_{$family}" : "{$action}_others_{$family}";

        if (!\App\Http\Middleware\CheckCapability::userCan($user, $cap)) {
            throw new McpError("This account does not have the '{$cap}' capability.", -32002);
        }
        return $user;
    }

    /** Downgrade a status the acting account may not set, rather than failing. */
    private function clampStatus(string $status, string $type = 'post'): string
    {
        if ($status !== 'published') return $status;
        $user = $this->actingUser();
        $cap = $type === 'page' ? 'publish_pages' : 'publish_posts';
        if (!\App\Http\Middleware\CheckCapability::userCan($user, $cap)) {
            return 'pending';
        }
        return $status;
    }

    // ==================================================================
    // Helpers
    // ==================================================================

    private function ok(mixed $id, mixed $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    private function err(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }

    /** Wrap plain text as a successful tool result. */
    private function toolText(string $text): array
    {
        return ['content' => [['type' => 'text', 'text' => $text]], 'isError' => false];
    }

    /** Wrap an error as a tool result (isError=true, per MCP tool semantics). */
    private function toolError(string $text): array
    {
        return ['content' => [['type' => 'text', 'text' => $text]], 'isError' => true];
    }

    private function json(array $data): string
    {
        return (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function raw(array $payload): Response
    {
        $headers = ['Content-Type' => 'application/json'];
        $status  = 200;

        // RFC 9728 §5.1 / MCP auth spec: an unauthenticated request must return
        // 401 with a WWW-Authenticate header pointing at the protected-resource
        // metadata. That header is exactly how Claude discovers where to send
        // the user for OAuth — without it, a connector cannot bootstrap.
        if ($this->isAuthError($payload)) {
            $status = 401;
            try {
                $meta = $this->app()->make(\App\Services\McpOAuthService::class);
                $headers['WWW-Authenticate'] = 'Bearer realm="Basehim MCP", '
                    . 'resource_metadata="' . $meta->issuer() . '/.well-known/oauth-protected-resource"';
            } catch (\Throwable) {
                $headers['WWW-Authenticate'] = 'Bearer realm="Basehim MCP"';
            }
        }

        return new Response(
            (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $status,
            $headers
        );
    }

    /** True when the JSON-RPC payload is (or contains) an auth error. */
    private function isAuthError(array $payload): bool
    {
        $isErr = fn($m) => is_array($m) && isset($m['error']['code'])
            && in_array((int) $m['error']['code'], [-32001, -32002], true);
        if ($isErr($payload)) return true;
        foreach ($payload as $m) { if ($isErr($m)) return true; }
        return false;
    }

    private function readJson(Request $request): mixed
    {
        $raw = file_get_contents('php://input') ?: '';
        if ($raw === '') return [];
        $decoded = json_decode($raw, true);
        return $decoded === null && json_last_error() !== JSON_ERROR_NONE ? [] : $decoded;
    }

    private function clampInt(mixed $v, int $min, int $max, int $default): int
    {
        $n = is_numeric($v) ? (int) $v : $default;
        return max($min, min($max, $n));
    }

    private function plain(string $html, int $len): string
    {
        $t = trim(preg_replace('/\s+/', ' ', strip_tags($html)) ?? '');
        return mb_strlen($t) > $len ? mb_substr($t, 0, $len - 1) . '…' : $t;
    }

    private function app(): Application
    {
        return Application::getInstance();
    }

    private function siteName(): string
    {
        try { return (string) $this->app()->make(SettingService::class)->get('general', 'site_title', 'Basehim'); }
        catch (\Throwable) { return 'Basehim'; }
    }

    private function siteUrl(): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $base = defined('BASEHIM_BASE') ? rtrim((string) BASEHIM_BASE, '/') : '';
        return $scheme . '://' . $host . $base;
    }
}

/** Internal exception carrying a JSON-RPC error code. */
final class McpError extends \RuntimeException
{
    public int $rpcCode;
    public function __construct(string $message, int $rpcCode = -32603)
    {
        parent::__construct($message);
        $this->rpcCode = $rpcCode;
    }
}
