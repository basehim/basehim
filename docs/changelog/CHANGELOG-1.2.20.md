# Basehim 1.2.20 — Profile photo editor

Cumulative: contains every 1.2.19 file, so it installs on 1.2.18 or 1.2.19.
No database changes.

## Photo editor (My Profile, Edit User)

- The photo shows large with a pencil button. The button opens a `<dialog>`
  over the page, the page blurred behind it (`::backdrop` with
  `backdrop-filter: blur(6px)`).
- Two sources: **Upload** (drop zone or file picker) and **Media library**
  (search, load more; shown only to users with `upload_media`, which the
  library requires).
- Either way the image opens in **Cropper.js 1.6.2** with a fixed round frame:
  drag, wheel/buttons to zoom, rotate ±90°, reset. Saved as 512 × 512 JPEG and
  uploaded through the existing `POST /admin/profile/avatar` /
  `/admin/users/{id}/avatar`, so all of 1.2.19's rules still apply.
- Cropper.js loads from jsDelivr on first use, pinned and with Subresource
  Integrity (sha384 for both files). If it cannot load — offline, blocked — the
  photo is used as it is, so the editor still works.
- A library image the canvas cannot read (another origin) is saved as is,
  by `media_id`.
- Files: `admin/views/partials/avatar-field.php` (markup and styles),
  `admin/assets/js/avatar-editor.js` (new).

## Live everywhere in the admin

- New helper `bh_user_avatar($user, $sizeClass, $thumb = null)`: the photo or
  the initial, marked `data-user-avatar="{id}"`.
- Used by the top bar (`layouts/app.php`), the Users list and the photo
  editor. After a save or removal the editor swaps every element marked with
  that user's id — no reload.
- Users list: every row's photo in one query —
  `AvatarService::thumbnails(array $userIds)`.

## Theme side

- **Comment form:** a logged-in commenter's current photo beside
  "Commenting as …" (`bh_comment_form()` args `show_avatar`, default true;
  `avatar_size`, default 32).
- **Tiny author box photo:** a theme reset such as Tailwind's
  `img { max-width: 100%; height: auto }` let a long bio squeeze the photo's
  link, and the photo with it. The author box and `bh_avatar()` now size the
  photo inline (`width`, `height`, `max-width:none`, `flex:none`) and keep the
  link from shrinking.

## Files

    index.php, install.php                       version 1.2.20
    bootstrap.php                                bh_user_avatar(); comment form; sizing
    app/Services/AvatarService.php               thumbnails()
    admin/views/partials/avatar-field.php        editor
    admin/assets/js/avatar-editor.js             new
    admin/views/layouts/app.php                  top bar avatar
    admin/views/users/index.php                  list avatars
    + all 1.2.19 files (see CHANGELOG-1.2.19 in that release)
