<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ajax;

use App\Core\Ajax\AjaxContext;
use App\Core\Ajax\AjaxException;
use App\Core\Application;
use App\Services\ActivityLogService;
use App\Services\AppService;
use App\Services\PermissionBroker;
use App\Services\ThemeService;

/**
 * The marketplace's gateway actions (1.2.84): browse, categories, install and
 * activate, for apps (apps.market_*) and themes (themes.market_*).
 *
 * They replace the hand-written JSON routes the page used before
 * (/admin/apps/marketplace/browse.json and friends, which remain for older
 * callers). The services keep returning their ['ok' => …] arrays; this class
 * turns a failure into an AjaxException so the client gets the gateway's one
 * error shape — a code it can branch on and a message it can show as is.
 */
final class MarketplaceActions
{
    // ---------------------------------------------------------------- apps

    public function appsBrowse(AjaxContext $ctx): array
    {
        $r = $this->apps()->marketplaceBrowse(self::query($ctx));
        $r = self::unwrap($r);
        $r['items'] = $r['apps'] ?? $r['plugins'] ?? [];
        unset($r['apps'], $r['plugins'], $r['ok']);
        return $r;
    }

    public function appsFacets(AjaxContext $ctx): array
    {
        return self::facets($this->apps()->marketplaceFacets());
    }

    public function appsInstall(AjaxContext $ctx): array
    {
        $slug = (string) $ctx->input('slug');
        $r = self::unwrap($this->apps()->marketplaceInstall($slug), 'INSTALL_FAILED', 422);
        ActivityLogService::record($ctx->userId(), 'app.installed', 'app', null, "Installed '{$slug}' from the marketplace");
        $ctx->log('Installed from the marketplace', ['slug' => $slug]);
        $row = $this->apps()->find($slug);
        return ['installed' => true, 'version' => (string) ($row['version'] ?? '')];
    }

    /** What activating this app will ask for: the consent step, as data. */
    public function appsConsent(AjaxContext $ctx): array
    {
        $slug = (string) $ctx->input('slug');
        $apps = $this->apps();
        $row = $apps->find($slug);
        if (!$row) throw AjaxException::notFound("That app isn't installed.");
        $broker = $this->broker();
        return [
            'slug'          => $slug,
            'name'          => (string) ($row['name'] ?? $slug),
            'needs_consent' => $apps->needsConsent($slug),
            'permissions'   => $broker->describeAll($broker->declared($slug)),
            'unknown'       => $broker->unknownDeclared($slug),
            'granted'       => $broker->grantedFor($slug),
        ] + $apps->consentContext($slug);
    }

    /**
     * Activate an installed app. With `consent` set, the permissions in
     * `permissions` are recorded first (an empty list withholds them all);
     * without it, an app that still needs approval answers needs_consent and
     * stays off, so the page can show the consent step.
     */
    public function appsActivate(AjaxContext $ctx): array
    {
        $slug = (string) $ctx->input('slug');
        $apps = $this->apps();
        if (!$apps->find($slug)) throw AjaxException::notFound("That app isn't installed.");
        $broker = $this->broker();

        if ($ctx->input('consent')) {
            $broker->grant($slug, array_map('strval', (array) $ctx->input('permissions', [])));
        } elseif ($apps->needsConsent($slug)) {
            return ['active' => false, 'needs_consent' => true];
        }

        try {
            $ok = $apps->activate($slug);
        } catch (\Throwable $e) {
            throw new AjaxException('ACTIVATION_FAILED', 'Activation failed: ' . $e->getMessage(), 422);
        }
        if (!$ok) {
            $problems = $apps->lastRequirementProblems();
            throw new AjaxException('ACTIVATION_FAILED', $problems
                ? 'It can\'t be turned on yet: ' . implode(' ', $problems)
                : 'It could not be turned on.', 422);
        }
        $ctx->log('Activated from the marketplace', ['slug' => $slug]);
        return ['active' => true, 'withheld_count' => count($broker->withheld($slug))];
    }

    // -------------------------------------------------------------- themes

    public function themesBrowse(AjaxContext $ctx): array
    {
        $r = self::unwrap($this->themes()->marketplaceBrowse(self::query($ctx)));
        $r['items'] = $r['themes'] ?? [];
        unset($r['themes'], $r['ok']);
        return $r;
    }

    public function themesFacets(AjaxContext $ctx): array
    {
        return self::facets($this->themes()->marketplaceFacets());
    }

    public function themesInstall(AjaxContext $ctx): array
    {
        $slug = (string) $ctx->input('slug');
        self::unwrap($this->themes()->marketplaceInstall($slug), 'INSTALL_FAILED', 422);
        ActivityLogService::record($ctx->userId(), 'theme.installed', 'theme', null, "Installed '{$slug}' from the marketplace");
        $ctx->log('Installed from the marketplace', ['slug' => $slug]);
        return ['installed' => true];
    }

    public function themesActivate(AjaxContext $ctx): array
    {
        $slug = (string) $ctx->input('slug');
        if (!$this->themes()->activate($slug)) throw AjaxException::notFound("That theme isn't installed.");
        $ctx->log('Theme applied from the marketplace', ['slug' => $slug]);
        return ['active' => true];
    }

    // ------------------------------------------------------------- helpers

    /** @return array<string,string> */
    private static function query(AjaxContext $ctx): array
    {
        return [
            'q'        => (string) $ctx->input('q', ''),
            'category' => (string) $ctx->input('category', ''),
            'tag'      => (string) $ctx->input('tag', ''),
            'sort'     => (string) $ctx->input('sort', ''),
            'page'     => (string) $ctx->input('page', ''),
        ];
    }

    /**
     * A service result, or the matching AjaxException. 'not_connected' gets
     * its own code so the page can offer the way to fix it.
     */
    private static function unwrap(array $r, string $code = 'MARKETPLACE_ERROR', int $status = 502): array
    {
        if (!empty($r['ok'])) return $r;
        $err = (string) ($r['error'] ?? '');
        if ($err === 'not_connected') {
            throw new AjaxException('NOT_CONNECTED', "This site isn't connected to the Basehim marketplace yet.", 503);
        }
        throw new AjaxException($code, $err !== '' ? $err : 'The marketplace did not answer. Try again in a moment.', $status);
    }

    private static function facets(array $r): array
    {
        if (empty($r['ok'])) return ['categories' => [], 'tags' => []];
        $cats = [];
        foreach ((array) ($r['categories'] ?? []) as $c) {
            if (!is_array($c) || trim((string) ($c['name'] ?? '')) === '') continue;
            $cats[] = ['name' => (string) $c['name'], 'count' => (int) ($c['count'] ?? 0)];
        }
        return ['categories' => $cats, 'tags' => array_values((array) ($r['tags'] ?? []))];
    }

    private function apps(): AppService { return Application::getInstance()->make(AppService::class); }
    private function themes(): ThemeService { return Application::getInstance()->make(ThemeService::class); }
    private function broker(): PermissionBroker { return Application::getInstance()->make(PermissionBroker::class); }
}
