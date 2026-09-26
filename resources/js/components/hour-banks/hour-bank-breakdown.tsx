import { TriangleAlert } from 'lucide-react';
import type { ReactNode } from 'react';
import { EmptyState } from '@/components/empty-state';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatMinutes, formatPercent } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { PieChart } from 'lucide-react';

export type BreakdownRow = {
    id: string;
    label: ReactNode;
    minutes: number;
    overage_minutes: number;
};

/**
 * Reparto del consumo de una bolsa (por persona o por tipo de tarea): horas, parte del total con
 * una barra proporcional y el exceso en rojo con icono.
 */
export function HourBankBreakdownTable({
    caption,
    firstColumn,
    rows,
}: {
    caption: string;
    firstColumn: string;
    rows: BreakdownRow[];
}) {
    if (rows.length === 0) {
        return (
            <EmptyState
                icon={PieChart}
                title={t('hour_banks.detail.no_time')}
            />
        );
    }

    const total = rows.reduce((sum, row) => sum + row.minutes, 0);

    return (
        <div
            className={cn('overflow-x-auto rounded-md border', FOCUS_RING)}
            role="region"
            aria-label={caption}
            tabIndex={0}
        >
            <table className="w-full min-w-[22rem] text-sm">
                <caption className="sr-only">{caption}</caption>
                <thead>
                    <tr className="border-b text-left">
                        <th scope="col" className="px-3 py-2 font-medium">
                            {firstColumn}
                        </th>
                        <th
                            scope="col"
                            className="px-3 py-2 text-right font-medium"
                        >
                            {t('hour_banks.detail.hours')}
                        </th>
                        <th scope="col" className="px-3 py-2 font-medium">
                            {t('hour_banks.detail.share')}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row) => {
                        const share = total > 0 ? row.minutes / total : 0;

                        return (
                            <tr
                                key={row.id}
                                className="border-b last:border-0 even:bg-muted"
                            >
                                <th
                                    scope="row"
                                    className="px-3 py-2 text-left font-normal"
                                >
                                    {row.label}
                                </th>
                                <td className="tabular px-3 py-2 text-right whitespace-nowrap">
                                    {formatMinutes(row.minutes)}
                                    {row.overage_minutes > 0 ? (
                                        <span className="ml-2 inline-flex items-center gap-1 text-xs font-medium text-danger">
                                            <TriangleAlert
                                                aria-hidden="true"
                                                className="size-3.5"
                                            />
                                            {t('hour_bank.overage_badge', {
                                                minutes: formatMinutes(
                                                    row.overage_minutes,
                                                ),
                                            })}
                                        </span>
                                    ) : null}
                                </td>
                                <td className="px-3 py-2">
                                    <span className="flex items-center gap-2">
                                        <span
                                            aria-hidden="true"
                                            className="h-2 w-20 shrink-0 rounded-[3px] bg-neutral-soft"
                                        >
                                            <span
                                                className="block h-full rounded-[3px] bg-chart-1"
                                                style={{
                                                    width: `${share * 100}%`,
                                                }}
                                            />
                                        </span>
                                        <span className="tabular text-xs">
                                            {formatPercent(share, 0)}
                                        </span>
                                    </span>
                                </td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
}
