# Basehim 1.2.34

## Email sender address

- **Automatic From address.** When Settings › Email › From Email is empty,
  Basehim now sends as `noreply@<your site's domain>` (taken from APP_URL,
  without `www.`) and saves that address to the setting. Previously it fell
  back to the Admin Email — usually a Gmail or similar address — which the
  host's mail server refuses to send as, so password resets, sign-in codes
  and notifications silently never arrived.
- **New installs** get `noreply@<domain>` filled in during setup.
- **Envelope sender.** With the PHP mail() driver, the envelope sender
  (Return-Path) is now set to the From address when it is on the site's own
  domain, so SPF checks line up and bounces return to the site.
- **SMTP greeting** uses the site's domain instead of the raw request host.

## Settings › Email

- The From Email field shows the address in use and which domain to use.
  Leaving it empty saves the `noreply@` address; a malformed address is
  refused instead of being saved.
- A warning appears when the From address is on another domain (gmail.com,
  outlook.com…), or when the site has no public domain to build one from.
- Only known email settings are saved now (the form previously stored any
  posted field), and the SMTP port and encryption are checked.
- The saved SMTP password is no longer printed into the page's HTML. Leave
  the field empty to keep it, or tick "Remove the saved password".
