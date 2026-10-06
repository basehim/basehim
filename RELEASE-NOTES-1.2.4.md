Uploaded images and files now keep a readable name instead of a random code.

Uploading "My Awesome Image.png" now gives you /uploads/2026/09/my-awesome-image.png
rather than a long string of letters and numbers, which is better for image
search and easier to recognise in a link. If a file with that name already
exists, the new one is numbered: my-awesome-image-2.png, -3, and so on. Two
people uploading the same name at the same moment still get separate files.

Media you have already uploaded is not renamed, so no existing link or image on
your site changes.

Also fixed:

- Apps that save into the media library — Image Studio, Photo Studio, Office,
  Pinout Maker and others — were failing with "No file uploaded". They now save
  normally.
- Uploading through the REST API reported an error even though the upload had
  worked, which could create duplicate files when a client retried. It now
  reports success.
