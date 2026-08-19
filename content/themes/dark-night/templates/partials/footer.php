</main>

<!-- Footer -->
<footer class="dn-footer">
    <div class="dn-container">
        <div class="dn-footer-grid">
            <div>
                <div class="dn-brand">
                    <div class="dn-moon" style="width:32px;height:32px;border-radius:9px;font-size:13px;"><i class="fa-solid fa-moon"></i></div>
                    <span class="dn-brand-name"><?= htmlspecialchars($site_title) ?></span>
                </div>
                <?php if (!empty($tagline)): ?>
                <p class="dn-footer-note"><?= htmlspecialchars($tagline) ?></p>
                <?php endif; ?>
            </div>
            <div>
                <h4>Explore</h4>
                <ul>
                    <?php foreach (($footer_menu ?? []) as $item): ?>
                        <li>
                            <a href="<?= htmlspecialchars(link_to($item['url'])) ?>" target="<?= htmlspecialchars($item['target'] ?? '_self') ?>">
                                <?= htmlspecialchars($item['title']) ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                    <?php if (empty($footer_menu)): ?>
                        <li><a href="<?= $base ?>/">Home</a></li>
                        <li><a href="<?= $base ?>/feed">RSS Feed</a></li>
                    <?php endif; ?>
                </ul>
            </div>
            <div>
                <h4>Connect</h4>
                <div class="dn-social">
                    <a href="<?= $base ?>/feed" title="RSS"><i class="fa-solid fa-rss"></i></a>
                </div>
            </div>
        </div>
        <div class="dn-copyright">
            <p style="margin:0;">&copy; <?= date('Y') ?> <?= htmlspecialchars($site_title) ?>. <?= htmlspecialchars($footer_text ?? 'All rights reserved.') ?></p>
            <p style="margin:0;">Powered by <a href="https://www.basehim.com">Basehim</a> · Dark Night theme</p>
        </div>
    </div>
</footer>

<?php echo function_exists('bh_footer') ? bh_footer() : ''; ?>
</body>
</html>
