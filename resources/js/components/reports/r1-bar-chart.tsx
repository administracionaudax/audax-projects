import {
    Bar,
    BarChart,
    CartesianGrid,
    LabelList,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import {
    BAR_RADIUS,
    buildTooltipRows,
    CHART_INK,
    formatHoursTick,
    hourTicks,
    legendItems,
    MAX_BAR_SIZE,
} from '@/components/charts/chart-config';
import type { SeriesDef } from '@/components/charts/chart-config';
import { ChartFrame } from '@/components/charts/chart-frame';
import { ChartLegend } from '@/components/charts/chart-legend';
import { ChartTooltipCard } from '@/components/charts/chart-tooltip';
import { formatMinutes, formatPercent } from '@/lib/format';
import { t } from '@/lib/i18n';

export type BarFormat = 'minutes' | 'percent';

export type BarRow = {
    id: string;
    label: string;
    /** Valor de cada serie: minutos o ratio (0,85 = 85 %); null sin dato. */
    values: Record<string, number | null>;
};

const ROW_HEIGHT = 32;
const LABEL_CHARS = 20;

export function formatBarValue(format: BarFormat, value: number): string {
    return format === 'percent'
        ? formatPercent(value, 0)
        : formatMinutes(value);
}

/** Marcas de porcentaje cada 25 % desde 0 hasta cubrir el máximo (al menos el 100 %). */
export function percentTicks(max: number): number[] {
    const top = Math.max(
        1,
        Math.ceil((Number.isFinite(max) ? max : 0) * 4) / 4,
    );
    const ticks: number[] = [];

    for (let value = 0; value <= top + 1e-9; value += 0.25) {
        ticks.push(Math.round(value * 100) / 100);
    }

    return ticks;
}

/** Recorta las etiquetas largas del eje (el nombre completo va en el tooltip y en la tabla). */
export function shortLabel(label: string, max = LABEL_CHARS): string {
    return label.length > max ? `${label.slice(0, max - 1)}…` : label;
}

type TickProps = {
    x?: number | string;
    y?: number | string;
    payload?: { value?: unknown };
};

function CategoryTick({ x, y, payload }: TickProps) {
    const raw = payload?.value;
    const text =
        typeof raw === 'string' || typeof raw === 'number' ? String(raw) : '';

    return (
        <g transform={`translate(${Number(x ?? 0)},${Number(y ?? 0)})`}>
            <title>{text}</title>
            <text
                x={-6}
                y={0}
                dy={4}
                textAnchor="end"
                fill={CHART_INK.label}
                fontSize={12}
            >
                {shortLabel(text)}
            </text>
        </g>
    );
}

/**
 * Barras horizontales de un reparto (por departamento, cliente, miembro…): una o varias series en
 * el orden fijo de la paleta (D-012), en horas (h:mm) o en porcentaje, con la vista de tabla
 * accesible. Con una sola serie, cada barra lleva su valor al final.
 */
export function R1BarChart({
    title,
    description,
    categoryLabel,
    rows,
    series,
    format,
}: {
    title: string;
    description?: string;
    /** Cabecera de la primera columna de la tabla («Cliente», «Persona»…). */
    categoryLabel: string;
    rows: BarRow[];
    series: SeriesDef[];
    format: BarFormat;
}) {
    const data = rows.map((row) => ({
        id: row.id,
        label: row.label,
        ...Object.fromEntries(
            series.map((serie) => [serie.key, row.values[serie.key] ?? 0]),
        ),
    }));
    const max = Math.max(
        0,
        ...rows.flatMap((row) =>
            series.map((serie) => row.values[serie.key] ?? 0),
        ),
    );
    const ticks = format === 'percent' ? percentTicks(max) : hourTicks(max, 4);
    const tickFormatter =
        format === 'percent'
            ? (value: number) => formatPercent(value, 0)
            : formatHoursTick;
    const valueFormatter = (value: number) => formatBarValue(format, value);
    const single = series.length === 1;
    const height =
        rows.length * ROW_HEIGHT * Math.max(1, series.length * 0.75) + 28;

    return (
        <ChartFrame
            title={title}
            description={description}
            summary={t('reports_r1.bars.summary', {
                title,
                items: rows
                    .map((row) =>
                        t('reports_r1.bars.summary_item', {
                            label: row.label,
                            values: series
                                .map(
                                    (serie) =>
                                        `${serie.label} ${
                                            row.values[serie.key] === null
                                                ? '—'
                                                : valueFormatter(
                                                      row.values[serie.key] ??
                                                          0,
                                                  )
                                        }`,
                                )
                                .join(', '),
                        }),
                    )
                    .join('; '),
            })}
            legend={<ChartLegend items={legendItems(series, 'rect')} />}
            table={{
                columns: [
                    { key: 'label', label: categoryLabel },
                    ...series.map((serie) => ({
                        key: serie.key,
                        label: serie.label,
                        numeric: true,
                    })),
                ],
                rows: rows.map((row) => ({
                    id: row.id,
                    label: row.label,
                    ...Object.fromEntries(
                        series.map((serie) => {
                            const value = row.values[serie.key];

                            return [
                                serie.key,
                                value === null || value === undefined
                                    ? '—'
                                    : valueFormatter(value),
                            ];
                        }),
                    ),
                })),
            }}
        >
            <ResponsiveContainer width="100%" height={height}>
                <BarChart
                    accessibilityLayer={false}
                    data={data}
                    layout="vertical"
                    margin={{
                        top: 0,
                        right: single ? 56 : 12,
                        bottom: 0,
                        left: 0,
                    }}
                    barCategoryGap={6}
                    barGap={2}
                >
                    <CartesianGrid horizontal={false} stroke={CHART_INK.grid} />
                    <XAxis
                        type="number"
                        ticks={ticks}
                        domain={[0, ticks.at(-1) ?? 'auto']}
                        tickFormatter={tickFormatter}
                        tick={{ fill: CHART_INK.axis, fontSize: 12 }}
                        tickLine={false}
                        axisLine={false}
                    />
                    <YAxis
                        type="category"
                        dataKey="label"
                        width={128}
                        tick={<CategoryTick />}
                        tickLine={false}
                        axisLine={{ stroke: CHART_INK.grid }}
                        interval={0}
                    />
                    <Tooltip
                        isAnimationActive={false}
                        cursor={{ fill: 'var(--muted)' }}
                        content={({ active, payload, label }) =>
                            active ? (
                                <ChartTooltipCard
                                    title={String(label ?? '')}
                                    rows={buildTooltipRows(
                                        payload,
                                        series,
                                        valueFormatter,
                                    )}
                                />
                            ) : null
                        }
                    />
                    {series.map((serie) => (
                        <Bar
                            key={serie.key}
                            dataKey={serie.key}
                            name={serie.label}
                            fill={serie.color}
                            maxBarSize={MAX_BAR_SIZE}
                            radius={[0, BAR_RADIUS, BAR_RADIUS, 0]}
                            isAnimationActive={false}
                        >
                            {single ? (
                                <LabelList
                                    dataKey={serie.key}
                                    position="right"
                                    offset={8}
                                    fill={CHART_INK.label}
                                    fontSize={12}
                                    formatter={(value: unknown) =>
                                        typeof value === 'number'
                                            ? valueFormatter(value)
                                            : ''
                                    }
                                />
                            ) : null}
                        </Bar>
                    ))}
                </BarChart>
            </ResponsiveContainer>
        </ChartFrame>
    );
}
