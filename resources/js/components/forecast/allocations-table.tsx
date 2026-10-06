import { MoreHorizontal, UserPlus, UserRound } from 'lucide-react';
import type { ReactNode } from 'react';
import { LAYER_FILL } from '@/components/forecast/layer-swatch';
import { Button } from '@/components/ui/button';
import {
    allocationAmountLabel,
    dateRange,
    formatHours,
    monthShort,
} from '@/lib/forecast';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { Allocation, LoadLayer } from '@/types/forecast';

const DAY = 86_400_000;

function time(date: string): number {
    return Date.parse(`${date}T00:00:00Z`);
}

function monthEnd(month: string): string {
    const [year, value] = month.split('-').map(Number);

    return new Date(Date.UTC(year, value, 0)).toISOString().slice(0, 10);
}

export type TimelineRange = { from: string; to: string; months: string[] };

/** Rango del minicronograma: de primeros del primer mes a final del último. */
export function timelineRange(months: string[]): TimelineRange | null {
    if (months.length === 0) {
        return null;
    }

    return {
        from: `${months[0]}-01`,
        to: monthEnd(months[months.length - 1]),
        months,
    };
}

/**
 * Minicronograma de una asignación (D-295 y D-296): la barra de sus fechas sobre los meses del
 * proyecto, con la capa (trama si es posible) y el borde discontinuo si es un hueco. Con `today`,
 * la línea de hoy.
 */
export function MiniTimeline({
    range,
    start,
    end,
    layer,
    gap = false,
    today,
    className,
}: {
    range: TimelineRange;
    start: string;
    end: string | null;
    layer: LoadLayer;
    gap?: boolean;
    today?: string;
    className?: string;
}) {
    const total = time(range.to) - time(range.from) + DAY;
    const left = Math.max(0, (time(start) - time(range.from)) / total);
    const right = Math.min(
        1,
        (time(end ?? range.to) - time(range.from) + DAY) / total,
    );
    const todayAt = today ? (time(today) - time(range.from)) / total : null;

    return (
        <span
            aria-hidden="true"
            className={cn('relative block h-3 w-full', className)}
            data-test="mini-timeline"
        >
            {range.months.slice(1).map((month) => (
                <span
                    key={month}
                    className="absolute inset-y-0 w-px bg-border"
                    style={{
                        left: `${((time(`${month}-01`) - time(range.from)) / total) * 100}%`,
                    }}
                />
            ))}
            <span
                className={cn(
                    'absolute inset-y-0',
                    LAYER_FILL[layer],
                    gap &&
                        'outline-1 outline-offset-0 outline-foreground outline-dashed',
                )}
                style={{
                    left: `${left * 100}%`,
                    width: `${Math.max(right - left, 0.01) * 100}%`,
                }}
            />
            {todayAt !== null && todayAt >= 0 && todayAt <= 1 ? (
                <span
                    className="absolute -inset-y-1 w-0.5 bg-brand"
                    style={{ left: `${todayAt * 100}%` }}
                />
            ) : null}
        </span>
    );
}

export function WhoCell({ allocation }: { allocation: Allocation }) {
    if (allocation.user) {
        return <span>{allocation.user.name}</span>;
    }

    return (
        <span className="flex items-center gap-2">
            <span
                aria-hidden="true"
                className="flex size-6 shrink-0 items-center justify-center rounded-full border border-dashed border-muted-foreground text-muted-foreground"
            >
                <UserRound className="size-3" />
            </span>
            <span className="min-w-0">
                <span className="block">{allocation.department?.name}</span>
                <span className="block text-xs text-muted-foreground">
                    {t('forecast.matrix.no_person')}
                </span>
            </span>
        </span>
    );
}

/**
 * Asignaciones de un previsto (D-295): quién (persona o hueco con avatar punteado), cómo, fechas,
 * total y el minicronograma de los meses del proyecto; «Asignar a…» en los huecos y editar en las
 * demás (según los permisos). El pie compara lo asignado con la estimación.
 */
