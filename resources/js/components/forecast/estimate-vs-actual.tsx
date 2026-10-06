import { ChartFrame } from '@/components/charts/chart-frame';
import { ChartLegend } from '@/components/charts/chart-legend';
import { formatHoursTick, hourTicks } from '@/components/charts/chart-config';
import { BulletBar, DeviationBadge } from '@/components/forecast/deviation';
import { ForecastStat } from '@/components/forecast/forecast-stat';
import { useWidth } from '@/components/forecast/use-width';
import { dayMonth, formatHours, monthShort } from '@/lib/forecast';
import { t } from '@/lib/i18n';
import type { EstimateVsActual } from '@/types/forecast';

const PAD = { top: 16, right: 112, bottom: 28, left: 48 };

function monthLabel(month: string): string {
    return monthShort(`${month}-01`);
}

/**
 * Horas acumuladas (D-297): el estimado (la línea base) en violeta, lo real en turquesa hasta hoy y
 * la previsión al cerrar en turquesa discontinuo hasta su fin; la línea de hoy y una etiqueta al
 * final de cada serie, colocadas para que no choquen. Un punto por mes.
 */
export function CumulativeChart({
    estimate,
    today,
}: {
    estimate: EstimateVsActual;
    today: string;
}) {
    const [ref, width] = useWidth<HTMLDivElement>();
    const months = estimate.by_month;
    const height = 260;
    const currentMonth = today.slice(0, 7);
    const currentIndex = months.findIndex((row) => row.month === currentMonth);
    const lastPast =
        currentIndex === -1
            ? months.length > 0 &&
              months[months.length - 1].month < currentMonth
                ? months.length - 1
                : -1
            : currentIndex;
    const max = Math.max(
        1,
        ...months.map((row) =>
            Math.max(
                row.cumulative_estimated,
                row.cumulative_projected,
                row.cumulative_actual,
            ),
        ),
    );
    const ticks = hourTicks(max * 1.05, 5);
    const yMax = ticks[ticks.length - 1] || 1;
    const plotWidth = Math.max(width - PAD.left - PAD.right, 40);
    const x = (index: number) =>
        PAD.left + (plotWidth * index) / Math.max(months.length, 1);
    const y = (value: number) =>
        PAD.top + (height - PAD.top - PAD.bottom) * (1 - value / yMax);
    const line = (values: [number, number][]) =>
        values
            .map(
                ([index, value], i) =>
                    `${i === 0 ? 'M' : 'L'}${x(index).toFixed(1)},${y(value).toFixed(1)}`,
            )
            .join(' ');
    const estimated = line([
        [0, 0],
        ...months.map((row, index): [number, number] => [
            index + 1,
            row.cumulative_estimated,
        ]),
    ]);
    // Lo real llega hasta hoy (dentro del mes en curso); la previsión sale de ahí.
    const todayIndex =
        currentIndex === -1
            ? null
            : currentIndex + Math.min((Number(today.slice(8, 10)) - 1) / 30, 1);
    const actualPoints: [number, number][] = [
        [0, 0],
        ...months
            .slice(0, lastPast === currentIndex ? currentIndex : lastPast + 1)
            .map((row, index): [number, number] => [
                index + 1,
                row.cumulative_actual,
            ]),
    ];
    const projectedPoints: [number, number][] = [];

    if (todayIndex !== null) {
        actualPoints.push([todayIndex, months[currentIndex].cumulative_actual]);
        projectedPoints.push([
            todayIndex,
            months[currentIndex].cumulative_actual,
        ]);
        months
            .slice(currentIndex)
            .forEach((row, index) =>
                projectedPoints.push([
                    currentIndex + 1 + index,
                    row.cumulative_projected,
                ]),
            );
    } else if (lastPast === -1 && months.length > 0) {
        // Aún no ha empezado: todo es previsión.
        projectedPoints.push(
            [0, 0],
            ...months.map((row, index): [number, number] => [
                index + 1,
                row.cumulative_projected,
            ]),
        );
    }

    const todayX = todayIndex === null ? null : x(todayIndex);
    const ends = [
        {
            key: 'estimated',
            label: t('forecast.estimate.label_estimated', {
                hours: formatHours(estimate.totals.estimated),
            }),
            value: estimate.totals.estimated,
            color: 'var(--chart-3)',
        },
        {
            key: 'projected',
            label: t('forecast.estimate.label_projected', {
                hours: formatHours(estimate.totals.projected),
            }),
            value: estimate.totals.projected,
            color: 'var(--chart-2)',
        },
    ]
        .map((item) => ({ ...item, y: y(item.value) }))
        .sort((a, b) => a.y - b.y);

    // Las etiquetas del final no se pisan: como poco 16 px entre una y otra.
    for (let index = 1; index < ends.length; index++) {
        if (ends[index].y - ends[index - 1].y < 16) {
            ends[index].y = ends[index - 1].y + 16;
        }
    }

    return (
        <div ref={ref} className="min-w-0" data-test="cumulative-chart">
            <svg
                width={width}
                height={height}
                aria-hidden="true"
                focusable="false"
                className="block overflow-visible"
            >
                {ticks.map((tick) => (
                    <g key={tick}>
                        <line
                            x1={PAD.left}
                            x2={PAD.left + plotWidth}
                            y1={y(tick)}
                            y2={y(tick)}
                            stroke="var(--border)"
                            strokeWidth={1}
                        />
                        <text
                            x={PAD.left - 6}
                            y={y(tick)}
                            dy="0.32em"
                            textAnchor="end"
                            className="fill-muted-foreground text-[11px] tabular-nums"
                        >
                            {formatHoursTick(tick)}
                        </text>
                    </g>
                ))}
                {months.map((row, index) => (
                    <text
                        key={row.month}
                        x={x(index) + (x(index + 1) - x(index)) / 2}
                        y={height - 8}
                        textAnchor="middle"
                        className="fill-muted-foreground text-[11px]"
                    >
                        {monthLabel(row.month)}
                    </text>
                ))}
                <path
                    d={estimated}
                    fill="none"
                    stroke="var(--chart-3)"
                    strokeWidth={2}
                />
                {projectedPoints.length > 1 ? (
                    <path
                        d={line(projectedPoints)}
                        fill="none"
                        stroke="var(--chart-2)"
                        strokeWidth={2}
                        strokeDasharray="5 4"
                    />
                ) : null}
                <path
                    d={line(actualPoints)}
                    fill="none"
                    stroke="var(--chart-2)"
                    strokeWidth={2}
                />
                {todayX !== null ? (
                    <g>
                        <line
                            x1={todayX}
                            x2={todayX}
                            y1={PAD.top - 6}
                            y2={height - PAD.bottom}
                            stroke="var(--brand)"
                            strokeWidth={2}
                        />
                        <text
                            x={todayX}
                            y={PAD.top - 8}
                            textAnchor="middle"
                            className="fill-primary-text text-[11px]"
                        >
                            {t('forecast.estimate.today')}
                        </text>
                    </g>
                ) : null}
                {ends.map((item) => (
                    <text
                        key={item.key}
                        x={PAD.left + plotWidth + 8}
                        y={item.y}
                        dy="0.32em"
                        className="fill-foreground text-[12px] font-medium"
                    >
                        {item.label}
                    </text>
                ))}
            </svg>
        </div>
    );
}

