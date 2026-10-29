Fixes apps that loaded without their styling or stayed on "Loading…" after 1.2.90.

- Fixed: 1.2.90 added a version tag to app folder links as well as to app files, so apps that build file links from their folder link pointed to files that don't exist. Folder links are now exactly as they were before 1.2.90; file links keep the tag, so app updates still reach visitors' browsers straight away.

After updating: nothing to do. If an app screen still looks unstyled, reload it once (Ctrl+Shift+R).
