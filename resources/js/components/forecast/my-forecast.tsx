import { Link } from '@inertiajs/react';
import { LoadCell } from '@/components/charts/load-cell';
import {
    LOAD_LEVELS,
    loadLevel,
    loadPercent,
} from '@/components/charts/thresholds';
import { ChartFrame } from '@/components/charts/chart-frame';
import { ChartLegend } from '@/components/charts/chart-legend';
import { MiniTimeline } from '@/components/forecast/allocations-table';
import { ForecastStat } from '@/components/forecast/forecast-stat';
import { LayerSwatch } from '@/components/forecast/layer-swatch';
import { StackedCapacityColumns } from '@/components/forecast/stacked-capacity-columns';
import { KeywordText } from '@/components/keyword-text';
import { Skeleton } from '@/components/ui/skeleton';
import { FOCUS_RING } from '@/lib/focus-ring';
import {
    allocationAmountLabel,
    bucketLabel,
    cellLoad,
    dateRange,
    dayMonth,
    formatHours,
    formatPercentValue,
    monthShort,
} from '@/lib/forecast';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { index as workloadIndex } from '@/routes/workload';
import type {
    ForecastPerson,
    LoadCell as Cell,
    MyAllocation,
    MyForecast,
} from '@/types/forecast';

/** Semanas de la tarjeta de Inicio (D-299). */
export const HOME_WEEKS = 12;

function monthLong(date: string): string {
    const text = new Intl.DateTimeFormat('es-ES', {
        month: 'long',
        timeZone: 'UTC',
    }).format(new Date(`${date}T00:00:00Z`));

    return text.charAt(0).toUpperCase() + text.slice(1);
}

const EMPTY: Cell = { capacity: 0, real: 0, firm: 0, tentative: 0 };

function me(data: MyForecast): ForecastPerson | null {
    return data.board.people[0] ?? null;
}

/** «Real · 8 h/sem», «Previsto posible: puede no salir · 50 % de tu jornada» (P8). */
function allocationLine(allocation: MyAllocation): string {
    const amount = allocationAmountLabel(allocation).replace(
        t('forecast.my.your_day_from'),
        t('forecast.my.your_day_to'),
    );

    return `${t(`forecast.my.layer.${allocation.layer}`)} · ${amount}`;
}

export function MyForecastSkeleton() {
    return (
        <div
            className="grid gap-2"
            role="status"
            aria-label={t('forecast.loading')}
        >
            <Skeleton className="h-11" />
            <Skeleton className="h-11" />
            <Skeleton className="h-24" />
        </div>
    );
}

/**
 * «Mi carga» en Inicio con la previsión (D-299 y D-305): solo mis asignaciones (P6), también las de
 * previstos (P8). Esta semana y la que viene con la celda de siempre, las próximas 12 semanas en %
 * de mi jornada (con la trama de lo posible y solo el pico etiquetado) y «Lo que viene».
 */
