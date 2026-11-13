<?php
/**
 * Profile photo — My Profile and Edit User.
 *
 * The photo with a pencil button. The button opens a dialog (page blurred
 * behind it) to upload a photo or pick one from the media library, crop it in a
 * round frame (Cropper.js), and save — at once, not with the page's Save
 * button. Every avatar of this user on the page updates when it is saved
 * (admin/assets/js/avatar-editor.js).
 *
 * @var array  $avatarUser  the user whose photo this is
 * @var string $avatarUrl   where to POST (…/avatar); removal posts to …/avatar/delete
 * @var string $csrf
 */
$__app   = \App\Core\Application::getInstance();
$__svc   = $__app->make(\App\Services\AvatarService::class);
$__av    = $__svc->forUser($avatarUser);
$__rules = $__svc->rules();
$__name  = (string) (($avatarUser['display_name'] ?? '') !== '' ? $avatarUser['display_name'] : ($avatarUser['username'] ?? '?'));
$__base  = defined('BASEHIM_BASE') ? rtrim((string) BASEHIM_BASE, '/') : '';
$__accept = implode(',', array_map(static fn($t) => '.' . $t, $__rules['types']));
// The library tab needs the media library, which needs upload_media.
$__canBrowse = false;
try {
    $__viewer = $__app->make(\App\Services\AuthService::class)->currentUser();
    $__canBrowse = $__viewer && \App\Http\Middleware\CheckCapability::userCan($__viewer, 'upload_media');
} catch (\Throwable) {}
$__mb = rtrim(rtrim(number_format($__rules['max_bytes'] / 1048576, 1), '0'), '.');
?>
<div class="bh-av" data-avatar-editor
     data-post="<?= htmlspecialchars($avatarUrl) ?>" data-csrf="<?= htmlspecialchars($csrf) ?>"
     data-user-id="<?= (int) ($avatarUser['id'] ?? 0) ?>" data-base="<?= htmlspecialchars($__base) ?>"
     data-can-browse="<?= $__canBrowse ? '1' : '0' ?>" data-max="<?= (int) $__rules['max_bytes'] ?>"
     data-has="<?= $__av ? '1' : '0' ?>">
    <div class="bh-av__face">
        <?= bh_user_avatar($avatarUser, 'bh-av__img', (string) ($__av['medium_url'] ?? '')) ?>
        <button type="button" class="bh-av__edit" data-av-open aria-label="Change profile photo" title="Change profile photo">
            <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M13.586 3.586a2 2 0 1 1 2.828 2.828l-.793.793-2.828-2.828.793-.793ZM11.379 5.793 3 14.172V17h2.828l8.38-8.379-2.83-2.828Z"/></svg>
        </button>
    </div>
    <div class="bh-av__text">
        <div class="bh-av__label">Profile photo</div>
        <p class="bh-av__hint">Shown with posts, comments and in the author box. Click the pencil to upload, choose from media, or crop.</p>
        <p class="bh-av__status" data-av-status role="status"></p>
    </div>
</div>

<dialog class="bh-av-dialog" id="bh-av-dialog" aria-labelledby="bh-av-title">
    <div class="bh-av-dialog__box">
        <header class="bh-av-dialog__head">
            <h2 id="bh-av-title" data-av-title>Profile photo</h2>
            <button type="button" class="bh-av-x" data-av-close aria-label="Close">
                <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z"/></svg>
            </button>
        </header>

        <section data-av-view="choose">
            <?php if ($__canBrowse): ?>
            <div class="bh-av-tabs" role="tablist">
                <button type="button" role="tab" class="is-on" aria-selected="true" data-av-tab="upload">Upload</button>
                <button type="button" role="tab" aria-selected="false" data-av-tab="library">Media library</button>
            </div>
            <?php endif; ?>
            <div data-av-pane="upload">
                <label class="bh-av-drop" data-av-drop>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M12 16V4m0 0-4 4m4-4 4 4M4 16v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/></svg>
                    <strong>Drop a photo here</strong>
                    <span>or <u>choose a file</u></span>
                    <input type="file" accept="<?= htmlspecialchars($__accept) ?>" data-av-file>
                </label>
                <p class="bh-av-small"><?= htmlspecialchars(strtoupper(implode(', ', $__rules['types']))) ?>, up to <?= $__mb ?> MB. You can crop it next.</p>
            </div>
            <?php if ($__canBrowse): ?>
            <div data-av-pane="library" hidden>
                <input type="search" class="bh-av-search" placeholder="Search images…" data-av-search aria-label="Search images">
                <div class="bh-av-grid" data-av-grid></div>
                <div class="bh-av-more"><button type="button" class="bh-av-btn bh-av-btn--quiet" data-av-more hidden>Load more</button></div>
            </div>
            <?php endif; ?>
        </section>

        <section data-av-view="crop" hidden>
            <div class="bh-av-crop"><img data-av-crop-img alt="Photo to crop"></div>
            <div class="bh-av-tools" data-av-tools>
                <button type="button" class="bh-av-tool" data-av-act="zoom-out" title="Zoom out" aria-label="Zoom out">−</button>
                <button type="button" class="bh-av-tool" data-av-act="zoom-in" title="Zoom in" aria-label="Zoom in">+</button>
                <button type="button" class="bh-av-tool" data-av-act="rotate-left" title="Rotate left" aria-label="Rotate left">⟲</button>
                <button type="button" class="bh-av-tool" data-av-act="rotate-right" title="Rotate right" aria-label="Rotate right">⟳</button>
                <button type="button" class="bh-av-tool bh-av-tool--wide" data-av-act="reset">Reset</button>
            </div>
            <p class="bh-av-small" data-av-crop-note>Drag to position, scroll or use + and − to zoom.</p>
        </section>

        <p class="bh-av-msg" data-av-msg role="alert"></p>
        <footer class="bh-av-dialog__foot">
            <button type="button" class="bh-av-btn bh-av-btn--danger" data-av-remove <?= $__av ? '' : 'hidden' ?>>Remove photo</button>
            <span class="bh-av-spacer"></span>
            <button type="button" class="bh-av-btn bh-av-btn--quiet" data-av-back hidden>Back</button>
            <button type="button" class="bh-av-btn bh-av-btn--quiet" data-av-close>Cancel</button>
            <button type="button" class="bh-av-btn" data-av-save hidden>Save photo</button>
        </footer>
    </div>
</dialog>

<script src="<?= htmlspecialchars($__base) ?>/admin/assets/js/avatar-editor.js?v=<?= urlencode(BASEHIM_VERSION) ?>" defer></script>
