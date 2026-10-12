Security release: HTML from authors and contributors is now filtered when a post is saved. Recommended for every site with more than one writer.

- Only editors and administrators can publish HTML exactly as written. For everyone else, scripts, event handlers (onclick and similar), javascript: links, forms and embedded frames from unknown sites are removed when the post is saved, whether it comes from the editor, the REST API or an AI agent over MCP. Until now an author could add a script that ran for any administrator who opened the post.
- Writers are told what was removed: a warning in the editor, and a warning field in API and MCP responses. Embeds from YouTube, Vimeo, Spotify, SoundCloud, Google Maps and the other providers the editor supports keep working.
- The new permission is called unfiltered_html. You can grant it to a trusted author, or deny it to an editor, under Users → edit → Individual permissions, and add it to custom roles. Give it only to people you would trust with your own admin account.
- Existing posts are not changed until someone saves them again.
- Markdown written by authors stays Markdown. It is saved as HTML only when it contains HTML that cannot be checked safely, and the writer is told.

For app developers: the post.unfiltered_html filter lets an app mark HTML it writes as trusted (for example from a scheduled task), and html.iframe_hosts adds embed providers.

No database changes.
