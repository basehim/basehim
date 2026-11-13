/**
 * Basehim's colours for an app's own Tailwind build (1.3.0).
 *
 *     // content/apps/my-app/tailwind.config.js
 *     module.exports = {
 *         presets: [require('../../../tailwind.preset.js')],
 *         content: ['./views/**\/*.php', './assets/js/**\/*.js'],
 *     };
 *
 * bg-accent-600, text-slate-700, border-slate-200, bg-palette-2 and dark:
 * then mean what they mean in core: they read the variables the admin's
 * stylesheet sets for the person's light/dark mode and accent, so the app
 * follows their choice with no colours of its own. See docs/ADMIN-UI.md,
 * "Light, dark and accent".
 */
module.exports = require('./tailwind.theme.js').preset;
