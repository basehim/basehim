Reliability and security fixes from the core audit.

- Accounts are safer through the API. Changing your own email or password
  there now needs your current password, and nobody can give themselves, or
  anyone else, a role higher than their own.
- Scheduled tasks keep running even when an app with tasks of its own is
  switched off or removed.
- Apps report what really happened. If an app's setup or upgrade fails, the
  Apps screen says so and the app stays off instead of being shown as
  "activated" or "upgraded". Fix the cause and activate it to try again; a
  failed upgrade is retried rather than forgotten.
- Saving a post with a category or tag that no longer exists is refused before
  anything is saved, so a retry can no longer create a duplicate post. Posts
  edited at the same moment no longer fail.
- Clearing an app's cache, or updating Basehim, now really clears cached data.
- Very large images (above 50 megapixels by default, see Settings → Media) are
  refused. Before, they could exhaust the server's memory.
- An app's dashboard card is shown to the people who can use that app, not to
  every signed-in account, unless the app says otherwise.

After updating: nothing to do. If an app's dashboard card disappeared for
editors or authors, ask its developer to give it a capability (for example
`'capability' => 'read'`), or grant those users access to the app.
