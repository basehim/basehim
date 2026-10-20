<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ajax;

use App\Core\Ajax\AjaxContext;
use App\Core\Ajax\AjaxException;
use App\Services\Dashboard\DashboardService;
use App\Services\Dashboard\InvalidSettings;

/**
 * The dashboard's AJAX actions (registered in routes/ajax.php, 1.2.68).
 *
 * Every action is registered with capability 'read' — the dashboard is open
 * to every signed-in role — and the REAL gate is per widget, inside
 * DashboardService: the widget's capability and visibility rule, the
 * action's own capability, and the user's stored layout deciding which
 * widget an instance id stands for. The browser never chooses a widget or its
 * settings for a data request; it names a card on its own dashboard.
 */
final class DashboardActions
{
    public function __construct(private DashboardService $dashboard) {}

    /** GET widgets.dashboard_state — the layout and the Add Widgets catalogue. */
    public function state(AjaxContext $ctx): array
    {
        return $this->stateFor($this->user($ctx));
    }

    /** GET widgets.dashboard_data — data for some cards, each independent. */
    public function data(AjaxContext $ctx): array
    {
        $ids = array_values(array_filter(array_map('trim', explode(',', (string) $ctx->input('ids', ''))), fn($s) => $s !== ''));
        if ($ids === []) throw AjaxException::invalid('Name at least one widget.');
        if (count($ids) > DashboardService::MAX_BATCH) {
            throw AjaxException::invalid('Ask for at most ' . DashboardService::MAX_BATCH . ' widgets at once.');
        }
        return ['widgets' => $this->dashboard->data($this->user($ctx), $ids, (bool) $ctx->input('force', false))];
    }

    /** POST widgets.dashboard_save — order, sizes and visibility of every card. */
    public function save(AjaxContext $ctx): array
    {
        $layout = json_decode((string) $ctx->input('layout'), true, 6);
        if (!is_array($layout) || !array_is_list($layout)) {
            throw AjaxException::invalid('The layout could not be read.', ['layout' => ['Send the cards as a JSON list.']]);
        }
        $user = $this->user($ctx);
        $this->guard(fn() => $this->dashboard->saveLayout($user, $layout));
        $ctx->log('Dashboard layout saved', ['user' => $ctx->userId(), 'cards' => count($layout)]);
        return $this->stateFor($user);
    }

    /** POST widgets.dashboard_update — collapse/expand, remove/restore or configure one card. */
    public function update(AjaxContext $ctx): array
    {
        $changes = [];
        if ($ctx->has('collapsed')) $changes['collapsed'] = (bool) $ctx->input('collapsed');
        if ($ctx->has('visible')) $changes['visible'] = (bool) $ctx->input('visible');
        if ($ctx->has('settings')) {
            $settings = json_decode((string) $ctx->input('settings'), true, 4);
            if (!is_array($settings) || (array_is_list($settings) && $settings !== [])) {
                throw AjaxException::invalid('The settings could not be read.', ['settings' => ['Send the settings as a JSON object.']]);
            }
            $changes['settings'] = $settings;
        }
        if ($changes === []) throw AjaxException::invalid('Nothing to change.');
        $user = $this->user($ctx);
        $item = $this->guard(fn() => $this->dashboard->updateInstance($user, (string) $ctx->input('id'), $changes));
        return ['item' => $item];
    }

    /** POST widgets.dashboard_reset — back to the site default. */
    public function reset(AjaxContext $ctx): array
    {
        $user = $this->user($ctx);
        $this->guard(fn() => $this->dashboard->reset($user));
        $ctx->log('Dashboard reset to the default', ['user' => $ctx->userId()]);
        return $this->stateFor($user);
    }

    /** POST widgets.dashboard_action — run one of a card's own actions. */
    public function action(AjaxContext $ctx): array
    {
        $user = $this->user($ctx);
        $res = $this->guard(fn() => $this->dashboard->runAction($user, (string) $ctx->input('id'), (string) $ctx->input('action')));
        $ctx->log('Dashboard widget action', ['id' => (string) $ctx->input('id'), 'action' => (string) $ctx->input('action')]);
        return $res;
    }

    /** POST widgets.dashboard_default — make this layout everyone's default, or clear it. */
    public function siteDefault(AjaxContext $ctx): array
    {
        $user = $this->user($ctx);
        $clear = (bool) $ctx->input('clear', false);
        $this->guard(fn() => $this->dashboard->saveSiteDefault($user, $clear));
        $ctx->log($clear ? 'Dashboard site default cleared' : 'Dashboard site default saved', ['user' => $ctx->userId()]);
        return ['has_site_default' => $this->dashboard->hasSiteDefault()];
    }

    // ------------------------------------------------------------------

    private function stateFor(array $user): array
    {
        $resolved = $this->dashboard->resolve($user);
        return [
            'layout'           => ['items' => $resolved['items'], 'customized' => $resolved['customized']],
            'catalog'          => $this->dashboard->catalog($user, $resolved),
            'has_site_default' => $this->dashboard->hasSiteDefault(),
        ];
    }

    private function user(AjaxContext $ctx): array
    {
        $u = $ctx->user();
        if ($u === null) throw AjaxException::forbidden();
        return $u;
    }

    /** Turn the service's exceptions into the answers the browser sees. */
    private function guard(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (InvalidSettings $e) {
            throw AjaxException::invalid($e->getMessage(), $e->fields);
        } catch (\DomainException) {
            throw AjaxException::forbidden();
        } catch (AjaxException $e) {
            throw $e;
        } catch (\RuntimeException) {
            throw new AjaxException('DASHBOARD_FAILED', "That didn't work. Try again in a moment.", 500);
        }
    }
}
