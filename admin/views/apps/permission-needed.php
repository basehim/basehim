<?php $this->extend('layouts.app'); ?>
<?php $this->section('content'); ?>
<?php
/**
 * An app's screen when the app needs a permission it hasn't been allowed
 * (1.2.85; AppPermissionPage). Instead of an error page: what it needs and
 * why, and — for someone who manages apps — the permission window, open.
 * Allowing it reloads this address, and the app's screen appears.
 * admin/assets/js/app-permission.js drives it.
 */
$i = $icon ?? ['type' => 'none', 'value' => ''];
$iconHtml = match ($i['type'] ?? 'none') {
    'image' => '<img src="' . htmlspecialchars((string) $i['value'], ENT_QUOTES) . '" alt="">',
    'glyph' => icon((string) $i['value'], 'w-6 h-6'),
    default => icon('puzzle-piece', 'w-6 h-6'),
};
$plain = (string) ($permission['friendly'] ?? $permission['description'] ?? '');
?>
<div class="bh-perm-gate" id="perm-gate"
     data-slug="<?= htmlspecialchars($slug) ?>"
     data-permission="<?= htmlspecialchars((string) $permission['key']) ?>"
     data-name="<?= htmlspecialchars($appName) ?>"
     data-can="<?= $canAllow ? '1' : '0' ?>">
    <div class="bh-perm-gate__card">
        <span class="bh-app-icon bh-perm-gate__icon<?= ($i['type'] ?? '') === 'image' ? ' bh-app-icon--img' : '' ?>" data-perm-icon><?= $iconHtml ?></span>
        <h2 class="bh-perm-gate__title"><?= htmlspecialchars($appName) ?> needs one more permission</h2>
        <p class="bh-perm-gate__text">
            To show this page it needs <strong><?= htmlspecialchars((string) $permission['label']) ?></strong>: <?= htmlspecialchars(lcfirst(rtrim($plain, '.'))) ?>.
            <?php if ($reason !== ''): ?><span class="bh-consent-why bh-perm-gate__why"><?= icon('chat-bubble-left-ellipsis', '') ?><span><?= htmlspecialchars($reason) ?></span></span><?php endif; ?>
        </p>
        <?php if ($canAllow): ?>
        <p class="bh-perm-gate__hint">Allow it and this page opens straight away. You can change it later under Apps.</p>
        <div class="bh-perm-gate__actions">
            <button type="button" class="bh-btn bh-btn--primary bh-btn--lg" data-perm-review><?= icon('shield-check', 'w-4 h-4') ?> Review and allow</button>
            <a class="bh-btn bh-btn--secondary bh-btn--lg" href="<?= $base ?>/admin/apps">Go to Apps</a>
        </div>
        <?php else: ?>
        <p class="bh-perm-gate__hint">Only an administrator can allow it. Ask one to open Apps → <?= htmlspecialchars($appName) ?> → Permissions.</p>
        <div class="bh-perm-gate__actions">
            <a class="bh-btn bh-btn--secondary bh-btn--lg" href="<?= $base ?>/admin/dashboard">Back to the dashboard</a>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php if ($canAllow): ?>
<?php $this->include('partials.item-modal'); ?>
<script src="<?= $base ?>/admin/assets/js/app-permission.js?v=<?= urlencode((string) BASEHIM_VERSION) ?>" defer></script>
<?php endif; ?>
<?php $this->endSection(); ?>
