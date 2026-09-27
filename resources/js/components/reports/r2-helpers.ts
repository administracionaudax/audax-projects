/**
 * Utilidades de los informes de R2 (cliente, proyecto y facturación).
 * - Los importes llegan como string decimal («1221.67»): se suman en céntimos enteros, nunca en
 *   coma flotante, para que los totales cuadren con los de la exportación.
 * - Las fechas de los cubos (AAAA-MM-DD) son días locales: no se convierten de zona.
 */
import { formatMinutes, LOCALE } from '@/lib/format';

/** «1221.67» → 122167 céntimos (null o vacío → 0). */
export function toCents(amount: string | null | undefined): number {
    if (amount === null || amount === undefined || amount === '') {
        return 0;
    }

    const value = Number(amount);

    return Number.isFinite(value) ? Math.round(value * 100) : 0;
}

/** Céntimos → «1221.67» (lo que espera formatCurrency). */
export function fromCents(cents: number): string {
    const sign = cents < 0 ? '-' : '';
    const abs = Math.abs(Math.round(cents));

    return `${sign}${Math.floor(abs / 100)}.${String(abs % 100).padStart(2, '0')}`;
}

/** Suma exacta de importes. */
export function sumMoney(amounts: ReadonlyArray<string | null>): string {
    return fromCents(amounts.reduce((sum, amount) => sum + toCents(amount), 0));
}

/** Ingreso − coste. */
export function subtractMoney(a: string | null, b: string | null): string {
    return fromCents(toCents(a) - toCents(b));
}

/** Margen sobre el ingreso (null si no hay ingreso). */
export function marginRatio(
    income: string | null,
    cost: string | null,
): number | null {
    const cents = toCents(income);

    return cents === 0 ? null : (cents - toCents(cost)) / cents;
}

/** Exceso con signo: 0 → «0:00»; 100 → «+1:40». */
export function formatOverage(minutes: number): string {
    return minutes > 0 ? `+${formatMinutes(minutes)}` : formatMinutes(0);
}

const monthFormatter = new Intl.DateTimeFormat(LOCALE, {
    month: 'short',
    year: 'numeric',
    timeZone: 'UTC',
});

const longMonthFormatter = new Intl.DateTimeFormat(LOCALE, {
    month: 'long',
    year: 'numeric',
    timeZone: 'UTC',
});

function utcDate(day: string): Date | null {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(day);

    return match
        ? new Date(Date.UTC(Number(match[1]), Number(match[2]) - 1, 1))
        : null;
}

/**
 * Etiqueta corta de un cubo de tiempo para los ejes: el mes («sept 2026») o el lunes de la
 * semana («21/09»).
 */
export function bucketLabel(bucket: string, kind: 'mes' | 'semana'): string {
    if (kind === 'semana') {
        const match = /^\d{4}-(\d{2})-(\d{2})$/.exec(bucket);

        return match ? `${match[2]}/${match[1]}` : bucket;
    }

    const date = utcDate(bucket);

    return date ? monthFormatter.format(date).replace('.', '') : bucket;
}

/** «2026-09-01» → «septiembre de 2026» (tablas y lectores de pantalla). */
export function monthName(bucket: string): string {
    const date = utcDate(bucket);

    return date ? longMonthFormatter.format(date) : bucket;
}

/** Desviación de horas reales frente a estimadas: minutos y ratio sobre lo estimado. */
export function deviation(
    estimated: number | null,
    actual: number,
): { minutes: number; ratio: number | null } | null {
    if (estimated === null) {
        return null;
    }

    return {
        minutes: actual - estimated,
        ratio: estimated > 0 ? (actual - estimated) / estimated : null,
    };
}
