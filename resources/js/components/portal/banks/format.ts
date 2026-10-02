/**
 * Formato de las bolsas del portal. Las fechas y horas, con lib/format.ts (es-ES, Europe/Madrid);
 * los meses (AAAA-MM-01) son fechas locales sin hora, así que se formatean sin cambiar de zona.
 */
import { formatDate, formatMinutes, LOCALE } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { PortalBank } from './types';

const MONTH_LONG = new Intl.DateTimeFormat(LOCALE, {
    month: 'long',
    year: 'numeric',
    timeZone: 'UTC',
});

const MONTH_SHORT = new Intl.DateTimeFormat(LOCALE, {
    month: 'short',
    year: '2-digit',
    timeZone: 'UTC',
});

function monthDate(month: string): Date | null {
    const match = /^(\d{4})-(\d{2})/.exec(month);

    return match
        ? new Date(Date.UTC(Number(match[1]), Number(match[2]) - 1, 1))
        : null;
}

/** «2026-09-01» → «Septiembre de 2026». */
export function formatMonth(month: string): string {
    const date = monthDate(month);

    if (!date) {
        return '';
    }

    const text = MONTH_LONG.format(date);

    return text.charAt(0).toUpperCase() + text.slice(1);
}

/** «2026-09-01» → «sept 26» (eje de la gráfica). */
export function formatMonthShort(month: string): string {
    const date = monthDate(month);

    return date ? MONTH_SHORT.format(date) : '';
}

/** «2026-09-01» → «2026-09» (el filtro ?mes= de las entradas). */
export function monthParam(month: string): string {
    return month.slice(0, 7);
}

/** «ARR-WEB · Web corporativa». */
export function projectLabel(project: PortalBank['project']): string {
    return t('portal_banks.project', {
        code: project.code,
        name: project.name,
    });
}

/** Vigencia: «Del 01/01/2026 al 30/06/2026» o «Desde el 01/01/2026». */
export function bankDates(
    bank: Pick<PortalBank, 'start_date' | 'end_date'>,
): string {
    return bank.end_date
        ? t('portal_banks.dates.range', {
              from: formatDate(bank.start_date),
              to: formatDate(bank.end_date),
          })
        : t('portal_banks.dates.from', { from: formatDate(bank.start_date) });
}

/** Consumido (dentro + exceso) de lo contratado: «52:30 de 50:00». */
export function consumedOf(figures: PortalBank['figures']): string {
    return t('portal_banks.consumed_of', {
        consumed: formatMinutes(
            figures.within_minutes + figures.overage_minutes,
        ),
        total: formatMinutes(figures.total_minutes),
    });
}
