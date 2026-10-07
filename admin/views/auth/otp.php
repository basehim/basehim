<?php $this->extend('layouts.signin'); ?>
<?php $this->section('content'); ?>
<?php
/**
 * Code entry. $mode:
 *   two_factor  the code emailed after a correct password (two-step verification)
 *   unlock      the code emailed after repeated failed attempts
 */
$mode = $mode ?? 'unlock';
$isTwoFactor = $mode === 'two_factor';
?>

<?php if ($isTwoFactor): ?>
    <h1 class="si-title">Check your email</h1>
    <p class="si-lede">We sent a 6-digit sign-in code to <strong><?= htmlspecialchars($email ?? '') ?></strong>. It works once and expires in 10 minutes.</p>
<?php else: ?>
    <h1 class="si-title">Enter the unlock code</h1>
    <p class="si-lede">After several failed attempts, sign-in was paused and a code was emailed to the account owner. Enter it to continue — you will still need your password.</p>
<?php endif; ?>

<form method="POST" action="<?= $base ?>/admin/login/<?= $isTwoFactor ? 'verify' : 'otp' ?>" class="si-form" id="si-code-form">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
    <div class="si-field">
        <label class="si-label" for="code"><?= $isTwoFactor ? 'Sign-in code' : 'Unlock code' ?></label>
        <input class="si-input si-input--code" type="text" name="code" id="code" required
               inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" autocomplete="one-time-code" autofocus>
    </div>
    <?php if ($isTwoFactor && !empty($trustDays)): ?>
    <label class="si-check">
        <input type="checkbox" name="trust" value="1">
        <span>Don't ask for a code on this device for <?= (int) $trustDays ?> days</span>
    </label>
    <?php endif; ?>
    <button type="submit" class="si-btn si-btn--block"><?= $isTwoFactor ? 'Verify and sign in' : 'Unlock sign-in' ?></button>
</form>

<div class="si-after si-row">
    <?php if ($isTwoFactor): ?>
        <form method="POST" action="<?= $base ?>/admin/login/verify/resend">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
            <button type="submit" class="si-textbtn">Send a new code</button>
        </form>
        <a class="si-link" href="<?= $base ?>/admin/login?switch=1">Use a different account</a>
    <?php else: ?>
        <span>Didn't get it? Check your spam folder.</span>
        <a class="si-link" href="<?= $base ?>/admin/login">Back to sign in</a>
    <?php endif; ?>
</div>

<script>
(function () {
    var input = document.getElementById('code'), form = document.getElementById('si-code-form');
    if (!input || !form) return;
    // Digits only; a pasted "123 456" or "Code: 123456" still works.
    input.addEventListener('input', function () {
        var d = input.value.replace(/\D+/g, '').slice(0, 6);
        if (input.value !== d) input.value = d;
    });
    form.addEventListener('submit', function () {
        var b = form.querySelector('button[type=submit]');
        if (b) { b.disabled = true; b.textContent = 'Checking…'; }
    });
})();
</script>

<?php $this->endSection(); ?>
