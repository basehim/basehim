<?php $this->extend('layouts.signin'); ?>
<?php $this->section('content'); ?>

<h1 class="si-title">Reset your password</h1>
<p class="si-lede">Enter the email address of your account and we'll send you a link to choose a new password.</p>

<form method="POST" action="<?= $base ?>/admin/forgot-password" class="si-form">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf ?? '') ?>">
    <div class="si-field">
        <label class="si-label" for="email">Email address</label>
        <input class="si-input" type="email" name="email" id="email" required autocomplete="email" autofocus>
    </div>
    <button type="submit" class="si-btn si-btn--block">Email me a reset link</button>
</form>

<p class="si-after">Remembered it? <a class="si-link" href="<?= $base ?>/admin/login">Back to sign in</a></p>

<?php $this->endSection(); ?>
