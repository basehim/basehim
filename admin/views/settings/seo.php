<?php $this->extend('layouts.app'); ?>
<?php $this->section('content'); ?>
<?php
/*
 * Settings → SEO (1.2.45; restyled 1.2.82).
 *
 * Core prints every SEO and social tag (App\Services\SeoHeadService), so
 * themes don't. Each switch below is posted as 0 or 1: an unticked checkbox
 * sends nothing, which used to mean an option could be switched on but never
 * off again (the sitemap switch had that bug).
 */
require_once __DIR__ . '/_ui.php';
$seoSvc = \App\Core\Application::getInstance()->make(\App\Services\SeoHeadService::class);
$cfg = $seoSvc->settings();
$v = $values ?? [];
$e = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

$groups = [
    'title'       => ['Page title', 'The <code>&lt;title&gt;</code> tag, built from the title format below. Off: the theme sets it.'],
    'description' => ['Meta description', 'From the post\'s SEO description, its excerpt, or the defaults below.'],
    'canonical'   => ['Canonical URL', '<code>&lt;link rel="canonical"&gt;</code> on every indexable page.'],
    'robots'      => ['Robots', '<code>noindex</code> for search results, 404s and previews; each post\'s own robots setting.'],
    'opengraph'   => ['Open Graph', 'Link previews on Facebook, LinkedIn, WhatsApp, Slack…: title, description, image, article dates.'],
    'twitter'     => ['X (Twitter) card', '<code>twitter:card</code>, title, description, image and your handle.'],
    'jsonld'      => ['Structured data (JSON-LD)', 'One schema.org graph per page, for rich results. Choose the types below.'],
    'generator'   => ['Generator tag', '<code>&lt;meta name="generator" content="Basehim CMS"&gt;</code>, without a version number.'],
];
$jsonTypes = [
    'website'      => ['WebSite', 'Site name and the sitelinks search box.'],
    'organization' => ['Organization', 'Publisher name and logo (Customizer → Site identity).'],
    'webpage'      => ['WebPage', 'Every page: name, description, dates, main image.'],
    'article'      => ['Article', 'Posts: headline, author, dates, image, section and tags.'],
    'breadcrumb'   => ['Breadcrumbs', 'Home › Category › Post.'],
];
$themeSeo = null;
try {
    $m = \App\Core\Application::getInstance()->make(\App\Services\ThemeService::class)->activeManifest();
    if (array_key_exists('seo', $m)) $themeSeo = $m['seo'];
} catch (\Throwable) {}
$sitemapOn = !in_array((string) ($v['generate_sitemap'] ?? '1'), ['0', ''], true) || ($v['generate_sitemap'] ?? null) === true;
$themeNote = '';
if ($themeSeo === false) {
    $themeNote = '<div class="bh-set-card__block"><div class="bh-note bh-note--warn">' . icon('exclamation-triangle', 'w-4 h-4') . '<span>The active theme sets <code>"seo": false</code> in theme.json, so it prints these tags itself and the switches below have no effect while it is active.</span></div></div>';
} elseif (is_array($themeSeo)) {
    $themeNote = '<div class="bh-set-card__block"><div class="bh-note">' . icon('information-circle', 'w-4 h-4') . '<span>The active theme handles some groups itself (theme.json): '
        . ($e(implode(', ', array_keys(array_filter($themeSeo, fn($x) => $x === false || $x === 'theme' || $x === 'external')))) ?: 'none') . '.</span></div></div>';
}
?>
<div class="bh-set">
    <?php $this->include('settings._head', compact('tab', 'base')); ?>

    <form method="POST" action="<?= $base ?>/admin/settings/seo" class="bh-set__form">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">

        <?= bh_set_card_open('SEO and social tags', 'Basehim writes every page\'s SEO and sharing tags itself, the same way for every theme. Apps and themes can change them through the <code>seo.*</code> filters.') ?>
            <?= bh_set_toggle('head_enabled', $cfg['enabled'], 'Let Basehim manage SEO tags', 'Off: Basehim adds no SEO tags at all and leaves whatever the theme prints.') ?>
            <?= $themeNote ?>
        <?= bh_set_card_close() ?>

        <?= bh_set_card_open('Tags', 'Switch a group off to leave it out of every page.') ?>
            <?php foreach ($groups as $g => [$label, $help]): ?>
                <?= bh_set_toggle('head_' . $g, $cfg['groups'][$g], $label, $help) ?>
                <?php if ($g === 'jsonld'): foreach ($jsonTypes as $t => [$tl, $th]): ?>
                    <?= bh_set_toggle('jsonld_' . $t, $cfg['jsonld'][$t], $tl, $e($th), ['sub' => true]) ?>
                <?php endforeach; endif; ?>
            <?php endforeach; ?>
        <?= bh_set_card_close() ?>

        <?= bh_set_card_open('Indexing') ?>
            <?= bh_set_toggle('head_strip_theme', $cfg['strip_theme'], 'Remove SEO tags printed by the theme', 'Themes written before 1.2.45 print their own title, description, Open Graph and JSON-LD tags. Basehim removes those, so crawlers see one consistent set. Switch off only if a theme needs its own copies kept.') ?>
            <?= bh_set_toggle('noindex_search', $cfg['noindex_search'], 'Keep search results out of search engines', '<code>noindex, follow</code> on /search pages.') ?>
            <?= bh_set_toggle('max_image_preview', $cfg['max_image_preview'], 'Allow large image previews', 'Adds <code>max-image-preview:large</code>, so Google can show a post\'s image large in results and Discover.') ?>
            <?= bh_set_toggle('generate_sitemap', $sitemapOn, 'XML sitemap', 'At <code>/sitemap.xml</code>.') ?>
        <?= bh_set_card_close() ?>

        <?= bh_set_card_open('Home page') ?>
            <?= bh_set_row('Title', '<input id="seo-home-title" type="text" name="home_title" maxlength="200" class="bh-input" value="' . $e($v['home_title'] ?? '') . '" placeholder="' . $e($cfg['title_format'] === '%title%' ? 'Your site title' : '') . '">', 'Empty: the site title. <code>%site%</code>, <code>%tagline%</code> and <code>%sep%</code> work here too.', ['for' => 'seo-home-title']) ?>
            <?= bh_set_row('Description', '<textarea id="seo-home-desc" name="home_description" rows="2" maxlength="400" class="bh-textarea">' . $e($v['home_description'] ?? '') . '</textarea>', 'Empty: the site tagline.', ['for' => 'seo-home-desc']) ?>
        <?= bh_set_card_close() ?>

        <?= bh_set_card_open('Defaults', 'For pages without their own.') ?>
            <?= bh_set_row('Title format', '<input id="seo-title-format" type="text" name="default_meta_title" class="bh-input font-mono text-sm" placeholder="%title%" value="' . $e($v['default_meta_title'] ?? '') . '">', '<code>%title%</code>, <code>%site%</code>, <code>%tagline%</code>, <code>%sep%</code>, <code>%page%</code>. Example: <code>%title% %sep% %site%</code>.', ['for' => 'seo-title-format']) ?>
            <?= bh_set_row('Separator', '<select id="seo-sep" name="title_separator" class="bh-select max-w-[8rem]">' . bh_set_options(array_combine(['–', '-', '|', '·', '•', '—', '/', '»'], ['–', '-', '|', '·', '•', '—', '/', '»']), $cfg['separator']) . '</select>', '', ['for' => 'seo-sep']) ?>
            <?= bh_set_row('Description', '<textarea id="seo-desc" name="default_meta_description" rows="2" class="bh-textarea">' . $e($v['default_meta_description'] ?? '') . '</textarea>', 'For pages with no description or excerpt of their own.', ['for' => 'seo-desc']) ?>
            <?= bh_set_row('Share image', '<input id="seo-img" type="text" name="default_og_image" class="bh-input" placeholder="https://… or /uploads/…" value="' . $e($v['default_og_image'] ?? '') . '">', 'Used when a page has no social or featured image. 1200×630 works everywhere.', ['for' => 'seo-img']) ?>
            <?= bh_set_row('X (Twitter) handle', '<input id="seo-tw" type="text" name="twitter_handle" class="bh-input" placeholder="@yourhandle" value="' . $e($v['twitter_handle'] ?? '') . '">', '', ['for' => 'seo-tw']) ?>
        <?= bh_set_card_close() ?>

        <?= bh_set_save() ?>
    </form>
</div>

<?php $this->endSection(); ?>
