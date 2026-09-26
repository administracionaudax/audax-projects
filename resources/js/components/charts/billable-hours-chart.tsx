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
import { formatMinutes, formatPercent } from '@/lib/format';

export type BillablePoint = {
    id: string;
    label: string;
    billable: number;
    nonBillable: number;
};

/** Orden fijo: facturable → var(--chart-1) (abajo), no facturable → var(--chart-2) (arriba). */
export const BILLABLE_SERIES = defineSeries([
    { key: 'billable', label: 'Facturable' },
    { key: 'nonBillable', label: 'No facturable' },
] as const);

export function BillableHoursChart({
    data,
    title = 'Horas facturables y no facturables',
    description = 'Por mes. El total va sobre cada columna.',
    height = 260,
}: {
    data: ReadonlyArray<BillablePoint>;
    title?: string;
    description?: string;
    height?: number;
}) {
    const rows = data.map((d) => ({ ...d, total: d.billable + d.nonBillable }));
    const ticks = hourTicks(Math.max(0, ...rows.map((d) => d.total)));
    const lastIndex = BILLABLE_SERIES.length - 1;

    return (
        <ChartFrame
            title={title}
            description={description}
            summary={`${title}: ${rows.map((d) => `${d.label} ${formatMinutes(d.billable)} facturables de ${formatMinutes(d.total)}`).join('; ')}.`}
            legend={
                <ChartLegend items={legendItems(BILLABLE_SERIES, 'rect')} />
            }
            table={{
                columns: [
                    { key: 'label', label: 'Mes' },
                    { key: 'billable', label: 'Facturable', numeric: true },
                    {
                        key: 'nonBillable',
                        label: 'No facturable',
                        numeric: true,
                    },
                    { key: 'total', label: 'Total', numeric: true },
                    { key: 'ratio', label: 'Facturabilidad', numeric: true },
                ],
                rows: rows.map((d) => ({
                    id: d.id,
                    label: d.label,
                    billable: formatMinutes(d.billable),
                    nonBillable: formatMinutes(d.nonBillable),
                    total: formatMinutes(d.total),
                    ratio: formatPercent(
                        d.total > 0 ? d.billable / d.total : 0,
                    ),
                })),
            }}
        >
            <ResponsiveContainer width="100%" height={height}>
                <BarChart
                    data={rows}
                    margin={{ top: 20, right: 8, bottom: 0, left: 0 }}
                >
                    <CartesianGrid vertical={false} stroke={CHART_INK.grid} />
                    <XAxis
                        dataKey="label"
                        tick={{ fill: CHART_INK.axis, fontSize: 12 }}
                        tickLine={false}
                        axisLine={{ stroke: CHART_INK.grid }}
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
                                    rows={buildTooltipRows(
                                        payload,
                                        BILLABLE_SERIES,
                                    )}
                                />
                            ) : null
                        }
                    />
                    {BILLABLE_SERIES.map((serie, index) => (
                        <Bar
                            key={serie.key}
                            dataKey={serie.key}
                            name={serie.label}
                            stackId="hours"
                            fill={serie.color}
                            stroke={CHART_INK.surface}
                            strokeWidth={SURFACE_GAP}
                            maxBarSize={MAX_BAR_SIZE}
                            radius={
                                index === lastIndex
                                    ? [BAR_RADIUS, BAR_RADIUS, 0, 0]
                                    : 0
                            }
                            isAnimationActive={false}
                        >
                            {index === lastIndex ? (
                                <LabelList
                                    dataKey="total"
                                    position="top"
                                    offset={6}
                                    fill={CHART_INK.label}
                                    fontSize={12}
                                    formatter={(value: unknown) =>
                                        typeof value === 'number'
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
