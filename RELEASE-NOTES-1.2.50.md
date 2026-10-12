Each site's admin now says which site it is, Settings save without reloading, and the API reference lists every endpoint.

- Admin tab titles are now "{Page} - {Site name} - Basehim" (e.g. "Admin Dashboard - Circuits DIY - Basehim"), and the tab icon is your site icon when one is set in the Customizer.
- The whole admin is marked noindex, nofollow: a meta tag on every admin page plus an X-Robots-Tag header on every /admin and /ajax response.
- All Settings screens save through the Basehim AJAX gateway, with no page reload. Checks and messages are the same as before, and the old form save still works if JavaScript doesn't run.
- The loading spinner now sits inside the button: Save changes on Settings, Save draft in the editor, and Delete in the media library. The button shows "Saving…", then "✓ Saved" (or a red "Not saved"), then its own label again.
- API → Reference adds the missing endpoints: site time, OAuth and discovery, robots.txt, llms.txt, the sitemap and feed, and the AJAX gateway. It also lists every AJAX action, every MCP tool and the routes your apps add, all read from the running site.
- Fixed in the "MCP Server — connect an AI assistant" section: the code boxes overflowed on phones with no scrollbar, and the endpoint now shows the full URL. Code boxes across the admin now have a visible scrollbar.
- Fixed: reference URLs left out the install folder and https behind a proxy, and the /posts filters were wrong (it takes ?q and ?author_id).

No database changes.
