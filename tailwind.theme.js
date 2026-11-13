/**
 * The admin's colour system (1.3.0): light and dark modes, and an accent
 * each person chooses (Account menu → Appearance, saved on their account).
 *
 * Every colour utility the admin uses (bg-, text-, border-, ring-, fill-…
 * with slate, white, the accent and the status hues) reads a CSS custom
 * property instead of a fixed value, so one stylesheet serves every mode and
 * accent. Nothing here is a hand-picked hex: the values are Tailwind's own
 * palette, and dark tones are mixed from it.
 *
 * How a colour resolves:
 *   - role: background, text and border get their own variable, because dark
 *     mode needs different shades for each (a light tint behind text becomes a
 *     dark tint; dark text becomes light text; white text stays white);
 *   - <html data-theme="light|dark">: the mode (the page's head script turns
 *     "system" into one of the two before anything is drawn);
 *   - <html data-accent="blue|green|…">: which palette `accent` (and `blue`,
 *     its alias) means.
 *
 * Variables hold "r g b" channels so Tailwind's opacity modifiers still work
 * (bg-accent-500/10).
 */
const colors = require('tailwindcss/colors');
const plugin = require('tailwindcss/plugin');

const STEPS = [50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950];

/*
 * The accents people can choose (1.3.0): each is a four-colour palette.
 *   colors   the palette's four colours, in the order the palette gives them
 *            (Default uses Tailwind's blue; the others are the reference
 *            palettes Sunset, Rose, Amphibian, Strawberry, Kiwi and Pink LA;
 *            Sunset's orange and navy are the reference pair, its sage
 *            and sunflower the two that complete it, between the two);
 *   main     which colour the admin is built on: buttons, links, the active
 *            menu item. Its full 50–950 scale is worked out from it below;
 *   second   the colour shown overlapping it in the picker, and the second
 *            colour of charts and gradients.
 * Charts use all four (palette-1 … palette-4: the main colour, the second,
 * then the other two in order).
 */
const ACCENTS = {
    blue:       { label: 'Default',    colors: [colors.blue[800], colors.blue[600], colors.blue[400], colors.blue[200]], main: 1, second: 2, scale: 'blue' },
    // Replaces Matte (1.3.0), which read almost the same as Default.
    sunset:     { label: 'Sunset',     colors: ['#FE7F2D', '#233D4D', '#619B8A', '#FCCA46'], main: 0, second: 1 },
    rose:       { label: 'Rose',       colors: ['#CC184E', '#E84575', '#F76CAE', '#FFE3B3'], main: 0, second: 2 },
    amphibian:  { label: 'Amphibian',  colors: ['#3E54D3', '#4F80E2', '#15CDCA', '#4FE0B6'], main: 0, second: 2 },
    strawberry: { label: 'Strawberry', colors: ['#F14666', '#EE8980', '#FFCDAA', '#9CB898'], main: 0, second: 3 },
    kiwi:       { label: 'Kiwi',       colors: ['#028174', '#0AB68B', '#92DE8B', '#FFE3B3'], main: 0, second: 2 },
    'pink-la':  { label: 'Pink LA',    colors: ['#7827E6', '#8D39EC', '#AA4FF6', '#EA80FC'], main: 0, second: 3 },
};
const DEFAULT_ACCENT = 'blue';


// Hues that keep their own meaning (status colours, tags). `blue` is not
// here: in the admin it is the accent.
const NEUTRALS = ['slate', 'gray'];
const HUES = ['red', 'orange', 'amber', 'yellow', 'green', 'emerald', 'teal', 'cyan', 'sky', 'indigo', 'violet', 'purple', 'pink', 'rose'];

