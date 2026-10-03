import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';

/**
 * Contraste sobre el degradado de marca (UI-02, SPEC §3.1: AA también sobre el degradado).
 *
 * Reconstruye `bg-brand-gradient` a partir de resources/css/app.css (capas radial-gradient de
 * --brand-gradient, el color de fondo y el velo --brand-veil encima) y compone el color en una
 * rejilla de 101 × 101 puntos que cubre TODA la superficie. Los radios y centros van en % de la
 * caja, así que el resultado en coordenadas normalizadas es el mismo en cualquier tamaño
 * (login a 375 o 1440 px, cabecera del portal, estados vacíos grandes).
 *
 * Las paradas se interpolan con alfa premultiplicado, como hace el navegador en sRGB.
 */

type Rgb = [number, number, number];
type Rgba = [number, number, number, number];

const css = readFileSync(
    new URL('../../resources/css/app.css', import.meta.url),
    'utf8',
);

function variable(selector: string, name: string): string {
    const start = css.indexOf(`${selector} {`);
    expect(start, `bloque ${selector}`).toBeGreaterThan(-1);
    const body = css.slice(start, css.indexOf('\n}', start));
    const match = new RegExp(`--${name}:\\s*([^;]+);`).exec(body);
    expect(match, `--${name} en ${selector}`).not.toBeNull();

    return (match?.[1] ?? '').replace(/\s+/g, ' ').trim();
}

function parseColor(value: string): Rgba {
    const hex = /^#([0-9a-f]{6})$/i.exec(value.trim());

    if (hex) {
        const [r, g, b] = [0, 2, 4].map((i) =>
            parseInt(hex[1].slice(i, i + 2), 16),
        );

        return [r, g, b, 1];
    }

    const rgb =
        /^rgb\(\s*(\d+)\s+(\d+)\s+(\d+)(?:\s*\/\s*([\d.]+))?\s*\)$/.exec(
            value.trim(),
        );

    if (rgb) {
        return [
            Number(rgb[1]),
            Number(rgb[2]),
            Number(rgb[3]),
            rgb[4] === undefined ? 1 : Number(rgb[4]),
        ];
    }

    throw new Error(`Color no soportado: ${value}`);
}

type Stop = { color: Rgba; at: number };

type Layer = (x: number, y: number) => Rgba;

/** Color de una lista de paradas en la posición t (0…1), con alfa premultiplicado. */
function sample(stops: Stop[], t: number): Rgba {
    if (t <= stops[0].at) {
        return stops[0].color;
    }

    for (let i = 1; i < stops.length; i++) {
        const [a, b] = [stops[i - 1], stops[i]];

        if (t <= b.at) {
            const k = (t - a.at) / (b.at - a.at);
            const alpha = a.color[3] + (b.color[3] - a.color[3]) * k;

            if (alpha === 0) {
                return [0, 0, 0, 0];
            }

            const channel = (c: number) =>
                (a.color[c] * a.color[3] +
                    (b.color[c] * b.color[3] - a.color[c] * a.color[3]) * k) /
                alpha;

            return [channel(0), channel(1), channel(2), alpha];
        }
    }

    return stops[stops.length - 1].color;
}

const STOP = /(rgb\([^)]*\)|#[0-9a-f]{6})\s+([\d.]+)%/gi;

function parseStops(body: string): Stop[] {
    return [...body.matchAll(STOP)].map((m) => ({
        color: parseColor(m[1]),
        at: Number(m[2]) / 100,
    }));
}

