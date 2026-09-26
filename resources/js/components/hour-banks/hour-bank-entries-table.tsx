import { Clock, TriangleAlert } from 'lucide-react';
import { TimeEntryStatusBadge } from '@/components/domain/badges';
import { EmptyState } from '@/components/empty-state';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatDate, formatMinutes } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { TimeEntry } from '@/types';

/**
 * Entradas de horas de la bolsa que puede ver quien mira (D-021: un empleado, solo las suyas):
 * fecha, persona, tarea, duración (con la parte en exceso en rojo) y estado.
 */
export function HourBankEntriesTable({
    entries,
    ownOnly,
}: {
    entries: TimeEntry[];
    /** Quien mira solo ve sus entradas (se avisa encima de la tabla). */
    ownOnly: boolean;
}) {
    if (entries.length === 0) {
        return (
            <EmptyState
                icon={Clock}
                title={t(
                    ownOnly
                        ? 'hour_banks.detail.no_own_entries'
                        : 'hour_banks.detail.no_entries',
                )}
            />
        );
    }

    return (
        <div
            className={cn('overflow-x-auto rounded-md border', FOCUS_RING)}
            role="region"
            aria-label={t('hour_banks.detail.entries')}
            tabIndex={0}
        >
            <table className="w-full min-w-[44rem] text-sm">
                <caption className="sr-only">
                    {t('hour_banks.detail.entries')}
                </caption>
                <thead>
                    <tr className="border-b text-left">
                        <th scope="col" className="px-3 py-2 font-medium">
                            {t('hour_banks.detail.date')}
                        </th>
                        <th scope="col" className="px-3 py-2 font-medium">
                            {t('hour_banks.detail.person')}
                        </th>
                        <th scope="col" className="px-3 py-2 font-medium">
                            {t('hour_banks.detail.task_and_note')}
                        </th>
                        <th
                            scope="col"
                            className="px-3 py-2 text-right font-medium"
                        >
                            {t('hour_banks.detail.hours')}
                        </th>
                        <th scope="col" className="px-3 py-2 font-medium">
                            {t('hour_banks.detail.status')}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    {entries.map((entry) => (
                        <tr
                            key={entry.id}
                            className="border-b last:border-0 even:bg-muted"
                        >
                            <td className="tabular px-3 py-2 whitespace-nowrap">
                                {formatDate(entry.date)}
                            </td>
                            <td className="px-3 py-2 whitespace-nowrap">
                                {entry.user?.name ?? ''}
                            </td>
                            <td className="px-3 py-2">
                                <span className="block">
                                    {entry.task?.title ?? ''}
                                </span>
                                {entry.description ? (
                                    <span className="block text-xs text-muted-foreground">
                                        {entry.description}
                                    </span>
                                ) : null}
                            </td>
                            <td className="tabular px-3 py-2 text-right whitespace-nowrap">
                                {formatMinutes(entry.minutes)}
                                {entry.overage_minutes > 0 ? (
                                    <span className="mt-0.5 flex items-center justify-end gap-1 text-xs font-medium text-danger">
                                        <TriangleAlert
                                            aria-hidden="true"
                                            className="size-3.5"
                                        />
                                        {t('hour_banks.detail.entry_overage', {
                                            minutes: formatMinutes(
                                                entry.overage_minutes,
                                            ),
                                        })}
                                    </span>
                                ) : null}
                            </td>
                            <td className="px-3 py-2">
                                <TimeEntryStatusBadge status={entry.status} />
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
