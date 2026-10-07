<?php $this->extend('layouts.signin'); ?>
<?php $this->section('content'); ?>

<h1 class="si-title">Choose a new password</h1>
<p class="si-lede">Use at least 8 characters. Every other device signed in to your account will be signed out.</p>

<form method="POST" action="<?= $base ?>/admin/reset-password" class="si-form">
    <input type="hidden" name="token" value="<?= htmlspecialchars($token ?? '') ?>">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf ?? '') ?>">
    <div class="si-field">
        <label class="si-label" for="password">New password</label>
        <input class="si-input" type="password" name="password" id="password" required minlength="8" autocomplete="new-password" autofocus>
    </div>
    <div class="si-field">
        <label class="si-label" for="password_confirm">Repeat the new password</label>
        <input class="si-input" type="password" name="password_confirm" id="password_confirm" required minlength="8" autocomplete="new-password">
    </div>
    <button type="submit" class="si-btn si-btn--block">Save new password</button>
</form>

<script>
(function () {
    var a = document.getElementById('password'), b = document.getElementById('password_confirm');
    if (!a || !b) return;
    function check() { b.setCustomValidity(b.value !== '' && a.value !== b.value ? 'The passwords do not match.' : ''); }
    a.addEventListener('input', check); b.addEventListener('input', check);
})();
</script>

<?php $this->endSection(); ?>
