import {
    Bar,
    CartesianGrid,
    ComposedChart,
    Line,
    ReferenceLine,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import {
    BAR_RADIUS,
    buildTooltipRows,
    CHART_INK,
    defineSeries,
    legendItems,
    LINE_WIDTH,
    MAX_BAR_SIZE,
    SURFACE_GAP,
} from '@/components/charts/chart-config';
import type { LegendItem } from '@/components/charts/chart-config';
import { ChartFrame } from '@/components/charts/chart-frame';
import { ChartLegend } from '@/components/charts/chart-legend';
import { ChartTooltipCard } from '@/components/charts/chart-tooltip';
import { formatCurrency } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { InvoicingReport } from '@/types';
import {
    amount,
    amountTicks,
    euroTick,
    monthTick,
    monthTitle,
    signedPercent,
    variation,
} from './invoicing-lib';

/**
 * Series de la gráfica por mes en orden fijo (D-012): facturado → --chart-1, previsto (borradores)
 * → --chart-2 y el año anterior → --chart-3. El año anterior es una referencia: línea discontinua
 * sobre el mismo eje (nunca un segundo eje). Se filtra DESPUÉS de definirlas: el color sigue a la serie.
 */
export const MONTH_SERIES = defineSeries([
    { key: 'invoiced', label: t('billing.invoicing.series.invoiced') },
    { key: 'planned', label: t('billing.invoicing.series.planned') },
    { key: 'previous', label: t('billing.invoicing.series.previous') },
] as const);

type Row = {
    id: string;
    label: string;
    invoiced: number;
    planned: number;
    previous: number | null;
};

/**
 * Facturado por mes (D-400): columnas de lo facturado con lo previsto encima y, al comparar, la línea
 * del mismo mes del año anterior. Tooltip con el ratón y vista de tabla accesible (con la variación).
 */
export function InvoicingMonthChart({
    months,
    compare,
}: {
    months: InvoicingReport['months'];
    compare: boolean;
}) {
    const crossesYears =
        months.length > 0 &&
        months[0].month.slice(0, 4) !== months.at(-1)?.month.slice(0, 4);
    const hasPlanned = months.some((month) => amount(month.planned) !== 0);
    const series = MONTH_SERIES.filter(
        (serie) =>
            (serie.key !== 'planned' || hasPlanned) &&
            (serie.key !== 'previous' || compare),
    );
    const rows: Row[] = months.map((month) => ({
        id: month.month,
        label: monthTick(month.month, crossesYears),
        invoiced: amount(month.invoiced),
        planned: amount(month.planned),
        previous: month.previous === null ? null : amount(month.previous),
    }));
    const tops = rows.flatMap((row) => [
        row.invoiced + Math.max(0, row.planned),
        row.previous ?? 0,
    ]);
    const ticks = amountTicks(
        Math.min(0, ...rows.map((row) => row.invoiced)),
        Math.max(0, ...tops),
    );
    const total = months.reduce(
        (sum, month) => sum + amount(month.invoiced),
        0,
    );
    const legend: LegendItem[] = legendItems(series, 'rect').map((item) =>
        item.key === 'previous' ? { ...item, shape: 'line' } : item,
    );

    return (
        <ChartFrame
            title={t('billing.invoicing.charts.months')}
            description={t(
                compare
                    ? 'billing.invoicing.charts.months_description_compare'
                    : 'billing.invoicing.charts.months_description',
            )}
            summary={t('billing.invoicing.charts.months_summary', {
                months: months.length,
                total: formatCurrency(total),
            })}
            legend={<ChartLegend items={legend} />}
            table={{
                columns: [
                    {
                        key: 'month',
                        label: t('billing.invoicing.columns.month'),
                    },
                    {
                        key: 'invoiced',
                        label: t('billing.invoicing.series.invoiced'),
                        numeric: true,
                    },
                    ...(hasPlanned
                        ? [
                              {
                                  key: 'planned',
                                  label: t('billing.invoicing.series.planned'),
                                  numeric: true,
                              },
                          ]
                        : []),
                    ...(compare
                        ? [
                              {
                                  key: 'previous',
                                  label: t('billing.invoicing.series.previous'),
                                  numeric: true,
                              },
                              {
                                  key: 'variation',
                                  label: t(
                                      'billing.invoicing.columns.variation',
                                  ),
                                  numeric: true,
                              },
                          ]
                        : []),
                    {
                        key: 'count',
                        label: t('billing.invoicing.columns.count'),
                        numeric: true,
                    },
                ],
                rows: months.map((month) => {
                    const change = variation(month.invoiced, month.previous);

                    return {
                        id: month.month,
                        month: monthTitle(month.month),
                        invoiced: formatCurrency(month.invoiced),
                        planned: formatCurrency(month.planned),
                        previous: formatCurrency(month.previous ?? '0'),
                        variation:
                            change === null ? '—' : signedPercent(change * 100),
                        count: String(month.count),
                    };
                }),
            }}
        >
            <ResponsiveContainer width="100%" height={300}>
                <ComposedChart
                    accessibilityLayer={false}
                    data={rows}
                    margin={{ top: 12, right: 8, bottom: 0, left: 0 }}
                    barCategoryGap="22%"
                >
                    <CartesianGrid vertical={false} stroke={CHART_INK.grid} />
                    <XAxis
                        dataKey="label"
                        tick={{ fill: CHART_INK.axis, fontSize: 12 }}
                        tickLine={false}
                        axisLine={{ stroke: CHART_INK.grid }}
                        interval="preserveStartEnd"
                        minTickGap={4}
                    />
                    <YAxis
                        ticks={ticks}
                        domain={[ticks[0] ?? 0, ticks.at(-1) ?? 'auto']}
                        tickFormatter={euroTick}
                        tick={{ fill: CHART_INK.axis, fontSize: 12 }}
                        tickLine={false}
                        axisLine={false}
                        width={56}
                    />
                    {(ticks[0] ?? 0) < 0 ? (
                        <ReferenceLine y={0} stroke={CHART_INK.axis} />
                    ) : null}
                    <Tooltip
                        isAnimationActive={false}
                        cursor={{ fill: 'var(--muted)' }}
                        content={({ active, payload, label }) => {
                            if (!active) {
                                return null;
                            }

                            const row = rows.find(
                                (item) => item.label === label,
                            );

                            return (
                                <ChartTooltipCard
                                    title={row ? monthTitle(row.id) : ''}
                                    rows={buildTooltipRows(
                                        payload,
                                        series,
                                        (value) => formatCurrency(value),
                                    )}
                                />
                            );
                        }}
                    />
                    {series
                        .filter((serie) => serie.key !== 'previous')
                        .map((serie, index, bars) => (
                            <Bar
                                key={serie.key}
                                dataKey={serie.key}
                                name={serie.label}
                                stackId="month"
                                fill={serie.color}
                                stroke={CHART_INK.surface}
                                strokeWidth={SURFACE_GAP}
                                maxBarSize={MAX_BAR_SIZE * 2}
                                radius={
                                    index === bars.length - 1
                                        ? [BAR_RADIUS, BAR_RADIUS, 0, 0]
                                        : 0
                                }
                                isAnimationActive={false}
                            />
                        ))}
                    {compare ? (
                        <Line
                            dataKey="previous"
                            name={t('billing.invoicing.series.previous')}
                            type="linear"
                            stroke={MONTH_SERIES[2].color}
                            strokeWidth={LINE_WIDTH}
                            strokeDasharray="5 4"
                            // Puntos solo con pocos meses (si no, se amontonan sobre las columnas).
                            dot={
                                rows.length <= 3 && {
                                    r: 3,
                                    fill: MONTH_SERIES[2].color,
                                    stroke: CHART_INK.surface,
                                    strokeWidth: SURFACE_GAP,
                                }
                            }
                            activeDot={{
                                r: 5,
                                fill: MONTH_SERIES[2].color,
                                stroke: CHART_INK.surface,
                                strokeWidth: SURFACE_GAP,
                            }}
                            isAnimationActive={false}
                        />
                    ) : null}
                </ComposedChart>
            </ResponsiveContainer>
        </ChartFrame>
    );
}
