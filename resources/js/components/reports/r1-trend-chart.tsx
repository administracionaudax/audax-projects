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
import type { SeriesDef } from '@/components/charts/chart-config';
import { ChartFrame } from '@/components/charts/chart-frame';
import { ChartLegend } from '@/components/charts/chart-legend';
import { ChartTooltipCard } from '@/components/charts/chart-tooltip';
import {
    formatCurrency,
    formatDate,
    formatMinutes,
    formatNumber,
    formatPercent,
    LOCALE,
} from '@/lib/format';
import { t } from '@/lib/i18n';
import type { SeriesPoint } from '@/types';

export type TrendBucket = 'semana' | 'mes';

/**
 * Series de la evolución en orden fijo (D-012): imputadas → --chart-1, capacidad → --chart-2,
 * facturables → --chart-3 e ingreso → --chart-4. El ingreso va en su propia gráfica (euros): se
 * filtra DESPUÉS de definir las series, así conserva su color.
 */
export const EVOLUTION_SERIES = defineSeries([
    { key: 'logged', label: t('reports_r1.evolution.logged') },
    { key: 'capacity', label: t('reports_r1.evolution.capacity') },
    { key: 'billable', label: t('reports_r1.evolution.billable') },
    { key: 'income', label: t('reports_r1.evolution.income') },
] as const);

const HOUR_SERIES = EVOLUTION_SERIES.filter((serie) => serie.key !== 'income');
const INCOME_SERIES = EVOLUTION_SERIES.filter(
    (serie) => serie.key === 'income',
);

const monthShort = new Intl.DateTimeFormat(LOCALE, {
    month: 'short',
    year: '2-digit',
    timeZone: 'UTC',
});
const monthLong = new Intl.DateTimeFormat(LOCALE, {
    month: 'long',
    year: 'numeric',
    timeZone: 'UTC',
});

function utc(date: string): number {
    const [year, month, day] = date.split('-').map(Number);

    return Date.UTC(year, month - 1, day);
}

/** Etiqueta del eje: «21/09» (lunes de la semana) o «sept 26». */
export function bucketLabel(bucket: TrendBucket, date: string): string {
    return bucket === 'semana'
        ? formatDate(date).slice(0, 5)
        : monthShort.format(utc(date)).replace('.', '');
}

/** Título del tooltip y de la tabla: «Semana del 21/09/2026» o «septiembre de 2026». */
export function bucketTitle(bucket: TrendBucket, date: string): string {
    return bucket === 'semana'
        ? t('reports_r1.evolution.week_of', { date: formatDate(date) })
        : monthLong.format(utc(date));
}

/** Marcas «limpias» de euros (1, 2, 2,5, 5, 10… × 10ⁿ) desde 0. */
export function currencyTicks(max: number, targetCount = 5): number[] {
    if (!Number.isFinite(max) || max <= 0) {
        return [0];
    }

    const raw = max / Math.max(targetCount - 1, 1);
    const magnitude = 10 ** Math.floor(Math.log10(raw));
    const step =
        [1, 2, 2.5, 5, 10].map((m) => m * magnitude).find((s) => s >= raw) ??
        10 * magnitude;
    const ticks: number[] = [];

    for (let value = 0; value < max + step; value += step) {
        ticks.push(value);

        if (value >= max) {
            break;
        }
    }

    return ticks;
}

function formatEuroTick(value: number): string {
    return value >= 1000
        ? `${formatNumber(value / 1000, 1)} k€`
        : `${formatNumber(value, 0)} €`;
}

type Row = Record<string, number | string> & { id: string; label: string };

