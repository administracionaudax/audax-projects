import { CircleAlert, CirclePause, CircleStop, Repeat } from 'lucide-react';
import { StatusBadge } from '@/components/styleguide/status-badges';
import { t } from '@/lib/i18n';
import type { RecurringRow } from '@/types';

/** «Mensual · día 29», «Cada 4 meses · día 1», «Anual · último día». */
export function periodicityText(
    row: Pick<RecurringRow, 'periodicity' | 'interval_months' | 'day_of_month'>,
): string {
    const every =
        row.periodicity === 'custom'
            ? t('invoicing.recurring.every_months', {
                  count: row.interval_months,
              })
            : t(`invoicing.recurring.periodicity.${row.periodicity}`);
    const day =
        row.day_of_month >= 31
            ? t('invoicing.recurring.last_day')
            : t('invoicing.recurring.day', { day: row.day_of_month });

    return `${every} · ${day}`;
}

/** El estado: activa, en pausa (y, si viene de Holded sin revisar, «por revisar») o acabada. */
export function RecurringStateBadge({ row }: { row: RecurringRow }) {
    if (row.last_error && row.state === 'active') {
        return (
            <StatusBadge tone="danger" icon={CircleAlert}>
                {t('invoicing.recurring.state.error')}
            </StatusBadge>
        );
    }

    if (row.state === 'paused') {
        return (
            <StatusBadge tone="warning" icon={CirclePause}>
                {row.from_holded && !row.reviewed
                    ? t('invoicing.recurring.state.holded_review')
                    : t('invoicing.recurring.state.paused')}
            </StatusBadge>
        );
    }

    if (row.state === 'finished') {
        return (
            <StatusBadge tone="neutral" icon={CircleStop}>
                {t('invoicing.recurring.state.finished')}
            </StatusBadge>
        );
    }

    return (
        <StatusBadge tone="success" icon={Repeat}>
            {t('invoicing.recurring.state.active')}
        </StatusBadge>
    );
}

/** Qué hace cada periodo: un borrador para revisar o emitirla (y enviarla) sola. */
export function RecurringModeText({
    row,
}: {
    row: Pick<RecurringRow, 'mode' | 'auto_send'>;
}) {
    return (
        <span className="text-sm">
            {row.mode === 'draft'
                ? t('invoicing.recurring.mode.draft')
                : row.auto_send
                  ? t('invoicing.recurring.mode.issue_send')
                  : t('invoicing.recurring.mode.issue')}
        </span>
    );
}
