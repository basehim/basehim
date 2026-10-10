<?php $this->extend('layouts.app'); ?>
<?php $this->section('content'); ?>
<?php
/*
 * Settings → SEO (1.2.45).
 *
 * Core prints every SEO and social tag (App\Services\SeoHeadService), so
 * themes don't. Each switch below is posted as 0 or 1: an unticked checkbox
 * sends nothing, which used to mean an option could be switched on but never
 * off again (the sitemap switch had that bug).
 */
$seoSvc = \App\Core\Application::getInstance()->make(\App\Services\SeoHeadService::class);
$cfg = $seoSvc->settings();
$v = $values ?? [];
$e = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$input = 'w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none';

$toggle = function (string $name, bool $on, string $label, string $help = '') use ($e): string {
    return '<label class="flex items-start gap-3 py-2">'
        . '<input type="hidden" name="' . $e($name) . '" value="0">'
        . '<input type="checkbox" name="' . $e($name) . '" value="1" ' . ($on ? 'checked' : '')
        . ' class="mt-0.5 rounded border-slate-300 text-blue-600 focus:ring-blue-500">'
        . '<span><span class="text-sm font-medium text-slate-800">' . $e($label) . '</span>'
        . ($help !== '' ? '<span class="block text-xs text-slate-500 mt-0.5">' . $help . '</span>' : '')
        . '</span></label>';
};

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
?>

<div class="mb-5">
    <h2 class="text-xl font-semibold text-slate-900">Settings</h2>
    <p class="text-sm text-slate-500">Configure your site.</p>
</div>