function TrendLines({
    rows,
    series,
    ticks,
    tickFormatter,
    valueFormatter,
    bucket,
    height,
}: {
    rows: Row[];
    series: SeriesDef[];
    ticks: number[];
    tickFormatter: (value: number) => string;
    valueFormatter: (value: number) => string;
    bucket: TrendBucket;
    height: number;
}) {
    return (
        <ResponsiveContainer width="100%" height={height}>
            <LineChart
                accessibilityLayer={false}
                data={rows}
                margin={{ top: 12, right: 12, bottom: 0, left: 0 }}
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
                    tickFormatter={tickFormatter}
                    tick={{ fill: CHART_INK.axis, fontSize: 12 }}
                    tickLine={false}
                    axisLine={false}
                    width={56}
                />
                <Tooltip
                    isAnimationActive={false}
                    cursor={{ stroke: CHART_INK.axis, strokeWidth: 1 }}
                    content={({ active, payload, label }) => {
                        if (!active) {
                            return null;
                        }

                        const row = rows.find((item) => item.label === label);

                        return (
                            <ChartTooltipCard
                                title={row ? bucketTitle(bucket, row.id) : ''}
                                rows={buildTooltipRows(
                                    payload,
                                    series,
                                    valueFormatter,
                                )}
                            />
                        );
                    }}
                />
                {series.map((serie) => (
                    <Line
                        key={serie.key}
                        type="monotone"
                        dataKey={serie.key}
                        name={serie.label}
                        stroke={serie.color}
                        strokeWidth={LINE_WIDTH}
                        strokeLinecap="round"
                        strokeLinejoin="round"
                        strokeDasharray={
                            serie.key === 'capacity' ? '4 4' : undefined
                        }
                        dot={rows.length === 1}
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
    );
}

/**
 * Evolución de las horas (SPEC §10.1): imputadas, capacidad (discontinua) y facturables por semana
 * o por mes, con la vista de tabla accesible (y la ocupación de cada periodo).
 */
export function R1HoursTrendChart({
    points,
    bucket,
    title,
    description,
    height = 280,
}: {
    points: SeriesPoint[];
    bucket: TrendBucket;
    title: string;
    description?: string;
    height?: number;
}) {
    const rows: Row[] = points.map((point) => ({
        id: point.bucket,
        label: bucketLabel(bucket, point.bucket),
        logged: point.logged_minutes,
        capacity: point.capacity_minutes,
        billable: point.billable_minutes,
    }));
    const max = Math.max(
        0,
        ...points.flatMap((p) => [
            p.logged_minutes,
            p.capacity_minutes,
            p.billable_minutes,
        ]),
    );
    const total = (key: keyof SeriesPoint) =>
        points.reduce((sum, p) => sum + Number(p[key] ?? 0), 0);

    return (
        <ChartFrame
            title={title}
            description={description}
            summary={t('reports_r1.evolution.summary', {
                title,
                periods: points.length,
                logged: formatMinutes(total('logged_minutes')),
                billable: formatMinutes(total('billable_minutes')),
                capacity: formatMinutes(total('capacity_minutes')),
            })}
            legend={<ChartLegend items={legendItems(HOUR_SERIES, 'line')} />}
            table={{
                columns: [
                    { key: 'period', label: t('reports_r1.columns.period') },
                    {
                        key: 'logged',
                        label: t('reports_r1.columns.logged'),
                        numeric: true,
                    },
                    {
                        key: 'billable',
                        label: t('reports_r1.columns.billable'),
                        numeric: true,
                    },
                    {
                        key: 'capacity',
                        label: t('reports_r1.columns.capacity'),
                        numeric: true,
                    },
                    {
                        key: 'occupancy',
                        label: t('reports_r1.columns.occupancy'),
                        numeric: true,
                    },
                ],
                rows: points.map((p) => ({
                    id: p.bucket,
                    period: bucketTitle(bucket, p.bucket),
                    logged: formatMinutes(p.logged_minutes),
                    billable: formatMinutes(p.billable_minutes),
                    capacity: formatMinutes(p.capacity_minutes),
                    occupancy:
                        p.capacity_minutes > 0
                            ? formatPercent(
                                  p.logged_minutes / p.capacity_minutes,
                              )
                            : '—',
                })),
            }}
        >
            <TrendLines
                rows={rows}
                series={HOUR_SERIES}
                ticks={hourTicks(max)}
                tickFormatter={formatHoursTick}
                valueFormatter={formatMinutes}
                bucket={bucket}
                height={height}
            />
        </ChartFrame>
    );
}

/**
 * Evolución del ingreso estimado (solo con view-financials), en euros y en su propia gráfica.
 */
export function R1IncomeTrendChart({
    points,
    bucket,
    title,
    total,
    description,
    height = 220,
}: {
    points: SeriesPoint[];
    bucket: TrendBucket;
    title: string;
    /** Ingreso del periodo (Metrics::summary), para el resumen accesible. */
    total: string | null;
    description?: string;
    height?: number;
}) {
    const values = points.map((p) => Number(p.income ?? 0));
    const rows: Row[] = points.map((point, index) => ({
        id: point.bucket,
        label: bucketLabel(bucket, point.bucket),
        income: values[index],
    }));

    return (
        <ChartFrame
            title={title}
            description={description}
            summary={t('reports_r1.evolution.income_summary', {
                title,
                periods: points.length,
                total: formatCurrency(total ?? '0'),
            })}
            table={{
                columns: [
                    { key: 'period', label: t('reports_r1.columns.period') },
                    {
                        key: 'income',
                        label: t('reports_r1.columns.income'),
                        numeric: true,
                    },
                ],
                rows: points.map((p) => ({
                    id: p.bucket,
                    period: bucketTitle(bucket, p.bucket),
                    income: formatCurrency(p.income ?? '0'),
                })),
            }}
        >
            <TrendLines
                rows={rows}
                series={INCOME_SERIES}
                ticks={currencyTicks(Math.max(0, ...values))}
                tickFormatter={formatEuroTick}
                valueFormatter={(value) => formatCurrency(value)}
                bucket={bucket}
                height={height}
            />
        </ChartFrame>
    );
}
