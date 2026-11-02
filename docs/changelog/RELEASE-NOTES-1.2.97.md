Fixes for the admin bar on phones, sites behind a proxy, content type lists and settings.

- On phones, when apps add menus to the admin bar, your account and display menus no longer slip off the screen: the whole bar now scrolls sideways, and status badges take less room.
- Sites behind a proxy or CDN that handles HTTPS (Cloudflare, a load balancer) now use https:// in every link Basehim builds, including canonical tags on posts, pages and author pages, and the "Remember me" sign-in cookie is marked secure.
- The admin list of an app's content type shows only the filters that type uses: no empty Tags filter for a type without tags.
- Settings screens save only their own settings.

After updating: nothing to do. If your site sits behind a proxy, make sure its address is listed in TRUSTED_PROXIES in .env (or that APP_URL starts with https://) so Basehim can tell the visit is secure.
