Security headers, and safer rate limits and outbound requests.

- New: Settings → Security. Every page now sends HTTPS protection (HSTS, one
  year by default) and a Content-Security-Policy. The policy starts in
  "Report only" mode: nothing is blocked, and anything it would block is
  listed on that screen. When the list stays empty, choose Enforce.
- Your PHP version is no longer shown to visitors: pages say
  "X-Powered-By: Basehim" instead of "PHP/8.x".
- The obsolete "X-XSS-Protection: 1; mode=block" header is now "0", as
  current browsers and security guides advise. Your .htaccess file is updated
  for this the first time you open the admin.
- Fixed: visitors could get round search and app rate limits by sending a
  made-up address in a header.
- Apps can no longer be used to reach private addresses on your server's
  network through Basehim's request helper.
- Apps that other apps need can't be switched off or deleted by mistake, and
  app updates reach visitors' browsers straight away.

After updating: open the admin once, then Settings → Security. Leave the
policy in Report only for a few days; if no reports appear, switch it to
Enforce.
