import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';

/**
 * Contraste WCAG 2.x de los tokens del tema (SPEC §3.1: AA en ambos temas).
 * Lee resources/css/app.css, resuelve colores hex y rgb(… / alfa) componiéndolos
 * sobre su superficie, y comprueba cada par texto/fondo y cada elemento no textual.
 */

type Rgb = [number, number, number];

const css = readFileSync(
    new URL('../../resources/css/app.css', import.meta.url),
    'utf8',
);

function block(selector: string): Record<string, string> {
    const start = css.indexOf(`${selector} {`);
    expect(start, `bloque ${selector}`).toBeGreaterThan(-1);
    const body = css.slice(start, css.indexOf('\n}', start));
    const vars: Record<string, string> = {};

    for (const m of body.matchAll(/--([a-z0-9-]+):\s*([^;]+);/g)) {
        vars[m[1]] = m[2].trim();
    }

    return vars;
}

function parse(value: string, over: Rgb): Rgb {
    const hex = /^#([0-9a-f]{6})$/i.exec(value);

    if (hex) {
        return [0, 2, 4].map((i) =>
            parseInt(hex[1].slice(i, i + 2), 16),
        ) as Rgb;
    }

    const rgb = /^rgb\((\d+)\s+(\d+)\s+(\d+)(?:\s*\/\s*([\d.]+))?\)$/.exec(
        value,
    );

    if (rgb) {
        const a = rgb[4] === undefined ? 1 : Number(rgb[4]);

        return [1, 2, 3].map(
            (i, k) => Number(rgb[i]) * a + over[k] * (1 - a),
        ) as Rgb;
    }

    throw new Error(`Color no soportado: ${value}`);
}

const luminance = ([r, g, b]: Rgb) => {
    const lin = (c: number) => {
        const s = c / 255;

        return s <= 0.04045 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4;
    };

    return 0.2126 * lin(r) + 0.7152 * lin(g) + 0.0722 * lin(b);
};

const contrast = (a: Rgb, b: Rgb) => {
    const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x);

    return (hi + 0.05) / (lo + 0.05);
};

const TEXT = 4.5;
const NON_TEXT = 3;

for (const [theme, selector] of [
    ['claro', ':root'],
    ['oscuro', '.dark'],
] as const) {
    describe(`tema ${theme}`, () => {
        const v = block(selector);
        const base = parse(v.background, [255, 255, 255]);
        const card = parse(v.card, base);
        const on = (surface: Rgb) => (name: string) => parse(v[name], surface);

        const surfaces: Record<string, Rgb> = {
            background: base,
            card,
            muted: parse(v.muted, card),
            accent: parse(v.accent, card),
            'success-soft': parse(v['success-soft'], card),
            'warning-soft': parse(v['warning-soft'], card),
            'danger-soft': parse(v['danger-soft'], card),
            'info-soft': parse(v['info-soft'], card),
            'neutral-soft': parse(v['neutral-soft'], card),
        };

        const textPairs: [string, string][] = [];

        for (const surface of Object.keys(surfaces)) {
            textPairs.push(
                ['foreground', surface],
                ['muted-foreground', surface],
            );
        }

        textPairs.push(
            ['primary-text', 'background'],
            ['primary-text', 'card'],
            ['primary-text', 'accent'],
            ['destructive-foreground', 'background'],
            ['destructive-foreground', 'card'],
            ['success', 'card'],
            ['warning', 'card'],
            ['danger', 'card'],
            ['info', 'card'],
            ['success', 'success-soft'],
            ['warning', 'warning-soft'],
            ['danger', 'danger-soft'],
            ['info', 'info-soft'],
        );

        it.each(textPairs)('texto %s sobre %s ≥ 4,5:1', (fg, surface) => {
            const s = surfaces[surface];
            expect(contrast(on(s)(fg), s)).toBeGreaterThanOrEqual(TEXT);
        });

        it('texto de botones sobre su fondo ≥ 4,5:1', () => {
            expect(
                contrast(on(card)('primary-foreground'), on(card)('primary')),
            ).toBeGreaterThanOrEqual(TEXT);
            expect(
                contrast([255, 255, 255], on(card)('destructive')),
            ).toBeGreaterThanOrEqual(TEXT);
            // Botón blanco sobre fondo oscuro: el color está en :root y no cambia en .dark.
            const onDark = parse(
                block(':root')['on-dark-foreground'],
                [255, 255, 255],
            );
            expect(contrast(onDark, [255, 255, 255])).toBeGreaterThanOrEqual(
                TEXT,
            );
        });

        it.each(['input', 'ring'])('elemento no textual %s ≥ 3:1', (name) => {
            expect(contrast(on(card)(name), card)).toBeGreaterThanOrEqual(
                NON_TEXT,
            );
        });

        // Anillo de foco (lib/focus-ring.ts): opaco y separado del control por un hueco del
        // color de fondo, así que linda con el fondo, la tarjeta y las superficies suaves.
        it.each(Object.keys(surfaces))(
            'anillo de foco opaco ≥ 3:1 sobre %s',
            (surface) => {
                expect(v.ring).toMatch(/^#[0-9a-f]{6}$/i);
                expect(
                    contrast(on(surfaces[surface])('ring'), surfaces[surface]),
                ).toBeGreaterThanOrEqual(NON_TEXT);
            },
        );

        it.each([1, 2, 3, 4, 5, 6])(
            'serie de datos chart-%i ≥ 3:1 sobre la tarjeta',
            (n) => {
                expect(
                    contrast(on(card)(`chart-${n}`), card),
                ).toBeGreaterThanOrEqual(NON_TEXT);
            },
        );
    });
}
