<?php
/**
 * Widgets tabs (1.2.102): core's tab bar, as on API and Analytics. Before,
 * each page drew its own dark-pill switcher. Expects $active ('widgets' or
 * 'areas') and $base in scope.
 */
$this->include('partials.tabs', [
    'base'      => $base,
    'active'    => $active ?? 'widgets',
    'ariaLabel' => 'Widgets sections',
    'tabs'      => [
        'widgets' => ['label' => 'Registered widgets', 'icon' => 'squares-2x2', 'url' => '/admin/widgets'],
        'areas'   => ['label' => 'Widget areas',       'icon' => 'rectangle-group', 'url' => '/admin/widgets/areas'],
    ],
]);
