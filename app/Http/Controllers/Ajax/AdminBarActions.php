<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ajax;

use App\Core\AdminBar\AdminBar;
use App\Core\AdminBar\AdminBarContext;
use App\Core\AdminBar\AdminBarSettings;
use App\Core\Ajax\AjaxContext;
use App\Core\Ajax\AjaxException;
use App\Core\Application;
use App\Services\UserService;

/**
 * The frontend admin bar's AJAX actions (1.2.96), registered in
 * routes/ajax.php under core's "admin" namespace.
 *
 * Each call carries the page's context as the signed descriptor the page
 * was rendered with (AdminBarContext::descriptor()), keyed to this session:
 * a call can only ask about the page this user was shown, and a changed or
 * borrowed descriptor is refused. Every component is checked again here —
 * capability, roles, context, visible() — so asking for a panel, indicator
 * or search source the user can't see gets nothing, whatever the browser
 * sends.
 */
final class AdminBarActions
{
    private Application $app;

    public function __construct()
    {
        $this->app = Application::getInstance();
    }

    /** admin.bar_panel — one panel's contents, when its dropdown opens. */
    public function panel(AjaxContext $ctx): array
    {
        [$user, $context] = $this->begin($ctx);
        $r = $this->bar()->runPanel((string) $ctx->input('id'), $context, $user);
        if ($r === null) throw AjaxException::notFound('That panel is not available here.');
        return $r;
    }

    /** admin.bar_indicators — the page's lazy badges, in one call after it loads. */
    public function indicators(AjaxContext $ctx): array
    {
        [$user, $context] = $this->begin($ctx);
        $ids = array_filter(array_map('trim', explode(',', (string) $ctx->input('ids', ''))));
        return ['values' => (object) $this->bar()->runIndicators($ids, $context, $user)];
    }

    /** admin.bar_notifications — the bell's notices for this user. */
    public function notifications(AjaxContext $ctx): array
    {
        [$user, $context] = $this->begin($ctx, true);
        // The admin header's bell is part of the admin, not of the front bar,
        // so the front bar's switches do not turn it off.
        if ($context->type() !== 'admin' && !$this->settings()->effective($user)['notifications']) return ['items' => []];
        return ['items' => $this->bar()->runNotifications($user, $context)];
    }

    /** admin.bar_search — quick search across what this user may edit. */
    public function search(AjaxContext $ctx): array
    {
        [$user, $context] = $this->begin($ctx, true);
        if ($context->type() !== 'admin' && !$this->settings()->effective($user)['search']) throw AjaxException::forbidden('Quick search is switched off.');
        $q = trim((string) $ctx->input('q', ''));
        if (mb_strlen($q) < 2) return ['groups' => [], 'query' => $q];
        return ['groups' => $this->bar()->runSearch($q, $user, $context, 5), 'query' => $q];
    }

    /** admin.bar_url — the shown post's address now (after Quick edit changed its slug). */
    public function url(AjaxContext $ctx): array
    {
        [$user, $context] = $this->begin($ctx);
        $id = $context->contentId();
        if ($id <= 0) throw AjaxException::notFound();
        $post = $this->app->make(\App\Repositories\PostRepository::class)->find($id);
        if (!$post || !empty($post['deleted_at']) || !$this->app->make(\App\Services\PostEditorService::class)->canEdit($user, $post)) {
            throw AjaxException::notFound();
        }
        $base = defined('BASEHIM_BASE') ? rtrim((string) BASEHIM_BASE, '/') : '';
        $url = $post['status'] === 'published' ? \App\Core\Helpers::postUrl($post) : '';
        return ['url' => $url !== '' ? $base . $url : null, 'status' => (string) $post['status']];
    }

    /**
     * admin.bar_prefs — save the user's own choices: display mode, button
     * position, tucked away, hidden sections. Only what the site lets users
     * change is stored; anything else is refused.
     */
    public function prefs(AjaxContext $ctx): array
    {
        $user = $ctx->user();
        $settings = $this->settings();
        if ($user === null || !$settings->allows($user)) throw AjaxException::forbidden();
        $site = $settings->site();
        $can = array_flip($site['user_options']);
        $current = $settings->userPrefs($user);
        $in = [];
        foreach (['mode' => 'mode', 'position' => 'position', 'hidden' => 'sections'] as $key => $option) {
            $v = $ctx->input($key);
            if ($v === null || $v === '') continue;
            if (!isset($can[$option])) throw AjaxException::forbidden('Your site does not let users change this.');
            $in[$key] = $key === 'hidden' ? (json_decode((string) $v, true) ?: []) : (string) $v;
        }
        if ($ctx->input('collapsed') !== null && $ctx->input('collapsed') !== '') {
            if (!$site['remember']) throw AjaxException::forbidden('Your site does not remember this.');
            $in['collapsed'] = (bool) $ctx->input('collapsed');
        }
        if ($in === []) throw AjaxException::invalid('Nothing to save.');
        $clean = AdminBarSettings::cleanPrefs($in);
        if (count($clean) !== count($in)) throw AjaxException::invalid('That choice is not one of the options.');
        $next = AdminBarSettings::cleanPrefs($clean + $current);
        if (!$this->app->make(UserService::class)->setMeta((int) $user['id'], AdminBarSettings::META_KEY, $next)) {
            throw new AjaxException('NOT_SAVED', 'Your choice could not be saved. Try again.', 500);
        }
        return ['prefs' => $next];
    }

    /**
     * The signed-in user and the page the call is about.
     *
     * $adminHeader: the call may come from the admin's own header (search and
     * notifications only). Its descriptor says type "admin" and is signed like
     * any other, so it cannot be forged; anyone who can open the admin may use
     * it, whether or not the site shows them the front-end bar. What each
     * provider returns is still filtered for this user.
     *
     * @return array{0:array,1:AdminBarContext}
     */
    private function begin(AjaxContext $ctx, bool $adminHeader = false): array
    {
        $user = $ctx->user();
        if ($user === null) throw AjaxException::forbidden();
        // The front bar's own calls are refused before the descriptor is read,
        // as they always were, for anyone the site does not show the bar to.
        if (!$adminHeader && !$this->settings()->allows($user)) throw AjaxException::forbidden();
        $secret = $this->app->make(\App\Core\Session::class)->csrfToken();
        $context = AdminBarContext::fromDescriptor((string) $ctx->input('ctx', ''), $secret);
        if ($context === null) {
            throw new AjaxException('CONTEXT_INVALID', 'This page is out of date (you signed in or out somewhere else). Reload it and try again.', 409);
        }
        if ($adminHeader && $context->type() !== 'admin' && !$this->settings()->allows($user)) throw AjaxException::forbidden();
        if (!$adminHeader && $context->type() === 'admin') throw AjaxException::notFound('That is not available in the admin header.');
        return [$user, $context];
    }

    private function bar(): AdminBar
    {
        return $this->app->make(AdminBar::class);
    }

    private function settings(): AdminBarSettings
    {
        return $this->app->make(AdminBarSettings::class);
    }
}
