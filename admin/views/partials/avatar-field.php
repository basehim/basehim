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

<style>
.bh-av{display:flex;align-items:center;gap:1.1rem}
.bh-av__face{position:relative;width:96px;height:96px;flex:none}
.bh-av__img{width:96px!important;height:96px!important;font-size:2.25rem;border:3px solid #fff;box-shadow:0 0 0 1px #e2e8f0,0 4px 14px -6px rgba(15,23,42,.35)}
.bh-av__edit{position:absolute;right:-2px;bottom:-2px;width:34px;height:34px;border-radius:50%;display:grid;place-items:center;background:#2563eb;color:#fff;border:3px solid #fff;cursor:pointer;box-shadow:0 2px 6px rgba(15,23,42,.25);transition:transform .15s,background .15s}
.bh-av__edit:hover{background:#1d4ed8;transform:scale(1.06)}
.bh-av__edit:focus-visible{outline:2px solid #93c5fd;outline-offset:2px}
.bh-av__edit svg{width:15px;height:15px}
.bh-av__label{font-size:.875rem;font-weight:600;color:#0f172a}
.bh-av__hint{font-size:.75rem;color:#64748b;margin:.15rem 0 0;max-width:34ch;line-height:1.45}
.bh-av__status{font-size:.75rem;margin:.35rem 0 0;min-height:1em;color:#047857}
.bh-av__status.is-error{color:#dc2626}

.bh-av-dialog{border:0;padding:0;border-radius:1rem;width:min(30rem,calc(100vw - 2rem));max-height:calc(100vh - 2rem);box-shadow:0 30px 70px -25px rgba(2,6,23,.55);color:#0f172a}
.bh-av-dialog::backdrop{background:rgba(15,23,42,.38);-webkit-backdrop-filter:blur(6px);backdrop-filter:blur(6px)}
.bh-av-dialog[open]{animation:bh-av-in .16s ease-out}
@keyframes bh-av-in{from{opacity:0;transform:translateY(8px) scale(.98)}}
.bh-av-dialog__box{display:flex;flex-direction:column;max-height:calc(100vh - 2rem)}
.bh-av-dialog__head{display:flex;align-items:center;justify-content:space-between;padding:1rem 1.25rem .5rem}
.bh-av-dialog__head h2{font-size:1.0625rem;font-weight:650;margin:0}
.bh-av-x{width:32px;height:32px;border-radius:.5rem;display:grid;place-items:center;border:0;background:transparent;color:#64748b;cursor:pointer}
.bh-av-x:hover{background:#f1f5f9;color:#0f172a}
.bh-av-x svg{width:18px;height:18px}
.bh-av-dialog section{padding:.25rem 1.25rem 0;overflow:auto}
.bh-av-tabs{display:flex;gap:.25rem;border-bottom:1px solid #e2e8f0;margin-bottom:1rem}
.bh-av-tabs button{border:0;background:none;padding:.55rem .8rem;font:inherit;font-size:.875rem;font-weight:500;color:#64748b;border-bottom:2px solid transparent;margin-bottom:-1px;cursor:pointer}
.bh-av-tabs button.is-on{color:#0f172a;border-bottom-color:#2563eb;font-weight:600}
.bh-av-drop{display:flex;flex-direction:column;align-items:center;gap:.25rem;padding:2rem 1rem;border:2px dashed #cbd5e1;border-radius:.85rem;background:#f8fafc;color:#475569;font-size:.875rem;cursor:pointer;text-align:center;transition:border-color .15s,background .15s}
.bh-av-drop:hover,.bh-av-drop.is-over{border-color:#2563eb;background:#eff6ff}
.bh-av-drop svg{width:34px;height:34px;color:#94a3b8;margin-bottom:.35rem}
.bh-av-drop strong{color:#0f172a}
.bh-av-drop u{color:#2563eb;text-decoration:none;font-weight:600}
.bh-av-drop input{position:absolute;width:1px;height:1px;opacity:0;pointer-events:none}
.bh-av-small{font-size:.75rem;color:#64748b;margin:.6rem 0 0}
.bh-av-search{width:100%;padding:.5rem .7rem;font:inherit;font-size:.875rem;border:1px solid #cbd5e1;border-radius:.55rem;margin-bottom:.75rem}
.bh-av-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(84px,1fr));gap:.5rem;max-height:300px;overflow:auto;padding:2px}
.bh-av-grid button{aspect-ratio:1;padding:0;border:2px solid transparent;border-radius:.6rem;overflow:hidden;background:#f1f5f9;cursor:pointer}
.bh-av-grid button:hover,.bh-av-grid button:focus-visible{border-color:#2563eb;outline:0}
.bh-av-grid img{width:100%;height:100%;object-fit:cover;display:block}
.bh-av-grid p{grid-column:1/-1;font-size:.8125rem;color:#64748b;text-align:center;margin:1rem 0}
.bh-av-more{text-align:center;margin-top:.6rem}
.bh-av-crop{height:300px;background:#0f172a;border-radius:.75rem;overflow:hidden}
.bh-av-crop img{display:block;max-width:100%}
.bh-av-crop.no-cropper{display:grid;place-items:center}
.bh-av-crop.no-cropper img{width:220px;height:220px;object-fit:cover;border-radius:50%}
.bh-av-crop .cropper-view-box,.bh-av-crop .cropper-face{border-radius:50%}
.bh-av-crop .cropper-view-box{outline:0;box-shadow:0 0 0 2px rgba(255,255,255,.9)}
.bh-av-crop .cropper-modal{background:#0f172a;opacity:.6}
.bh-av-tools{display:flex;justify-content:center;gap:.4rem;margin-top:.75rem}
.bh-av-tool{min-width:38px;height:36px;padding:0 .6rem;border:1px solid #cbd5e1;background:#fff;border-radius:.55rem;font:inherit;font-size:1.05rem;cursor:pointer;color:#334155}
.bh-av-tool--wide{font-size:.8125rem;font-weight:500}
.bh-av-tool:hover{background:#f8fafc;border-color:#94a3b8}
.bh-av-msg{margin:.6rem 1.25rem 0;font-size:.8125rem;color:#dc2626;min-height:1em}
.bh-av-dialog__foot{display:flex;align-items:center;gap:.5rem;padding:.9rem 1.25rem 1.1rem}
.bh-av-spacer{flex:1}
.bh-av-btn{padding:.5rem 1rem;border-radius:.55rem;border:1px solid transparent;background:#2563eb;color:#fff;font:inherit;font-size:.875rem;font-weight:600;cursor:pointer}
.bh-av-btn:hover{background:#1d4ed8}
.bh-av-btn:disabled{opacity:.6;cursor:default}
.bh-av-btn--quiet{background:#fff;color:#0f172a;border-color:#cbd5e1;font-weight:500}
.bh-av-btn--quiet:hover{background:#f8fafc}
.bh-av-btn--danger{background:#fff;color:#dc2626;border-color:transparent;font-weight:500;padding-left:.5rem;padding-right:.5rem}
.bh-av-btn--danger:hover{background:#fef2f2}
@media (prefers-reduced-motion:reduce){.bh-av-dialog[open]{animation:none}.bh-av__edit{transition:none}}
</style>
<script src="<?= htmlspecialchars($__base) ?>/admin/assets/js/avatar-editor.js?v=<?= urlencode(BASEHIM_VERSION) ?>" defer></script>