export function AllocationsTable({
    allocations,
    months,
    layer,
    footer,
    onEdit,
    onAssign,
}: {
    allocations: Allocation[];
    months: string[];
    layer: LoadLayer;
    footer?: ReactNode;
    onEdit?: (allocation: Allocation) => void;
    onAssign?: (allocation: Allocation) => void;
}) {
    const range = timelineRange(months);

    return (
        <div className="overflow-x-auto">
            <table className="w-full text-sm" data-test="allocations-table">
                <thead>
                    <tr>
                        <th scope="col" className="px-3 py-2 text-left">
                            {t('forecast.show.who')}
                        </th>
                        <th scope="col" className="px-3 py-2 text-left">
                            {t('forecast.show.how')}
                        </th>
                        <th scope="col" className="px-3 py-2 text-left">
                            {t('forecast.show.dates')}
                        </th>
                        <th scope="col" className="px-3 py-2 text-right">
                            {t('forecast.show.total')}
                        </th>
                        <th
                            scope="col"
                            className="hidden min-w-48 px-3 py-2 text-left md:table-cell"
                        >
                            {range ? (
                                <span className="flex justify-between gap-2 tracking-normal normal-case">
                                    {range.months.map((month) => (
                                        <span key={month}>
                                            {monthShort(`${month}-01`)}
                                        </span>
                                    ))}
                                </span>
                            ) : null}
                        </th>
                        <th scope="col" className="px-3 py-2 text-right">
                            <span className="sr-only">
                                {t('forecast.gaps.actions')}
                            </span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    {allocations.map((allocation) => (
                        <tr
                            key={allocation.id}
                            className="border-b"
                            data-test="allocation-row"
                        >
                            <td className="px-3 py-2.5 align-middle">
                                <WhoCell allocation={allocation} />
                                {allocation.note ? (
                                    <p className="text-xs text-muted-foreground">
                                        {allocation.note}
                                    </p>
                                ) : null}
                            </td>
                            <td className="px-3 py-2.5 align-middle whitespace-nowrap">
                                {allocationAmountLabel(allocation)}
                            </td>
                            <td className="tabular px-3 py-2.5 align-middle whitespace-nowrap">
                                {dateRange(
                                    allocation.start_date,
                                    allocation.end_date,
                                )}
                            </td>
                            <td className="tabular px-3 py-2.5 text-right align-middle whitespace-nowrap">
                                {formatHours(allocation.planned_minutes)}
                            </td>
                            <td className="hidden px-3 py-2.5 align-middle md:table-cell">
                                {range ? (
                                    <MiniTimeline
                                        range={range}
                                        start={allocation.start_date}
                                        end={allocation.end_date}
                                        layer={layer}
                                        gap={allocation.is_gap}
                                    />
                                ) : null}
                            </td>
                            <td className="px-3 py-2.5 text-right align-middle whitespace-nowrap">
                                {allocation.is_gap &&
                                allocation.can.assign &&
                                onAssign ? (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={() => onAssign(allocation)}
                                    >
                                        <UserPlus aria-hidden="true" />
                                        {t('forecast.assign.open')}
                                        <span className="sr-only">
                                            {' '}
                                            {allocation.department?.name}
                                        </span>
                                    </Button>
                                ) : null}
                                {allocation.can.update && onEdit ? (
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        onClick={() => onEdit(allocation)}
                                        aria-label={t(
                                            'forecast.show.edit_allocation',
                                            {
                                                who:
                                                    allocation.user?.name ??
                                                    allocation.department
                                                        ?.name ??
                                                    '',
                                            },
                                        )}
                                    >
                                        <MoreHorizontal aria-hidden="true" />
                                    </Button>
                                ) : null}
                            </td>
                        </tr>
                    ))}
                </tbody>
                {footer ? (
                    <tfoot>
                        <tr className="bg-neutral-soft">{footer}</tr>
                    </tfoot>
                ) : null}
            </table>
        </div>
    );
}
