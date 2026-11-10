# Basehim 1.2.106

A Quick View for the Comments list. No database changes.

## Why

The Comments list truncates each comment to ~140 characters, and comments have
no per-row edit screen — moderation happens through the row menu and the bulk
bar. That left no way to read a long comment in full before deciding on it: you
saw a snippet and had to approve, spam or delete on partial information.

## The change

`comments.list` now registers a `quick_view` callable (`routes/tables.php`).
Opening a row's menu shows **Quick View** — the row menu adds it automatically
for any table that defines the callable (`datatable.js`, `payload.quick_view`) —
and it shows:

- the full comment body, as a labelless full-width item (the `wide => true`
  Quick View item from 1.2.105), so it keeps its line breaks and is readable at
  length;
- a **Details** section: author email (a `mailto:` link), website (passed
  through `CommentService::safeAuthorUrl()` so only a safe `http(s)` link is
  linked), IP address, the time it was posted, a "In reply to" line for a
  reply, and the post it's on, linked to that post's editor;
- a status badge — Approved (green), Pending (amber), Spam (red), Trash (gray).

The callable fetches one comment with the same post join `CommentService::
paginate()` uses, and returns `null` for a missing id (the dialog then shows its
not-found message). The comment body is carried verbatim; the client renders
Quick View values as text, so any markup in a comment shows as text rather than
being interpreted.

Nothing else changed: the columns, the status filter, the bulk actions
(approve / pending / spam / delete) and the moderation routes are untouched.

## Tests

`tests/Unit/Release12106Test.php` — the registration exposes a `quick_view`,
the website goes through the safe-URL guard, and `cleanView()` keeps the badge,
the Details section and the full-width body (line breaks and verbatim markup).
