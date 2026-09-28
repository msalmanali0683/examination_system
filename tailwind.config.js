import fs from 'node:fs';
import defaultTheme from 'tailwindcss/defaultTheme';
import colors from 'tailwindcss/colors';
import plugin from 'tailwindcss/plugin';
import forms from '@tailwindcss/forms';

/**
 * Colour themes. resources/themes.json is the one list of themes (PHP reads
 * it too, for the picker and validation). Three colour scales are driven by
 * CSS variables so the whole site follows the chosen theme without any view
 * naming a colour:
 *
 *   primary — the accent (buttons, links, active states, focus rings)
 *   gray    — surfaces, borders and text, tinted slightly toward the accent
 *   slate   — the dark chrome (sidebar, login backdrop), tinted more strongly
 *
 * Status colours (green / red / yellow / amber) are deliberately NOT themed.
 * Each theme's variables are emitted under [data-theme="<key>"]; the default
 * theme also lives on :root.
 */
const config = JSON.parse(fs.readFileSync(new URL('./resources/themes.json', import.meta.url), 'utf8'));
const SHADES = [50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950];

const channels = (hex) => [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16));
const mix = (base, tint, amount) => base.map((value, i) => Math.round(value * (1 - amount) + tint[i] * amount));

// Neutrals pick up the accent's tint mostly in their very light and very dark
// shades (backgrounds, borders, the dark sidebar); the mid shades that carry
// body and muted text stay close to plain grey so contrast is never traded away.
const tintAt = (shade, amount) => (shade <= 200 || shade >= 800 ? amount : amount * 0.4);

const scaleVariables = (name, palette, tintPalette = palette, amount = 0) =>
    Object.fromEntries(
        SHADES.map((shade) => [
            `--${name}-${shade}`,
            mix(channels(palette[shade]), channels(tintPalette[shade]), tintAt(shade, amount)).join(' '),
        ])
    );

// Some hues (emerald, teal, orange) are too light at shade 600 for white button
// text to read comfortably, so those themes use the next `darken` steps darker
// for the strong half of their accent scale (500 and up).
const accentPalette = (theme) => {
    const palette = colors[theme.hue];
    const steps = theme.darken ?? 0;

    return Object.fromEntries(
        SHADES.map((shade, index) => [shade, shade >= 500 ? palette[SHADES[Math.min(index + steps, SHADES.length - 1)]] : palette[shade]])
    );
};

const themeVariables = (theme) => ({
    ...scaleVariables('primary', accentPalette(theme)),
    ...scaleVariables('gray', colors.gray, colors[theme.hue], theme.grayTint),
    ...scaleVariables('slate', colors.slate, colors[theme.hue], theme.chromeTint),
});

const variableScale = (name) =>
    Object.fromEntries(
        SHADES.map((shade) => [
            shade,
            ({ opacityValue }) =>
                opacityValue === undefined ? `rgb(var(--${name}-${shade}))` : `rgb(var(--${name}-${shade}) / ${opacityValue})`,
        ])
    );

/** @type {import('tailwindcss').Config} */
export default {
    darkMode: 'class',

    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                primary: variableScale('primary'),
                gray: variableScale('gray'),
                slate: variableScale('slate'),
            },
        },
    },

    plugins: [
        forms,
        plugin(({ addBase }) => {
            const rules = {};

            for (const [key, theme] of Object.entries(config.themes)) {
                const selector = key === config.default ? `:root, [data-theme="${key}"]` : `[data-theme="${key}"]`;
                rules[selector] = themeVariables(theme);
            }

            addBase(rules);
        }),
    ],
};
