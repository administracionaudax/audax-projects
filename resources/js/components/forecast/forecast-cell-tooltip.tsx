import {
    LOAD_LEVELS,
    loadLevel,
    loadPercent,
} from '@/components/charts/thresholds';
import { LAYER_FILL } from '@/components/forecast/layer-swatch';
import { formatHours, formatPercentValue, plural } from '@/lib/forecast';
import type { CellItem, LayerToggles } from '@/lib/forecast';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/** Como mucho, estas filas en un departamento; el resto, «y N proyectos más» (D-290). */
export const TOOLTIP_MAX_ITEMS = 7;

/**
 * Contenido del tooltip de una celda de la previsión (sobre el estilo de ChartTooltipCard): el
 * título, el % grande con su icono y su nivel, una fila por proyecto (de real a seguro y a posible,
 * de más a menos horas; las capas apagadas, atenuadas) y el pie con las horas frente a la
 * capacidad, si se pasa y los festivos y ausencias. En un hueco, las horas sin persona.
 */
export function ForecastCellTooltip({
    title,
    load,
    capacity,
    items,
    layers,
    footer = [],
    gap = false,
    maxItems = TOOLTIP_MAX_ITEMS,
    className,
}: {
    title: string;
    load: number;
    /** null en un hueco (no tiene capacidad). */
    capacity: number | null;
    items: CellItem[];
    layers: LayerToggles;
    footer?: string[];
    gap?: boolean;
    maxItems?: number;
    className?: string;
}) {
    const level = capacity === null ? null : loadLevel(load, capacity);
    const meta = level ? LOAD_LEVELS[level] : null;
    const Icon = meta?.icon;
    const shown = items.slice(0, maxItems);
    const lines: string[] = [];

    if (!gap && capacity !== null && capacity > 0) {
        lines.push(
            t('forecast.tooltip.assigned_of', {
                load: formatHours(load),
                capacity: formatHours(capacity),
            }),
        );

        if (load > capacity) {
            lines.push(
                t('forecast.tooltip.over_by', {
                    hours: formatHours(load - capacity),
                }),
            );
        } else if (load < capacity) {
            lines.push(
                t('forecast.tooltip.free', {
                    hours: formatHours(capacity - load),
                }),
            );
        }
    }

    lines.push(...footer);

    return (
        <div
            data-test="forecast-tooltip"
            className={cn(
                'w-72 max-w-[calc(100vw-2rem)] border bg-popover px-3 py-2 text-sm text-popover-foreground',
                className,
            )}
        >
            <p className="mb-1.5 text-xs text-muted-foreground">{title}</p>
            {gap ? (
                <p className="mb-2 flex items-baseline gap-2">
                    <span className="tabular text-xl font-semibold">
                        {formatHours(load)}
                    </span>
                    <span className="text-muted-foreground">
                        {t('forecast.tooltip.unassigned')}
                    </span>
                </p>
            ) : meta && Icon ? (
                <p className="mb-2 flex items-center gap-2">
                    <Icon
                        aria-hidden="true"
                        className={cn('size-4 shrink-0', meta.tone)}
                    />
                    {level === 'none' ? (
                        <span>{meta.label}</span>
                    ) : (
                        <>
                            <span className="tabular text-xl font-semibold">
                                {formatPercentValue(
                                    loadPercent(load, capacity ?? 0),
                                )}
                            </span>
                            <span className="text-muted-foreground">
                                {meta.label}
                            </span>
                        </>
                    )}
                </p>
            ) : null}
            {shown.length > 0 ? (
                <ul className="grid gap-1">
                    {shown.map((item) => (
                        <li
                            key={item.key}
                            className={cn(
                                'flex items-start gap-2',
                                !layers[item.layer] && 'opacity-45',
                            )}
                        >
                            <span
                                aria-hidden="true"
                                className={cn(
                                    'mt-1.5 h-2 w-3 shrink-0',
                                    LAYER_FILL[item.layer],
                                )}
                            />
                            <span className="tabular shrink-0 font-medium">
                                {formatHours(item.minutes)}
                            </span>
                            <span className="min-w-0 text-muted-foreground">
                                {item.title}
                                {item.layer === 'real'
                                    ? ''
                                    : ` · ${t(`forecast.tooltip.layer.${item.layer}`)}`}
                                {layers[item.layer]
                                    ? ''
                                    : ` (${t('forecast.tooltip.layer_off')})`}
                            </span>
                        </li>
                    ))}
                </ul>
            ) : (
                <p className="text-muted-foreground">
                    {t('forecast.tooltip.nothing')}
                </p>
            )}
            {items.length > shown.length ? (
                <p className="mt-1 text-xs text-muted-foreground">
                    {plural(
                        'forecast.tooltip.more_one',
                        'forecast.tooltip.more_other',
                        items.length - shown.length,
                    )}
                </p>
            ) : null}
            {lines.length > 0 ? (
                <ul className="mt-2 grid gap-0.5 border-t pt-2 text-xs text-muted-foreground">
                    {lines.map((line) => (
                        <li key={line}>{line}</li>
                    ))}
                </ul>
            ) : null}
        </div>
    );
}
