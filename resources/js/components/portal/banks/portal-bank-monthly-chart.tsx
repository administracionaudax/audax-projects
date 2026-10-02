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
import { formatMinutes } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { PortalBankMonth } from '@/types/portal';
import { formatMonth, formatMonthShort } from './format';

type SeriesKey = 'inBank' | 'overage';

/**
 * Series apiladas: las horas dentro de la bolsa con la serie 1 de la paleta (D-012) y el exceso
 * con el color de estado «peligro» (SPEC §8.6: el exceso, siempre en rojo). La leyenda, el tooltip
 * y la tabla nombran cada serie: nunca solo color.
 */
export const PORTAL_MONTHLY_SERIES: ReadonlyArray<{
    key: SeriesKey;
    label: string;
    color: string;
}> = [
    {
        key: 'inBank',
        label: t('portal_banks.monthly.in_bank'),
        color: seriesColor(0),
    },
    {
        key: 'overage',
        label: t('portal_banks.monthly.overage'),
        color: 'var(--danger)',
    },
];

type Row = {
    id: string;
    label: string;
    month: string;
    inBank: number;
    overage: number;
    total: number;
};

/** Filas de la gráfica y de su tabla, en el orden de los meses. */
export function monthlyRows(months: ReadonlyArray<PortalBankMonth>): Row[] {
    return months.map((month) => ({
        id: month.month,
        label: formatMonthShort(month.month),
        month: month.month,
        inBank: month.within_minutes,
        overage: month.overage_minutes,
        total: month.within_minutes + month.overage_minutes,
    }));
}

function tooltipRows(
    payload: ReadonlyArray<{ dataKey?: unknown; value?: unknown }> | undefined,
    series: typeof PORTAL_MONTHLY_SERIES,
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

/**
 * Consumo por mes de una bolsa en el portal (SPEC §11): barras apiladas de lo que va dentro de la
 * bolsa y el exceso (PortalBankFigures::byMonth), con «Ver como tabla» como alternativa accesible
 * (ChartFrame). El exceso solo aparece en la leyenda si lo hay.
 */
export function PortalBankMonthlyChart({
    months,
    height = 240,
}: {
    months: ReadonlyArray<PortalBankMonth>;
    height?: number;
}) {
    const rows = monthlyRows(months);
    const ticks = hourTicks(Math.max(0, ...rows.map((row) => row.total)));
    const title = t('portal_banks.monthly.title');
    const totalOverage = rows.reduce((sum, row) => sum + row.overage, 0);
    const series = PORTAL_MONTHLY_SERIES.filter(
        (serie) => serie.key !== 'overage' || totalOverage > 0,
    );

    return (
        <ChartFrame
            title={title}
            description={t('portal_banks.monthly.description')}
            summary={t('portal_banks.monthly.summary', {
                title,
                months: rows.length,
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
                    { key: 'month', label: t('portal_banks.monthly.month') },
                    {
                        key: 'inBank',
                        label: t('portal_banks.monthly.in_bank'),
                        numeric: true,
                    },
                    {
                        key: 'overage',
                        label: t('portal_banks.monthly.overage'),
                        numeric: true,
                    },
                    {
                        key: 'total',
                        label: t('portal_banks.monthly.total'),
                        numeric: true,
                    },
                ],
                rows: rows.map((row) => ({
                    id: row.id,
                    month: formatMonth(row.month),
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
                        content={({ active, payload }) => {
                            const row = payload?.[0]?.payload as
                                | { month?: string }
                                | undefined;

                            return active ? (
                                <ChartTooltipCard
                                    title={formatMonth(row?.month ?? '')}
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
