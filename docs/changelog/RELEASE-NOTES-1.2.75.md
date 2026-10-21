More reliability fixes from the core audit, and the groundwork for shops
and payments.

- If a database update fails while Basehim updates itself, the Updates screen
  now says so and stops, instead of reporting success and installing the next
  update on top.
- Database updates are safe to run twice: running one again no longer shifts
  stored times.
- Scheduled tasks never run twice at the same moment, a task cut off by a
  time limit is run again, and a task that keeps failing waits longer between
  attempts instead of retrying constantly. Admin → System lists failing
  tasks and their last error.
- Settings saved at the same moment no longer overwrite each other or come
  back with old values.
- For developers: a request sent again with the same `Idempotency-Key` gets
  the first answer back instead of creating a second order or post, for the
  REST API, app pages (`'idempotent' => true`) and AJAX actions. Apps also get
  transactions, safe settings updates and a helper for verifying webhooks.
  See APP-API.md and PUBLIC-ROUTES.md.

After updating: nothing to do. The update adds two small database tables and
two columns to scheduled tasks.