// ---------------------------------------------------------------------------
// Colour arithmetic on Tailwind's hex values.
// ---------------------------------------------------------------------------
const rgb = (hex) => {
    const h = hex.replace('#', '');
    const n = parseInt(h.length === 3 ? h.split('').map((c) => c + c).join('') : h, 16);
    return [(n >> 16) & 255, (n >> 8) & 255, n & 255];
};
const mix = (a, b, t) => rgb(a).map((v, i) => Math.round(v * (1 - t) + rgb(b)[i] * t));
const ch = (c) => (Array.isArray(c) ? c : rgb(c)).join(' ');
const hex = (c) => '#' + c.map((x) => x.toString(16).padStart(2, '0')).join('');
// WCAG relative luminance and contrast ratio.
const lum = (c) => {
    const [r, g, b] = (Array.isArray(c) ? c : rgb(c)).map((x) => {
        x /= 255;
        return x <= 0.03928 ? x / 12.92 : ((x + 0.055) / 1.055) ** 2.4;
    });
    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
};
const contrast = (a, b) => { const [x, y] = [lum(a), lum(b)].sort((m, n) => n - m); return (x + 0.05) / (y + 0.05); };
// Mix `from` towards `to` in small steps until it reaches `ratio` against `against`.
const until = (from, to, against, ratio, start = 0) => {
    for (let t = start; t <= 1; t += 0.02) {
        const c = hex(mix(from, to, t));
        if (contrast(c, against) >= ratio) return c;
    }
    return to;
};
const INK = colors.slate[950];

/*
 * A 50–950 scale built on one colour: lighter steps mix it with white, 500 is
 * the colour itself, and 600 is the first shade (at least a little darker)
 * that keeps white text at 4.5:1, so buttons and links are readable whatever
 * the palette. The darker steps continue from 600 towards slate-950.
 */
function scaleFrom(base) {
    const out = {};
    [[50, 0.08], [100, 0.16], [200, 0.3], [300, 0.5], [400, 0.75]].forEach(([s, t]) => { out[s] = hex(mix('#ffffff', base, t)); });
    out[500] = base.toLowerCase();
    out[600] = until(base, INK, '#ffffff', 4.5, 0.12);
    [[700, 0.22], [800, 0.4], [900, 0.56], [950, 0.72]].forEach(([s, t]) => { out[s] = hex(mix(out[600], INK, t)); });
    return out;
}
// An accent's scale: Tailwind's own for Default, worked out for the others.
const accentScale = (a) => (a.scale ? colors[a.scale] : scaleFrom(a.colors[a.main]));
// The four chart colours: main, second, then the rest in the palette's order.
const accentOrder = (a) => [a.main, a.second, ...a.colors.map((_, i) => i).filter((i) => i !== a.main && i !== a.second)].map((i) => a.colors[i]);

// ---------------------------------------------------------------------------
// Dark mode tones, all from slate.
// ---------------------------------------------------------------------------
const S = colors.slate;
const DARK = {
    page: mix(S[900], S[950], 0.55),   // the page behind cards
    surface: rgb(S[900]),              // cards, menus, inputs (light: white)
    raised: mix(S[800], S[900], 0.5),  // hover rows, subtle fills
};
const surfaceHex = '#' + DARK.surface.map((v) => v.toString(16).padStart(2, '0')).join('');

// ---------------------------------------------------------------------------
// The named colour palette (1.3.0): ten colours, the same whatever the accent,
// for core, apps and themes, used like Bootstrap's (.bh-bg-red, .bh-text-red,
// .bh-text-bg-red…, or bg-red / text-red in Tailwind builds). Status colours
// are aliases of four of them, so success is always green and danger always
// red, whichever accent a person picks. docs/ADMIN-UI.md, "Colour palette".
// ---------------------------------------------------------------------------
const NAMED = {
    red: '#E81C2B', orange: '#F4891E', yellow: '#EACE2A', green: '#0AA34F', blue: '#459FE3',
    pink: '#D80B8F', purple: '#6A3499', brown: '#6D340D', black: '#0F1113', white: '#FFFFFF',
};
const STATUS = { success: 'green', warning: 'orange', danger: 'red', info: 'blue' };
const NAMES = [...Object.keys(NAMED), ...Object.keys(STATUS)];

