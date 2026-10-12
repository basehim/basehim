Behind-the-scenes fixes to database updates, tags, and comment moderation.

Comments from administrators and editors are now published straight away,
even when "Moderate first" is on. Before, your own comments went into the
approval queue and you had to approve them yourself. Comments from everyone
else are handled exactly as before.

Database updates now run once each and stop safely on an error. Previously,
the first update after installing Basehim repeated every database update from
the beginning, and an update that failed partway could be marked as done.

There is now a single Tags list. New sites were given two "Tags" lists in the
admin, and tags added to the second one never appeared on the site. Any tags in
it are moved into the main one, with their posts.