/** radial-gradient(RX% RY% at CX% CY%, …): elipse con radios y centro en % de la caja. */
function radialLayers(value: string): { layers: Layer[]; base: Rgb } {
    const layers: Layer[] = [];

    for (const m of value.matchAll(
        /radial-gradient\(\s*([\d.]+)%\s+([\d.]+)%\s+at\s+([\d.]+)%\s+([\d.]+)%\s*,(.*?)\)\s*(?:,|$)/g,
    )) {
        const [rx, ry, cx, cy] = [m[1], m[2], m[3], m[4]].map(
            (n) => Number(n) / 100,
        );
        const stops = parseStops(`${m[5]})`);
        expect(stops.length, 'paradas de la capa radial').toBeGreaterThan(1);

        layers.push((x, y) =>
            sample(stops, Math.hypot((x - cx) / rx, (y - cy) / ry)),
        );
    }

    const base = /(#[0-9a-f]{6})\s*$/i.exec(value);
    expect(base, 'color de fondo del degradado').not.toBeNull();
    const [r, g, b] = parseColor(base?.[1] ?? '#000000');

    return { layers, base: [r, g, b] };
}

/** linear-gradient(to top, …): la posición 0 % es el borde inferior. */
function veilLayer(value: string): Layer {
    const m = /^linear-gradient\(\s*to top\s*,(.*)\)$/.exec(value);
    expect(m, '--brand-veil debe ser un linear-gradient(to top, …)').not.toBe(
        null,
    );
    const stops = parseStops(m?.[1] ?? '');

    return (_x, y) => sample(stops, 1 - y);
}

function over(top: Rgba, bottom: Rgb): Rgb {
    const a = top[3];

    return [0, 1, 2].map((i) => top[i] * a + bottom[i] * (1 - a)) as Rgb;
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

const gradient = radialLayers(variable(':root', 'brand-gradient'));
const veil = veilLayer(variable(':root', 'brand-veil'));

/** Color de bg-brand-gradient en (x, y) normalizados: fondo, capas de abajo arriba y velo. */
function surfaceAt(x: number, y: number, withVeil = true): Rgb {
    let color = gradient.base;

    for (const layer of [...gradient.layers].reverse()) {
        color = over(layer(x, y), color);
    }

    return withVeil ? over(veil(x, y), color) : color;
}

const GRID = 100;
const points: { x: number; y: number; surface: Rgb }[] = [];

for (let i = 0; i <= GRID; i++) {
    for (let j = 0; j <= GRID; j++) {
        const [x, y] = [i / GRID, j / GRID];
        points.push({ x, y, surface: surfaceAt(x, y) });
    }
}

/** Contraste mínimo de un color (con su alfa) sobre toda la superficie, y dónde se da. */
function worst(color: Rgba): { ratio: number; x: number; y: number } {
    let result = { ratio: Infinity, x: 0, y: 0 };

    for (const { x, y, surface } of points) {
        const ratio = contrast(over(color, surface), surface);

        if (ratio < result.ratio) {
            result = { ratio, x, y };
        }
    }

    return result;
}

const at = (w: { x: number; y: number }) =>
    `en (${Math.round(w.x * 100)} %, ${Math.round(w.y * 100)} %)`;

describe('degradado de marca con velo', () => {
    it('lee las cinco capas radiales del degradado', () => {
        expect(gradient.layers).toHaveLength(5);
    });

    it('sin el velo, la zona clara inferior no llega a AA (el velo es necesario)', () => {
        const bare = points.map((p) => surfaceAt(p.x, p.y, false));
        const worstBare = Math.min(
            ...bare.map((surface) => contrast([255, 255, 255], surface)),
        );

        // Ni siquiera el blanco puro llega a 4,5:1 sobre el turquesa de abajo a la derecha.
        expect(worstBare).toBeLessThan(4.5);
    });

    it.each([
        ['texto principal (foreground del tema oscuro)', 'foreground', '.dark'],
        [
            'texto secundario (--on-gradient-muted)',
            'on-gradient-muted',
            ':root',
        ],
        [
            'palabra clave (--on-gradient-keyword)',
            'on-gradient-keyword',
            ':root',
        ],
    ])('%s ≥ 4,5:1 en toda la superficie', (_label, name, selector) => {
        const w = worst(parseColor(variable(selector, name)));
        expect(w.ratio, at(w)).toBeGreaterThanOrEqual(4.5);
    });

    it('texto blanco ≥ 4,5:1 en toda la superficie', () => {
        const w = worst([255, 255, 255, 1]);
        expect(w.ratio, at(w)).toBeGreaterThanOrEqual(4.5);
    });

    it('el texto secundario lleva al menos un 85 % de blanco', () => {
        const [r, g, b, a] = parseColor(variable(':root', 'on-gradient-muted'));
        expect([r, g, b]).toEqual([255, 255, 255]);
        expect(a).toBeGreaterThanOrEqual(0.85);
    });

    it('anillo de foco del tema oscuro ≥ 3:1 en toda la superficie', () => {
        const w = worst(parseColor(variable('.dark', 'ring')));
        expect(w.ratio, at(w)).toBeGreaterThanOrEqual(3);
    });

    it('bg-brand-gradient pinta el velo encima de la imagen de marca y del degradado de respaldo', () => {
        expect(css).toMatch(
            /@utility bg-brand-gradient\s*\{\s*background:\s*var\(--brand-veil\),\s*url\('\/brand\/fondo-marca\.jpg'\)[^,;]*,\s*var\(--brand-gradient\);/,
        );
    });
});

/**
 * Imagen de marca (D-137): la portada original de la hoja de Audax, con background-size: cover.
 * tests/fixtures/brand-cover-grid.json la guarda reducida a 96 × 54 píxeles (media de cada zona)
 * junto con el SHA-256 del JPEG, así que si cambia la imagen hay que regenerar la rejilla. Se
 * comprueban los recortes que hace `cover` en una caja apaisada (cabecera del portal), una de
 * 16:9 y una vertical (login en el móvil), con el velo aplicado a la altura de cada caja.
 */
type Cover = {
    sha256: string;
    width: number;
    height: number;
    pixels: Rgb[];
};

const cover = JSON.parse(
    readFileSync(
        new URL('../fixtures/brand-cover-grid.json', import.meta.url),
        'utf8',
    ),
) as Cover;

/** Recorte central de `cover` para una caja de proporción `ratio` (ancho / alto). */
function coverCrop(ratio: number): Rgb[][] {
    const imageRatio = cover.width / cover.height;
    const [w, h] =
        ratio > imageRatio
            ? [cover.width, Math.max(2, Math.round(cover.width / ratio))]
            : [Math.max(2, Math.round(cover.height * ratio)), cover.height];
    const [x0, y0] = [
        Math.floor((cover.width - w) / 2),
        Math.floor((cover.height - h) / 2),
    ];
    const rows: Rgb[][] = [];

    for (let y = 0; y < h; y++) {
        const row: Rgb[] = [];

        for (let x = 0; x < w; x++) {
            row.push(cover.pixels[(y0 + y) * cover.width + x0 + x]);
        }

        rows.push(row);
    }

    return rows;
}

function worstOnCover(color: Rgba, ratio: number): number {
    const rows = coverCrop(ratio);
    let result = Infinity;

    rows.forEach((row, j) => {
        const y = (j + 0.5) / rows.length;
        row.forEach((pixel) => {
            const surface = over(veil(0, y), pixel);
            result = Math.min(result, contrast(over(color, surface), surface));
        });
    });

    return result;
}

describe('imagen de marca con velo', () => {
    it('la rejilla corresponde a la imagen publicada', async () => {
        const { createHash } = await import('node:crypto');
        const jpeg = readFileSync(
            new URL('../../public/brand/fondo-marca.jpg', import.meta.url),
        );
        expect(createHash('sha256').update(jpeg).digest('hex')).toBe(
            cover.sha256,
        );
        expect(cover.pixels).toHaveLength(cover.width * cover.height);
    });

    const boxes: [string, number][] = [
        ['apaisada (cabecera del portal, 6:1)', 6],
        ['16:9', 16 / 9],
        ['vertical (login en el móvil, 375 × 812)', 375 / 812],
    ];

    it.each(boxes)('texto blanco ≥ 4,5:1 en caja %s', (_label, ratio) => {
        expect(worstOnCover([255, 255, 255, 1], ratio)).toBeGreaterThanOrEqual(
            4.5,
        );
    });

    it.each(boxes)(
        'texto secundario y palabra clave ≥ 4,5:1 en caja %s',
        (_label, ratio) => {
            for (const name of ['on-gradient-muted', 'on-gradient-keyword']) {
                expect(
                    worstOnCover(parseColor(variable(':root', name)), ratio),
                    name,
                ).toBeGreaterThanOrEqual(4.5);
            }
        },
    );

    it.each(boxes)('anillo de foco ≥ 3:1 en caja %s', (_label, ratio) => {
        expect(
            worstOnCover(parseColor(variable('.dark', 'ring')), ratio),
        ).toBeGreaterThanOrEqual(3);
    });
});
