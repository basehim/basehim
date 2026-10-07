<?php $this->extend('layouts.signin'); ?>
<?php $this->section('content'); ?>
<?php $site = \App\Http\Controllers\Admin\AuthController::site(); ?>

<h1 class="si-title">Sign in to <?= htmlspecialchars($site['name']) ?></h1>
<p class="si-lede">Use the email address or username of your account.</p>

<form method="POST" action="<?= $base ?>/admin/login" class="si-form" id="si-login">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">

    <?php if (!empty($honeypot)): ?>
    <div class="si-hp" aria-hidden="true">
        <label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label>
    </div>
    <?php endif; ?>

    <div class="si-field">
        <label class="si-label" for="login">Email or username</label>
        <input class="si-input" type="text" name="login" id="login" required
               autocomplete="username" autocapitalize="none" spellcheck="false"
               value="<?= htmlspecialchars((string) ($lastLogin ?? '')) ?>" <?= empty($lastLogin) ? 'autofocus' : '' ?>>
    </div>

    <div class="si-field">
        <div class="si-field__top">
            <label class="si-label" for="password">Password</label>
            <a class="si-link" href="<?= $base ?>/admin/forgot-password">Forgot password?</a>
        </div>
        <div class="si-password">
            <input class="si-input" type="password" name="password" id="password" required autocomplete="current-password" <?= !empty($lastLogin) && empty($needCaptcha) ? 'autofocus' : '' ?>>
            <button type="button" class="si-reveal" id="si-reveal" aria-label="Show password" aria-pressed="false">
                <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M1.75 10s3-5.75 8.25-5.75S18.25 10 18.25 10 15.25 15.75 10 15.75 1.75 10 1.75 10Z"/><circle cx="10" cy="10" r="2.75"/></svg>
            </button>
        </div>
    </div>

    <?php if (!empty($needCaptcha) && !empty($captcha)): ?>
    <div class="si-question">
        <label class="si-label" for="captcha">To continue, answer: what is <b><?= htmlspecialchars($captcha['question']) ?></b>?</label>
        <input class="si-input" type="text" name="captcha" id="captcha" required inputmode="numeric" pattern="-?[0-9]*" autocomplete="off" autofocus>
    </div>
    <?php endif; ?>

    <?php if (!empty($rememberMe)): ?>
    <label class="si-check">
        <input type="checkbox" name="remember" value="1">
        <span>Keep me signed in on this device</span>
    </label>
    <?php endif; ?>

    <button type="submit" class="si-btn si-btn--block">Sign in</button>
</form>

<?php if (!empty($canUnlock)): ?>
<form method="POST" action="<?= $base ?>/admin/login/unlock" class="si-after">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
    Is this your account? <button type="submit" class="si-textbtn">Email me an unlock code</button>
</form>
<?php endif; ?>

<?php if (!empty($allowRegistration)): ?>
<p class="si-after">New here? <a class="si-link" href="<?= $base ?>/admin/register">Create an account</a></p>
<?php endif; ?>

<script>
(function () {
    var btn = document.getElementById('si-reveal'), input = document.getElementById('password');
    if (btn && input) btn.addEventListener('click', function () {
        var show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        btn.setAttribute('aria-pressed', show ? 'true' : 'false');
        btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        input.focus();
    });
    var form = document.getElementById('si-login');
    if (form) form.addEventListener('submit', function () {
        var b = form.querySelector('button[type=submit]');
        if (b) { b.disabled = true; b.textContent = 'Signing in…'; }
    });
})();
</script>

<?php $this->endSection(); ?>
