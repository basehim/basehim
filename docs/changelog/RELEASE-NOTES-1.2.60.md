Schedule posts for later.

- New: a Publish panel at the top of the editor's sidebar. Choose Immediately
  or Schedule, pick a date and time, and the button changes to Schedule. Times
  are in your site's timezone (Settings → General), shown next to the fields,
  with a preview of exactly when the post goes live.
- Scheduled posts stay hidden from visitors, feeds, the sitemap, search and the
  API until their time, then go live on their own. They are published by the
  first visit after the scheduled time, so the person who opens it already sees
  it. For exact times on a quiet site, add the cron URL shown under Admin →
  System → Cache & Maintenance to your hosting control panel's cron jobs.
- A scheduled post can be moved to another time, published now, or put back to
  draft. Updating it never publishes it early.
- Editing a published post keeps its date. Scheduling a live post again (taking
  it off the site until then) is only done when you choose Schedule, and the
  editor asks first.
- A past or invalid time is refused with a clear message; your edits are still
  saved and the post's status does not change.
- Fixed: contributors could choose "Scheduled" or "Private" in the status menu.
  Anything that goes out now needs a role that can publish; contributors submit
  for review, and the button now says so.
- Fixed: publishing a scheduled post from the list's bulk actions now dates it
  today rather than keeping its future time.
- Posts imported from WordPress as "scheduled" now go live at their time.
- New: the cron URL, the number of scheduled posts and the next one due are
  shown under Admin → System → Cache & Maintenance.

After updating: nothing to do. Optional: set up the cron URL (Admin → System →
Cache & Maintenance) so scheduled posts go live on the minute even when nobody
is visiting.
