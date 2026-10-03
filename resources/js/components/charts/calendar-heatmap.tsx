import type { KeyboardEvent, PointerEvent } from 'react';
import { useRef, useState } from 'react';
import {
    DAILY_HOURS_THRESHOLDS,
    sequentialColor,
    sequentialStep,
} from '@/components/charts/chart-config';
import { ChartFrame } from '@/components/charts/chart-frame';
import { ChartTooltipCard } from '@/components/charts/chart-tooltip';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatDate, formatMinutes, LOCALE } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/**
 * Heatmap diario (calendario de calor, SPEC §10.5) hecho a mano: columnas = semanas
 * (de lunes a domingo, SPEC §3), filas = días. Rampa secuencial de una sola tonalidad
 * (azul de datos sobre la tarjeta) y gris neutro para los días sin horas.
 */

export type DayMinutes = {
    /** Fecha local sin hora, "YYYY-MM-DD". */
    date: string;
    minutes: number;
};

export type CalendarCell = DayMinutes & {
    /** Posición entre las celdas con dato (orden cronológico). */
    index: number;
    weekday: number;
    step: number;
};

export type CalendarWeek = {
    /** Lunes de la semana, "YYYY-MM-DD". */
    id: string;
    /** Mes que empieza en esta semana ("sep"), si procede. */
    monthLabel: string | null;
    days: Array<CalendarCell | null>;
};

const DAY_MS = 86_400_000;

/** Nombres de días y meses en es-ES (Intl), de lunes a domingo. Las fechas van en UTC. */
function dayNames(weekday: 'narrow' | 'long'): string[] {
    const format = new Intl.DateTimeFormat(LOCALE, {
        weekday,
        timeZone: 'UTC',
    });

    // 2024-01-01 fue lunes.
    return Array.from({ length: 7 }, (_, i) =>
        format.format(Date.UTC(2024, 0, 1 + i)),
    );
}

const WEEKDAY_SHORT = dayNames('narrow');
const WEEKDAY_LONG = dayNames('long');
const monthFormat = new Intl.DateTimeFormat(LOCALE, {
    month: 'short',
    timeZone: 'UTC',
});
const monthShort = (ms: number) => monthFormat.format(ms).replace(/\.$/, '');

function toUtc(date: string): number {
    const [y, m, d] = date.split('-').map(Number);

    return Date.UTC(y, m - 1, d);
}

function toIso(ms: number): string {
    return new Date(ms).toISOString().slice(0, 10);
}

/** 0 = lunes … 6 = domingo. */
export function weekdayIndex(date: string): number {
    return (new Date(toUtc(date)).getUTCDay() + 6) % 7;
}

/** Agrupa días consecutivos en semanas de lunes a domingo (huecos fuera de rango = null). */
export function buildCalendarWeeks(
    days: ReadonlyArray<DayMinutes>,
    thresholds: ReadonlyArray<number> = DAILY_HOURS_THRESHOLDS,
): CalendarWeek[] {
    if (days.length === 0) {
        return [];
    }

    const sorted = [...days].sort((a, b) => a.date.localeCompare(b.date));
    const byDate = new Map(sorted.map((d) => [d.date, d.minutes]));
    const first = toUtc(sorted[0].date);
    const last = toUtc(sorted[sorted.length - 1].date);
    const start = first - weekdayIndex(sorted[0].date) * DAY_MS;
    const weeks: CalendarWeek[] = [];
    let index = 0;

    for (let monday = start; monday <= last; monday += 7 * DAY_MS) {
        const week: CalendarWeek = {
            id: toIso(monday),
            monthLabel: null,
            days: [],
        };

        for (let offset = 0; offset < 7; offset++) {
            const ms = monday + offset * DAY_MS;
            const date = toIso(ms);

            if (ms < first || ms > last) {
                week.days.push(null);
                continue;
            }

            const minutes = byDate.get(date) ?? 0;

            if (weeks.length === 0 && week.monthLabel === null) {
                week.monthLabel = monthShort(ms);
            }

            if (new Date(ms).getUTCDate() === 1) {
                week.monthLabel = monthShort(ms);
            }

            week.days.push({
                date,
                minutes,
                index: index++,
                weekday: offset,
                step: sequentialStep(minutes, thresholds),
            });
        }

        weeks.push(week);
    }

    // La etiqueta de la primera semana se quita si el mes siguiente empieza muy cerca (se solaparían).
    const nextLabel = weeks.findIndex((w, i) => i > 0 && w.monthLabel !== null);

    if (nextLabel > 0 && nextLabel < 3) {
        weeks[0].monthLabel = null;
    }

    return weeks;
}

