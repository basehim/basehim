<?php $this->extend('layouts.app'); ?>
<?php $this->section('content'); ?>

<div class="mb-5">
    <h2 class="text-xl font-semibold text-slate-900">Settings</h2>
    <p class="text-sm text-slate-500">Configure your site.</p>
</div>

<div>
    <?php $this->include('settings._nav', compact('tab', 'base')); ?>

    <div class="mt-0">
        <div class="bg-white rounded-xl border border-slate-200 p-6">
            <h3 class="font-semibold text-slate-900 mb-5">Reading Settings</h3>
            <form method="POST" action="<?= $base ?>/admin/settings/reading" class="space-y-5">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Homepage Displays</label>
                    <select name="homepage_type" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none">
                        <?php $ht = $values['homepage_type'] ?? 'posts'; ?>
                        <option value="posts" <?= $ht === 'posts' ? 'selected' : '' ?>>Latest Posts</option>
                        <option value="page" <?= $ht === 'page' ? 'selected' : '' ?>>Static Page</option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Static Homepage Slug</label>
                    <input type="text" name="homepage_slug" value="<?= htmlspecialchars($values['homepage_slug'] ?? '') ?>" placeholder="about"
                        class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none">
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1.5">Posts per page</label>
                        <input type="number" name="posts_per_page" min="1" max="100" value="<?= htmlspecialchars($values['posts_per_page'] ?? 10) ?>"
                            class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1.5">Feed Items</label>
                        <input type="number" name="feed_items" min="1" max="50" value="<?= htmlspecialchars($values['feed_items'] ?? 10) ?>"
                            class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none">
                    </div>
                </div>

                <?php /* The hidden 0 is what an unticked box sends. Without it the
                         setting was never saved as off: once ticked, it could not
                         be unticked. */ ?>
                <label class="flex items-center gap-2">
                    <input type="hidden" name="discourage_search" value="0">
                    <input type="checkbox" name="discourage_search" value="1" <?= !empty($values['discourage_search']) ? 'checked' : '' ?>
                        class="rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                    <span class="text-sm text-slate-700">Discourage search engines from indexing this site</span>
                </label>

                <div class="pt-4 border-t border-slate-200 space-y-3">
                    <h3 class="text-sm font-semibold text-slate-900">Authors</h3>
                    <label class="flex items-start gap-2">
                        <input type="hidden" name="author_archives" value="0">
                        <input type="checkbox" name="author_archives" value="1" <?= (string) ($values['author_archives'] ?? '1') !== '0' ? 'checked' : '' ?>
                            class="mt-0.5 rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                        <span class="text-sm text-slate-700">Author archive pages
                            <span class="block text-xs text-slate-500">Each author who has published a post gets a page listing their posts, at /author/their-name. The address uses the author's display name, never their login name.</span>
                        </span>
                    </label>
                    <label class="flex items-start gap-2">
                        <input type="hidden" name="author_box" value="0">
                        <input type="checkbox" name="author_box" value="1" <?= (string) ($values['author_box'] ?? '1') !== '0' ? 'checked' : '' ?>
                            class="mt-0.5 rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                        <span class="text-sm text-slate-700">Author box on posts
                            <span class="block text-xs text-slate-500">Shows the author's name, picture and bio under each post, in themes that support it.</span>
                        </span>
                    </label>
                </div>

                <?php
                    $sm = (string) ($values['search_mode'] ?? 'auto');
                    $vc = (string) ($values['view_counting'] ?? 'buffered');
                ?>
                <div class="pt-4 border-t border-slate-200 space-y-4">
                    <h3 class="text-sm font-semibold text-slate-900">Search and page views</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="search_mode" class="block text-sm font-medium text-slate-700 mb-1.5">Search method</label>
                            <select id="search_mode" name="search_mode" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none">
                                <option value="auto" <?= $sm !== 'like' ? 'selected' : '' ?>>Word index (fast, recommended)</option>
                                <option value="like" <?= $sm === 'like' ? 'selected' : '' ?>>Text match (finds parts of words; slow on large sites)</option>
                            </select>
                            <p class="mt-1 text-xs text-slate-500">The word index finds posts containing every word of three letters or more. Choose text match for languages written without spaces between words.</p>
                        </div>
                        <div>
                            <label for="search_rate_limit" class="block text-sm font-medium text-slate-700 mb-1.5">Searches per minute, per visitor</label>
                            <input id="search_rate_limit" type="number" name="search_rate_limit" min="0" max="1000" value="<?= htmlspecialchars((string) ($values['search_rate_limit'] ?? 30)) ?>"
                                class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none">
                            <p class="mt-1 text-xs text-slate-500">For the site's search page and the search API. 0 means no limit.</p>
                        </div>
                    </div>
                    <div>
                        <label for="view_counting" class="block text-sm font-medium text-slate-700 mb-1.5">Page views</label>
                        <select id="view_counting" name="view_counting" class="w-full md:w-1/2 px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none">
                            <option value="buffered" <?= $vc === 'buffered' ? 'selected' : '' ?>>Count, added up every minute (recommended)</option>
                            <option value="direct" <?= $vc === 'direct' ? 'selected' : '' ?>>Count, updated on every view</option>
                            <option value="off" <?= $vc === 'off' ? 'selected' : '' ?>>Don't count</option>
                        </select>
                        <p class="mt-1 text-xs text-slate-500">Adding views up every minute keeps a popular post fast under heavy traffic; the numbers shown can be a minute behind.</p>
                    </div>
                </div>

                <button type="submit" class="px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg shadow-sm">
                    <?= icon('document-check', 'w-4 h-4 mr-1') ?> Save changes
                </button>
            </form>
        </div>
    </div>
</div>

<?php $this->endSection(); ?>
