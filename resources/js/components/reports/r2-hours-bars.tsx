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
    CHART_INK,
    defineSeries,
    formatHoursTick,
    hourTicks,
    MAX_BAR_SIZE,
    SURFACE_GAP,
} from '@/components/charts/chart-config';
import type { LegendItem } from '@/components/charts/chart-config';
import { ChartFrame } from '@/components/charts/chart-frame';
import { ChartLegend } from '@/components/charts/chart-legend';
import { ChartTooltipCard } from '@/components/charts/chart-tooltip';
import { formatOverage } from '@/components/reports/r2-helpers';
import { cartesianBox } from '@/components/reports/r2-stacked-bars-chart';
import { formatMinutes } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { BreakdownRow } from '@/types';

/** Máximo de barras: el resto se suma en «Otros» para que la gráfica se lea en el móvil. */
export const R2_MAX_BARS = 10;

/** Una sola serie categórica (var(--chart-1)); el exceso va aparte, en el rojo de estado. */
const HOURS_SERIES = defineSeries([
    { key: 'inside', label: t('reports_r2.chart.hours') },
] as const);

const OVERAGE_COLOR = 'var(--danger)';

const ROW_HEIGHT = 34;

type BarRow = {
    id: string;
    label: string;
    inside: number;
    overage: number;
    logged: number;
    billable: number;
};

/** Filas para la gráfica: las primeras R2_MAX_BARS y «Otros» con la suma del resto. */
export function barRows(
    rows: ReadonlyArray<BreakdownRow>,
    othersLabel: string,
): BarRow[] {
    const toRow = (row: BreakdownRow, index: number): BarRow => ({
        id: row.key ?? `none-${index}`,
        label: row.name,
        inside: row.logged_minutes - row.overage_minutes,
        overage: row.overage_minutes,
        logged: row.logged_minutes,
        billable: row.billable_minutes,
    });

    if (rows.length <= R2_MAX_BARS) {
        return rows.map(toRow);
    }

    const rest = rows.slice(R2_MAX_BARS - 1);

    return [
        ...rows.slice(0, R2_MAX_BARS - 1).map(toRow),
        rest.reduce<BarRow>(
            (sum, row) => ({
                ...sum,
                inside: sum.inside + row.logged_minutes - row.overage_minutes,
                overage: sum.overage + row.overage_minutes,
                logged: sum.logged + row.logged_minutes,
                billable: sum.billable + row.billable_minutes,
            }),
            {
                id: 'others',
                label: othersLabel,
                inside: 0,
                overage: 0,
                logged: 0,
                billable: 0,
            },
        ),
    ];
}

function shorten(label: string, max = 22): string {
    return label.length > max ? `${label.slice(0, max - 1)}…` : label;
}

/**
 * Horas por persona o por tipo de tarea en barras horizontales: lo imputado dentro de la bolsa
 * (o sin bolsa) en azul y el exceso a continuación, en rojo (SPEC §8.6), con su vista de tabla.
 */