/** Etiquetas de la escala: "0 h", "< 2 h", "2–4 h", …, "≥ 8 h". */
export function scaleLabels(
    thresholds: ReadonlyArray<number> = DAILY_HOURS_THRESHOLDS,
): string[] {
    const h = (minutes: number) => formatMinutes(minutes).replace(/:00$/, '');

    return [
        t('charts.heatmap.scale.none'),
        t('charts.heatmap.scale.below', { hours: h(thresholds[0]) }),
        ...thresholds.slice(1).map((limit, i) =>
            t('charts.heatmap.scale.range', {
                from: h(thresholds[i]),
                to: h(limit),
            }),
        ),
        t('charts.heatmap.scale.above', {
            hours: h(thresholds[thresholds.length - 1]),
        }),
    ];
}

export function describeDay(cell: DayMinutes): string {
    return t('charts.heatmap.day', {
        weekday: WEEKDAY_LONG[weekdayIndex(cell.date)],
        date: formatDate(cell.date),
        minutes: formatMinutes(cell.minutes),
    });
}

type Active = { index: number; left: number; top: number };

export function CalendarHeatmap({
    days,
    title = t('charts.heatmap.title'),
    description = t('charts.heatmap.description'),
}: {
    days: ReadonlyArray<DayMinutes>;
    title?: string;
    description?: string;
}) {
    const weeks = buildCalendarWeeks(days);
    const cells = weeks.flatMap((w) =>
        w.days.filter((d): d is CalendarCell => d !== null),
    );
    const [active, setActive] = useState<Active | null>(null);
    const [announce, setAnnounce] = useState('');
    const wrapperRef = useRef<HTMLDivElement>(null);
    const labels = scaleLabels();

    const total = cells.reduce((sum, c) => sum + c.minutes, 0);
    const worked = cells.filter((c) => c.minutes > 0).length;

    const place = (index: number, element: Element | null) => {
        const wrapper = wrapperRef.current;

        if (!wrapper || !element) {
            return;
        }

        const box = wrapper.getBoundingClientRect();
        const rect = element.getBoundingClientRect();
        const center = rect.left - box.left + rect.width / 2;

        setActive({
            index,
            left: Math.min(Math.max(center, 88), Math.max(box.width - 88, 88)),
            top: rect.top - box.top,
        });
    };

    const onPointer = (event: PointerEvent<HTMLDivElement>) => {
        const target = (event.target as HTMLElement).closest('[data-index]');

        if (!target) {
            return;
        }

        place(Number(target.getAttribute('data-index')), target);
    };

    const onKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
        const moves: Record<string, number> = {
            ArrowUp: -1,
            ArrowDown: 1,
            ArrowLeft: -7,
            ArrowRight: 7,
            Home: -Infinity,
            End: Infinity,
        };

        if (!(event.key in moves) || cells.length === 0) {
            return;
        }

        event.preventDefault();
        const current = active?.index ?? cells.length - 1;
        const next = Math.min(
            Math.max(current + moves[event.key], 0),
            cells.length - 1,
        );

        place(
            next,
            wrapperRef.current?.querySelector(`[data-index="${next}"]`) ?? null,
        );
        setAnnounce(describeDay(cells[next]));
    };

    const activeCell = active ? cells[active.index] : undefined;

    const weekRows = weeks.map((week) => {
        const inRange = week.days.filter((d): d is CalendarCell => d !== null);
        const minutes = inRange.reduce((sum, d) => sum + d.minutes, 0);

        return {
            id: week.id,
            week: t('charts.heatmap.week', { date: formatDate(week.id) }),
            minutes: formatMinutes(minutes),
            days: String(inRange.filter((d) => d.minutes > 0).length),
        };
    });

    return (
        <ChartFrame
            title={title}
            description={description}
            chartRole="group"
            summary={t('charts.heatmap.summary', {
                title,
                total: formatMinutes(total),
                days: worked,
            })}
            table={{
                columns: [
                    { key: 'week', label: t('charts.heatmap.column.week') },
                    {
                        key: 'minutes',
                        label: t('charts.heatmap.column.hours'),
                        numeric: true,
                    },
                    {
                        key: 'days',
                        label: t('charts.heatmap.column.days'),
                        numeric: true,
                    },
                ],
                rows: weekRows,
            }}
        >
            <div
                ref={wrapperRef}
                className={cn('relative w-full rounded-md', FOCUS_RING)}
                style={{ maxWidth: weeks.length * 20 + 28 }}
                tabIndex={0}
                onPointerOver={onPointer}
                onPointerLeave={() => setActive(null)}
                onKeyDown={onKeyDown}
                onBlur={() => setActive(null)}
                role="group"
                aria-label={t('charts.heatmap.grid_label')}
            >
                <div
                    className="grid gap-0.5"
                    style={{
                        gridTemplateColumns: `1.25rem repeat(${weeks.length}, minmax(0, 1fr))`,
                    }}
                    aria-hidden="true"
                >
                    <span />
                    {weeks.map((week) => (
                        <span
                            key={`m-${week.id}`}
                            className="h-4 overflow-visible text-[11px] leading-4 whitespace-nowrap text-muted-foreground"
                        >
                            {week.monthLabel}
                        </span>
                    ))}

                    {WEEKDAY_SHORT.map((label, weekday) => (
                        <div key={label} className="contents">
                            <span className="self-center text-[11px] leading-none text-muted-foreground">
                                {label}
                            </span>
                            {weeks.map((week) => {
                                const cell = week.days[weekday];

                                if (!cell) {
                                    return (
                                        <span key={`${week.id}-${weekday}`} />
                                    );
                                }

                                return (
                                    <span
                                        key={cell.date}
                                        data-index={cell.index}
                                        className={cn(
                                            'aspect-square rounded-sm',
                                            active?.index === cell.index &&
                                                'ring-2 ring-foreground ring-offset-1 ring-offset-card',
                                        )}
                                        style={{
                                            backgroundColor: sequentialColor(
                                                cell.step,
                                            ),
                                        }}
                                    />
                                );
                            })}
                        </div>
                    ))}
                </div>

                {active && activeCell ? (
                    <div
                        className="pointer-events-none absolute z-10 -translate-x-1/2 -translate-y-full pb-2"
                        style={{ left: active.left, top: active.top }}
                    >
                        <ChartTooltipCard
                            title={t('charts.heatmap.day_title', {
                                weekday: WEEKDAY_LONG[activeCell.weekday],
                                date: formatDate(activeCell.date),
                            })}
                            rows={[
                                {
                                    key: 'minutes',
                                    label: t('charts.heatmap.logged'),
                                    color: sequentialColor(activeCell.step),
                                    value: formatMinutes(activeCell.minutes),
                                },
                            ]}
                        />
                    </div>
                ) : null}

                <span className="sr-only" aria-live="polite">
                    {announce}
                </span>
            </div>

            <ul
                className="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground"
                aria-label={t('charts.heatmap.scale.label')}
            >
                {labels.map((label, step) => (
                    <li key={label} className="flex items-center gap-1.5">
                        <span
                            aria-hidden="true"
                            className="size-3 rounded-sm"
                            style={{ backgroundColor: sequentialColor(step) }}
                        />
                        {label}
                    </li>
                ))}
            </ul>
        </ChartFrame>
    );
}
