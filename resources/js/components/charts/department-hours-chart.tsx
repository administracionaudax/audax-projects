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
    MAX_BAR_SIZE,
} from '@/components/charts/chart-config';
import { ChartFrame } from '@/components/charts/chart-frame';
import { ChartTooltipCard } from '@/components/charts/chart-tooltip';
import { formatMinutes } from '@/lib/format';

export type CategoryMinutes = {
    id: string;
    label: string;
    minutes: number;
};

/** Una sola serie → un solo color (var(--chart-1)) y sin leyenda: el título ya la nombra. */
export const DEPARTMENT_HOURS_SERIES = defineSeries([
    { key: 'minutes', label: 'Horas imputadas' },
] as const);

const ROW_HEIGHT = 36;

export function DepartmentHoursChart({
    data,
    title = 'Horas imputadas por departamento',
    description = 'Mes en curso.',
}: {
    data: ReadonlyArray<CategoryMinutes>;
    title?: string;
    description?: string;
}) {
    const [serie] = DEPARTMENT_HOURS_SERIES;
    const ticks = hourTicks(Math.max(0, ...data.map((d) => d.minutes)), 4);
    const height = data.length * ROW_HEIGHT + 28;

    return (
        <ChartFrame
            title={title}
            description={description}
            summary={`${title}: ${data.map((d) => `${d.label} ${formatMinutes(d.minutes)}`).join(', ')}.`}
            table={{
                columns: [
                    { key: 'label', label: 'Departamento' },
                    { key: 'minutes', label: 'Horas', numeric: true },
                ],
                rows: data.map((d) => ({
                    id: d.id,
                    label: d.label,
                    minutes: formatMinutes(d.minutes),
                })),
            }}
        >
            <ResponsiveContainer width="100%" height={height}>
                <BarChart
                    data={[...data]}
                    layout="vertical"
                    margin={{ top: 0, right: 56, bottom: 0, left: 0 }}
                    barCategoryGap={8}
                >
                    <CartesianGrid horizontal={false} stroke={CHART_INK.grid} />
                    <XAxis
                        type="number"
                        ticks={ticks}
                        domain={[0, ticks.at(-1) ?? 'auto']}
                        tickFormatter={formatHoursTick}
                        tick={{ fill: CHART_INK.axis, fontSize: 12 }}
                        tickLine={false}
                        axisLine={false}
                    />
                    <YAxis
                        type="category"
                        dataKey="label"
                        width={96}
                        tick={{ fill: CHART_INK.label, fontSize: 13 }}
                        tickLine={false}
                        axisLine={{ stroke: CHART_INK.grid }}
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
                                        DEPARTMENT_HOURS_SERIES,
                                    )}
                                />
                            ) : null
                        }
                    />
                    <Bar
                        dataKey={serie.key}
                        name={serie.label}
                        fill={serie.color}
                        maxBarSize={MAX_BAR_SIZE}
                        radius={[0, BAR_RADIUS, BAR_RADIUS, 0]}
                        isAnimationActive={false}
                    >
                        <LabelList
                            dataKey={serie.key}
                            position="right"
                            offset={8}
                            fill={CHART_INK.label}
                            fontSize={12}
                            formatter={(value: unknown) =>
                                typeof value === 'number'
                                    ? formatMinutes(value)
                                    : ''
                            }
                        />
                    </Bar>
                </BarChart>
            </ResponsiveContainer>
        </ChartFrame>
    );
}