/*
 * Every shade of one named colour, worked out, never typed in:
 *   fill    the colour; deepened a little (at most 20%) when that lets white
 *           text read at 4.5:1 on it, else kept as it is with black text;
 *   on      the text drawn on the fill (white or the palette's black);
 *   text    the colour as text on the page: deepened (light) or lifted (dark)
 *           only until it reads at 4.5:1 on its subtle fill, so on the card too;
 *   subtle  a tint of it on the card, for notes, badges and rows;
 *   border  a line in it that shows on the card.
 * Black and white fills are literal, with the other as their text. As text
 * they follow the same rule as the rest, so `text-black` stays readable in
 * dark mode (a light grey there) and `text-white` in light (a mid grey).
 */
function namedShades(hexc, mode) {
    const black = NAMED.black, card = mode === 'dark' ? surfaceHex : '#ffffff';
    let fill = hexc, on = black;
    if (hexc === NAMED.white) on = black;
    else if (hexc === black) on = '#ffffff';
    else {
        for (let t = 0; t <= 0.2001; t += 0.02) {
            const c = hex(mix(hexc, black, t));
            if (contrast(c, '#ffffff') >= 4.5) { fill = c; on = '#ffffff'; break; }
        }
    }
    const subtle = hex(mix(card, hexc, mode === 'dark' ? 0.2 : 0.12));
    const text = until(hexc, mode === 'dark' ? '#ffffff' : black, subtle, 4.5);
    return { fill, on, text, subtle, border: hex(mix(card, hexc, mode === 'dark' ? 0.5 : 0.45)) };
}

// The palette's variables for one mode: --bh-red, --bh-on-red, --bh-red-text,
// --bh-red-subtle, --bh-red-border; the same for success, warning, danger and
// info (plus -soft, the name status fills had before).
function namedVars(mode) {
    const out = {};
    for (const n of NAMES) {
        const sh = namedShades(NAMED[STATUS[n] || n], mode);
        out[`--bh-${n}`] = ch(sh.fill);
        out[`--bh-on-${n}`] = ch(sh.on);
        out[`--bh-${n}-text`] = ch(sh.text);
        out[`--bh-${n}-subtle`] = ch(sh.subtle);
        out[`--bh-${n}-border`] = ch(sh.border);
        if (STATUS[n]) out[`--bh-${n}-soft`] = ch(sh.subtle);
    }
    return out;
}

// The palette's classes, Bootstrap-style and always in the stylesheet (not
// only when a scanned file uses them), so apps and themes can use them
// without a build. Utilities still win over them.
function namedClasses() {
    const c = (n, k) => `rgb(var(--bh-${k === '' ? n : k === 'on' ? 'on-' + n : n + '-' + k}))`;
    const out = {};
    for (const n of NAMES) {
        out[`.bh-bg-${n}`] = { 'background-color': c(n, '') };
        out[`.bh-bg-${n}-subtle`] = { 'background-color': c(n, 'subtle') };
        out[`.bh-text-${n}`] = { color: c(n, 'text') };
        out[`.bh-text-bg-${n}`] = { 'background-color': c(n, ''), color: c(n, 'on') };
        out[`.bh-border-${n}`] = { 'border-color': c(n, '') };
        out[`.bh-border-${n}-subtle`] = { 'border-color': c(n, 'border') };
    }
    return out;
}

// A neutral shade in dark mode, per role.
function darkNeutral(p, role, step) {
    const P = colors[p];
    if (role === 'bg') {
        return {
            50: DARK.page, 100: DARK.raised, 200: rgb(P[800]), 300: rgb(P[700]), 400: rgb(P[600]),
            500: rgb(P[500]), 600: rgb(P[400]), 700: rgb(P[600]), 800: rgb(P[700]), 900: rgb(P[800]), 950: rgb(P[900]),
        }[step];
    }
    if (role === 'tx') {
        // 50–200 are light text on dark backgrounds (tooltips, dark chips): kept.
        // 400 (hints, captions) sits between 500 and 400 so it reads at 4.5:1.
        return {
            50: rgb(P[50]), 100: rgb(P[100]), 200: rgb(P[200]), 300: rgb(P[500]), 400: mix(P[500], P[400], 0.4),
            500: rgb(P[400]), 600: rgb(P[400]), 700: rgb(P[300]), 800: rgb(P[200]), 900: rgb(P[100]), 950: rgb(P[50]),
        }[step];
    }
    // borders and dividers
    return {
        50: DARK.raised, 100: rgb(P[800]), 200: mix(P[800], P[700], 0.35), 300: rgb(P[700]), 400: rgb(P[600]),
        500: rgb(P[500]), 600: rgb(P[500]), 700: rgb(P[600]), 800: rgb(P[700]), 900: rgb(P[800]), 950: rgb(P[900]),
    }[step];
}

