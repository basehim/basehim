/**
 * Builds admin/assets/css/colors.css (1.3.0): Basehim's named colour palette
 * on its own, for themes and any page that doesn't load the admin stylesheet.
 * No reset and no utilities: only the palette's variables (light, and dark
 * under <html data-theme="dark">) and its classes (.bh-bg-red,
 * .bh-text-bg-success…). The colours are defined once, in tailwind.theme.js.
 *
 *     npx tailwindcss@3.4.19 -c tailwind.colors.config.js \
 *         -i admin/assets/css/src/colors.css -o admin/assets/css/colors.css --minify
 */
const palette = require('./tailwind.theme.js');
const safelist = require('./tailwind.safelist.js').filter((c) => c.startsWith('bh-'));

module.exports = {
    content: [{ raw: '' }],
    safelist,
    corePlugins: [],
    plugins: [palette.colorsPlugin],
};
