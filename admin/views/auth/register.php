<?php $this->extend('layouts.signin'); ?>
<?php $this->section('content'); ?>
<?php $site = \App\Http\Controllers\Admin\AuthController::site(); ?>

<h1 class="si-title">Create your account</h1>
<p class="si-lede">Join <?= htmlspecialchars($site['name']) ?>. You'll sign in with your username or email.</p>

<form method="POST" action="<?= $base ?>/admin/register" class="si-form">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">

    <?php if (!empty($honeypot)): ?>
    <div class="si-hp" aria-hidden="true">
        <label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label>
    </div>
    <?php endif; ?>

    <div class="si-field">
        <label class="si-label" for="display_name">Your name</label>
        <input class="si-input" type="text" name="display_name" id="display_name" autocomplete="name" maxlength="120" autofocus>
    </div>
    <div class="si-field">
        <label class="si-label" for="username">Username</label>
        <input class="si-input" type="text" name="username" id="username" required autocomplete="username"
               autocapitalize="none" spellcheck="false" pattern="[A-Za-z0-9_.\-]{3,32}" maxlength="32">
        <span class="si-hint">3–32 letters, numbers, dots, dashes or underscores.</span>
    </div>
    <div class="si-field">
        <label class="si-label" for="email">Email address</label>
        <input class="si-input" type="email" name="email" id="email" required autocomplete="email">
    </div>
    <div class="si-field">
        <label class="si-label" for="password">Password</label>
        <div class="si-password">
            <input class="si-input" type="password" name="password" id="password" required minlength="8" autocomplete="new-password">
            <button type="button" class="si-reveal" id="si-reveal" aria-label="Show password" aria-pressed="false">
                <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M1.75 10s3-5.75 8.25-5.75S18.25 10 18.25 10 15.25 15.75 10 15.75 1.75 10 1.75 10Z"/><circle cx="10" cy="10" r="2.75"/></svg>
            </button>
        </div>
        <span class="si-hint">At least 8 characters.</span>
    </div>
    <button type="submit" class="si-btn si-btn--block">Create account</button>
</form>

<p class="si-after">Already have an account? <a class="si-link" href="<?= $base ?>/admin/login">Sign in</a></p>

<script>
(function () {
    var btn = document.getElementById('si-reveal'), input = document.getElementById('password');
    if (btn && input) btn.addEventListener('click', function () {
        var show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        btn.setAttribute('aria-pressed', show ? 'true' : 'false');
        btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    });
})();
</script>

<?php $this->endSection(); ?>
