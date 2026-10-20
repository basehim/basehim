Clearer empty lists, and one administration email everywhere.

- When a list has nothing to show, a large icon now sits centred above the
  message, which says whether there is nothing yet ("There are no posts to
  show yet.") or nothing matched your search or filters. Errors look the same
  way, in red.
- Changing the Administration Email in Settings → General now updates
  ADMIN_EMAIL in your site's .env file too.
- If that address is also your own sign-in email, Settings offers to change
  your account with it. It asks for your current password first, just like My
  Profile, so an unattended signed-in browser cannot take your account over.
- Changing your own email on My Profile now updates the Administration Email
  when they were the same.
- The Administration Email is checked: a mistyped address is refused instead
  of being saved.

After updating: if your Administration Email and account email already differ,
set the one you want (on Settings → General, or on My Profile). Saving
Settings → General once also brings .env up to date.
