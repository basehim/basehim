/**
 * Tailwind for the Basehim admin (1.2.77; one accent colour since 1.2.80).
 *
 * Builds admin/assets/css/tailwind.min.css from admin/assets/css/src/tailwind.css,
 * which holds the shared admin components (.bh-btn, .bh-card, .bh-badge, the
 * dashboard cards…) written with @apply. Sites never build anything: the
 * compiled file ships in the release. To rebuild after changing a view or the
 * source:
 *
 *     npx tailwindcss@3.4.19 -c tailwind.config.js \
 *         -i admin/assets/css/src/tailwind.css -o admin/assets/css/tailwind.min.css --minify
 *
 * (npm run build:css does the same.) Classes are found by scanning the files
 * below, so a class built from pieces at runtime ('bh-dstat--' + tone) must
 * appear whole somewhere, or be safelisted here.
 */
module.exports = {
    content: [
        './admin/views/**/*.php',
        './admin/assets/js/**/*.js',
        './app/**/*.php',
        './routes/**/*.php',
        './install.php',
        './docs/examples/**/*.php',
    ],
    theme: {
        extend: {
            colors: {
                // The admin's one accent colour (1.2.80): every decorative use —
                // icon squares, tiles, pills, bars, links, primary buttons — is
                // a shade of it; everything else is slate. Change it here to
                // re-colour the admin. (Tailwind's blue.)
                accent: {
                    50: '#eff6ff', 100: '#dbeafe', 200: '#bfdbfe', 300: '#93c5fd', 400: '#60a5fa', 500: '#3b82f6',
                    600: '#2563eb', 700: '#1d4ed8', 800: '#1e40af', 900: '#1e3a8a', 950: '#172554',
                },
            },
            boxShadow: {
                // One elevation for every card, tile button and panel.
                card: '0 1px 2px 0 rgb(15 23 42 / 0.04), 0 8px 24px -12px rgb(15 23 42 / 0.10)',
                'card-hover': '0 1px 2px 0 rgb(15 23 42 / 0.05), 0 14px 32px -14px rgb(15 23 42 / 0.18)',
                pop: '0 16px 36px -16px rgb(15 23 42 / 0.35)',
            },
            keyframes: {
                'bh-shimmer': { from: { backgroundPosition: '100% 0' }, to: { backgroundPosition: '-100% 0' } },
                'bh-new': {
                    '0%': { boxShadow: '0 0 0 0 rgb(37 99 235 / 0.45)', borderColor: '#2563eb' },
                    '100%': { boxShadow: '0 0 0 10px rgb(37 99 235 / 0)' },
                },
                'bh-fade': { from: { opacity: '0' } },
                'bh-slide': { from: { transform: 'translateX(24px)', opacity: '0.4' } },
                // Dashboard data arriving (1.2.77): bars grow, lines draw, labels rise.
                'bh-grow': { from: { transform: 'scaleY(0)' }, to: { transform: 'scaleY(1)' } },
                'bh-draw': { from: { strokeDashoffset: '1' }, to: { strokeDashoffset: '0' } },
                'bh-rise': { from: { opacity: '0', transform: 'translate(-50%, 4px)' }, to: { opacity: '1', transform: 'translate(-50%, 0)' } },
            },
            animation: {
                'bh-shimmer': 'bh-shimmer 1.3s ease-in-out infinite',
                'bh-new': 'bh-new 1.4s ease-out',
                'bh-fade': 'bh-fade .15s ease-out',
                'bh-slide': 'bh-slide .2s cubic-bezier(.2, .7, .3, 1)',
                'bh-grow': 'bh-grow .7s cubic-bezier(.2, .8, .2, 1) both',
                'bh-draw': 'bh-draw 1s cubic-bezier(.4, 0, .2, 1) both',
                'bh-rise': 'bh-rise .35s ease-out both',
            },
        },
    },
    plugins: [],
};
