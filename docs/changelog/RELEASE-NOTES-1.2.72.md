Apps can add public pages to your site safely.

- Apps can now add their own public pages and web addresses — a shop, a
  pricing page, a members area, a small API — and show them inside your
  theme, with your header, footer and menus.
- Your own pages, posts, search, feed and admin always come first: an app can
  no longer take over one of Basehim's addresses or slip a page into the admin
  area without sign-in. If an app tries, it is refused and the app's report
  (Apps → Report → Routes) says why.
- If two apps want the same address, the first keeps it and the report names
  the other.
- The app report now lists every address an app serves, the checks on each
  (sign-in, security token), and any page of yours an app address hides.
- Wrong requests get clearer answers: "method not allowed" instead of "not
  found" when an address exists but not for that kind of request.

After updating: nothing to do. Your apps' existing pages keep working.
