import { readdirSync, readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import { CHART_COLORS } from '@/components/charts/chart-config';
import {
    composite,
    contrastRatio,
    formatRatio,
    parseCssColor,
} from '@/components/styleguide/contrast';

/**
 * Paleta de datos: la usan las gráficas solo a través de var(--chart-n) y la guía de estilo
 * calcula el contraste en el navegador con estos mismos ayudantes.
 */

const css = readFileSync(
    new URL('../../resources/css/app.css', import.meta.url),
    'utf8',
);

function declaredChartTokens(selector: string): string[] {
    const start = css.indexOf(`${selector} {`);
    const body = css.slice(start, css.indexOf('\n}', start));

    return [...body.matchAll(/--(chart-\d+):/g)].map((m) => m[1]);
}

describe('tokens de datos en app.css', () => {
    it.each([':root', '.dark'])(
        '%s declara chart-1…6 en orden, tantos como colores tiene la paleta',
        (selector) => {
            expect(declaredChartTokens(selector)).toEqual(
                CHART_COLORS.map((c) => c.slice(6, -1)),
            );
        },
    );
});

describe('componentes de gráficas', () => {
    const dir = new URL(
        '../../resources/js/components/charts/',
        import.meta.url,
    );
    const files = readdirSync(dir).filter((f) => /\.tsx?$/.test(f));

    it('existen', () => {
        expect(files.length).toBeGreaterThan(0);
    });

    it.each(files)('%s no lleva colores fijos (solo tokens)', (file) => {
        const source = readFileSync(new URL(file, dir), 'utf8');

        expect(source).not.toMatch(
            /#[0-9a-f]{3}(?:[0-9a-f]{3})?(?:[0-9a-f]{2})?\b/i,
        );
        expect(source).not.toMatch(/\b(?:rgb|rgba|hsl|hsla|oklch)\(/i);
    });

    it.each(files)('%s no cicla la paleta con módulo', (file) => {
        const source = readFileSync(new URL(file, dir), 'utf8');

        expect(source).not.toMatch(/%\s*CHART_COLORS\.length/);
    });

    it('no hay tartas ni donuts', () => {
        const all = files
            .map((f) => readFileSync(new URL(f, dir), 'utf8'))
            .join('\n');

        expect(all).not.toMatch(/\b(PieChart|Pie|RadialBarChart)\b/);
    });
});

describe('ayudantes de contraste', () => {
    it.each([
        ['rgb(1, 113, 255)', { r: 1, g: 113, b: 255, a: 1 }],
        ['rgba(255, 255, 255, 0.12)', { r: 255, g: 255, b: 255, a: 0.12 }],
        ['rgb(255 255 255 / 0.06)', { r: 255, g: 255, b: 255, a: 0.06 }],
        ['rgb(255 255 255 / 60%)', { r: 255, g: 255, b: 255, a: 0.6 }],
        ['#001B39', { r: 0, g: 27, b: 57, a: 1 }],
        ['#ffffff99', { r: 255, g: 255, b: 255, a: 0.6 }],
        ['color(srgb 1 0 0.5 / 0.5)', { r: 255, g: 0, b: 127.5, a: 0.5 }],
    ])('interpreta %s', (value, expected) => {
        const parsed = parseCssColor(value);

        expect(parsed).not.toBeNull();
        expect(parsed?.r).toBeCloseTo(expected.r, 1);
        expect(parsed?.g).toBeCloseTo(expected.g, 1);
        expect(parsed?.b).toBeCloseTo(expected.b, 1);
        expect(parsed?.a).toBeCloseTo(expected.a, 2);
    });

    it('devuelve null con lo que no entiende', () => {
        expect(parseCssColor('var(--chart-1)')).toBeNull();
        expect(parseCssColor('transparent')).toBeNull();
    });

    it('calcula el contraste WCAG', () => {
        const white = parseCssColor('#ffffff')!;
        const black = parseCssColor('#000000')!;

        expect(contrastRatio(white, black)).toBeCloseTo(21, 5);
        expect(contrastRatio(white, white)).toBeCloseTo(1, 5);
        // Azul de marca sobre blanco: 4,37:1 (vale para texto grande, no para cuerpo).
        expect(contrastRatio(parseCssColor('#0171ff')!, white)).toBeCloseTo(
            4.37,
            2,
        );
        // Botón principal: blanco sobre #0068EB.
        expect(
            contrastRatio(white, parseCssColor('#0068eb')!),
        ).toBeGreaterThanOrEqual(4.5);
    });

    it('compone los colores con alfa sobre su superficie', () => {
        const border = composite(
            parseCssColor('rgb(255 255 255 / 0.12)')!,
            parseCssColor('#000f20')!,
        );

        expect(border.a).toBe(1);
        expect(border.r).toBeCloseTo(30.6, 1);
        expect(border.b).toBeCloseTo(58.76, 1);
    });

    it('formatea la razón en es-ES', () => {
        expect(formatRatio(4.3712)).toBe('4,37:1');
        expect(formatRatio(21)).toBe('21,00:1');
    });
});
