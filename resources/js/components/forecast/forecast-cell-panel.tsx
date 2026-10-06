import { Link } from '@inertiajs/react';
import { ArrowRight, UserPlus } from 'lucide-react';
import { useState } from 'react';
import {
    LOAD_LEVELS,
    loadLevel,
    loadPercent,
} from '@/components/charts/thresholds';
import { AssignGapDialog } from '@/components/forecast/assign-gap-dialog';
import {
    cellFooter,
    groupName,
    rowName,
} from '@/components/forecast/forecast-matrix';
import type { MatrixTarget } from '@/components/forecast/forecast-matrix';
import { LayerBadge } from '@/components/forecast/layer-badge';
import { rowCell, rowItems } from '@/components/forecast/matrix-model';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import {
    bucketLabel,
    cellLoad,
    formatHours,
    formatPercentValue,
} from '@/lib/forecast';
import type { LayerToggles } from '@/lib/forecast';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { ForecastBoard, ForecastGap } from '@/types/forecast';

function upperFirst(text: string): string {
    return text.charAt(0).toUpperCase() + text.slice(1);
}

/**
 * Panel de una celda de la matriz (D-290 §4.1, como el de /carga): de qué proyectos sale cada hora,
 * con enlace a su previsto o a la Planificación del proyecto, el pie de la celda y, en un hueco,
 * «Asignar a…». Lo mismo que el tooltip, para quien no usa el ratón o quiere actuar.
 */
