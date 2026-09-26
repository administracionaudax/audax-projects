import {
    Bar,
    BarChart,
    CartesianGrid,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import {
    BAR_RADIUS,
    CHART_INK,
    formatHoursTick,
    hourTicks,
    MAX_BAR_SIZE,
    seriesColor,
    SURFACE_GAP,
} from '@/components/charts/chart-config';
import type { TooltipRow } from '@/components/charts/chart-config';
import { ChartFrame } from '@/components/charts/chart-frame';
import { ChartLegend } from '@/components/charts/chart-legend';
import { ChartTooltipCard } from '@/components/charts/chart-tooltip';
import { formatDate, formatMinutes } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { HourBankWeek } from '@/types';

type SeriesKey = 'inBank' | 'overage';

/**
 * Series de la gráfica: las horas dentro de la bolsa con la serie 1 de la paleta (D-012) y el
 * exceso con el color de estado «peligro» (SPEC §8.6: el exceso siempre en rojo), apiladas.
 * El texto de la leyenda y del tooltip nombra cada serie: nunca solo color.
 */
export const HOUR_BANK_WEEKLY_SERIES: ReadonlyArray<{
    key: SeriesKey;
    label: string;
    color: string;
}> = [
    {
        key: 'inBank',
        label: t('hour_banks.chart.in_bank'),
        color: seriesColor(0),
    },
    {
        key: 'overage',
        label: t('hour_banks.chart.overage'),
        color: 'var(--danger)',
    },
];

type Payload = ReadonlyArray<{ dataKey?: unknown; value?: unknown }>;

/** Filas del tooltip en el orden de las series, con h:mm. */
function tooltipRows(
    payload: Payload | undefined,
    series: typeof HOUR_BANK_WEEKLY_SERIES,
): TooltipRow[] {
    return series.flatMap((serie) => {
        const item = payload?.find((p) => String(p.dataKey) === serie.key);

        return typeof item?.value === 'number'
            ? [
                  {
                      key: serie.key,
                      label: serie.label,
                      color: serie.color,
                      value: formatMinutes(item.value),
                  },
              ]
            : [];
    });
}

/** «21/09» a partir del lunes «2026-09-21». */
function weekLabel(weekStart: string): string {
    return formatDate(weekStart).slice(0, 5);
}

/**
 * Consumo por semana ISO de una bolsa (SPEC §8, detalle): minutos dentro de la bolsa frente a
 * exceso. Con «Ver como tabla» como alternativa accesible.
 */
export function HourBankWeeklyChart({
    weeks,
    height = 240,
}: {
    weeks: HourBankWeek[];
    height?: number;
}) {
    const rows = weeks.map((week) => ({
        id: week.week,
        label: weekLabel(week.week_start),
        weekStart: week.week_start,
        inBank: week.in_bank_minutes,
        overage: week.overage_minutes,
        total: week.in_bank_minutes + week.overage_minutes,
    }));
    const ticks = hourTicks(Math.max(0, ...rows.map((row) => row.total)));
    const title = t('hour_banks.chart.title');
    const totalOverage = rows.reduce((sum, row) => sum + row.overage, 0);
    const series = HOUR_BANK_WEEKLY_SERIES.filter(
        (serie) => serie.key !== 'overage' || totalOverage > 0,
    );

    return (
        <ChartFrame
            title={title}
            description={t('hour_banks.chart.description')}
            summary={t('hour_banks.chart.summary', {
                title,
                weeks: rows.length,
                total: formatMinutes(
                    rows.reduce((sum, row) => sum + row.total, 0),
                ),
                overage: formatMinutes(totalOverage),
            })}
            legend={
                <ChartLegend
                    items={
                        series.length > 1
                            ? series.map((serie) => ({
                                  ...serie,
                                  shape: 'rect' as const,
                              }))
                            : []
                    }
                />
            }
            table={{
                columns: [
                    { key: 'week', label: t('hour_banks.chart.column_week') },
                    {
                        key: 'inBank',
                        label: t('hour_banks.chart.in_bank'),
                        numeric: true,
                    },
                    {
                        key: 'overage',
                        label: t('hour_banks.chart.overage'),
                        numeric: true,
                    },
                    {
                        key: 'total',
                        label: t('hour_banks.chart.column_total'),
                        numeric: true,
                    },
                ],
                rows: rows.map((row) => ({
                    id: row.id,
                    week: t('charts.weekly.tooltip_title', {
                        label: formatDate(row.weekStart),
                    }),
                    inBank: formatMinutes(row.inBank),
                    overage: formatMinutes(row.overage),
                    total: formatMinutes(row.total),
                })),
            }}
        >
            <ResponsiveContainer width="100%" height={height}>
                <BarChart
                    accessibilityLayer={false}
                    data={rows}
                    margin={{ top: 8, right: 8, bottom: 0, left: 0 }}
                >
                    <CartesianGrid vertical={false} stroke={CHART_INK.grid} />
                    <XAxis
                        dataKey="label"
                        tick={{ fill: CHART_INK.axis, fontSize: 12 }}
                        tickLine={false}
                        axisLine={{ stroke: CHART_INK.grid }}
                        minTickGap={12}
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
                        content={({ active, payload }) => {
                            const row = payload?.[0]?.payload as
                                | { weekStart?: string }
                                | undefined;

                            return active ? (
                                <ChartTooltipCard
                                    title={t('charts.weekly.tooltip_title', {
                                        label: formatDate(row?.weekStart),
                                    })}
                                    rows={tooltipRows(payload, series)}
                                />
                            ) : null;
                        }}
                    />
                    {series.map((serie, index) => (
                        <Bar
                            key={serie.key}
                            dataKey={serie.key}
                            name={serie.label}
                            stackId="minutes"
                            fill={serie.color}
                            stroke={CHART_INK.surface}
                            strokeWidth={SURFACE_GAP}
                            maxBarSize={MAX_BAR_SIZE}
                            radius={
                                index === series.length - 1
                                    ? [BAR_RADIUS, BAR_RADIUS, 0, 0]
                                    : 0
                            }
                            isAnimationActive={false}
                        />
                    ))}
                </BarChart>
            </ResponsiveContainer>
        </ChartFrame>
    );
}
