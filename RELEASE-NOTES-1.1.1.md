Appearance settings move into a new Customizer with a live preview, and themes
can now have options of their own.

**Appearance → Customize** shows the controls beside a live preview of the site.
Colours and sizes update instantly as you change them; options that change the
page layout refresh the preview. Nothing is saved until you press Save, and
leaving without saving changes nothing — the preview is never visible to
visitors.

**Site name, description, logo, site icon, footer text and custom CSS** are all
in the Customizer now, in one place instead of split across two screens. Your
existing values carry over untouched and nothing needs re-entering. The old
Settings → Appearance link redirects to the new screen.

**Themes can declare their own options** — colours, layouts, spacing, images —
and they appear in the Customizer automatically. Theme authors describe the
options in the theme's manifest; there is no admin code to write and no PHP in
templates. Options are stored separately for each theme, so switching themes and
switching back does not lose your work.

Preview widths for desktop, tablet and phone are built in.

If you are running a theme of your own, it needs one line added to its header to
support this — see the changelog for the detail. A theme without it keeps
working; it simply has nothing to customise.

**The media picker now works everywhere.** It was loaded by some admin screens
and not others — on the new Customizer the Choose button did nothing at all.
Every admin screen now shares one definition of the common assets, so a screen
cannot be built without the picker. Apps can also add an image field to their
own screens with markup alone, rather than writing picker code.

