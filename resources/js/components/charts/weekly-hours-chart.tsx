import {
    CartesianGrid,
    Line,
    LineChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import {
    buildTooltipRows,
    CHART_INK,
    defineSeries,
    formatHoursTick,
    hourTicks,
    legendItems,
    LINE_WIDTH,
    SURFACE_GAP,
} from '@/components/charts/chart-config';
import { ChartFrame } from '@/components/charts/chart-frame';
import { ChartLegend } from '@/components/charts/chart-legend';
import { ChartTooltipCard } from '@/components/charts/chart-tooltip';
import { formatMinutes, formatPercent } from '@/lib/format';
import { t } from '@/lib/i18n';

export type WeeklyHoursPoint = {
    /** Identificador estable (p. ej. "2026-W36"). */
    id: string;
    /** Etiqueta del eje (p. ej. "01/09"). */
    label: string;
    /** Minutos imputados en la semana. */
    logged: number;
    /** Capacidad de la semana, en minutos. */
    capacity: number;
};

/** Series en orden fijo: imputadas → var(--chart-1), capacidad → var(--chart-2). */
export const WEEKLY_HOURS_SERIES = defineSeries([
    { key: 'logged', label: t('charts.weekly.series.logged') },
    { key: 'capacity', label: t('charts.weekly.series.capacity') },
] as const);

const DIRECT_LABELS: Record<string, string> = {
    logged: t('charts.weekly.end_label.logged'),
    capacity: t('charts.weekly.end_label.capacity'),
};

type EndLabelProps = {
    x?: number | string;
    y?: number | string;
    index?: number;
};

export function WeeklyHoursChart({
    data,
    title = t('charts.weekly.title'),
    description = t('charts.weekly.description'),
    height = 260,
}: {
    data: ReadonlyArray<WeeklyHoursPoint>;
    title?: string;
    description?: string;
    height?: number;
}) {
    const max = Math.max(0, ...data.flatMap((d) => [d.logged, d.capacity]));
    const ticks = hourTicks(max);
    const last = data.at(-1);
    const upperKey =
        last && last.logged > last.capacity ? 'logged' : 'capacity';

    const endLabel =
        (key: string) =>
        ({ x, y, index }: EndLabelProps) => {
            if (
                index !== data.length - 1 ||
                x === undefined ||
                y === undefined
            ) {
                return <g />;
            }

            return (
                <text
                    x={Number(x) + 8}
                    y={Number(y)}
                    dy={key === upperKey ? -6 : 14}
                    fill={CHART_INK.label}
                    fontSize={12}
                >
                    {DIRECT_LABELS[key]}
                </text>
            );
        };

    const totalLogged = data.reduce((sum, d) => sum + d.logged, 0);
    const totalCapacity = data.reduce((sum, d) => sum + d.capacity, 0);

    return (
        <ChartFrame
            title={title}
            description={description}
            summary={t('charts.weekly.summary', {
                title,
                weeks: data.length,
                logged: formatMinutes(totalLogged),
                capacity: formatMinutes(totalCapacity),
                ratio: formatPercent(
                    totalCapacity > 0 ? totalLogged / totalCapacity : 0,
                ),
            })}
            legend={
                <ChartLegend items={legendItems(WEEKLY_HOURS_SERIES, 'line')} />
            }
            table={{
                columns: [
                    { key: 'label', label: t('charts.weekly.column.week') },
                    {
                        key: 'logged',
                        label: t('charts.weekly.column.logged'),
                        numeric: true,
                    },
                    {
                        key: 'capacity',
                        label: t('charts.weekly.column.capacity'),
                        numeric: true,
                    },
                    {
                        key: 'ratio',
                        label: t('charts.weekly.column.ratio'),
                        numeric: true,
                    },
                ],
                rows: data.map((d) => ({
                    id: d.id,
                    label: d.label,
                    logged: formatMinutes(d.logged),
                    capacity: formatMinutes(d.capacity),
                    ratio: formatPercent(
                        d.capacity > 0 ? d.logged / d.capacity : 0,
                    ),
                })),
            }}
        >
            <ResponsiveContainer width="100%" height={height}>
                <LineChart
                    accessibilityLayer={false}
                    data={[...data]}
                    margin={{ top: 12, right: 76, bottom: 0, left: 0 }}
                >
                    <CartesianGrid vertical={false} stroke={CHART_INK.grid} />
                    <XAxis
                        dataKey="label"
                        tick={{ fill: CHART_INK.axis, fontSize: 12 }}
                        tickLine={false}
                        axisLine={{ stroke: CHART_INK.grid }}
                        minTickGap={16}
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
                        cursor={{ stroke: CHART_INK.axis, strokeWidth: 1 }}
                        content={({ active, payload, label }) =>
                            active ? (
                                <ChartTooltipCard
                                    title={t('charts.weekly.tooltip_title', {
                                        label: String(label ?? ''),
                                    })}
                                    rows={buildTooltipRows(
                                        payload,
                                        WEEKLY_HOURS_SERIES,
                                    )}
                                />
                            ) : null
                        }
                    />
                    {WEEKLY_HOURS_SERIES.map((serie) => (
                        <Line
                            key={serie.key}
                            type="monotone"
                            dataKey={serie.key}
                            name={serie.label}
                            stroke={serie.color}
                            strokeWidth={LINE_WIDTH}
                            strokeLinecap="round"
                            strokeLinejoin="round"
                            dot={false}
                            activeDot={{
                                r: 4,
                                fill: serie.color,
                                stroke: CHART_INK.surface,
                                strokeWidth: SURFACE_GAP,
                            }}
                            label={endLabel(serie.key)}
                            isAnimationActive={false}
                        />
                    ))}
                </LineChart>
            </ResponsiveContainer>
        </ChartFrame>
    );
}