export function ForecastCellPanel({
    board,
    target,
    layers,
    gaps,
    onClose,
}: {
    board: ForecastBoard;
    target: MatrixTarget | null;
    layers: LayerToggles;
    gaps?: ForecastGap[];
    onClose: () => void;
}) {
    const [assigning, setAssigning] = useState<ForecastGap | null>(null);

    if (target === null) {
        return null;
    }

    const { row, index } = target;
    const bucket = board.buckets[index];
    const period = bucket
        ? bucketLabel(bucket, board.period.granularity).long
        : '';
    const cell = rowCell(row, index);
    const load = cellLoad(cell, layers);
    const noCapacity =
        row.kind === 'gap' ||
        (row.kind === 'person' && !row.person.has_schedule);
    const level = loadLevel(load, cell.capacity);
    const meta = LOAD_LEVELS[level];
    const Icon = meta.icon;
    const items = rowItems(board, row, index);
    const footer = cellFooter(board, row, index);
    const gapAllocations =
        row.kind === 'gap'
            ? (gaps ?? []).filter((gap) =>
                  board.sources.some(
                      (source) =>
                          source.allocation_id === gap.allocation.id &&
                          (source.minutes[index] ?? 0) > 0,
                  ),
              )
            : [];

    return (
        <Sheet open onOpenChange={(open) => (open ? null : onClose())}>
            <SheetContent
                side="right"
                className="w-full gap-0 overflow-y-auto sm:max-w-md"
                data-test="forecast-cell-panel"
            >
                <SheetHeader className="gap-1 pr-12">
                    <SheetTitle className="text-lg font-normal">
                        {rowName(row)}
                    </SheetTitle>
                    <SheetDescription>
                        {upperFirst(period)}
                        {row.kind === 'person' &&
                        row.group.kind === 'department'
                            ? ` · ${groupName(row.group)}`
                            : ''}
                    </SheetDescription>
                </SheetHeader>
                <div className="grid gap-5 px-4 pb-6">
                    {noCapacity ? (
                        <p className="flex items-baseline gap-2">
                            <span className="tabular text-3xl font-semibold">
                                {formatHours(load)}
                            </span>
                            <span className="text-muted-foreground">
                                {row.kind === 'gap'
                                    ? t('forecast.tooltip.unassigned')
                                    : t('forecast.cell.no_schedule')}
                            </span>
                        </p>
                    ) : (
                        <div
                            className={cn(
                                'flex items-center gap-3 p-3',
                                meta.surface,
                            )}
                        >
                            <Icon
                                aria-hidden="true"
                                className={cn('size-5 shrink-0', meta.tone)}
                            />
                            <p className="flex flex-wrap items-baseline gap-x-2">
                                {level === 'none' ? null : (
                                    <span className="tabular text-3xl font-semibold">
                                        {formatPercentValue(
                                            loadPercent(load, cell.capacity),
                                        )}
                                    </span>
                                )}
                                <span>{meta.label}</span>
                                {cell.capacity > 0 ? (
                                    <span className="w-full text-sm text-muted-foreground">
                                        {t('forecast.tooltip.assigned_of', {
                                            load: formatHours(load),
                                            capacity: formatHours(
                                                cell.capacity,
                                            ),
                                        })}
                                        {load > cell.capacity
                                            ? ` · ${t('forecast.tooltip.over_by', { hours: formatHours(load - cell.capacity) })}`
                                            : ''}
                                    </span>
                                ) : null}
                            </p>
                        </div>
                    )}

                    <section
                        aria-labelledby="forecast-panel-items"
                        className="grid gap-2"
                    >
                        <h3
                            id="forecast-panel-items"
                            className="text-sm font-medium"
                        >
                            {t('forecast.panel.where')}
                        </h3>
                        {items.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                {t('forecast.tooltip.nothing')}
                            </p>
                        ) : (
                            <ul className="grid gap-2">
                                {items.map((item) => (
                                    <li
                                        key={item.key}
                                        className={cn(
                                            'flex items-start gap-3 border-b pb-2 last:border-0',
                                            !layers[item.layer] && 'opacity-60',
                                        )}
                                    >
                                        <span className="tabular w-14 shrink-0 text-right font-medium">
                                            {formatHours(item.minutes)}
                                        </span>
                                        <span className="min-w-0 flex-1">
                                            <Link
                                                href={item.href}
                                                className="text-primary-text hover:underline"
                                            >
                                                {item.title}
                                            </Link>
                                            <LayerBadge
                                                layer={item.layer}
                                                className="mt-0.5 flex text-muted-foreground"
                                            />
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>

                    {footer.length > 0 ? (
                        <ul className="grid gap-1 text-sm text-muted-foreground">
                            {footer.map((line) => (
                                <li key={line}>{line}</li>
                            ))}
                        </ul>
                    ) : null}

                    {gapAllocations.length > 0 ? (
                        <section
                            aria-labelledby="forecast-panel-gaps"
                            className="grid gap-2"
                        >
                            <h3
                                id="forecast-panel-gaps"
                                className="text-sm font-medium"
                            >
                                {t('forecast.panel.assign_title')}
                            </h3>
                            {gapAllocations.map((gap) => (
                                <div
                                    key={gap.allocation.id}
                                    className="flex flex-wrap items-center justify-between gap-2 border p-2 text-sm"
                                >
                                    <span className="min-w-0">
                                        {gap.container.name}
                                    </span>
                                    {gap.allocation.can.assign ? (
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="outline"
                                            onClick={() => setAssigning(gap)}
                                        >
                                            <UserPlus aria-hidden="true" />
                                            {t('forecast.assign.open')}
                                        </Button>
                                    ) : null}
                                </div>
                            ))}
                        </section>
                    ) : null}

                    {row.kind === 'person' || row.kind === 'group' ? (
                        <p className="flex items-start gap-1.5 text-xs text-muted-foreground">
                            <ArrowRight
                                aria-hidden="true"
                                className="mt-0.5 size-3 shrink-0"
                            />
                            {t('forecast.panel.edit_hint')}
                        </p>
                    ) : null}
                </div>
                {assigning ? (
                    <AssignGapDialog
                        allocation={assigning.allocation}
                        departmentName={
                            assigning.allocation.department?.name ?? ''
                        }
                        open
                        onOpenChange={(open) =>
                            open ? null : setAssigning(null)
                        }
                    />
                ) : null}
            </SheetContent>
        </Sheet>
    );
}
