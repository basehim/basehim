# Basehim 1.2.19 — Profile photos

`users.avatar_media_id` and `AuthorService::avatarUrl()` existed, but nothing
could set the column, and themes drew initials or a Gravatar. 1.2.19 adds the
missing pieces end to end.

## Setting a photo

- **My Profile** and **Edit User** have a photo control (preview, Upload/Change,
  Remove). It saves at once over XHR, independent of the page's Save button.
  Shared template: `admin/views/partials/avatar-field.php`.
- Anyone may change their own photo. Changing someone else's follows Edit User's
  rule: `AccessControl::canManage()` — users below your level.
- `App\Services\AvatarService` is the single place for the rules, used by the
  admin screens and the API alike:
  - images only: jpg, jpeg, png, gif, webp — intersected with the site's allowed
    media types. **SVG is refused** (it can carry script);
  - at most 5 MB, or the site's upload limit if lower;
  - the file must be a real image (`getimagesize`), whatever its name;
  - stored as an ordinary media item ("Profile photo — {name}", alt = name).
    Replacing or removing a photo leaves the old file in the library.

## Showing it

- Every post row from `PostRepository` (all 7 queries) now carries
  `author_avatar_url` — the author's photo at the 150 px `thumbnail` size, or
  null — via one extra `LEFT JOIN {media}`; no extra query per post.
- `AuthorService::avatarUrl($user, $size = 150)` returns a resized copy for the
  size asked (thumbnail ≤ 75 px, medium ≤ 150 px, else original);
  `publicProfile()` adds `avatar_thumbnail_url`.
- New helper `bh_avatar($postOrUser, $size = 32, $args = [])`: the photo as
  `<img>`, or initials when there is none. Args: `class`, `alt`, `styles`.
- `bh_author_box()` uses the thumbnail when the avatar is 75 px or smaller,
  instead of the original upload.
- Themes: `default` (post meta, post list) and `basehim` (post meta, post card)
  show the photo, falling back to their existing initials/icon.

## REST API

| Method | Path | |
|---|---|---|
| GET    | `/api/v1/me/avatar` | your photo |
| POST   | `/api/v1/me/avatar` | multipart `avatar`, or `media_id` |
| DELETE | `/api/v1/me/avatar` | remove |
| GET    | `/api/v1/users/{id}/avatar` | a user's photo |
| POST   | `/api/v1/users/{id}/avatar` | yourself, or an administrator |
| DELETE | `/api/v1/users/{id}/avatar` | yourself, or an administrator |

Answer: `{"data": {"user_id", "avatar": {media_id, url, thumbnail_url,
medium_url, width, height} | null}}`. 401 / 403 / 404 / 422 (422 includes the
allowed `rules`). User responses (`GET /me`, `/users`, `/users/{id}`) now carry
`avatar` and `avatar_url`; post responses carry `author_avatar_url`.

Admin endpoints (session + CSRF, JSON): `POST /admin/profile/avatar`,
`/admin/profile/avatar/delete`, `/admin/users/{id}/avatar`,
`/admin/users/{id}/avatar/delete`. All are listed in the API Reference.

## Fixed

`ErrorHandler::statusFromException()` compared `getCode()` against 400–599 and
returned it from an `int` method. A PDOException's code is a string such as
`"42S02"`, so the error page itself died with a TypeError and visitors got a
blank 500. Only an integer code is used as a status now.

## Files

    index.php, install.php                        version 1.2.19
    bootstrap.php                                 bh_avatar(); author box size
    routes/admin.php, routes/api.php              10 routes
    app/Services/AvatarService.php                new
    app/Http/Controllers/Api/AvatarController.php new
    app/Http/Controllers/Api/ApiController.php    avatar in user responses
    app/Http/Controllers/Admin/ProfileController.php, UserController.php
    app/Repositories/PostRepository.php           author_avatar_url
    app/Services/AuthorService.php                sized avatars
    app/Core/ErrorHandler.php                     string exception codes
    admin/views/partials/avatar-field.php         new
    admin/views/profile/index.php, users/edit.php, api/reference.php
    content/themes/default/templates/single.php, index.php
    content/themes/basehim/templates/single.php, partials/post-card.php

No database changes.
