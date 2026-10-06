import { useState } from 'react';
import { formatHoursTick, hourTicks } from '@/components/charts/chart-config';
import {
    LOAD_LEVELS,
    loadLevel,
    loadPercent,
} from '@/components/charts/thresholds';
import { STACK_GAP } from '@/components/forecast/department-column';
import { ForecastCellTooltip } from '@/components/forecast/forecast-cell-tooltip';
import { LAYER_SVG_FILL } from '@/components/forecast/layer-swatch';
import { useWidth } from '@/components/forecast/use-width';
import {
    ALL_LAYERS,
    bucketLabel,
    cellItems,
    cellLoad,
    formatPercentValue,
    LAYERS,
    monthShort,
} from '@/lib/forecast';
import type { LayerToggles } from '@/lib/forecast';
import { cn } from '@/lib/utils';
import type { ForecastBucket, LoadCell, LoadSource } from '@/types/forecast';

const LEFT = 40;

/**
 * Columnas apiladas por semana frente a la capacidad (D-299): real abajo, seguro encima y posible
 * arriba con trama, con 2 px de separación, y la capacidad (la jornada) escalonada. En horas, con su
 * eje y el % debajo (de una de cada `labelEvery` semanas y siempre que es «Alta» o «Sobrecarga»); o
 * en % de la jornada (`normalize="percent"`), con la línea del 100 % y solo el pico etiquetado.
 * Al pasar el ratón o con el foco, el tooltip de la semana con de qué proyectos sale cada hora.
 */
