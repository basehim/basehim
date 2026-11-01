<?php
declare(strict_types=1);

namespace Example\SiteHealth;

use App\Core\Request;

/**
 * A status indicator in the admin bar (docs/ADMIN-BAR.md): a Security menu
 * on the right for administrators, with a Good / Warning badge, the checks
 * in a panel, a notice in the bell when something needs attention, and a
 * quick-search source for the checks themselves.
 */
class App extends \App\Core\App
{
    public function boot(): void
    {
        $bar = $this->api()->adminBar();
        $bar->menu(['id' => 'security', 'label' => 'Security', 'icon' => 'shield-check', 'region' => 'end', 'priority' => 5,
                    'capability' => 'manage_options']);
        $bar->indicator(['id' => 'status', 'parent' => 'security', 'type' => 'status', 'label' => 'site health',
            'value' => fn() => $this->failing() === [] ? ['value' => 'Good', 'tone' => 'success'] : ['value' => 'Warning', 'tone' => 'warning']]);
        $bar->panel(['id' => 'checks', 'parent' => 'security', 'label' => 'Checks', 'data' => fn() => [
            'rows' => array_map(fn($c) => ['label' => $c['label'], 'value' => $c['ok'] ? 'OK' : 'Needs attention', 'tone' => $c['ok'] ? 'success' : 'warning'], $this->checks()),
            'actions' => [['label' => 'Security settings', 'url' => '/admin/settings/security']],
        ]]);
        $bar->item(['id' => 'system', 'parent' => 'security', 'label' => 'System status', 'icon' => 'heart', 'href' => '/admin/system']);
        $bar->notifications(['id' => 'alerts', 'capability' => 'manage_options', 'items' => fn() => array_map(
            fn($c) => ['id' => $c['key'], 'title' => $c['label'] . ' needs attention', 'text' => $c['help'], 'tone' => 'warning', 'url' => '/admin/settings/security'],
            $this->failing())]);
        $bar->search(['id' => 'checks', 'label' => 'Security checks', 'icon' => 'shield-check', 'capability' => 'manage_options',
            'search' => fn(string $q, array $user, int $limit) => array_slice(array_values(array_map(
                fn($c) => ['title' => $c['label'], 'meta' => $c['ok'] ? 'OK' : 'Needs attention', 'url' => '/admin/settings/security'],
                array_filter($this->checks(), fn($c) => stripos($c['label'], $q) !== false))), 0, $limit)]);
    }

    private function checks(): array
    {
        return [
            ['key' => 'https', 'label' => 'HTTPS', 'ok' => Request::serverIsSecure(), 'help' => 'Pages are served without encryption.'],
            ['key' => 'debug', 'label' => 'Debug mode', 'ok' => !filter_var(\App\Core\Env::get('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOLEAN),
             'help' => 'Error details are shown to visitors.'],
            ['key' => 'install', 'label' => 'Installer removed', 'ok' => !is_file(BASEHIM_ROOT . '/install.php'), 'help' => 'install.php is still on the server.'],
        ];
    }

    private function failing(): array
    {
        return array_values(array_filter($this->checks(), fn($c) => !$c['ok']));
    }
}