// A coloured shade in dark mode, per role: pale tints become dark tints of the
// hue, dark text becomes light text; the strong middle stays.
function darkHue(get, role, step) {
    const tint = (t) => mix(surfaceHex, get(500), t);
    if (role === 'bg') {
        const t = { 50: 0.12, 100: 0.18, 200: 0.26, 300: 0.38 }[step];
        return t ? tint(t) : rgb(get(step));
    }
    if (role === 'tx') {
        const to = { 500: 400, 600: 300, 700: 300, 800: 200, 900: 200, 950: 100 }[step];
        return rgb(get(to || step));
    }
    const t = { 50: 0.16, 100: 0.22, 200: 0.3, 300: 0.42 }[step];
    return t ? tint(t) : rgb(get(step));
}

// Background, text and border. Every other colour utility (ring, fill,
// stroke, gradients, outline) uses the background shades.
const ROLES = ['bg', 'tx', 'bd'];
const v = (role, hue, step) => `--bh-${role}-${hue}${step === undefined ? '' : '-' + step}`;
const use = (role, hue, step) => `rgb(var(${v(role, hue, step)}) / <alpha-value>)`;

// The Tailwind colour scales for one role.
function scales(role) {
    const out = {
        transparent: 'transparent', current: 'currentColor', inherit: 'inherit', black: colors.black,
        white: use(role, 'white'),
        accent: Object.fromEntries(STEPS.map((s) => [s, use(role, 'accent', s)])),
    };
    out.blue = out.accent;
    out.palette = Object.fromEntries([1, 2, 3, 4].map((i) => [i, use(role, 'palette', i)]));
    for (const h of [...NEUTRALS, ...HUES]) out[h] = Object.fromEntries(STEPS.map((s) => [s, use(role, h, s)]));
    if (role === 'tx') out.white = 'rgb(255 255 255 / <alpha-value>)'; // white text stays white
    // The named palette (1.3.0): `bg-red`, `bg-red-subtle`, `text-red`,
    // `text-on-red`, `border-red`, `border-red-subtle`, and the same for
    // brown and the statuses. `blue-600` stays the accent; bare `blue` is the
    // palette's blue. Black and white keep Tailwind's meaning.
    const named = (n, k) => `rgb(var(--bh-${k ? (k === 'on' ? 'on-' + n : n + '-' + k) : n}) / <alpha-value>)`;
    for (const n of NAMES) {
        if (n === 'black' || n === 'white') continue;
        const scale = n === 'blue' ? { ...out.accent } : { ...(out[n] || {}) };
        if (role === 'bg') Object.assign(scale, { DEFAULT: named(n), subtle: named(n, 'subtle') });
        if (role === 'tx') scale.DEFAULT = named(n, 'text');
        if (role === 'bd') Object.assign(scale, { DEFAULT: named(n), subtle: named(n, 'border') });
        out[n] = scale;
        if (role === 'tx') out['on-' + n] = named(n, 'on');
    }
    return out;
}

// Light-mode grey text (1.3.0): hints in 400 were 2.6:1 on white, and 500 on
// a raised fill (code, chips, hover rows) fell just under 4.5:1. Each is
// darkened only as far as it takes to read at 4.5:1 on the surface it sits
// on: 400 on the page (slate-50), 500 on a raised fill (100). 400 stays the
// lighter of the two, so hints still sit below secondary text.
function lightNeutralText(p, step) {
    const P = colors[p];
    if (step === 400) return until(P[400], P[600], P[50], 4.5);
    if (step === 500) return until(P[500], P[700], P[100], 4.5);
    return P[step];
}