export function StackedCapacityColumns({
    buckets,
    cells,
    sources = [],
    layers = ALL_LAYERS,
    normalize = 'hours',
    height = 200,
    labelEvery = 2,
    tooltipTitle,
    className,
}: {
    buckets: ForecastBucket[];
    cells: LoadCell[];
    sources?: LoadSource[];
    layers?: LayerToggles;
    normalize?: 'hours' | 'percent';
    height?: number;
    labelEvery?: number;
    tooltipTitle?: (bucket: ForecastBucket) => string;
    className?: string;
}) {
    const [ref, width] = useWidth<HTMLDivElement>(320);
    const [active, setActive] = useState<number | null>(null);
    const percent = normalize === 'percent';
    const left = percent ? 0 : LEFT;
    const bottom = percent ? 16 : 36;
    const top = percent ? 18 : 8;
    const value = (cell: LoadCell, minutes: number) =>
        percent
            ? cell.capacity > 0
                ? (minutes * 100) / cell.capacity
                : 0
            : minutes;
    const loads = cells.map((cell) => value(cell, cellLoad(cell, layers)));
    const capacities = cells.map((cell) => value(cell, cell.capacity));
    const max = Math.max(percent ? 100 : 1, ...loads, ...capacities);
    const ticks = percent ? [] : hourTicks(max * 1.05, 4);
    const yMax = percent
        ? Math.max(max * 1.08, 110)
        : ticks[ticks.length - 1] || 1;
    const plotWidth = Math.max(width - left - (percent ? 0 : 8), 40);
    const slot = plotWidth / Math.max(buckets.length, 1);
    const bar = Math.min(24, Math.max(3, slot * 0.6));
    const y = (amount: number) =>
        top + (height - top - bottom) * (1 - Math.min(amount, yMax) / yMax);
    const every = Math.max(labelEvery, Math.ceil(36 / slot));
    const peak = loads.reduce(
        (best, load, index) => (load > (loads[best] ?? -1) ? index : best),
        0,
    );
    let capacityPath = '';

    cells.forEach((cell, index) => {
        const x0 = left + slot * index;
        const level = percent ? (cell.capacity > 0 ? 100 : 0) : cell.capacity;
        capacityPath += `${index === 0 ? 'M' : 'L'}${x0},${y(level)} L${x0 + slot},${y(level)} `;
    });

    return (
        <div
            ref={ref}
            className={cn('relative min-w-0', className)}
            data-test="stacked-columns"
        >
            <svg
                width={width}
                height={height}
                aria-hidden="true"
                focusable="false"
                className="block overflow-visible"
            >
                {ticks.map((tick) => (
                    <g key={tick}>
                        <line
                            x1={left}
                            x2={left + plotWidth}
                            y1={y(tick)}
                            y2={y(tick)}
                            stroke="var(--border)"
                        />
                        <text
                            x={left - 6}
                            y={y(tick)}
                            dy="0.32em"
                            textAnchor="end"
                            className="fill-muted-foreground text-[11px] tabular-nums"
                        >
                            {formatHoursTick(tick)}
                        </text>
                    </g>
                ))}
                {cells.map((cell, index) => {
                    const center = left + slot * index + slot / 2;
                    let base = 0;
                    const segments = LAYERS.filter(
                        (layer) => layers[layer] && cell[layer] > 0,
                    ).map((layer, position) => {
                        const from = y(value(cell, base));
                        base += cell[layer];
                        const to = y(value(cell, base));

                        return {
                            layer,
                            y: to,
                            height: Math.max(
                                0,
                                from - to - (position > 0 ? STACK_GAP : 0),
                            ),
                        };
                    });
                    const load = cellLoad(cell, layers);
                    const level = loadLevel(load, cell.capacity);
                    const strong = level === 'high' || level === 'over';
                    const showLabel = percent
                        ? index === peak && load > 0
                        : // Sin que se pisen: en un móvil, menos etiquetas (las fuertes, si caben).
                          (strong && slot >= 34) || index % every === 0;

                    return (
                        <g key={buckets[index]?.key ?? index}>
                            {segments.map((segment) =>
                                segment.height > 0.2 ? (
                                    <rect
                                        key={segment.layer}
                                        data-layer={segment.layer}
                                        x={center - bar / 2}
                                        y={segment.y}
                                        width={bar}
                                        height={segment.height}
                                        fill={LAYER_SVG_FILL[segment.layer]}
                                    />
                                ) : null,
                            )}
                            {showLabel && cell.capacity > 0 ? (
                                <LevelLabel
                                    x={center}
                                    y={
                                        percent
                                            ? y(loads[index]) - 6
                                            : height - bottom + 14
                                    }
                                    text={formatPercentValue(
                                        loadPercent(load, cell.capacity),
                                    )}
                                    level={strong ? level : null}
                                />
                            ) : null}
                            {!percent &&
                            buckets[index] &&
                            (index === 0 ||
                                buckets[index].from.slice(5, 7) !==
                                    buckets[index - 1].from.slice(5, 7)) ? (
                                <text
                                    x={left + slot * index}
                                    y={height - 4}
                                    className="fill-muted-foreground text-[11px]"
                                >
                                    {monthShort(buckets[index].from)}
                                </text>
                            ) : null}
                        </g>
                    );
                })}
                <line
                    x1={left}
                    x2={left + plotWidth}
                    y1={y(0)}
                    y2={y(0)}
                    stroke="var(--muted-foreground)"
                />
                <path
                    d={capacityPath}
                    fill="none"
                    stroke="var(--foreground)"
                    strokeOpacity={0.6}
                    strokeWidth={1}
                />
            </svg>
            {/* Zonas de foco y de ratón: todo el alto de cada semana. */}
            <div className="absolute inset-0" style={{ left }}>
                {buckets.map((bucket, index) => (
                    <button
                        key={bucket.key}
                        type="button"
                        tabIndex={sources.length > 0 ? 0 : -1}
                        aria-label={`${tooltipTitle ? tooltipTitle(bucket) : bucketLabel(bucket, 'week').long}: ${formatPercentValue(loadPercent(cellLoad(cells[index], layers), cells[index].capacity)) || '—'}`}
                        onMouseEnter={() => setActive(index)}
                        onMouseLeave={() => setActive(null)}
                        onFocus={() => setActive(index)}
                        onBlur={() => setActive(null)}
                        onKeyDown={(event) =>
                            event.key === 'Escape' ? setActive(null) : null
                        }
                        className="absolute inset-y-0 outline-none hover:bg-foreground/5 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset"
                        style={{ left: slot * index, width: slot }}
                    />
                ))}
            </div>
            {active !== null && sources.length > 0 ? (
                <div
                    role="tooltip"
                    className="pointer-events-none absolute top-full z-30 mt-2"
                    style={{
                        left: Math.min(
                            Math.max(left + slot * active + slot / 2 - 144, 0),
                            Math.max(width - 288, 0),
                        ),
                    }}
                >
                    <ForecastCellTooltip
                        title={
                            tooltipTitle
                                ? tooltipTitle(buckets[active])
                                : bucketLabel(buckets[active], 'week').long
                        }
                        load={cellLoad(cells[active], layers)}
                        capacity={cells[active].capacity}
                        items={cellItems(sources, active, () => true)}
                        layers={layers}
                    />
                </div>
            ) : null}
        </div>
    );
}

/** El % de una columna, con el icono de su nivel delante si es «Alta» o «Sobrecarga» (D-292). */
function LevelLabel({
    x,
    y,
    text,
    level,
}: {
    x: number;
    y: number;
    text: string;
    level: 'high' | 'over' | 'none' | 'under' | 'balanced' | null;
}) {
    const meta = level ? LOAD_LEVELS[level] : null;
    const Icon = meta?.icon;
    const width = text.length * 6;

    return (
        <g>
            {Icon && meta ? (
                <Icon
                    x={x - width / 2 - 13}
                    y={y - 9}
                    width={11}
                    height={11}
                    className={meta.tone}
                />
            ) : null}
            <text
                x={x}
                y={y}
                textAnchor="middle"
                className={cn(
                    'text-[11px] tabular-nums',
                    meta
                        ? 'fill-foreground font-medium'
                        : 'fill-muted-foreground',
                )}
            >
                {text}
            </text>
        </g>
    );
}
