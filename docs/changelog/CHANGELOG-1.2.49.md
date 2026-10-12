# Basehim 1.2.49

A fix for modified dates: **viewing a post no longer marks it as modified.**

## The bug

`posts.updated_at` is an `ON UPDATE CURRENT_TIMESTAMP` column. The view
counter updated the same row (`view_count = view_count + 1`), so every page
view also set the post's modified date to "now". That date is what core puts
in:

- `article:modified_time` (Open Graph) and `dateModified` (JSON-LD), which
  search engines read as "this article changed today";
- the sitemap's `lastmod`, so crawlers re-fetch posts that never changed;
- the admin's "last modified" columns and sorting.

On CDIY, 485 of the 486 posts viewed since the migration had their modified
date overwritten this way.

Deleting a user and giving their posts to someone else did the same to every
one of those posts.

## The fix

Both statements now keep `updated_at` as it is (`updated_at = updated_at`).
Views are still counted, and real edits still set the modified date.

Tests: `tests/Integration/PostDatesTest.php`. They fail without the fix.

No database changes. Dates that were already overwritten stay as they are;
CDIY's were restored from circuits-diy.com.

Files changed: `app/Repositories/PostRepository.php`,
`app/Http/Controllers/Admin/UserController.php`, `index.php`, `install.php`.
