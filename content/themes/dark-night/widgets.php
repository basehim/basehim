<?php
/**
 * Widgets contributed by the Dark Night theme.
 * Returned array is registered with keys auto-namespaced to
 * 'theme.dark-night.{key}'. Each render callback receives
 * ($settings, $surface) and returns HTML.
 */

return [
    'moon-phase' => [
        'title'       => 'Moon Banner',
        'description' => 'A small moonlit banner in the theme’s style.',
        'icon'        => 'fa-moon',
        'surfaces'    => ['frontend', 'editor'],
        'fields'      => [
            ['key' => 'text', 'label' => 'Banner text', 'type' => 'text', 'default' => 'Reading under the stars'],
        ],
        'render'      => function (array $s, string $surface): string {
            $text = htmlspecialchars((string) ($s['text'] ?? 'Reading under the stars'), ENT_QUOTES);
            return '<div style="position:relative;overflow:hidden;padding:22px 20px;border-radius:14px;'
                . 'background:radial-gradient(40rem 12rem at 80% -4rem,rgba(240,194,75,.15),transparent),#0e1420;'
                . 'border:1px solid rgba(148,163,184,.14);color:#e6eaf2;">'
                . '<span style="display:inline-grid;place-items:center;width:34px;height:34px;border-radius:10px;'
                . 'background:radial-gradient(circle at 32% 30%,#ffe9ad,#f0c24b 55%,#b98a1e);color:#3a2c05;'
                . 'margin-bottom:10px;"><i class="fa-solid fa-moon"></i></span>'
                . '<div style="font-family:Lora,Georgia,serif;font-size:1.15rem;">' . $text . '</div></div>';
        },
    ],
];
