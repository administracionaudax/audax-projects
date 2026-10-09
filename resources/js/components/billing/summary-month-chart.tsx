import { useState } from 'react';
import {
    Bar,
    CartesianGrid,
    ComposedChart,
    Line,
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
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { formatCurrency } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { BillingSummaryData } from '@/types';
import {
    amount,
    amountTicks,
    euroTick,
    monthTick,
    monthTitle,
} from './invoicing-lib';

/**
 * Series en orden fijo (D-012): facturado → --chart-1 (columnas), cobrado → --chart-2 (línea) y el
 * año anterior → --chart-3 (línea discontinua, opcional). Las tres con IVA y en el mismo eje: nunca
 * un segundo eje. El color sigue a la serie aunque se oculte el año anterior.
 */
export const SUMMARY_SERIES = defineSeries([
    { key: 'invoiced', label: t('billing.summary.series.invoiced') },
    { key: 'collected', label: t('billing.summary.series.collected') },
    { key: 'previous', label: t('billing.summary.series.previous') },
] as const);

type Row = {
    id: string;
    label: string;
    invoiced: number;
    collected: number;
    previous: number;
};

/**
 * Facturado y cobrado por mes (I1, D-411; INF-6), como el gráfico de lo esperado frente a lo
 * cobrado de FreeAgent: columnas de lo facturado (total con IVA, por mes de emisión), la línea de lo
 * cobrado (por la fecha de cada cobro) y, si se marca, lo facturado el mismo mes del año anterior.
 * Tooltip con el ratón y vista de tabla.
 */
export function SummaryMonthChart({
    months,
    previousYear,
}: {
    months: BillingSummaryData['months'];
    previousYear: string;
}) {
    const [compare, setCompare] = useState(false);
    const crossesYears =
        months.length > 0 &&
        months[0].month.slice(0, 4) !== months.at(-1)?.month.slice(0, 4);
    const series = SUMMARY_SERIES.filter(
        (serie) => serie.key !== 'previous' || compare,
    );
    const rows: Row[] = months.map((month) => ({
        id: month.month,
        label: monthTick(month.month, crossesYears),
        invoiced: amount(month.invoiced),
        collected: amount(month.collected),
        previous: amount(month.previous),
    }));
    const ticks = amountTicks(
        Math.min(0, ...rows.map((row) => row.invoiced)),
        Math.max(
            0,
            ...rows.flatMap((row) => [
                row.invoiced,
                row.collected,
                compare ? row.previous : 0,
            ]),
        ),
    );
    const totals = rows.reduce(
        (sum, row) => ({
            invoiced: sum.invoiced + row.invoiced,
            collected: sum.collected + row.collected,
        }),
        { invoiced: 0, collected: 0 },
    );
    const legend: LegendItem[] = legendItems(series, 'rect').map((item) =>
        item.key === 'invoiced' ? item : { ...item, shape: 'line' },
    );
    const dot = (color: string) =>
        rows.length <= 6 && {
            r: 3,
            fill: color,
            stroke: CHART_INK.surface,
            strokeWidth: SURFACE_GAP,
        };

    return (
        <div className="grid gap-2" data-test="summary-month-chart">
            <ChartFrame
                title={t('billing.summary.chart.title')}
                description={t('billing.summary.chart.description')}
                summary={t('billing.summary.chart.summary', {
                    months: months.length,
                    invoiced: formatCurrency(totals.invoiced),
                    collected: formatCurrency(totals.collected),
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
                            label: t('billing.summary.series.invoiced'),
                            numeric: true,
                        },
                        {
                            key: 'collected',
                            label: t('billing.summary.series.collected'),
                            numeric: true,
                        },
                        {
                            key: 'previous',
                            label: t('billing.summary.series.previous_year', {
                                year: previousYear,
                            }),
                            numeric: true,
                        },
                    ],
                    rows: months.map((month) => ({
                        id: month.month,
                        month: monthTitle(month.month),
                        invoiced: formatCurrency(month.invoiced),
                        collected: formatCurrency(month.collected),
                        previous: formatCurrency(month.previous),
                    })),
                }}
            >
                <ResponsiveContainer width="100%" height={260}>
                    <ComposedChart
                        accessibilityLayer={false}
                        data={rows}
                        margin={{ top: 12, right: 8, bottom: 0, left: 0 }}
                        barCategoryGap="22%"
                    >
                        <CartesianGrid
                            vertical={false}
                            stroke={CHART_INK.grid}
                        />
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
                        <Bar
                            dataKey="invoiced"
                            name={SUMMARY_SERIES[0].label}
                            fill={SUMMARY_SERIES[0].color}
                            stroke={CHART_INK.surface}
                            strokeWidth={SURFACE_GAP}
                            maxBarSize={MAX_BAR_SIZE * 2}
                            radius={BAR_RADIUS}
                            isAnimationActive={false}
                        />
                        <Line
                            dataKey="collected"
                            name={SUMMARY_SERIES[1].label}
                            type="linear"
                            stroke={SUMMARY_SERIES[1].color}
                            strokeWidth={LINE_WIDTH}
                            dot={dot(SUMMARY_SERIES[1].color)}
                            activeDot={{
                                r: 5,
                                fill: SUMMARY_SERIES[1].color,
                                stroke: CHART_INK.surface,
                                strokeWidth: SURFACE_GAP,
                            }}
                            isAnimationActive={false}
                        />
                        {compare ? (
                            <Line
                                dataKey="previous"
                                name={SUMMARY_SERIES[2].label}
                                type="linear"
                                stroke={SUMMARY_SERIES[2].color}
                                strokeWidth={LINE_WIDTH}
                                strokeDasharray="5 4"
                                dot={false}
                                activeDot={{
                                    r: 5,
                                    fill: SUMMARY_SERIES[2].color,
                                    stroke: CHART_INK.surface,
                                    strokeWidth: SURFACE_GAP,
                                }}
                                isAnimationActive={false}
                            />
                        ) : null}
                    </ComposedChart>
                </ResponsiveContainer>
            </ChartFrame>
            <div className="flex items-center gap-2">
                <Checkbox
                    id="summary-previous-year"
                    checked={compare}
                    onCheckedChange={(value) => setCompare(value === true)}
                    data-test="summary-previous-year"
                />
                <Label
                    htmlFor="summary-previous-year"
                    className="text-sm font-normal text-muted-foreground"
                >
                    {t('billing.summary.chart.compare', { year: previousYear })}
                </Label>
            </div>
        </div>
    );
}
