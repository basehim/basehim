Image options in the Customizer now work.

The Customizer's own screen was not loading the media picker, so pressing
**Choose** on the logo, site icon or any image option did nothing at all. Every
admin screen now shares one definition of the common assets, so this cannot
happen again on a screen added later.

The picker also gained a reusable form of itself: an app can now add an image
field to its own screens with markup alone, rather than writing picker code. The
Customizer's image option uses it.

Everything else from 1.1.1 is unchanged.
