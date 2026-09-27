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
    CHART_COLORS,
    CHART_INK,
    defineSeries,
    formatHoursTick,
    hourTicks,
    legendItems,
    MAX_BAR_SIZE,
    SURFACE_GAP,
} from '@/components/charts/chart-config';
import { ChartFrame } from '@/components/charts/chart-frame';
import { ChartLegend } from '@/components/charts/chart-legend';
import { ChartTooltipCard } from '@/components/charts/chart-tooltip';
import { formatMinutes } from '@/lib/format';
import { t } from '@/lib/i18n';

export type R2StackSeries = { key: string; label: string };

export type R2StackBucket = {
    id: string;
    /** Etiqueta corta del eje. */
    label: string;
    /** Etiqueta larga para la tabla y el tooltip (por defecto, la corta). */
    longLabel?: string;
};

/** Minutos por serie y cubo: values[serie][cubo]. */
export type R2StackValues = Record<string, Record<string, number>>;

export const R2_OTHERS_KEY = '__others__';

/**
 * D-012: la paleta categórica tiene 6 colores y nunca se cicla. Con más series, se quedan las
 * 5 primeras (llegan ordenadas de más a menos horas) y el resto se suma en «Otros».
 */
export function groupSeries(
    series: ReadonlyArray<R2StackSeries>,
    values: R2StackValues,
    othersLabel: string,
): { series: R2StackSeries[]; values: R2StackValues } {
    if (series.length <= CHART_COLORS.length) {
        return { series: [...series], values };
    }

    const keep = series.slice(0, CHART_COLORS.length - 1);
    const grouped: R2StackValues = {};
    const others: Record<string, number> = {};

    for (const serie of keep) {
        grouped[serie.key] = values[serie.key] ?? {};
    }

    for (const serie of series.slice(CHART_COLORS.length - 1)) {
        for (const [bucket, minutes] of Object.entries(
            values[serie.key] ?? {},
        )) {
            others[bucket] = (others[bucket] ?? 0) + minutes;
        }
    }

    grouped[R2_OTHERS_KEY] = others;

    return {
        series: [...keep, { key: R2_OTHERS_KEY, label: othersLabel }],
        values: grouped,
    };
}

/**
 * Barras apiladas de horas por cubo de tiempo (mes o semana) y serie (proyectos, facturables…),
 * con los colores de la paleta en orden fijo (D-012), leyenda, total encima de cada barra y la
 * vista de tabla accesible de ChartFrame. Con más de 6 series agrupa el resto en «Otros».
 */
export function R2StackedBarsChart({
    title,
    description,
    bucketColumn,
    buckets,
    series,
    values,
    height = 280,
}: {
    title: string;
    description?: string;
    /** Cabecera de la primera columna de la tabla («Mes», «Semana»). */
    bucketColumn: string;
    buckets: ReadonlyArray<R2StackBucket>;
    series: ReadonlyArray<R2StackSeries>;
    values: R2StackValues;
    height?: number;
}) {
    const grouped = groupSeries(series, values, t('reports_r2.chart.others'));
    // Claves internas s0…s5: los ids de proyecto no sirven como dataKey de Recharts.
    const defs = defineSeries(
        grouped.series.map((serie, index) => ({
            key: `s${index}`,
            label: serie.label,
        })),
    );
    const rows = buckets.map((bucket) => {
        const row: Record<string, number | string> = {
            id: bucket.id,
            label: bucket.label,
        };
        let total = 0;

        grouped.series.forEach((serie, index) => {
            const minutes = grouped.values[serie.key]?.[bucket.id] ?? 0;
            row[`s${index}`] = minutes;
            total += minutes;
        });
        row.total = total;

        return row;
    });
    const totals = rows.map((row) => Number(row.total));
    const ticks = hourTicks(Math.max(0, ...totals));
    const lastIndex = defs.length - 1;
    const grandTotal = totals.reduce((sum, value) => sum + value, 0);

    return (
        <ChartFrame
            title={title}
            description={description}
            summary={t('reports_r2.chart.stacked_summary', {
                title,
                buckets: buckets.length,
                total: formatMinutes(grandTotal),
                series: grouped.series
                    .map((serie) =>
                        t('reports_r2.chart.summary_item', {
                            label: serie.label,
                            minutes: formatMinutes(
                                Object.values(
                                    grouped.values[serie.key] ?? {},
                                ).reduce((sum, value) => sum + value, 0),
                            ),
                        }),
                    )
                    .join(', '),
            })}
            legend={<ChartLegend items={legendItems(defs, 'rect')} />}
            table={{
                columns: [
                    { key: 'label', label: bucketColumn },
                    ...defs.map((def) => ({
                        key: def.key,
                        label: def.label,
                        numeric: true,
                    })),
                    {
                        key: 'total',
                        label: t('reports_r2.chart.total'),
                        numeric: true,
                    },
                ],
                rows: rows.map((row, index) => ({
                    id: String(row.id),
                    label: buckets[index].longLabel ?? buckets[index].label,
                    ...Object.fromEntries(
                        defs.map((def) => [
                            def.key,
                            formatMinutes(Number(row[def.key])),
                        ]),
                    ),
                    total: formatMinutes(Number(row.total)),
                })),
            }}
        >
            <ResponsiveContainer width="100%" height={height}>
                <BarChart
                    accessibilityLayer={false}
                    data={rows}
                    margin={{ top: 20, right: 8, bottom: 0, left: 0 }}
                >
                    <CartesianGrid vertical={false} stroke={CHART_INK.grid} />
                    <XAxis
                        dataKey="label"
                        tick={{ fill: CHART_INK.axis, fontSize: 12 }}
                        tickLine={false}
                        axisLine={{ stroke: CHART_INK.grid }}
                        minTickGap={8}
                    />
                    <YAxis
                        ticks={ticks}
                        domain={[0, ticks.at(-1) ?? 'auto']}
                        tickFormatter={formatHoursTick}
                        tick={{ fill: CHART_INK.axis, fontSize: 12 }}
                        tickLine={false}
                        axisLine={false}
                        width={56}
                    />
                    <Tooltip
                        isAnimationActive={false}
                        cursor={{ fill: 'var(--muted)' }}
                        content={({ active, payload, label }) =>
                            active ? (
                                <ChartTooltipCard
                                    title={String(label ?? '')}
                                    rows={buildTooltipRows(payload, defs)}
                                />
                            ) : null
                        }
                    />
                    {defs.map((def, index) => (
                        <Bar
                            key={def.key}
                            dataKey={def.key}
                            name={def.label}
                            stackId="hours"
                            fill={def.color}
                            stroke={CHART_INK.surface}
                            strokeWidth={SURFACE_GAP}
                            maxBarSize={MAX_BAR_SIZE * 2}
                            radius={
                                index === lastIndex
                                    ? [BAR_RADIUS, BAR_RADIUS, 0, 0]
                                    : 0
                            }
                            isAnimationActive={false}
                        >
                            {index === lastIndex && buckets.length <= 16 ? (
                                <LabelList
                                    dataKey="total"
                                    position="top"
                                    offset={6}
                                    fill={CHART_INK.label}
                                    fontSize={12}
                                    formatter={(value: unknown) =>
                                        typeof value === 'number' && value > 0
                                            ? formatMinutes(value)
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
