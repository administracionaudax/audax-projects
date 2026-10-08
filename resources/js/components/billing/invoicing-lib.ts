/**
 * Piezas puras del informe de facturación (D-400): escalas en euros, etiquetas de mes, servicios y
 * tramos de antigüedad. Los importes llegan como cadenas decimales («1234.50»); aquí solo se pasan
 * a número para dibujar y comparar, nunca para sumar dinero (eso lo hace el servidor).
 */
import { formatNumber, LOCALE } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { AgingBucket, BillingService } from '@/types';

export const BILLING_SERVICES: readonly BillingService[] = [
    'bolsas',
    'fees',
    'desarrollo',
    'diseno',
    'mantenimiento',
    'auditorias',
    'seo',
    'herramientas',
    'inversion',
    'otros',
];

export const AGING_BUCKETS: readonly AgingBucket[] = [
    'current',
    'd1_30',
    'd31_60',
    'd61_90',
    'd90_plus',
];

/** «1234.50» → 1234.5 (0 si no es un número). */
export function amount(value: string | null | undefined): number {
    const number = Number(value ?? 0);

    return Number.isFinite(number) ? number : 0;
}

/** Nombre de un servicio (o de «Sin desglose por línea»). */
export function serviceLabel(key: BillingService | 'sin_desglose'): string {
    return t(`billing.invoicing.services.${key}`);
}

export function agingLabel(key: AgingBucket): string {
    return t(`billing.invoicing.aging.${key}`);
}

/**
 * Marcas «limpias» de euros (1, 2, 2,5, 5 × 10ⁿ) que cubren de min a max, siempre con el 0 (las
 * rectificativas pueden dejar un mes o un servicio en negativo).
 */
export function amountTicks(
    min: number,
    max: number,
    targetCount = 5,
): number[] {
    const low = Math.min(0, Number.isFinite(min) ? min : 0);
    const high = Math.max(0, Number.isFinite(max) ? max : 0);

    if (high - low <= 0) {
        return [0];
    }

    const raw = (high - low) / Math.max(targetCount - 1, 1);
    const magnitude = 10 ** Math.floor(Math.log10(raw));
    const step =
        [1, 2, 2.5, 5, 10].map((m) => m * magnitude).find((s) => s >= raw) ??
        10 * magnitude;
    const start = Math.floor(low / step) * step;
    const ticks: number[] = [];

    for (let value = start; value < high + step; value += step) {
        ticks.push(Math.round(value * 100) / 100);

        if (value >= high) {
            break;
        }
    }

    return ticks;
}

/** Marca del eje: «12 k€», «1,2 M€» o «850 €». */
export function euroTick(value: number): string {
    const abs = Math.abs(value);

    if (abs >= 1_000_000) {
        return `${formatNumber(value / 1_000_000, 1)} M€`;
    }

    return abs >= 1000
        ? `${formatNumber(value / 1000, abs >= 10_000 ? 0 : 1)} k€`
        : `${formatNumber(value, 0)} €`;
}

/** Importe sin céntimos para las etiquetas de las barras: «12.345 €». */
export function compactCurrency(value: string | number): string {
    const number = typeof value === 'string' ? amount(value) : value;

    return new Intl.NumberFormat(LOCALE, {
        style: 'currency',
        currency: 'EUR',
        maximumFractionDigits: 0,
        useGrouping: 'always',
    }).format(number);
}

/** Peso «12.5» → «12,5 %». */
export function shareLabel(share: string | null): string {
    return share === null ? '—' : `${formatNumber(Number(share), 1)} %`;
}

const monthShort = new Intl.DateTimeFormat(LOCALE, {
    month: 'short',
    timeZone: 'UTC',
});
const monthShortYear = new Intl.DateTimeFormat(LOCALE, {
    month: 'short',
    year: '2-digit',
    timeZone: 'UTC',
});
const monthLong = new Intl.DateTimeFormat(LOCALE, {
    month: 'long',
    year: 'numeric',
    timeZone: 'UTC',
});

function utc(month: string): number {
    const [year, number] = month.split('-').map(Number);

    return Date.UTC(year, number - 1, 1);
}

/** Etiqueta del eje: «ene» (o «ene 26» si el periodo cruza años). */
export function monthTick(month: string, withYear: boolean): string {
    return (withYear ? monthShortYear : monthShort)
        .format(utc(month))
        .replace('.', '');
}

/** «enero de 2026». */
export function monthTitle(month: string): string {
    return monthLong.format(utc(month));
}

/** El mismo mes del año anterior: «2026-03» → «2025-03». */
export function previousYearMonth(month: string): string {
    const [year, number] = month.split('-');

    return `${Number(year) - 1}-${number}`;
}

/**
 * Variación entre dos importes en tanto por uno (0,125 = +12,5 %); null sin base o sin cambio
 * medible. Solo para pintar: el servidor da la cifra exacta (variation_pct).
 */
export function variation(
    current: string,
    previous: string | null,
): number | null {
    if (previous === null) {
        return null;
    }

    const base = amount(previous);

    return base === 0 ? null : (amount(current) - base) / Math.abs(base);
}

/** «+12,5 %», «−3,0 %» o «0,0 %». */
export function signedPercent(pct: string | number): string {
    const value = typeof pct === 'string' ? Number(pct) : pct;
    const sign = value > 0 ? '+' : value < 0 ? '−' : '';

    return `${sign}${formatNumber(Math.abs(value), 1)} %`;
}