// The variables, as CSS rules.
function base() {
    const light = {}, dark = {};
    for (const role of ROLES) {
        light[v(role, 'white')] = '255 255 255';
        if (role !== 'tx') dark[v(role, 'white')] = ch(DARK.surface);
        for (const p of NEUTRALS) for (const s of STEPS) {
            light[v(role, p, s)] = ch(role === 'tx' ? lightNeutralText(p, s) : colors[p][s]);
            dark[v(role, p, s)] = ch(darkNeutral(p, role, s));
        }
        for (const h of HUES) for (const s of STEPS) {
            light[v(role, h, s)] = ch(colors[h][s]);
            dark[v(role, h, s)] = ch(darkHue((x) => colors[h][x], role, s));
        }
    }
    // Plain names for hand-written stylesheets (ui.css, datatable.css, the
    // layout): use them as rgb(var(--bh-surface)).
    Object.assign(light, {
        '--bh-page': ch(colors.slate[50]), '--bh-surface': '255 255 255', '--bh-raised': ch(colors.slate[100]),
        '--bh-text': ch(colors.slate[800]), '--bh-strong': ch(colors.slate[900]), '--bh-muted': ch(colors.slate[500]),
        '--bh-line': ch(colors.slate[200]), '--bh-line-strong': ch(colors.slate[300]),
        // Status colours (success, warning, danger, info) come from the named
        // palette below, so they are the same whatever the accent.
        ...namedVars('light'), '--bh-neutral': ch(colors.slate[500]),
        'color-scheme': 'light',
    });
    Object.assign(dark, {
        '--bh-page': ch(DARK.page), '--bh-surface': ch(DARK.surface), '--bh-raised': ch(DARK.raised),
        '--bh-text': ch(colors.slate[200]), '--bh-strong': ch(colors.slate[50]), '--bh-muted': ch(colors.slate[400]),
        '--bh-line': ch(mix(colors.slate[800], colors.slate[700], 0.35)), '--bh-line-strong': ch(colors.slate[700]),
        ...namedVars('dark'), '--bh-neutral': ch(colors.slate[500]),
        'color-scheme': 'dark',
    });

    const rules = { ':root, [data-theme="light"]': light, '[data-theme="dark"]': dark };
    // Accent palettes: light by [data-accent], dark by both attributes (more
    // specific, so the dark tones of the chosen accent win).
    for (const [name, a] of Object.entries(ACCENTS)) {
        const sc = accentScale(a);
        const get = (s) => sc[s];
        const l = {}, d = {};
        for (const role of ROLES) for (const s of STEPS) {
            l[v(role, 'accent', s)] = ch(get(s));
            d[v(role, 'accent', s)] = ch(darkHue(get, role, s));
        }
        // The four palette colours, for charts and the picker. On a dark
        // surface a colour too dark to see is lifted towards white until it
        // stands out (3:1, as WCAG asks of graphics); on white, a very pale
        // one is deepened just enough to show against the card.
        accentOrder(a).forEach((c, i) => {
            for (const role of ROLES) {
                l[v(role, 'palette', i + 1)] = ch(until(c, sc[700], '#ffffff', 1.35));
                d[v(role, 'palette', i + 1)] = ch(until(c, '#ffffff', surfaceHex, 3));
            }
        });
        // For hand-written CSS and apps (docs/ADMIN-UI.md, "Light, dark and
        // accent"): the accent, the text drawn on it, and the palette.
        l['--bh-accent'] = d['--bh-accent'] = ch(get(600));
        l['--bh-accent-hover'] = d['--bh-accent-hover'] = ch(get(700));
        l['--bh-on-accent'] = d['--bh-on-accent'] = '255 255 255';
        for (let i = 1; i <= 4; i++) {
            l[`--bh-palette-${i}`] = l[v('bg', 'palette', i)];
            d[`--bh-palette-${i}`] = d[v('bg', 'palette', i)];
        }
        l['--bh-accent-soft'] = ch(get(50));
        d['--bh-accent-soft'] = ch(darkHue(get, 'bg', 50));
        l['--bh-accent-text'] = ch(get(700));
        d['--bh-accent-text'] = ch(darkHue(get, 'tx', 700));
        const sel = name === DEFAULT_ACCENT ? `:root, [data-accent="${name}"]` : `[data-accent="${name}"]`;
        rules[sel] = Object.assign(rules[sel] || {}, l);
        rules[`[data-theme="dark"][data-accent="${name}"]` + (name === DEFAULT_ACCENT ? ', [data-theme="dark"]:not([data-accent])' : '')] = d;
    }
    return rules;
}

