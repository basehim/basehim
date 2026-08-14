<?php
/**
 * API tabs — rendered through the shared tab component.
 * Expects $subtab (active key) and $base in scope.
 */
$this->include('partials.tabs', [
    'base'      => $base,
    'active'    => $subtab ?? '',
    'ariaLabel' => 'API sections',
    'tabs'      => [
        'overview'  => ['label' => 'Overview',   'icon' => 'information-circle', 'url' => '/admin/api'],
        'keys'      => ['label' => 'API Keys',   'icon' => 'key',                'url' => '/admin/api/keys'],
        'reference' => ['label' => 'Reference',  'icon' => 'book-open',          'url' => '/admin/api/reference'],
        'mcp'       => ['label' => 'MCP Server', 'icon' => 'cpu-chip',           'url' => '/admin/api/mcp'],
    ],
]);
