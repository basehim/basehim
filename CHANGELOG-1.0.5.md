# Basehim 1.0.5 — preferred address, and the last of the agents

## Preferred address

Settings → Permalinks now has a **Preferred address** section: choose `www` or
no `www`, and whether to force HTTPS. Saving writes the redirect rules into
`.htaccess`.

A site reachable at more than one address splits its search ranking between
them, and can log a visitor out when they cross from one to the other. This
makes every visit land on the same address.

### The rules are more careful than the usual copy-paste

**HTTPS is tested three ways, not one.** Behind Cloudflare or a load balancer
the connection to Apache genuinely is plain HTTP, so a rule that checks only
`%{HTTPS}` redirects a request that already arrived over HTTPS — to itself,
forever. The forwarded headers are what say which scheme the visitor used.

**The host rules keep the scheme they found.** Hard-coding `https://` breaks a
site that has no certificate yet; hard-coding `http://` throws away a secure
connection. The scheme is captured into an environment variable first and reused.

**Adding `www` only applies to a name with one dot.** Otherwise
`shop.example.com` becomes `www.shop.example.com`, which does not exist.
`localhost` and bare IP addresses are left alone for the same reason.

### Editing .htaccess safely

That file can make a site unreachable while also removing the admin screen you
would use to put it right. So:

- the rules live between `# BEGIN Basehim canonical URL` and its `# END`, and
  nothing outside those markers is touched — hand-written rules and anything
  the host added survive;
- the file is written through a temporary file and renamed, so a failure
  halfway cannot leave a half-written `.htaccess`;
- a timestamped backup is taken before every write;
- **after writing, the site is fetched.** If it does not answer, the backup goes
  straight back. You are told the rules were reverted rather than discovering it
  later.

If there is no `.htaccess`, or it is not writable, the setting still saves and
the screen shows the exact block to paste. Nginx installs are told plainly that
this belongs in the server configuration instead.

There is also a **Leave it alone** option, which is the right choice when the
host or Cloudflare already redirects — two sets of rules can loop.

## Desktop agents removed

`AgentService`, both `AgentController`s and the agents admin view were still on
disk. Nothing routed to them, nothing referenced them, and no migration created
the tables they queried — 43 KB of code that looked live and was not.

**A patch cannot delete files**, since updates overlay rather than replace. To
finish the removal, delete these by hand after installing:

    app/Services/AgentService.php
    app/Http/Controllers/Admin/AgentController.php
    app/Http/Controllers/Api/AgentController.php
    admin/views/agents/

They are already gone from the 1.0.5 source, so a fresh install will not have
them, and the GitHub sync will drop them from the repository on the next push.
Leaving them costs nothing but confusion.

The `.htaccess` shipped here also loses a comment about agents polling for
commands, which described behaviour that no longer exists.

## After installing

The updated `.htaccess` in this patch **overwrites the one at your site root**.
If you have added rules of your own to that file, copy them out first and paste
them back afterwards — outside the BEGIN/END markers, where they will be left
alone from then on.