<div>
    <?php $this->include('settings._nav', compact('tab', 'base')); ?>
    <form method="POST" action="<?= $base ?>/admin/settings/seo" class="space-y-5">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">

        <div class="bg-white rounded-xl border border-slate-200 p-6">
            <h3 class="font-semibold text-slate-900 mb-1">SEO and social tags</h3>
            <p class="text-sm text-slate-500 mb-4">
                Basehim writes every page's SEO and sharing tags itself, the same way for every theme.
                Apps and themes can change them through the <code class="text-xs bg-slate-100 px-1 rounded">seo.*</code> filters.
            </p>
            <?= $toggle('head_enabled', $cfg['enabled'], 'Let Basehim manage SEO tags', 'Off: Basehim adds no SEO tags at all and leaves whatever the theme prints.') ?>
            <?php if ($themeSeo === false): ?>
                <p class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2 mt-2">
                    The active theme sets <code>"seo": false</code> in theme.json, so it prints these tags itself and the switches below have no effect while it is active.
                </p>
            <?php elseif (is_array($themeSeo)): ?>
                <p class="text-xs text-slate-600 bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 mt-2">
                    The active theme handles some groups itself (theme.json): <?= $e(implode(', ', array_keys(array_filter($themeSeo, fn($x) => $x === false || $x === 'theme' || $x === 'external')))) ?: 'none' ?>.
                </p>
            <?php endif; ?>
        </div>

        <div class="bg-white rounded-xl border border-slate-200 p-6">
            <h3 class="font-semibold text-slate-900 mb-1">Tags</h3>
            <p class="text-sm text-slate-500 mb-3">Untick a group to leave it out of every page.</p>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-x-8">
                <?php foreach ($groups as $g => [$label, $help]): ?>
                    <?= $toggle('head_' . $g, $cfg['groups'][$g], $label, $help) ?>
                <?php endforeach; ?>
            </div>
            <div class="mt-4 pl-4 border-l-2 border-slate-100">
                <div class="text-sm font-medium text-slate-700 mb-1">Structured data types</div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-x-8">
                    <?php foreach ($jsonTypes as $t => [$label, $help]): ?>
                        <?= $toggle('jsonld_' . $t, $cfg['jsonld'][$t], $label, $e($help)) ?>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="mt-4 border-t border-slate-100 pt-3">
                <?= $toggle('head_strip_theme', $cfg['strip_theme'], 'Remove SEO tags printed by the theme', 'Themes written before 1.2.45 print their own title, description, Open Graph and JSON-LD tags. Basehim removes those, so crawlers see one consistent set. Untick only if a theme needs its own copies kept.') ?>
                <?= $toggle('noindex_search', $cfg['noindex_search'], 'Keep search results out of search engines', '<code>noindex, follow</code> on /search pages.') ?>
                <?= $toggle('max_image_preview', $cfg['max_image_preview'], 'Allow large image previews', 'Adds <code>max-image-preview:large</code>, so Google can show a post\'s image large in results and Discover.') ?>
            </div>
        </div>

        <div class="bg-white rounded-xl border border-slate-200 p-6 space-y-5">
            <h3 class="font-semibold text-slate-900">Home page</h3>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5" for="seo-home-title">Home page title</label>
                <input id="seo-home-title" type="text" name="home_title" maxlength="200" value="<?= $e($v['home_title'] ?? '') ?>" placeholder="<?= $e($cfg['title_format'] === '%title%' ? 'Your site title' : '') ?>" class="<?= $input ?>">
                <p class="text-xs text-slate-500 mt-1">Empty: the site title. <code>%site%</code>, <code>%tagline%</code> and <code>%sep%</code> work here too.</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5" for="seo-home-desc">Home page description</label>
                <textarea id="seo-home-desc" name="home_description" rows="2" maxlength="400" class="<?= $input ?>"><?= $e($v['home_description'] ?? '') ?></textarea>
                <p class="text-xs text-slate-500 mt-1">Empty: the site tagline.</p>
            </div>
        </div>

        <div class="bg-white rounded-xl border border-slate-200 p-6 space-y-5">
            <h3 class="font-semibold text-slate-900">Defaults</h3>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-slate-700 mb-1.5" for="seo-title-format">Title format</label>
                    <input id="seo-title-format" type="text" name="default_meta_title" value="<?= $e($v['default_meta_title'] ?? '') ?>" placeholder="%title%" class="<?= $input ?> font-mono text-sm">
                    <p class="text-xs text-slate-500 mt-1">For pages without their own SEO title. <code>%title%</code>, <code>%site%</code>, <code>%tagline%</code>, <code>%sep%</code>, <code>%page%</code>. Example: <code>%title% %sep% %site%</code>.</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5" for="seo-sep">Separator</label>
                    <select id="seo-sep" name="title_separator" class="<?= $input ?> bg-white">
                        <?php foreach (['–', '-', '|', '·', '•', '—', '/', '»'] as $sep): ?>
                            <option value="<?= $e($sep) ?>" <?= $cfg['separator'] === $sep ? 'selected' : '' ?>><?= $e($sep) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1.5" for="seo-desc">Default description</label>
                <textarea id="seo-desc" name="default_meta_description" rows="2" class="<?= $input ?>"><?= $e($v['default_meta_description'] ?? '') ?></textarea>
                <p class="text-xs text-slate-500 mt-1">For pages with no description or excerpt of their own.</p>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5" for="seo-img">Default share image</label>
                    <input id="seo-img" type="text" name="default_og_image" value="<?= $e($v['default_og_image'] ?? '') ?>" placeholder="https://… or /uploads/…" class="<?= $input ?>">
                    <p class="text-xs text-slate-500 mt-1">Used when a page has no social image or featured image. 1200×630 works everywhere.</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5" for="seo-tw">X (Twitter) handle</label>
                    <input id="seo-tw" type="text" name="twitter_handle" value="<?= $e($v['twitter_handle'] ?? '') ?>" placeholder="@yourhandle" class="<?= $input ?>">
                </div>
            </div>
            <div class="border-t border-slate-100 pt-3">
                <?= $toggle('generate_sitemap', !in_array((string) ($v['generate_sitemap'] ?? '1'), ['0', ''], true) || ($v['generate_sitemap'] ?? null) === true, 'Generate XML sitemap', 'At <code>/sitemap.xml</code>.') ?>
            </div>
        </div>

        <button type="submit" class="px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg shadow-sm">
            <?= icon('document-check', 'w-4 h-4 mr-1') ?> Save changes
        </button>
    </form>
</div>

<?php $this->endSection(); ?>
