/**
 * Contraste WCAG 2.x calculado en el navegador a partir de getComputedStyle
 * (los valores llegan como rgb(), rgba() o color(srgb …) según el navegador).
 */

export type Rgba = { r: number; g: number; b: number; a: number };

export const AA_TEXT = 4.5;
export const AA_NON_TEXT = 3;

/** Convierte un color CSS ya resuelto a RGBA (0–255, alfa 0–1). Devuelve null si no lo entiende. */
export function parseCssColor(value: string): Rgba | null {
    const input = value.trim().toLowerCase();

    const hex = /^#([0-9a-f]{6})([0-9a-f]{2})?$/.exec(input);

    if (hex) {
        const [r, g, b] = [0, 2, 4].map((i) =>
            parseInt(hex[1].slice(i, i + 2), 16),
        );

        return { r, g, b, a: hex[2] ? parseInt(hex[2], 16) / 255 : 1 };
    }

    const rgb =
        /^rgba?\(\s*([\d.]+)[\s,]+([\d.]+)[\s,]+([\d.]+)(?:\s*[,/]\s*([\d.]+%?))?\s*\)$/.exec(
            input,
        );

    if (rgb) {
        return {
            r: Number(rgb[1]),
            g: Number(rgb[2]),
            b: Number(rgb[3]),
            a: alpha(rgb[4]),
        };
    }

    const srgb =
        /^color\(srgb\s+([\d.e-]+)\s+([\d.e-]+)\s+([\d.e-]+)(?:\s*\/\s*([\d.]+%?))?\s*\)$/.exec(
            input,
        );

    if (srgb) {
        return {
            r: clamp255(Number(srgb[1]) * 255),
            g: clamp255(Number(srgb[2]) * 255),
            b: clamp255(Number(srgb[3]) * 255),
            a: alpha(srgb[4]),
        };
    }

    return null;
}

function alpha(value: string | undefined): number {
    if (value === undefined) {
        return 1;
    }

    return value.endsWith('%')
        ? Number(value.slice(0, -1)) / 100
        : Number(value);
}

function clamp255(value: number): number {
    return Math.min(Math.max(value, 0), 255);
}

/** Compone un color con alfa sobre un fondo opaco. */
export function composite(color: Rgba, over: Rgba): Rgba {
    const a = color.a;

    return {
        r: color.r * a + over.r * (1 - a),
        g: color.g * a + over.g * (1 - a),
        b: color.b * a + over.b * (1 - a),
        a: 1,
    };
}

export function relativeLuminance({ r, g, b }: Rgba): number {
    const lin = (c: number) => {
        const s = c / 255;

        return s <= 0.04045 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4;
    };

    return 0.2126 * lin(r) + 0.7152 * lin(g) + 0.0722 * lin(b);
}

export function contrastRatio(a: Rgba, b: Rgba): number {
    const [hi, lo] = [relativeLuminance(a), relativeLuminance(b)].sort(
        (x, y) => y - x,
    );

    return (hi + 0.05) / (lo + 0.05);
}

/** 4.3712 → "4,37:1". */
export function formatRatio(ratio: number): string {
    return `${ratio.toLocaleString('es-ES', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    })}:1`;
}

/**
 * Lee las declaraciones de variables CSS de los bloques ":root" y ".dark" de las hojas
 * de estilo cargadas (mismo origen). Permite pintar los dos temas a la vez, sea cual sea el activo.
 */
export function readThemeDeclarations(doc: Document = document): {
    light: Record<string, string>;
    dark: Record<string, string>;
} {
    const light: Record<string, string> = {};
    const dark: Record<string, string> = {};

    const visit = (rules: CSSRuleList) => {
        for (const rule of Array.from(rules)) {
            if ('selectorText' in rule && 'style' in rule) {
                const selectors = String(rule.selectorText)
                    .split(',')
                    .map((s) => s.trim());
                const target = selectors.includes(':root')
                    ? light
                    : selectors.includes('.dark')
                      ? dark
                      : null;

                if (target) {
                    const style = rule.style as CSSStyleDeclaration;

                    for (let i = 0; i < style.length; i++) {
                        const name = style[i];

                        if (name.startsWith('--')) {
                            target[name] = style.getPropertyValue(name).trim();
                        }
                    }
                }
            }

            if ('cssRules' in rule && rule.cssRules) {
                visit(rule.cssRules as CSSRuleList);
            }
        }
    };

    for (const sheet of Array.from(doc.styleSheets)) {
        try {
            visit(sheet.cssRules);
        } catch {
            // Hoja de otro origen: no se puede leer y no contiene el tema.
        }
    }

    return { light, dark: { ...light, ...dark } };
}
