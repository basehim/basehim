-- 005_fix_post_status.sql
--
-- Repair for installs seeded by the pre-1.31.3 installer, which inserted the
-- sample "Hello, world!" post and "About" page with status = 'publish'. The
-- whole application filters published content on status = 'published', so those
-- rows were invisible on the public site, in listings, and in the feed.
--
-- 'publish' is not a valid status anywhere in Basehim, so normalising it to
-- 'published' is safe and affects nothing that was working correctly.
UPDATE {posts} SET `status` = 'published' WHERE `status` = 'publish';
