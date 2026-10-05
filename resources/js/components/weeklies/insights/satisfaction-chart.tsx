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
    LINE_WIDTH,
    SURFACE_GAP,
} from '@/components/charts/chart-config';
import { ChartFrame } from '@/components/charts/chart-frame';
import { ChartTooltipCard } from '@/components/charts/chart-tooltip';
import { t } from '@/lib/i18n';
import type { ClientSatisfactionPointRow } from '@/types/weekly-insights';

/** Una sola serie: la satisfacción → var(--chart-1) (D-012). */
export const SATISFACTION_SERIES = defineSeries([
    { key: 'score', label: t('weeklies.client.chart_series') },
] as const);

/** «+3 pts», «-2 pts», «0 pts» o «N/D» (formatDelta de ClientView.tsx). */
export function formatPoints(delta: number | null): string {
    if (delta === null) {
        return t('weeklies.client.delta_none');
    }

    const rounded = Math.round(delta);

    return t('weeklies.client.delta_points', {
        sign: rounded > 0 ? '+' : '',
        points: rounded,
    });
}

/**
 * Evolución de la satisfacción de un cliente (F-132): una línea con la puntuación de cada cierre, de
 * 0 a 100, con su tabla para quien no ve la gráfica.
 */
export function SatisfactionChart({
    clientName,
    points,
    height = 240,
}: {
    clientName: string;
    points: ClientSatisfactionPointRow[];
    height?: number;
}) {
    const data = points.map((point) => ({
        id: String(point.cycle_id),
        label: point.number,
        week: point.label,
        score: point.score,
    }));

    return (
        <ChartFrame
            title={t('weeklies.client.satisfaction_title')}
            description={t('weeklies.client.satisfaction_description')}
            summary={t('weeklies.client.chart_summary', {
                client: clientName,
                weeks: points.length,
                last: points.at(-1)?.score ?? 0,
            })}
            table={{
                columns: [
                    { key: 'label', label: t('weeklies.client.chart_week') },
                    {
                        key: 'score',
                        label: t('weeklies.client.chart_score'),
                        numeric: true,
                    },
                    {
                        key: 'delta',
                        label: t('weeklies.client.chart_delta'),
                        numeric: true,
                    },
                ],
                rows: points.map((point) => ({
                    id: String(point.cycle_id),
                    label: point.label,
                    score: `${point.score} %`,
                    delta: formatPoints(point.delta),
                })),
            }}
        >
            <ResponsiveContainer width="100%" height={height}>
                <LineChart
                    accessibilityLayer={false}
                    data={data}
                    margin={{ top: 12, right: 16, bottom: 0, left: 0 }}
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
                        domain={[0, 100]}
                        ticks={[0, 25, 50, 75, 100]}
                        tickFormatter={(value: number) => `${value} %`}
                        tick={{ fill: CHART_INK.axis, fontSize: 12 }}
                        tickLine={false}
                        axisLine={false}
                        width={48}
                    />
                    <Tooltip
                        isAnimationActive={false}
                        cursor={{ stroke: CHART_INK.axis, strokeWidth: 1 }}
                        content={({ active, payload }) => {
                            const row = payload?.[0]?.payload as
                                | { week?: string }
                                | undefined;

                            return active ? (
                                <ChartTooltipCard
                                    title={t('weeklies.client.chart_tooltip', {
                                        label: row?.week ?? '',
                                    })}
                                    rows={buildTooltipRows(
                                        payload,
                                        SATISFACTION_SERIES,
                                        (value) => `${value} %`,
                                    )}
                                />
                            ) : null;
                        }}
                    />
                    {SATISFACTION_SERIES.map((serie) => (
                        <Line
                            key={serie.key}
                            type="monotone"
                            dataKey={serie.key}
                            name={serie.label}
                            stroke={serie.color}
                            strokeWidth={LINE_WIDTH}
                            strokeLinecap="round"
                            strokeLinejoin="round"
                            dot={{ r: 3, fill: serie.color, strokeWidth: 0 }}
                            activeDot={{
                                r: 4,
                                fill: serie.color,
                                stroke: CHART_INK.surface,
                                strokeWidth: SURFACE_GAP,
                            }}
                            isAnimationActive={false}
                        />
                    ))}
                </LineChart>
            </ResponsiveContainer>
        </ChartFrame>
    );
}
