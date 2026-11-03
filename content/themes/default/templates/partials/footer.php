</main>

<!-- Footer -->
<footer class="mt-16 border-t border-slate-100 bg-slate-50">
    <div class="max-w-6xl mx-auto px-4 lg:px-6 py-10">
        <?php if (function_exists('has_widget_area') && has_widget_area('footer')): ?>
        <div class="footer-widgets grid grid-cols-1 md:grid-cols-3 gap-8 mb-8 [&_.widget]:text-sm [&_.widget-title]:text-xs [&_.widget-title]:font-semibold [&_.widget-title]:text-slate-900 [&_.widget-title]:uppercase [&_.widget-title]:tracking-wider [&_.widget-title]:mb-3 [&_a]:text-slate-600 hover:[&_a]:text-brand-600 [&_ul]:space-y-2">
            <?= widget_area('footer') ?>
        </div>
        <?php endif; ?>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-8 mb-8">
            <div>
                <div class="flex items-center gap-3 mb-3">
                    <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-brand-500 to-brand-700 grid place-items-center text-white">
                        <?= icon('bolt', 'w-4 h-4') ?>
                    </div>
                    <span class="font-semibold text-slate-900"><?= htmlspecialchars($site_title) ?></span>
                </div>
                <?php if (!empty($tagline)): ?>
                <p class="text-sm text-slate-600"><?= htmlspecialchars($tagline) ?></p>
                <?php endif; ?>
            </div>
            <div>
                <h4 class="text-xs font-semibold text-slate-900 uppercase tracking-wider mb-3">Explore</h4>
                <ul class="space-y-2 text-sm">
                    <?php foreach (($footer_menu ?? []) as $item): ?>
                        <li>
                            <a href="<?= htmlspecialchars(link_to($item['url'])) ?>" target="<?= htmlspecialchars($item['target'] ?? '_self') ?>" class="text-slate-600 hover:text-brand-600">
                                <?= htmlspecialchars($item['title']) ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                    <?php if (empty($footer_menu)): ?>
                        <li><a href="<?= $base ?>/" class="text-slate-600 hover:text-brand-600">Home</a></li>
                        <?php foreach (($primary_menu ?? []) as $__item): if (($__item['url'] ?? '') === '/' ) continue; ?>
                        <li><a href="<?= htmlspecialchars(link_to($__item['url'])) ?>" class="text-slate-600 hover:text-brand-600"><?= htmlspecialchars($__item['title']) ?></a></li>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </ul>
            </div>
            <div>
                <h4 class="text-xs font-semibold text-slate-900 uppercase tracking-wider mb-3">Follow</h4>
                <?php // 1.2.98: one square RSS icon under "Connect" read as unfinished. ?>
                <a href="<?= $base ?>/feed" class="inline-flex items-center gap-2 text-sm text-slate-600 hover:text-brand-600">
                    <?= icon('rss', 'w-4 h-4') ?> Subscribe by RSS
                </a>
                <form action="<?= $base ?>/search" method="GET" role="search" class="mt-4 bht-foot-search">
                    <input type="search" name="q" placeholder="Search…" aria-label="Search this site" class="bht-input-touch">
                </form>
            </div>
        </div>
        <div class="pt-6 border-t border-slate-200 flex flex-col md:flex-row md:items-center md:justify-between gap-2 text-xs text-slate-500">
            <p>&copy; <?= date('Y') ?> <?= htmlspecialchars($site_title) ?>. <?= htmlspecialchars($footer_text ?? 'All rights reserved.') ?></p>
            <p>Powered by <a href="https://www.basehim.com" class="text-brand-600 hover:underline" rel="noopener">Basehim</a></p>
        </div>
    </div>
</footer>

<?php echo function_exists('bh_footer') ? bh_footer() : ''; ?>
</body>
</html>