/** Estimado y real de cada mes uno junto al otro, con lo que queda asignado encima, discontinuo. */
export function MonthColumns({ estimate }: { estimate: EstimateVsActual }) {
    const [ref, width] = useWidth<HTMLDivElement>(420);
    const months = estimate.by_month;
    const height = 200;
    const left = 44;
    const bottom = 24;
    const max = Math.max(
        1,
        ...months.map((row) =>
            Math.max(row.estimated, row.actual + row.remaining),
        ),
    );
    const ticks = hourTicks(max * 1.05, 4);
    const yMax = ticks[ticks.length - 1] || 1;
    const slot = Math.max((width - left) / Math.max(months.length, 1), 24);
    const bar = Math.min(18, slot / 3);
    const y = (value: number) => 8 + (height - bottom - 8) * (1 - value / yMax);

    return (
        <div
            ref={ref}
            className="min-w-0 overflow-x-auto"
            data-test="month-columns"
        >
            <svg
                width={Math.max(width, left + slot * months.length)}
                height={height}
                aria-hidden="true"
                focusable="false"
                className="block"
            >
                {ticks.map((tick) => (
                    <g key={tick}>
                        <line
                            x1={left}
                            x2={left + slot * months.length}
                            y1={y(tick)}
                            y2={y(tick)}
                            stroke="var(--border)"
                        />
                        <text
                            x={left - 6}
                            y={y(tick)}
                            dy="0.32em"
                            textAnchor="end"
                            className="fill-muted-foreground text-[11px] tabular-nums"
                        >
                            {formatHoursTick(tick)}
                        </text>
                    </g>
                ))}
                {months.map((row, index) => {
                    const center = left + slot * index + slot / 2;

                    return (
                        <g key={row.month}>
                            <rect
                                x={center - bar - 1}
                                y={y(row.estimated)}
                                width={bar}
                                height={y(0) - y(row.estimated)}
                                fill="var(--chart-3)"
                            />
                            <rect
                                x={center + 1}
                                y={y(row.actual)}
                                width={bar}
                                height={y(0) - y(row.actual)}
                                fill="var(--chart-2)"
                            />
                            {row.remaining > 0 ? (
                                <rect
                                    x={center + 1.5}
                                    y={y(row.actual + row.remaining)}
                                    width={bar - 1}
                                    height={
                                        y(row.actual) -
                                        y(row.actual + row.remaining)
                                    }
                                    fill="color-mix(in oklab, var(--chart-2) 12%, var(--card))"
                                    stroke="var(--chart-2)"
                                    strokeDasharray="3 2"
                                />
                            ) : null}
                            <text
                                x={center}
                                y={height - 6}
                                textAnchor="middle"
                                className="fill-muted-foreground text-[11px]"
                            >
                                {monthLabel(row.month)}
                            </text>
                        </g>
                    );
                })}
            </svg>
        </div>
    );
}