/*
 * The named colours only (--bh-surface, --bh-text, --bh-accent…, not every
 * shade), scoped to some elements instead of the page: for UI drawn on pages
 * that don't load the admin stylesheet, such as the admin bar on the public
 * site (1.3.0). The elements carry data-theme / data-appearance / data-accent
 * themselves; "system" is resolved in CSS, so nothing flashes and the theme's
 * own <html> is never touched (no color-scheme on it).
 */
function scoped(prefixes) {
    const named = (vars) => Object.fromEntries(Object.entries(vars).filter(([k]) => !/^--bh-(bg|tx|bd)-/.test(k)));
    const scope = (sel) => sel.split(/,\s*/).flatMap((one) => prefixes.map((p) => (one === ':root' ? p : p + one))).join(', ');
    const out = {}, system = {};
    for (const [sel, vars] of Object.entries(base())) {
        const v = named(vars);
        if (!Object.keys(v).length) continue;
        out[scope(sel)] = v;
        // "System": the dark rules again, for data-appearance="system", when the device is dark.
        if (sel.includes('[data-theme="dark"]')) {
            const sys = sel.split(/,\s*/).filter((x) => !x.includes(':not(')).map((x) => x.replace('[data-theme="dark"]', '[data-appearance="system"]')).join(', ');
            system[scope(sys)] = v;
        }
    }
    out['@media (prefers-color-scheme: dark)'] = system;
    return out;
}

const theme = {
    colors: scales('bg'),
    backgroundColor: scales('bg'),
    textColor: scales('tx'),
    // DEFAULT: the colour of a bare `border` (Tailwind's gray-200).
    borderColor: { ...scales('bd'), DEFAULT: use('bd', 'gray', 200) },
    placeholderColor: scales('tx'),
};

module.exports = {
    ACCENTS,
    DEFAULT_ACCENT,
    theme,
    /*
     * For apps that build their own Tailwind CSS (tailwind.preset.js): the
     * same colour names as core, reading core's variables, and dark: keyed on
     * <html data-theme="dark">. It defines no variables itself; the admin's
     * stylesheet does, so an app's classes follow the person's choice.
     */
    preset: { darkMode: ['selector', '[data-theme="dark"]'], theme: { extend: {}, ...theme } },
    plugin: plugin(({ addBase }) => { addBase(base()); addBase(namedClasses()); }),
    /*
     * The named palette alone, for admin/assets/css/colors.css: what themes
     * and pages without the admin stylesheet link. Light on :root; dark under
     * [data-theme="dark"] (a theme that has a dark mode sets it on <html>).
     */
    colorsPlugin: plugin(({ addBase }) => {
        addBase({ ':root, [data-theme="light"]': namedVars('light'), '[data-theme="dark"]': namedVars('dark') });
        addBase(namedClasses());
    }),
    NAMED,
    STATUS,
    namedShades,
    // The named colours on given elements only (see scoped() above).
    scopedPlugin: (prefixes) => plugin(({ addBase }) => addBase(scoped(prefixes))),
    // Each accent's worked-out scale and chart colours (for docs and tests).
    scales: Object.fromEntries(Object.entries(ACCENTS).map(([k, a]) => [k, { scale: accentScale(a), palette: accentOrder(a) }])),
    contrast,
};
