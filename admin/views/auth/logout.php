<?php $this->extend('layouts.signin'); ?>
<?php $this->section('content'); ?>
<?php $site = \App\Http\Controllers\Admin\AuthController::site(); ?>

<h1 class="si-title">Sign out of <?= htmlspecialchars($site['name']) ?>?</h1>
<p class="si-lede">
    <?php if (!empty($user)): ?>
        You're signed in as <strong><?= htmlspecialchars((string) (($user['display_name'] ?? '') ?: ($user['username'] ?? ''))) ?></strong>.
    <?php endif; ?>
    Signing out ends this session on this browser.
</p>

<form method="POST" action="<?= $base ?>/admin/logout" class="si-form">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
    <div class="si-actions">
        <button type="submit" class="si-btn" autofocus>Sign out</button>
        <a class="si-btn si-btn--quiet" href="<?= $base ?>/admin/dashboard">Stay signed in</a>
    </div>
</form>

<?php $this->endSection(); ?>