export function MyForecastCard({ data }: { data?: MyForecast | null }) {
    if (!data) {
        return <MyForecastSkeleton />;
    }

    const person = me(data);
    const cells = person?.cells ?? [];
    const buckets = data.board.buckets.slice(0, HOME_WEEKS);
    const today = data.board.period.today;
    const upcoming = data.allocations
        .filter((allocation) => allocation.start_date > today)
        .slice(0, 3);

    return (
        <div className="grid gap-4" data-test="my-forecast">
            <dl className="grid gap-2">
                {[0, 1].map((index) => {
                    const bucket = data.board.buckets[index];
                    const cell = cells[index] ?? EMPTY;

                    if (!bucket) {
                        return null;
                    }

                    return (
                        <div
                            key={bucket.key}
                            className="grid grid-cols-[minmax(0,1fr)_7.5rem] items-center gap-2"
                        >
                            <dt className="min-w-0 text-sm">
                                <span className="block">
                                    {t(
                                        index === 0
                                            ? 'workload_home.this_week'
                                            : 'workload_home.next_week',
                                    )}
                                </span>
                                <span className="tabular block text-xs text-muted-foreground">
                                    {dateRange(
                                        index === 0 && today > bucket.from
                                            ? today
                                            : bucket.from,
                                        bucket.to,
                                    )}
                                </span>
                            </dt>
                            <dd>
                                <span className="sr-only">
                                    {cell.capacity > 0
                                        ? `${formatPercentValue(loadPercent(cellLoad(cell), cell.capacity))}, ${LOAD_LEVELS[loadLevel(cellLoad(cell), cell.capacity)].label}`
                                        : LOAD_LEVELS.none.label}
                                </span>
                                <div aria-hidden="true">
                                    <LoadCell
                                        planned={cellLoad(cell)}
                                        capacity={cell.capacity}
                                    />
                                </div>
                            </dd>
                        </div>
                    );
                })}
            </dl>

            <div className="grid gap-1">
                <p className="text-xs text-muted-foreground">
                    {t('forecast.my.next_weeks', { count: HOME_WEEKS })}
                </p>
                <StackedCapacityColumns
                    buckets={buckets}
                    cells={cells.slice(0, HOME_WEEKS)}
                    sources={data.board.sources}
                    normalize="percent"
                    height={92}
                />
                <div className="flex justify-between text-xs text-muted-foreground">
                    <span>{buckets[0] ? dayMonth(buckets[0].from) : ''}</span>
                    <span>
                        {buckets.length > 0
                            ? dayMonth(buckets[buckets.length - 1].from)
                            : ''}
                    </span>
                </div>
                <ChartLegend
                    className="text-xs"
                    items={[
                        {
                            key: 'real',
                            label: t('forecast.badge.real'),
                            color: 'var(--chart-1)',
                            shape: 'rect',
                        },
                        {
                            key: 'firm',
                            label: t('forecast.badge.firm'),
                            color: 'var(--chart-3)',
                            shape: 'rect',
                        },
                        {
                            key: 'tentative',
                            label: t('forecast.badge.tentative'),
                            color: 'var(--chart-3)',
                            shape: 'hatch',
                        },
                    ]}
                />
            </div>

            {upcoming.length > 0 ? (
                <section
                    aria-labelledby="my-forecast-upcoming"
                    className="grid gap-2 border-t pt-3"
                >
                    <h3
                        id="my-forecast-upcoming"
                        className="text-xs text-muted-foreground"
                    >
                        {t('forecast.my.upcoming')}
                    </h3>
                    <ul className="grid gap-2">
                        {upcoming.map((allocation) => (
                            <li
                                key={allocation.id}
                                className="grid grid-cols-[auto_minmax(0,1fr)_auto] items-start gap-2 text-sm"
                            >
                                <LayerSwatch
                                    layer={allocation.layer}
                                    className="mt-1"
                                />
                                <span className="min-w-0">
                                    <span className="block">
                                        {[
                                            allocation.container.client_name,
                                            allocation.container.name,
                                        ]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    </span>
                                    <span className="block text-xs text-muted-foreground">
                                        {allocationLine(allocation)}
                                    </span>
                                </span>
                                <span className="text-xs whitespace-nowrap text-muted-foreground">
                                    {t('forecast.dates.from', {
                                        date: dayMonth(allocation.start_date),
                                    })}
                                </span>
                            </li>
                        ))}
                    </ul>
                </section>
            ) : null}

            <Link
                href={workloadIndex()}
                className={cn(
                    'self-start text-sm text-primary-text hover:underline',
                    FOCUS_RING,
                )}
            >
                {t('forecast.my.open')}
            </Link>
        </div>
    );
}

/**
 * «Mi carga» en /carga con la previsión (D-299 y D-305): las cifras (esta semana, el mes que viene,
 * la semana más cargada y las horas libres en 3 meses), las 26 semanas en horas frente a mi jornada y
 * «Mis asignaciones» en cronograma. Nunca la previsión de los demás (P4).
 */
export function MyForecastView({
    data,
    showHeader = true,
}: {
    data?: MyForecast | null;
    showHeader?: boolean;
}) {
    if (!data) {
        return <MyForecastSkeleton />;
    }

    const person = me(data);
    const cells = person?.cells ?? [];
    const buckets = data.board.buckets;
    const today = data.board.period.today;
    const thisWeek = cells[0] ?? EMPTY;
    const nextMonth = new Date(`${today}T00:00:00Z`);
    nextMonth.setUTCMonth(nextMonth.getUTCMonth() + 1, 1);
    const nextMonthKey = nextMonth.toISOString().slice(0, 7);
    const monthCell = buckets.reduce<Cell>((sum, bucket, index) => {
        if (bucket.from.slice(0, 7) !== nextMonthKey) {
            return sum;
        }

        const cell = cells[index] ?? EMPTY;

        return {
            capacity: sum.capacity + cell.capacity,
            real: sum.real + cell.real,
            firm: sum.firm + cell.firm,
            tentative: sum.tentative + cell.tentative,
        };
    }, EMPTY);
    const busiest = cells.reduce(
        (best, cell, index) => {
            const percent = loadPercent(cellLoad(cell), cell.capacity) ?? -1;

            return percent > best.percent ? { index, percent } : best;
        },
        { index: -1, percent: -1 },
    );
    const threeMonths = cells
        .slice(0, 13)
        .reduce(
            (sum, cell) => sum + Math.max(cell.capacity - cellLoad(cell), 0),
            0,
        );
    const level = (cell: Cell) =>
        LOAD_LEVELS[loadLevel(cellLoad(cell), cell.capacity)];
    const LevelIcon = ({ cell }: { cell: Cell }) => {
        const meta = level(cell);
        const Icon = meta.icon;

        return (
            <Icon
                aria-hidden="true"
                className={cn('mt-px size-3 shrink-0', meta.tone)}
            />
        );
    };
    const start = buckets[0]?.from ?? today;
    const end = buckets[buckets.length - 1]?.to ?? today;
    const months: string[] = [];
    for (const bucket of buckets) {
        const month = bucket.from.slice(0, 7);
        if (!months.includes(month)) {
            months.push(month);
        }
    }

    return (
        <div
            className="grid min-w-0 grid-cols-[minmax(0,1fr)] gap-6"
            data-test="my-forecast-view"
        >
            {showHeader ? (
                <div className="max-w-2xl space-y-1">
                    <h2 className="text-2xl font-normal tracking-tight">
                        <KeywordText text={t('forecast.my.title')} />
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        {t('forecast.my.description')}
                    </p>
                </div>
            ) : null}

            <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <ForecastStat
                    label={t('workload_home.this_week')}
                    value={
                        formatPercentValue(
                            loadPercent(cellLoad(thisWeek), thisWeek.capacity),
                        ) || '—'
                    }
                    detail={
                        <>
                            <LevelIcon cell={thisWeek} />
                            {`${level(thisWeek).label} · ${t('forecast.cell.of', { load: formatHours(cellLoad(thisWeek)), capacity: formatHours(thisWeek.capacity) })}`}
                        </>
                    }
                />
                <ForecastStat
                    label={monthLong(`${nextMonthKey}-01`)}
                    value={
                        formatPercentValue(
                            loadPercent(
                                cellLoad(monthCell),
                                monthCell.capacity,
                            ),
                        ) || '—'
                    }
                    detail={
                        <>
                            <LevelIcon cell={monthCell} />
                            {level(monthCell).label}
                            {monthCell.tentative > 0
                                ? ` · ${t('forecast.my.tentative_part', { hours: formatHours(monthCell.tentative) })}`
                                : ''}
                        </>
                    }
                />
                <ForecastStat
                    label={t('forecast.my.busiest')}
                    value={
                        busiest.index >= 0
                            ? formatPercentValue(busiest.percent)
                            : '—'
                    }
                    detail={
                        busiest.index >= 0 && buckets[busiest.index] ? (
                            <>
                                <LevelIcon cell={cells[busiest.index]} />
                                {
                                    bucketLabel(buckets[busiest.index], 'week')
                                        .long
                                }
                            </>
                        ) : null
                    }
                />
                <ForecastStat
                    label={t('forecast.my.free')}
                    value={formatHours(threeMonths)}
                    detail={t('forecast.my.free_help')}
                />
            </div>

            <section className="border bg-card p-4">
                <ChartFrame
                    title={t('forecast.my.by_week')}
                    description={t('forecast.my.by_week_help')}
                    summary={t('forecast.my.by_week_summary', {
                        count: buckets.length,
                    })}
                    chartRole="group"
                    legend={
                        <ChartLegend
                            items={[
                                {
                                    key: 'real',
                                    label: t('forecast.layers.real'),
                                    color: 'var(--chart-1)',
                                    shape: 'rect',
                                },
                                {
                                    key: 'firm',
                                    label: t('forecast.layers.firm'),
                                    color: 'var(--chart-3)',
                                    shape: 'rect',
                                },
                                {
                                    key: 'tentative',
                                    label: t('forecast.layers.tentative'),
                                    color: 'var(--chart-3)',
                                    shape: 'hatch',
                                },
                                {
                                    key: 'capacity',
                                    label: t('forecast.my.your_schedule'),
                                    color: 'var(--foreground)',
                                    shape: 'line',
                                },
                            ]}
                        />
                    }
                    table={{
                        columns: [
                            { key: 'week', label: t('forecast.table.period') },
                            {
                                key: 'real',
                                label: t('forecast.layers.real'),
                                numeric: true,
                            },
                            {
                                key: 'firm',
                                label: t('forecast.layers.firm'),
                                numeric: true,
                            },
                            {
                                key: 'tentative',
                                label: t('forecast.layers.tentative'),
                                numeric: true,
                            },
                            {
                                key: 'capacity',
                                label: t('forecast.table.capacity'),
                                numeric: true,
                            },
                            {
                                key: 'percent',
                                label: t('forecast.table.percent'),
                                numeric: true,
                            },
                        ],
                        rows: buckets.map((bucket, index) => {
                            const cell = cells[index] ?? EMPTY;

                            return {
                                id: bucket.key,
                                week: bucketLabel(bucket, 'week').long,
                                real: formatHours(cell.real),
                                firm: formatHours(cell.firm),
                                tentative: formatHours(cell.tentative),
                                capacity: formatHours(cell.capacity),
                                percent:
                                    formatPercentValue(
                                        loadPercent(
                                            cellLoad(cell),
                                            cell.capacity,
                                        ),
                                    ) || '—',
                            };
                        }),
                    }}
                >
                    <StackedCapacityColumns
                        buckets={buckets}
                        cells={cells}
                        sources={data.board.sources}
                        height={240}
                    />
                </ChartFrame>
            </section>

            <section
                aria-labelledby="my-allocations"
                className="grid gap-3 border bg-card p-4"
            >
                <div>
                    <h3 id="my-allocations" className="text-base font-medium">
                        {t('forecast.my.allocations')}
                    </h3>
                    <p className="text-sm text-muted-foreground">
                        {t('forecast.my.allocations_help')}
                    </p>
                </div>
                {data.allocations.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {t('forecast.my.none')}
                    </p>
                ) : (
                    <ul className="grid gap-3" data-test="my-allocations">
                        <li
                            aria-hidden="true"
                            className="hidden grid-cols-[minmax(0,16rem)_minmax(0,1fr)] gap-4 text-xs text-muted-foreground md:grid"
                        >
                            <span />
                            <span className="flex justify-between">
                                {months.map((month) => (
                                    <span key={month}>
                                        {monthShort(`${month}-01`)}
                                    </span>
                                ))}
                            </span>
                        </li>
                        {data.allocations.map((allocation) => (
                            <li
                                key={allocation.id}
                                className="grid gap-1 md:grid-cols-[minmax(0,16rem)_minmax(0,1fr)] md:items-center md:gap-4"
                            >
                                <span className="min-w-0 text-sm">
                                    <span className="block">
                                        {[
                                            allocation.container.client_name,
                                            allocation.container.name,
                                        ]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    </span>
                                    <span className="block text-xs text-muted-foreground">
                                        {allocationLine(allocation)} ·{' '}
                                        {dateRange(
                                            allocation.start_date,
                                            allocation.end_date,
                                        )}
                                    </span>
                                </span>
                                <MiniTimeline
                                    range={{
                                        from: start,
                                        to: end,
                                        months: months,
                                    }}
                                    start={
                                        allocation.start_date < start
                                            ? start
                                            : allocation.start_date
                                    }
                                    end={
                                        allocation.end_date &&
                                        allocation.end_date < end
                                            ? allocation.end_date
                                            : end
                                    }
                                    layer={allocation.layer}
                                    today={today}
                                    className="h-4"
                                />
                            </li>
                        ))}
                    </ul>
                )}
            </section>
        </div>
    );
}
