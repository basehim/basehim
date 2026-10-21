Faster on large sites: the last group of fixes from the core audit.

- Post lists and the API stay fast however many posts a site has, and the
  API can page through every post by cursor (`?after=`).
- Search uses the database's word index and is limited to 30 searches a
  minute per visitor (Settings → Reading). Searching for "%" no longer matches
  everything.
- Posts, pages, categories and tags with Urdu, Arabic, Chinese, Cyrillic and
  other non-Latin titles get readable addresses instead of "n-a". Existing
  addresses are unchanged. Saving many posts with the same title is no longer
  slow.
- Author pages and unknown /author/ addresses are a single quick lookup.
- The sitemap lists every published post, in several files when needed;
  before, it stopped at 2,000.
- Page views are added up every minute, so a very popular post no longer
  slows down under heavy traffic (Settings → Reading → Page views).
- Old sign-in tokens, activity logs, notifications, log files and unused
  session files are cleaned up daily.

After updating: nothing to do. Search engines read the new sitemap at the
same address. If a site's search should find parts of words, or is in a
language written without spaces, choose "Text match" under Settings →
Reading → Search method.
