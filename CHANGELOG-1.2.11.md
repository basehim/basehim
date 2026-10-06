# Basehim 1.2.11 — migrations run once, one Tags taxonomy, moderators' comments

## Migrations ran twice

**Cause.** The installer recorded each migration by file name
(`005_fix_post_status.sql`); the updater and the System page recorded it without
the extension (`005_fix_post_status`). Neither recognised the other's rows, so
the first update — or the first press of **Run migrations** — after an install
ran every migration from 001 again. On a live install, all nine had been
recorded twice, 80 minutes after installation. Most migrations are written to
survive this; `005_fix_post_status` rewrites data, and any future migration
that was not safe to repeat would have failed or duplicated data.

Three more weaknesses in the same code:

- **Two copies of the runner.** The updater and the System page each had
  their own, and they had already drifted once (the System page's copy never
  expanded `{table}` tokens and had never worked).
- **Errors after the first statement were lost.** Each migration file was
  sent to `PDO::exec()` whole; MySQL reports an error only for the first
  statement of such a batch, so a migration that failed halfway was recorded
  as applied.
- **Nothing prevented a second record** — the table had no unique index.

**Fix.**

- **`MigrationService`, the one runner.** The updater and the System page both
  call it. It compares names without `.sql`, so either form counts as the same
  migration; runs **one statement at a time**, so every error surfaces with its
  migration and statement number; records a migration **only after every
  statement succeeds** (a failed one stays pending and runs again next time);
  closes every result set; and holds a named lock so two runs cannot overlap.
- **The installer records names without `.sql`.**
- **Migration `011`** tidies the table — one row per migration, all without
  `.sql` — and adds a **unique index**, so a migration can never be recorded
  twice again.
- The System page lists each migration once.

Verified: on a live install the new runner found exactly one pending migration
(`011`), applied it, and found nothing on a second run; a deliberately failing
migration was reported as `statement 2: Unknown column …`, **not** recorded,
and left pending.

## Two Tags taxonomies

Migration `001` creates the `tag` taxonomy, which the post editor, `/tag/`
archives, the Tags widget, the sitemap, the menu builder and WP Migrator all
use. The installer then also created `post_tag`, the WordPress name — so every
install had a second **Tags** in admin, and a tag created there would never
appear on the site.

- The installer now seeds `tag`, which already exists, so it simply confirms
  it.
- Migration `011` folds any `post_tag` into `tag`: a tag present in both
  becomes one, keeping every post from both; the rest move across as they are;
  the empty `post_tag` is removed; counts are recomputed.

Rehearsed twice over on copies of two live databases: 22 tags and 119 post
links came through unchanged, category links untouched; a seeded clash
("Arduino" in both, on different posts) merged into one tag on both posts.

The Tags widget on a site with no tags is empty because there are no tags —
this release does not change that.

## Moderators' comments waited for moderation

With **Moderate first** on, every comment was held — including one from the
super admin, who then had to approve their own comment.

A signed-in user with the **`moderate_comments`** capability — by default super
admin, admin and editor — is now published immediately, and skips the checks
meant for strangers (posting rate, blocklist, link count, moderation words).
The **duplicate check still applies**: it catches a double submission from
anyone. Authors, contributors, subscribers and guests are unchanged.

**Found on the way: `AuthService::userCan()` always returned false.** It read
`capabilities.<role>` from the config; roles live under
`capabilities.roles.<role>`, so it found no capabilities for anyone, the super
admin included. It now gives the same answer as the admin area
(`CheckCapability::userCan()`), including per-user grants and denials. Nothing
in core used it before this release; apps use `UsersApi::can()`, which was
already correct.

Verified through the real comment handler with moderation on: super admin and
editor published, including a second comment at once and one with five links;
an exact duplicate refused; author and guest held; guests still rate-limited;
with moderation off, unchanged.

## Files

    app/Services/MigrationService.php                    new: the one runner
    database/migrations/011_migration_keys_and_tags.sql  new: tidy migrations, unique index; fold post_tag into tag
    app/Services/UpdateService.php                       uses MigrationService
    app/Http/Controllers/Admin/SystemController.php      uses MigrationService
    app/Services/SystemInfoService.php                   lists each migration once
    install.php                                          records names without .sql; seeds "tag"
    app/Http/Controllers/Web/CommentController.php       moderators published immediately
    app/Services/CommentService.php                      trusted comments skip stranger checks
    app/Services/AuthService.php                         userCan() fixed

## Note on this update itself

The migration that installs with this update is run by the updater already
loaded in memory — the previous one. Migration `011` is written for that
runner too: every statement is safe to repeat, and none returns rows.
