/**
 * Build of the public-site admin bar's stylesheet (1.3.0):
 *     npm run build:adminbar
 *
 * The bar is drawn on theme pages, which don't load the admin stylesheet, so
 * it carries its own copy of the named colours (--bh-surface, --bh-accent…),
 * scoped to the bar and its dialogs. No preflight and no utilities: nothing
 * here may touch the theme's page.
 */
const palette = require('./tailwind.theme.js');

module.exports = {
    content: [{ raw: '' }],
    corePlugins: [],   // no preflight, no utilities, no '*' defaults: nothing touches the theme's page
    theme: palette.theme,
    plugins: [palette.scopedPlugin(['#bh-admin-bar', '.bhab-dialog', '.bhab-toast'])],
};
