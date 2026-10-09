import { t } from '@/lib/i18n';
import { tCount } from '@/lib/people';
import type { CollectionStatus, HoldedSyncSummary } from '@/types';

/**
 * Tiempos de Facturación que se calculan en el navegador (D-407 y D-409): cuánto hace de la última
 * lectura de Holded y el estado de cobro relativo de una factura («Vencida hace 8 días»).
 */

/** Más de 26 h sin leer Holded: la lectura de la noche no ha llegado (ámbar). */
export const SYNC_STALE_HOURS = 26;

const DATE_ONLY = /^(\d{4})-(\d{2})-(\d{2})$/;

/** Días naturales de `from` a `to` (AAAA-MM-DD, sin zona): positivo si `to` es posterior. */
export function daysBetween(from: string, to: string): number {
    const a = DATE_ONLY.exec(from);
    const b = DATE_ONLY.exec(to);

    if (!a || !b) {
        return 0;
    }

    const utc = (m: RegExpExecArray) =>
        Date.UTC(Number(m[1]), Number(m[2]) - 1, Number(m[3]));

    return Math.round((utc(b) - utc(a)) / 86_400_000);
}

/** «hace 5 min», «hace 3 h», «hace 2 días» (o «ahora mismo»). */
export function timeAgo(iso: string, now: Date = new Date()): string {
    const minutes = Math.max(
        0,
        Math.floor((now.getTime() - new Date(iso).getTime()) / 60_000),
    );

    if (minutes < 1) {
        return t('billing.sync.just_now');
    }

    if (minutes < 60) {
        return t('billing.sync.ago_minutes', { count: minutes });
    }

    const hours = Math.floor(minutes / 60);

    if (hours < 48) {
        return t('billing.sync.ago_hours', { count: hours });
    }

    return tCount('billing.sync.ago_days', Math.floor(hours / 24));
}

export type SyncTone = 'ok' | 'stale' | 'failed' | 'running' | 'never';

/** Estado de la última lectura: bien, vieja (más de 26 h), fallida, en curso o nunca. */
export function syncTone(
    sync: HoldedSyncSummary | null,
    now: Date = new Date(),
): SyncTone {
    if (sync === null) {
        return 'never';
    }

    if (sync.status === 'failed') {
        return 'failed';
    }

    if (sync.status === 'running') {
        return 'running';
    }

    const at = new Date(sync.finished_at ?? sync.started_at).getTime();

    return now.getTime() - at > SYNC_STALE_HOURS * 3_600_000 ? 'stale' : 'ok';
}

export type RelativeCollection = {
    /** Estado efectivo: una pendiente con el vencimiento pasado ya es vencida aunque Holded no se haya vuelto a leer. */
    status: CollectionStatus;
    label: string;
    /** Días de retraso (vencida) o que faltan para vencer (pendiente); null si no aplica. */
    days: number | null;
};

/**
 * Estado de cobro con días (D-407): «Vencida hace 8 días», «Vence hoy», «Vence en 5 días», «Cobrada
 * en parte · 40 %», «Cobrada», «Anulada» o «Borrador». `today` es la fecha de hoy en Madrid.
 */
export function relativeCollection(
    invoice: {
        collection_status: CollectionStatus;
        due_on: string | null;
        total: string;
        pending_total: string;
        is_draft?: boolean;
    },
    today: string,
): RelativeCollection {
    const status = invoice.collection_status;

    if (invoice.is_draft || status === 'draft') {
        return {
            status: 'draft',
            label: t('billing.collection.draft'),
            days: null,
        };
    }

    if (status === 'cancelled' || status === 'paid') {
        return { status, label: t(`billing.collection.${status}`), days: null };
    }

    const pending = Number(invoice.pending_total);

    if (!(pending > 0)) {
        return {
            status: 'paid',
            label: t('billing.collection.paid'),
            days: null,
        };
    }

    const total = Number(invoice.total);
    const paidPct =
        total > 0 ? Math.round(((total - pending) / total) * 100) : 0;

    if (invoice.due_on !== null) {
        const late = daysBetween(invoice.due_on, today);

        if (late > 0) {
            return {
                status: 'overdue',
                label: tCount('billing.relative.overdue', late),
                days: late,
            };
        }

        if (status !== 'partial' || paidPct <= 0) {
            return {
                status: status === 'overdue' ? 'unpaid' : status,
                label:
                    late === 0
                        ? t('billing.relative.due_today')
                        : tCount('billing.relative.due_in', -late),
                days: -late,
            };
        }
    }

    if (status === 'partial' && paidPct > 0) {
        return {
            status: 'partial',
            label: t('billing.relative.partial', { pct: paidPct }),
            days: null,
        };
    }

    return {
        status: status === 'overdue' ? 'unpaid' : status,
        label: t('billing.collection.unpaid'),
        days: null,
    };
}
