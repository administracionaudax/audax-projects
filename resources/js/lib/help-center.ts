import { t } from '@/lib/i18n';
import type {
    HelpFaqSection,
    HelpUpdateEntry,
    HelpUpdateStatus,
} from '@/types/weeklies';

/**
 * Piezas puras del centro de ayuda (10.7): el filtro de las novedades (F-152), el buscador de las
 * preguntas frecuentes (F-156), los meses y los tamaños. Las prueba tests/js/help-center.test.ts.
 */

export type HelpUpdateKindFilter = 'all' | 'release' | 'manual';

export type HelpUpdateStatusFilter = 'all' | HelpUpdateStatus;

/** Texto plano de un HTML de RichText (para buscar). */
export function plainText(html: string): string {
    return html
        .replace(/<[^>]*>/g, ' ')
        .replace(/&nbsp;/g, ' ')
        .replace(/&amp;/g, '&')
        .replace(/&lt;/g, '<')
        .replace(/&gt;/g, '>')
        .replace(/&quot;/g, '"')
        .replace(/&#39;/g, "'")
        .replace(/\s+/g, ' ')
        .trim();
}

/** Minúsculas y sin tildes, para comparar. */
export function normalizeSearch(value: string): string {
    return value
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .trim();
}

/**
 * Las novedades que pasan el buscador (título, resumen, versión, contenido y cambios) y los
 * filtros de tipo y estado, como el listado de WeeklySync.
 */
export function filterUpdates(
    entries: HelpUpdateEntry[],
    filters: {
        q: string;
        kind: HelpUpdateKindFilter;
        status: HelpUpdateStatusFilter;
    },
): HelpUpdateEntry[] {
    const needle = normalizeSearch(filters.q);

    return entries.filter((entry) => {
        if (filters.kind !== 'all' && entry.kind !== filters.kind) {
            return false;
        }

        if (filters.status !== 'all' && entry.status !== filters.status) {
            return false;
        }

        if (needle === '') {
            return true;
        }

        const bag = [
            entry.title,
            entry.subtitle,
            entry.version ?? '',
            entry.manual ? plainText(entry.manual.body) : '',
            ...(entry.release?.changes.map((change) => change.description) ??
                []),
        ].join(' ');

        return normalizeSearch(bag).includes(needle);
    });
}

/**
 * Las secciones con las preguntas que contienen el texto (en la pregunta o en la respuesta); sin
 * texto, todas. Las secciones sin ninguna, fuera.
 */
export function searchFaqs(
    sections: HelpFaqSection[],
    q: string,
): HelpFaqSection[] {
    const needle = normalizeSearch(q);

    if (needle === '') {
        return sections;
    }

    return sections
        .map((section) => ({
            ...section,
            faqs: section.faqs.filter((faq) =>
                normalizeSearch(
                    `${faq.question} ${plainText(faq.answer)}`,
                ).includes(needle),
            ),
        }))
        .filter((section) => section.faqs.length > 0);
}

const MONTHS = [
    'january',
    'february',
    'march',
    'april',
    'may',
    'june',
    'july',
    'august',
    'september',
    'october',
    'november',
    'december',
] as const;

/** «Octubre» para el mes 10. */
export function monthLabel(month: number): string {
    const key = MONTHS[month - 1];

    return key ? t(`help.months.${key}`) : String(month);
}

/** «12,5 MB». */
export function formatMegabytes(bytes: number): string {
    const megabytes = bytes / (1024 * 1024);

    return `${megabytes.toLocaleString('es-ES', {
        maximumFractionDigits: megabytes >= 100 ? 0 : 1,
    })} MB`;
}

/** Mueve un elemento de una lista (para reordenar con los botones). */
export function moveItem<T>(items: T[], from: number, to: number): T[] {
    if (from === to || from < 0 || to < 0 || to >= items.length) {
        return items;
    }

    const next = [...items];
    const [item] = next.splice(from, 1);
    next.splice(to, 0, item);

    return next;
}
