import { CalendarOff, OctagonAlert, UserRoundX } from 'lucide-react';
import { LOAD_LEVELS, loadPercent } from '@/components/charts/thresholds';
import { LayerBar } from '@/components/forecast/layer-bar';
import { LAYER_FILL } from '@/components/forecast/layer-swatch';
import {
    cellLevel,
    cellLoad,
    formatHours,
    formatPercentValue,
    LAYERS,
} from '@/lib/forecast';
import type { LayerToggles } from '@/lib/forecast';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type {
    ForecastAbsence,
    LoadCell,
    LoadLayers,
} from '@/types/forecast';

/** Por qué una celda no tiene capacidad (D-292): la semana entera ausente, festivo o sin jornada. */
export type NoCapacityReason = 'absence' | 'holiday' | 'no_schedule' | 'none';

export function noCapacityReason({
    cell,
    absence,
    holidays,
    hasSchedule,
}: {
    cell: LoadCell;
    absence: ForecastAbsence | null;
    holidays: number;
    hasSchedule: boolean;
}): NoCapacityReason {
    if (!hasSchedule) {
        return 'no_schedule';
    }

    if (cell.capacity > 0) {
        return 'none';
    }

    if (absence && absence.days > 0) {
        return 'absence';
    }

    return holidays > 0 ? 'holiday' : 'none';
}

/**
 * Nombre accesible de una celda (D-292 §6): «Luis Martín, semana 46 (9 nov – 15 nov): 130 %,
 * Sobrecarga, 52 h de 40 h», más «capacidad reducida por ausencia» si toca.
 */
export function forecastCellLabel({
    name,
    period,
    cell,
    layers,
    absence,
    reason,
}: {
    name: string;
    period: string;
    cell: LoadCell;
    layers: LayerToggles;
    absence: ForecastAbsence | null;
    reason: NoCapacityReason;
}): string {
    const load = cellLoad(cell, layers);
    const level = cellLevel(cell, layers);
    const head = `${name}, ${period}`;

    if (reason === 'no_schedule') {
        return `${head}: ${t('forecast.cell.label_no_schedule', { load: formatHours(load) })}`;
    }

    if (level === 'none') {
        return `${head}: ${t('forecast.cell.label_no_capacity', {
            reason: t(`forecast.cell.reason.${reason}`),
            load: formatHours(load),
        })}`;
    }

    const parts = [
        formatPercentValue(loadPercent(load, cell.capacity)),
        LOAD_LEVELS[level].label,
        t('forecast.cell.of', {
            load: formatHours(load),
            capacity: formatHours(cell.capacity),
        }),
    ];

    if (absence && absence.days > 0) {
        parts.push(t('forecast.cell.reduced'));
    }

    return `${head}: ${parts.join(', ')}`;
}

/**
 * Celda de una persona en la matriz (D-290 y D-292): el tinte, el icono y el % del semáforo de la
 * Carga (D-052), con la barra de capas abajo y la muesca de la ausencia parcial en la esquina. Sin
 * capacidad, el motivo («Ausencia», «Festivo») o, en un colaborador sin jornada, sus horas y «sin
 * jornada» (nunca un % inventado).
 */
export function ForecastCell({
    cell,
    layers,
    absence,
    reason,
}: {
    cell: LoadCell;
    layers: LayerToggles;
    absence: ForecastAbsence | null;
    reason: NoCapacityReason;
}) {
    const load = cellLoad(cell, layers);
    const level = cellLevel(cell, layers);
    const meta = LOAD_LEVELS[level];
    const Icon = meta.icon;
    const reduced = cell.capacity > 0 && absence !== null && absence.days > 0;

    if (reason === 'no_schedule') {
        return (
            <span
                data-test="forecast-cell"
                data-level="no_schedule"
                className="flex h-10 flex-col justify-between bg-neutral-soft px-1.5 py-1"
            >
                <span className="tabular flex items-center gap-1 text-xs font-medium">
                    <UserRoundX aria-hidden="true" className="size-3 shrink-0 text-muted-foreground" />
                    {load > 0 ? formatHours(load) : '—'}
                </span>
                <span className="truncate text-[0.6875rem] text-muted-foreground">
                    {t('forecast.cell.no_schedule')}
                </span>
            </span>
        );
    }

    if (level === 'none') {
        return (
            <span
                data-test="forecast-cell"
                data-level="none"
                className="flex h-10 flex-col justify-between bg-neutral-soft px-1.5 py-1 text-muted-foreground"
            >
                <span className="flex items-center gap-1">
                    {load > 0 ? (
                        <OctagonAlert aria-hidden="true" className="size-3 text-danger" />
                    ) : (
                        <CalendarOff aria-hidden="true" className="size-3" />
                    )}
                    {load > 0 ? (
                        <span className="tabular text-xs text-foreground">
                            {formatHours(load)}
                        </span>
                    ) : null}
                </span>
                <span className="truncate text-[0.6875rem]">
                    {t(`forecast.cell.reason.${reason}`)}
                </span>
            </span>
        );
    }

    return (
        <span
            data-test="forecast-cell"
            data-level={level}
            className={cn(
                'relative flex h-10 flex-col justify-between px-1.5 py-1 text-foreground',
                meta.surface,
            )}
        >
            {reduced ? (
                <span
                    aria-hidden="true"
                    data-test="absence-notch"
                    className="absolute top-0 right-0 size-0 border-t-8 border-l-8 border-t-muted-foreground border-l-transparent"
                />
            ) : null}
            <span className="tabular flex items-center gap-1 text-xs leading-3.5 font-medium whitespace-nowrap">
                <Icon aria-hidden="true" className={cn('size-3 shrink-0', meta.tone)} />
                {formatPercentValue(loadPercent(load, cell.capacity))}
            </span>
            <LayerBar minutes={cell} capacity={cell.capacity} layers={layers} />
        </span>
    );
}

/**
 * Celda de un hueco sin persona (D-293): borde discontinuo, las horas y una tira con las capas que
 * lo forman. Sin % (un hueco no tiene capacidad). Vacía si no hay nada.
 */
export function GapCell({
    minutes,
    layers,
}: {
    minutes: LoadLayers;
    layers: LayerToggles;
}) {
    const load = cellLoad(minutes, layers);

    if (load <= 0) {
        return <span data-test="gap-cell" className="block h-10" />;
    }

    return (
        <span
            data-test="gap-cell"
            className="flex h-10 flex-col justify-between border border-dashed border-muted-foreground px-1.5 py-1"
        >
            <span className="tabular text-xs font-medium">{formatHours(load)}</span>
            <span aria-hidden="true" className="flex h-1.5 gap-0.5">
                {LAYERS.filter((layer) => layers[layer] && minutes[layer] > 0).map(
                    (layer) => (
                        <span key={layer} className={cn('block h-full flex-1', LAYER_FILL[layer])} />
                    ),
                )}
            </span>
        </span>
    );
}