/**
 * «Estimado frente a real» de un previsto vinculado (D-297): las cifras (estimado, real hasta hoy,
 * previsión al cerrar con su desviación y las fechas), las horas acumuladas, por departamento (barra
 * de bala), por mes (columnas emparejadas) y por persona (tabla). Cada gráfica con su tabla.
 */
export function EstimateVsActualSection({
    estimate,
    today,
}: {
    estimate: EstimateVsActual;
    today: string;
}) {
    const totals = estimate.totals;
    const dates = estimate.dates;
    const maxDepartment = Math.max(
        1,
        ...estimate.by_department.map((row) =>
            Math.max(row.estimated, row.projected, row.actual),
        ),
    );
    const percentOfEstimate =
        totals.estimated > 0
            ? Math.round((totals.actual * 100) / totals.estimated)
            : null;

    return (
        <div className="grid gap-6" data-test="estimate-vs-actual">
            <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <ForecastStat
                    label={t('forecast.estimate.estimated')}
                    value={formatHours(totals.estimated)}
                    detail={t('forecast.estimate.baseline')}
                />
                <ForecastStat
                    label={t('forecast.estimate.actual')}
                    value={formatHours(totals.actual)}
                    detail={
                        percentOfEstimate === null
                            ? null
                            : t('forecast.estimate.of_estimate', {
                                  percent: `${percentOfEstimate} %`,
                              })
                    }
                />
                <ForecastStat
                    label={t('forecast.estimate.projected')}
                    value={formatHours(totals.projected)}
                    detail={
                        <span className="flex flex-wrap items-center gap-x-2">
                            <DeviationBadge
                                percent={totals.projected_deviation_percent}
                            />
                            {totals.estimated > 0 &&
                            totals.projected !== totals.estimated
                                ? t(
                                      totals.projected > totals.estimated
                                          ? 'forecast.estimate.more'
                                          : 'forecast.estimate.less',
                                      {
                                          hours: formatHours(
                                              Math.abs(
                                                  totals.projected -
                                                      totals.estimated,
                                              ),
                                          ),
                                      },
                                  )
                                : null}
                        </span>
                    }
                />
                <ForecastStat
                    label={t('forecast.estimate.dates')}
                    value={
                        <span className="grid grid-cols-2 gap-3 text-sm font-normal tracking-normal">
                            <span>
                                <span className="block text-xs text-muted-foreground">
                                    {t('forecast.estimate.start')}
                                </span>
                                {dates.actual_start
                                    ? dayMonth(dates.actual_start)
                                    : '—'}
                            </span>
                            <span>
                                <span className="block text-xs text-muted-foreground">
                                    {t('forecast.estimate.end')}
                                </span>
                                {dates.actual_end
                                    ? dayMonth(dates.actual_end)
                                    : dates.projected_end
                                      ? dayMonth(dates.projected_end)
                                      : '—'}
                            </span>
                        </span>
                    }
                    detail={t('forecast.estimate.estimated_dates', {
                        from: dates.estimated_start
                            ? dayMonth(dates.estimated_start)
                            : '—',
                        to: dates.estimated_end
                            ? dayMonth(dates.estimated_end)
                            : '—',
                    })}
                />
            </div>

            <section className="border bg-card p-4">
                <ChartFrame
                    title={t('forecast.estimate.cumulative')}
                    description={t('forecast.estimate.cumulative_help')}
                    summary={t('forecast.estimate.cumulative_summary', {
                        estimated: formatHours(totals.estimated),
                        actual: formatHours(totals.actual),
                        projected: formatHours(totals.projected),
                    })}
                    legend={
                        <ChartLegend
                            items={[
                                {
                                    key: 'e',
                                    label: t(
                                        'forecast.estimate.series_estimated',
                                    ),
                                    color: 'var(--chart-3)',
                                    shape: 'line',
                                },
                                {
                                    key: 'a',
                                    label: t('forecast.estimate.series_actual'),
                                    color: 'var(--chart-2)',
                                    shape: 'line',
                                },
                                {
                                    key: 'p',
                                    label: t(
                                        'forecast.estimate.series_projected',
                                    ),
                                    color: 'var(--chart-2)',
                                    shape: 'dashed',
                                },
                            ]}
                        />
                    }
                    table={{
                        columns: [
                            {
                                key: 'month',
                                label: t('forecast.estimate.month'),
                            },
                            {
                                key: 'estimated',
                                label: t('forecast.estimate.series_estimated'),
                                numeric: true,
                            },
                            {
                                key: 'actual',
                                label: t('forecast.estimate.series_actual'),
                                numeric: true,
                            },
                            {
                                key: 'projected',
                                label: t('forecast.estimate.series_projected'),
                                numeric: true,
                            },
                        ],
                        rows: estimate.by_month.map((row) => ({
                            id: row.month,
                            month: monthLabel(row.month),
                            estimated: formatHours(row.cumulative_estimated),
                            actual: formatHours(row.cumulative_actual),
                            projected: formatHours(row.cumulative_projected),
                        })),
                    }}
                >
                    <CumulativeChart estimate={estimate} today={today} />
                </ChartFrame>
            </section>

            <div className="grid gap-4 lg:grid-cols-2">
                <section className="border bg-card p-4">
                    <ChartFrame
                        title={t('forecast.estimate.by_department')}
                        description={t('forecast.estimate.by_department_help')}
                        summary={t('forecast.estimate.by_department')}
                        legend={
                            <ChartLegend
                                items={[
                                    {
                                        key: 'a',
                                        label: t(
                                            'forecast.estimate.series_actual',
                                        ),
                                        color: 'var(--chart-2)',
                                        shape: 'rect',
                                    },
                                    {
                                        key: 'p',
                                        label: t(
                                            'forecast.estimate.series_projected',
                                        ),
                                        color: 'var(--chart-2)',
                                        shape: 'dashed',
                                    },
                                    {
                                        key: 'e',
                                        label: t(
                                            'forecast.estimate.series_estimated',
                                        ),
                                        color: 'var(--chart-3)',
                                        shape: 'line',
                                    },
                                ]}
                            />
                        }
                        table={{
                            columns: [
                                {
                                    key: 'name',
                                    label: t('forecast.board.department'),
                                },
                                {
                                    key: 'estimated',
                                    label: t(
                                        'forecast.estimate.series_estimated',
                                    ),
                                    numeric: true,
                                },
                                {
                                    key: 'actual',
                                    label: t('forecast.estimate.series_actual'),
                                    numeric: true,
                                },
                                {
                                    key: 'projected',
                                    label: t(
                                        'forecast.estimate.series_projected',
                                    ),
                                    numeric: true,
                                },
                            ],
                            rows: estimate.by_department.map((row) => ({
                                id: String(row.department_id ?? 'none'),
                                name:
                                    row.name ??
                                    t('forecast.board.no_department'),
                                estimated: formatHours(row.estimated),
                                actual: formatHours(row.actual),
                                projected: formatHours(row.projected),
                            })),
                        }}
                    >
                        <ul className="grid gap-3">
                            {estimate.by_department.map((row) => (
                                <li
                                    key={row.department_id ?? 'none'}
                                    className="grid grid-cols-[6rem_minmax(0,1fr)_auto] items-center gap-3 text-sm sm:grid-cols-[8rem_minmax(0,1fr)_auto]"
                                >
                                    <span className="truncate">
                                        {row.name ??
                                            t('forecast.board.no_department')}
                                    </span>
                                    <BulletBar
                                        actual={row.actual}
                                        projected={row.projected}
                                        target={row.estimated}
                                        max={maxDepartment}
                                        targetTone="estimate"
                                    />
                                    <span className="tabular text-right text-xs whitespace-nowrap">
                                        {t('forecast.estimate.of', {
                                            actual: formatHours(row.projected),
                                            estimated: formatHours(
                                                row.estimated,
                                            ),
                                        })}
                                        <DeviationBadge
                                            percent={
                                                row.projected_deviation_percent
                                            }
                                            className="flex justify-end"
                                        />
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </ChartFrame>
                </section>
                <section className="border bg-card p-4">
                    <ChartFrame
                        title={t('forecast.estimate.by_month')}
                        description={t('forecast.estimate.by_month_help')}
                        summary={t('forecast.estimate.by_month')}
                        legend={
                            <ChartLegend
                                items={[
                                    {
                                        key: 'e',
                                        label: t(
                                            'forecast.estimate.series_estimated',
                                        ),
                                        color: 'var(--chart-3)',
                                        shape: 'rect',
                                    },
                                    {
                                        key: 'a',
                                        label: t(
                                            'forecast.estimate.series_actual',
                                        ),
                                        color: 'var(--chart-2)',
                                        shape: 'rect',
                                    },
                                    {
                                        key: 'p',
                                        label: t(
                                            'forecast.estimate.series_projected',
                                        ),
                                        color: 'var(--chart-2)',
                                        shape: 'dashed',
                                    },
                                ]}
                            />
                        }
                        table={{
                            columns: [
                                {
                                    key: 'month',
                                    label: t('forecast.estimate.month'),
                                },
                                {
                                    key: 'estimated',
                                    label: t(
                                        'forecast.estimate.series_estimated',
                                    ),
                                    numeric: true,
                                },
                                {
                                    key: 'actual',
                                    label: t('forecast.estimate.series_actual'),
                                    numeric: true,
                                },
                                {
                                    key: 'remaining',
                                    label: t('forecast.estimate.remaining'),
                                    numeric: true,
                                },
                            ],
                            rows: estimate.by_month.map((row) => ({
                                id: row.month,
                                month: monthLabel(row.month),
                                estimated: formatHours(row.estimated),
                                actual: formatHours(row.actual),
                                remaining: formatHours(row.remaining),
                            })),
                        }}
                    >
                        <MonthColumns estimate={estimate} />
                    </ChartFrame>
                </section>
            </div>

            <section className="grid gap-3 border bg-card p-4">
                <h3 className="text-base font-medium">
                    {t('forecast.estimate.by_person')}
                </h3>
                <div className="overflow-x-auto">
                    <table
                        className="w-full text-sm"
                        data-test="estimate-people"
                    >
                        <thead>
                            <tr>
                                <th scope="col" className="px-3 py-2 text-left">
                                    {t('forecast.estimate.person')}
                                </th>
                                <th
                                    scope="col"
                                    className="px-3 py-2 text-right"
                                >
                                    {t('forecast.estimate.series_estimated')}
                                </th>
                                <th
                                    scope="col"
                                    className="px-3 py-2 text-right"
                                >
                                    {t('forecast.estimate.actual')}
                                </th>
                                <th
                                    scope="col"
                                    className="px-3 py-2 text-right"
                                >
                                    {t('forecast.estimate.series_projected')}
                                </th>
                                <th
                                    scope="col"
                                    className="px-3 py-2 text-right"
                                >
                                    {t('forecast.show.deviation')}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {estimate.by_user.map((row) => (
                                <tr key={row.user_id} className="border-b">
                                    <th
                                        scope="row"
                                        className="px-3 py-2 text-left font-normal"
                                    >
                                        {row.name}
                                    </th>
                                    <td className="tabular px-3 py-2 text-right">
                                        {formatHours(row.estimated)}
                                    </td>
                                    <td className="tabular px-3 py-2 text-right">
                                        {formatHours(row.actual)}
                                    </td>
                                    <td className="tabular px-3 py-2 text-right">
                                        {formatHours(row.projected)}
                                    </td>
                                    <td className="px-3 py-2 text-right">
                                        <DeviationBadge
                                            percent={
                                                row.projected_deviation_percent
                                            }
                                        />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                        <tfoot>
                            <tr className="bg-neutral-soft">
                                <th
                                    scope="row"
                                    className="px-3 py-2 text-left font-medium"
                                >
                                    {t('forecast.show.total')}
                                </th>
                                <td className="tabular px-3 py-2 text-right font-medium">
                                    {formatHours(totals.estimated)}
                                </td>
                                <td className="tabular px-3 py-2 text-right font-medium">
                                    {formatHours(totals.actual)}
                                </td>
                                <td className="tabular px-3 py-2 text-right font-medium">
                                    {formatHours(totals.projected)}
                                </td>
                                <td className="px-3 py-2 text-right">
                                    <DeviationBadge
                                        percent={
                                            totals.projected_deviation_percent
                                        }
                                    />
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </section>
        </div>
    );
}