export function R2HoursBars({
    title,
    description,
    firstColumn,
    rows,
}: {
    title: string;
    description?: string;
    firstColumn: string;
    rows: ReadonlyArray<BreakdownRow>;
}) {
    const data = barRows(rows, t('reports_r2.chart.others'));
    const [serie] = HOURS_SERIES;
    const withOverage = data.some((row) => row.overage > 0);
    const ticks = hourTicks(Math.max(0, ...data.map((row) => row.logged)), 4);
    const legend: LegendItem[] = withOverage
        ? [
              {
                  key: 'inside',
                  label: t('reports_r2.chart.hours_in_bank'),
                  color: serie.color,
                  shape: 'rect',
              },
              {
                  key: 'overage',
                  label: t('reports_r2.chart.overage'),
                  color: OVERAGE_COLOR,
                  shape: 'rect',
              },
          ]
        : [];

    const endLabel = (props: { value?: unknown; viewBox?: unknown }) => {
        const box = cartesianBox(props.viewBox);
        const text = typeof props.value === 'string' ? props.value : '';

        return text && box ? (
            <text
                x={box.x + box.width + 8}
                y={box.y + box.height / 2}
                dominantBaseline="central"
                fill={CHART_INK.label}
                fontSize={12}
            >
                {text}
            </text>
        ) : (
            <g />
        );
    };
    // El total de la fila va al final de la barra: en el tramo del exceso si lo hay y, si no, en
    // el de las horas (un tramo a 0 no lleva etiqueta).
    const endValue = (onOverage: boolean) => (row: BarRow) =>
        row.logged > 0 && row.overage > 0 === onOverage
            ? formatMinutes(row.logged)
            : '';

    return (
        <ChartFrame
            title={title}
            description={description}
            summary={t('reports_r2.chart.bars_summary', {
                title,
                items: data
                    .map((row) =>
                        t(
                            row.overage > 0
                                ? 'reports_r2.chart.bars_item_overage'
                                : 'reports_r2.chart.summary_item',
                            {
                                label: row.label,
                                minutes: formatMinutes(row.logged),
                                overage: formatMinutes(row.overage),
                            },
                        ),
                    )
                    .join(', '),
            })}
            legend={<ChartLegend items={legend} />}
            table={{
                columns: [
                    { key: 'label', label: firstColumn },
                    {
                        key: 'logged',
                        label: t('reports_r2.column.logged'),
                        numeric: true,
                    },
                    {
                        key: 'billable',
                        label: t('reports_r2.column.billable'),
                        numeric: true,
                    },
                    {
                        key: 'overage',
                        label: t('reports_r2.column.overage'),
                        numeric: true,
                    },
                ],
                rows: data.map((row) => ({
                    id: row.id,
                    label: row.label,
                    logged: formatMinutes(row.logged),
                    billable: formatMinutes(row.billable),
                    overage: formatOverage(row.overage),
                })),
            }}
        >
            <ResponsiveContainer
                width="100%"
                height={data.length * ROW_HEIGHT + 28}
            >
                <BarChart
                    accessibilityLayer={false}
                    data={data}
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
                        width={124}
                        tickFormatter={(value: unknown) =>
                            shorten(String(value))
                        }
                        tick={{ fill: CHART_INK.label, fontSize: 12 }}
                        tickLine={false}
                        axisLine={{ stroke: CHART_INK.grid }}
                    />
                    <Tooltip
                        isAnimationActive={false}
                        cursor={{ fill: 'var(--muted)' }}
                        content={({ active, payload, label }) => {
                            const row = payload?.[0]?.payload as
                                | BarRow
                                | undefined;

                            return active && row ? (
                                <ChartTooltipCard
                                    title={String(label ?? '')}
                                    rows={[
                                        {
                                            key: 'logged',
                                            label: t(
                                                'reports_r2.column.logged',
                                            ),
                                            color: serie.color,
                                            value: formatMinutes(row.logged),
                                        },
                                        ...(row.overage > 0
                                            ? [
                                                  {
                                                      key: 'overage',
                                                      label: t(
                                                          'reports_r2.chart.overage',
                                                      ),
                                                      color: OVERAGE_COLOR,
                                                      value: formatOverage(
                                                          row.overage,
                                                      ),
                                                  },
                                              ]
                                            : []),
                                    ]}
                                />
                            ) : null;
                        }}
                    />
                    <Bar
                        dataKey="inside"
                        name={serie.label}
                        stackId="hours"
                        fill={serie.color}
                        maxBarSize={MAX_BAR_SIZE}
                        radius={
                            withOverage ? 0 : [0, BAR_RADIUS, BAR_RADIUS, 0]
                        }
                        isAnimationActive={false}
                    >
                        <LabelList
                            dataKey={endValue(false)}
                            content={endLabel}
                        />
                    </Bar>
                    {withOverage ? (
                        <Bar
                            dataKey="overage"
                            name={t('reports_r2.chart.overage')}
                            stackId="hours"
                            fill={OVERAGE_COLOR}
                            stroke={CHART_INK.surface}
                            strokeWidth={SURFACE_GAP}
                            maxBarSize={MAX_BAR_SIZE}
                            radius={[0, BAR_RADIUS, BAR_RADIUS, 0]}
                            isAnimationActive={false}
                        >
                            <LabelList
                                dataKey={endValue(true)}
                                content={endLabel}
                            />
                        </Bar>
                    ) : null}
                </BarChart>
            </ResponsiveContainer>
        </ChartFrame>
    );
}
