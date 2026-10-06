import {
    DndContext,
    DragOverlay,
    MouseSensor,
    TouchSensor,
    pointerWithin,
    rectIntersection,
    useDroppable,
    useSensor,
    useSensors,
} from '@dnd-kit/core';
import type {
    Announcements,
    CollisionDetection,
    DragEndEvent,
    DragStartEvent,
} from '@dnd-kit/core';
import {
    CalendarDays,
    CalendarOff,
    CalendarRange,
    ChevronLeft,
    ChevronRight,
    Diamond,
    Plus,
    TriangleAlert,
    UserX,
    Users,
} from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import type { CSSProperties, MouseEvent, ReactNode } from 'react';
import {
    anchorDay,
    calendarTitle,
    daysOf,
    isRange,
    movedTeamDates,
    shiftDate,
    taskOnDay,
} from '@/components/calendar/calendar-query';
import { TeamChip } from '@/components/calendar/team-chip';
import type { TeamChipContext } from '@/components/calendar/team-chip';
import {
    formatLoadPercent,
    LOAD_LEVELS,
    loadLevel,
} from '@/components/charts/thresholds';
import { StatusIcon } from '@/components/planning/calendar-chip';
import {
    daysBetween,
    monthGrid,
    monthOf,
} from '@/components/planning/calendar-dates';
import { RescheduleDialog } from '@/components/planning/reschedule-dialog';
import { useReschedule } from '@/components/planning/use-reschedule';
import { CalendarDayPlan } from '@/components/day-plan/calendar-day-plan';
import { UserAvatar } from '@/components/tasks/task-fields';
import {
    weekdayLongLabel,
    weekdayShortLabel,
} from '@/components/time/week-days';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { Switch } from '@/components/ui/switch';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { useIsMobile } from '@/hooks/use-mobile';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatDate, formatMinutes } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { TaskStatus } from '@/types';
import type {
    TeamCalendarData,
    TeamCalendarDay,
    TeamCalendarRow,
    TeamCalendarTask,
    TeamCalendarView,
} from '@/types/calendar';

/** Tarjetas que caben en un día del mes; el resto, en «+N más». */
export const MONTH_VISIBLE = 3;

const DAY = 'day:';

/** El día bajo el puntero; sin puntero (teclado de dnd-kit), el de más intersección. */
const dropUnderPointer: CollisionDetection = (args) => {
    const underPointer = pointerWithin(args);

    return underPointer.length > 0 ? underPointer : rectIntersection(args);
};

/** «day:2026-10-05» o «day:2026-10-05:7» (celda de una persona) → la fecha. */
function dayOfDrop(id: string | number | undefined): string | null {
    return typeof id === 'string' && id.startsWith(DAY)
        ? id.slice(DAY.length, DAY.length + 10)
        : null;
}

function dayLabel(date: string, today: string): string {
    const label = `${weekdayLongLabel(date)}, ${formatDate(date)}`;

    return date === today
        ? t('planning.calendar.today_label', { date: label })
        : label;
}

type Span = {
    task: TeamCalendarTask;
    startColumn: number;
    endColumn: number;
    lane: number;
    continuesBefore: boolean;
    continuesAfter: boolean;
};

/** Franjas (inicio → entrega) que cruzan unos días, repartidas en filas sin solaparse. */
export function teamSpans(tasks: TeamCalendarTask[], days: string[]): Span[] {
    const first = days[0];
    const last = days[days.length - 1];
    const spans = tasks
        .filter(
            (task) =>
                isRange(task) &&
                (task.start_date as string) <= last &&
                (task.due_date as string) >= first,
        )
        .map((task) => ({
            task,
            startColumn: Math.max(
                0,
                daysBetween(first, task.start_date as string),
            ),
            endColumn: Math.min(
                days.length - 1,
                daysBetween(first, task.due_date as string),
            ),
            continuesBefore: (task.start_date as string) < first,
            continuesAfter: (task.due_date as string) > last,
        }))
        .sort(
            (a, b) =>
                a.startColumn - b.startColumn ||
                b.endColumn - a.endColumn ||
                a.task.id - b.task.id,
        );
    const laneEnds: number[] = [];

    return spans.map((span) => {
        let lane = laneEnds.findIndex((end) => end < span.startColumn);

        if (lane === -1) {
            lane = laneEnds.length;
            laneEnds.push(span.endColumn);
        } else {
            laneEnds[lane] = span.endColumn;
        }

        return { ...span, lane };
    });
}

/** Tareas por el día de su tarjeta (la entrega o, sin ella, el inicio). */
function byAnchor(tasks: TeamCalendarTask[]): Map<string, TeamCalendarTask[]> {
    const map = new Map<string, TeamCalendarTask[]>();

    for (const task of tasks) {
        const day = anchorDay(task);

        if (day !== null) {
            map.set(day, [...(map.get(day) ?? []), task]);
        }
    }

    return map;
}

/** Ancho de una franja que ocupa `days` celdas (relleno de 2 × 0,25 rem y borde de 1 px). */
function spanWidth(days: number): string {
    return days === 1
        ? '100%'
        : `calc(${days} * 100% + ${days - 1} * (0.5rem + 1px))`;
}

/** Franja de una tarea de rango: abre su panel; las fechas, en su nombre. No se arrastra. */
function SpanButton({
    span,
    context,
    style,
}: {
    span: Pick<Span, 'task' | 'continuesBefore' | 'continuesAfter'>;
    context: TeamChipContext;
    style?: CSSProperties;
}) {
    const { task } = span;
    const project = context.projectById.get(task.project_id);
    const label = t('planning.calendar.span_label', {
        task: task.title,
        start: formatDate(task.start_date),
        due: formatDate(task.due_date),
    });

    return (
        <button
            type="button"
            onClick={() => context.onOpen(task.id)}
            className={cn(
                'relative z-10 flex h-5 min-w-0 items-center gap-1 border border-l-[3px] bg-info-soft px-1.5 text-left text-xs text-foreground hover:underline',
                span.continuesBefore && 'border-l-0',
                span.continuesAfter && 'border-r-0',
                FOCUS_RING,
            )}
            style={{
                ...style,
                borderLeftColor: span.continuesBefore
                    ? undefined
                    : project?.color,
            }}
            aria-label={label}
            title={label}
            data-test="team-span"
            data-task-id={task.id}
        >
            {span.continuesBefore ? (
                <ChevronLeft aria-hidden="true" className="size-3 shrink-0" />
            ) : null}
            <CalendarRange
                aria-hidden="true"
                className="size-3 shrink-0 text-primary-text"
            />
            <span className="truncate">{task.title}</span>
            {span.continuesAfter ? (
                <ChevronRight
                    aria-hidden="true"
                    className="ml-auto size-3 shrink-0"
                />
            ) : null}
        </button>
    );
}

/** Filas de franjas de una semana: en cada fila, la franja que empieza en cada columna. */
function Lanes({
    spans,
    column,
    columns,
    context,
}: {
    spans: Span[];
    column: number;
    columns: number;
    context: TeamChipContext;
}) {
    const lanes: (Span | null)[][] = [];

    for (const span of spans) {
        while (lanes.length <= span.lane) {
            lanes.push(Array.from({ length: columns }, () => null));
        }

        lanes[span.lane][span.startColumn] = span;
    }

    if (lanes.length === 0) {
        return null;
    }

    return (
        <div className="mb-1 grid gap-0.5" data-test="team-lanes">
            {lanes.map((lane, index) => {
                const span = lane[column];

                if (span === null) {
                    return (
                        <div key={index} aria-hidden="true" className="h-5" />
                    );
                }

                return (
                    <SpanButton
                        key={index}
                        span={span}
                        context={context}
                        style={{
                            width: spanWidth(
                                span.endColumn - span.startColumn + 1,
                            ),
                        }}
                    />
                );
            })}
        </div>
    );
}

/**
 * Celda de un día donde se suelta una tarea. Un clic en su hueco vacío (no en una tarjeta) crea
 * una tarea ese día; con el teclado, el botón «+» de su cabecera.
 */
function DayCell({
    id,
    date,
    className,
    onCreate,
    children,
    as = 'td',
}: {
    id: string;
    date: string;
    className?: string;
    onCreate?: (date: string) => void;
    children: ReactNode;
    as?: 'td' | 'div';
}) {
    const { setNodeRef, isOver } = useDroppable({ id });
    const Tag = as;
    const create = (event: MouseEvent<HTMLElement>) => {
        if (
            onCreate &&
            event.target instanceof HTMLElement &&
            event.target.closest('button, a, [role="dialog"]') === null
        ) {
            onCreate(date);
        }
    };

    return (
        <Tag
            ref={setNodeRef}
            className={cn(className, isOver && 'bg-accent')}
            onClick={create}
            data-date={date}
            data-test="team-day"
        >
            {children}
        </Tag>
    );
}

function CreateButton({
    date,
    onCreate,
}: {
    date: string;
    onCreate?: (date: string) => void;
}) {
    if (!onCreate) {
        return null;
    }

    return (
        <button
            type="button"
            onClick={() => onCreate(date)}
            className={cn(
                'inline-flex size-5 items-center justify-center text-muted-foreground hover:bg-accent hover:text-foreground',
                FOCUS_RING,
            )}
            aria-label={t('team_calendar.create_on', {
                date: formatDate(date),
            })}
            title={t('team_calendar.create_on', { date: formatDate(date) })}
            data-test="team-create"
        >
            <Plus aria-hidden="true" className="size-3.5" />
        </button>
    );
}

function MoreTasks({
    date,
    tasks,
    hidden,
    context,
}: {
    date: string;
    tasks: TeamCalendarTask[];
    hidden: number;
    context: TeamChipContext;
}) {
    const [open, setOpen] = useState(false);

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <button
                    type="button"
                    className={cn(
                        'w-full px-1.5 py-0.5 text-left text-xs text-primary-text hover:underline',
                        FOCUS_RING,
                    )}
                    aria-label={t('planning.calendar.more_label', {
                        count: hidden,
                        date: formatDate(date),
                    })}
                    data-test="team-more"
                >
                    {t('planning.calendar.more', { count: hidden })}
                </button>
            </PopoverTrigger>
            <PopoverContent className="w-80 p-2" align="start">
                <p className="mb-2 px-1 text-sm font-medium first-letter:uppercase">
                    {dayLabel(date, context.today)}
                </p>
                <ul className="grid max-h-80 gap-1 overflow-y-auto">
                    {tasks.map((task) => (
                        <li key={task.id}>
                            <TeamChip
                                task={task}
                                dragId={`more:${task.id}`}
                                draggable={false}
                                context={{
                                    ...context,
                                    onOpen: (taskId) => {
                                        setOpen(false);
                                        context.onOpen(taskId);
                                    },
                                    onMove: (moved, day, keep) => {
                                        setOpen(false);
                                        context.onMove(moved, day, keep);
                                    },
                                }}
                            />
                        </li>
                    ))}
                </ul>
            </PopoverContent>
        </Popover>
    );
}

function DayNumber({
    date,
    today,
    muted,
    onCreate,
}: {
    date: string;
    today: string;
    muted: boolean;
    onCreate?: (date: string) => void;
}) {
    const isToday = date === today;

    return (
        <p
            className={cn(
                'mb-1 flex items-center justify-between gap-1 text-xs',
                muted && 'text-muted-foreground',
            )}
        >
            <span className="sr-only">{dayLabel(date, today)}</span>
            <span
                aria-hidden="true"
                className={cn(
                    'tabular inline-flex size-6 items-center justify-center',
                    isToday && 'bg-primary font-medium text-primary-foreground',
                )}
            >
                {Number(date.slice(8, 10))}
            </span>
            <CreateButton date={date} onCreate={onCreate} />
        </p>
    );
}

function MonthGrid({
    calendar,
    tasks,
    context,
    onCreate,
}: {
    calendar: TeamCalendarData;
    tasks: TeamCalendarTask[];
    context: TeamChipContext;
    onCreate?: (date: string) => void;
}) {
    const month = monthOf(calendar.date);
    const weeks = monthGrid(month);
    const anchored = byAnchor(tasks);

    return (
        <table
            className="w-full min-w-[48rem] table-fixed border-collapse"
            data-test="team-month"
        >
            <caption className="sr-only">
                {t('team_calendar.caption', {
                    period: calendarTitle(
                        'month',
                        calendar.date,
                        calendar.from,
                        calendar.to,
                    ),
                })}
            </caption>
            <thead>
                <tr>
                    {weeks[0].map((date) => (
                        <th
                            key={date}
                            scope="col"
                            abbr={weekdayLongLabel(date)}
                            className="border-b px-2 py-1 text-left text-xs font-medium text-muted-foreground capitalize"
                        >
                            {weekdayShortLabel(date)}
                        </th>
                    ))}
                </tr>
            </thead>
            <tbody>
                {weeks.map((week) => {
                    const spans = teamSpans(tasks, week);

                    return (
                        <tr key={week[0]}>
                            {week.map((date, column) => {
                                const dayTasks = anchored.get(date) ?? [];
                                const visible = dayTasks.slice(
                                    0,
                                    MONTH_VISIBLE,
                                );
                                const hidden = dayTasks.length - visible.length;
                                const outside = !date.startsWith(month);

                                return (
                                    <DayCell
                                        key={date}
                                        id={`${DAY}${date}`}
                                        date={date}
                                        onCreate={onCreate}
                                        className={cn(
                                            'h-32 border p-1 align-top',
                                            outside && 'bg-muted',
                                        )}
                                    >
                                        <DayNumber
                                            date={date}
                                            today={context.today}
                                            muted={outside}
                                            onCreate={onCreate}
                                        />
                                        <Lanes
                                            spans={spans}
                                            column={column}
                                            columns={7}
                                            context={context}
                                        />
                                        {dayTasks.length > 0 ? (
                                            <ul
                                                className="grid gap-1"
                                                aria-label={t(
                                                    'planning.calendar.day_tasks',
                                                    {
                                                        date: formatDate(date),
                                                        count: dayTasks.length,
                                                    },
                                                )}
                                            >
                                                {visible.map((task) => (
                                                    <li key={task.id}>
                                                        <TeamChip
                                                            task={task}
                                                            dragId={`grid:${task.id}`}
                                                            context={context}
                                                            compact
                                                        />
                                                    </li>
                                                ))}
                                                {hidden > 0 ? (
                                                    <li>
                                                        <MoreTasks
                                                            date={date}
                                                            tasks={dayTasks}
                                                            hidden={hidden}
                                                            context={context}
                                                        />
                                                    </li>
                                                ) : null}
                                            </ul>
                                        ) : null}
                                    </DayCell>
                                );
                            })}
                        </tr>
                    );
                })}
            </tbody>
        </table>
    );
}

/** Mes en el móvil (D-144): una lista agrupada por día, solo los días con tareas. */
function MonthList({
    calendar,
    tasks,
    context,
    onCreate,
}: {
    calendar: TeamCalendarData;
    tasks: TeamCalendarTask[];
    context: TeamChipContext;
    onCreate?: (date: string) => void;
}) {
    const month = monthOf(calendar.date);
    const anchored = byAnchor(tasks);
    const days = daysOf(calendar.from, calendar.to).filter(
        (date) =>
            date.startsWith(month) && (anchored.get(date) ?? []).length > 0,
    );

    if (days.length === 0) {
        return (
            <p
                className="text-sm text-muted-foreground"
                data-test="team-month-list"
            >
                {t('team_calendar.month_empty')}
            </p>
        );
    }

    return (
        <ol className="grid gap-4" data-test="team-month-list">
            {days.map((date) => {
                const dayTasks = anchored.get(date) ?? [];

                return (
                    <li key={date}>
                        <DayCell
                            as="div"
                            id={`${DAY}${date}`}
                            date={date}
                            className="grid gap-1"
                        >
                            <h3 className="flex items-center justify-between gap-2 border-b pb-1 text-sm font-medium first-letter:uppercase">
                                <span>
                                    {dayLabel(date, context.today)}{' '}
                                    <span className="font-normal text-muted-foreground">
                                        ({dayTasks.length})
                                    </span>
                                </span>
                                <CreateButton date={date} onCreate={onCreate} />
                            </h3>
                            <ul className="grid gap-1">
                                {dayTasks.map((task) => (
                                    <li key={task.id}>
                                        <TeamChip
                                            task={task}
                                            dragId={`list:${task.id}`}
                                            context={context}
                                        />
                                    </li>
                                ))}
                            </ul>
                        </DayCell>
                    </li>
                );
            })}
        </ol>
    );
}

function WeekGrid({
    calendar,
    tasks,
    context,
    onCreate,
}: {
    calendar: TeamCalendarData;
    tasks: TeamCalendarTask[];
    context: TeamChipContext;
    onCreate?: (date: string) => void;
}) {
    const days = daysOf(calendar.from, calendar.to);
    const spans = teamSpans(tasks, days);
    const anchored = byAnchor(tasks);

    return (
        <table
            className="w-full min-w-[48rem] table-fixed border-collapse"
            data-test="team-week"
        >
            <caption className="sr-only">
                {t('team_calendar.caption', {
                    period: calendarTitle(
                        'week',
                        calendar.date,
                        calendar.from,
                        calendar.to,
                    ),
                })}
            </caption>
            <thead>
                <tr>
                    {days.map((date) => (
                        <th
                            key={date}
                            scope="col"
                            className="border-b px-2 py-1 text-left text-xs font-medium text-muted-foreground"
                        >
                            <DayHead date={date} today={context.today} />
                        </th>
                    ))}
                </tr>
            </thead>
            <tbody>
                <tr>
                    {days.map((date, column) => {
                        const dayTasks = anchored.get(date) ?? [];

                        return (
                            <DayCell
                                key={date}
                                id={`${DAY}${date}`}
                                date={date}
                                onCreate={onCreate}
                                className="h-72 border p-1 align-top"
                            >
                                <p className="mb-1 flex justify-end">
                                    <span className="sr-only">
                                        {dayLabel(date, context.today)}
                                    </span>
                                    <CreateButton
                                        date={date}
                                        onCreate={onCreate}
                                    />
                                </p>
                                <Lanes
                                    spans={spans}
                                    column={column}
                                    columns={7}
                                    context={context}
                                />
                                {dayTasks.length > 0 ? (
                                    <ul
                                        className="grid gap-1"
                                        aria-label={t(
                                            'planning.calendar.day_tasks',
                                            {
                                                date: formatDate(date),
                                                count: dayTasks.length,
                                            },
                                        )}
                                    >
                                        {dayTasks.map((task) => (
                                            <li key={task.id}>
                                                <TeamChip
                                                    task={task}
                                                    dragId={`grid:${task.id}`}
                                                    context={context}
                                                />
                                            </li>
                                        ))}
                                    </ul>
                                ) : null}
                            </DayCell>
                        );
                    })}
                </tr>
            </tbody>
        </table>
    );
}

function DayHead({ date, today }: { date: string; today: string }) {
    return (
        <>
            <span className="capitalize">{weekdayShortLabel(date)}</span>{' '}
            <span
                className={cn(
                    'tabular',
                    date === today && 'bg-primary px-1 text-primary-foreground',
                )}
            >
                {formatDate(date).slice(0, 5)}
            </span>
            {date === today ? (
                <span className="sr-only"> {t('planning.calendar.today')}</span>
            ) : null}
        </>
    );
}

/** Tareas de rango que siguen en curso ese día sin vencer ni empezar en él. */
function inProgress(
    tasks: TeamCalendarTask[],
    date: string,
): TeamCalendarTask[] {
    return tasks.filter(
        (task) =>
            isRange(task) && anchorDay(task) !== date && taskOnDay(task, date),
    );
}

function InProgressList({
    tasks,
    context,
}: {
    tasks: TeamCalendarTask[];
    context: TeamChipContext;
}) {
    if (tasks.length === 0) {
        return null;
    }

    return (
        <div className="grid gap-0.5">
            <p className="text-xs text-muted-foreground">
                {t('team_calendar.in_progress')}
            </p>
            <ul className="grid gap-0.5">
                {tasks.map((task) => (
                    <li key={task.id}>
                        <SpanButton
                            span={{
                                task,
                                continuesBefore: false,
                                continuesAfter: false,
                            }}
                            context={context}
                            style={{ width: '100%' }}
                        />
                    </li>
                ))}
            </ul>
        </div>
    );
}

function DayList({
    calendar,
    tasks,
    context,
    onCreate,
}: {
    calendar: TeamCalendarData;
    tasks: TeamCalendarTask[];
    context: TeamChipContext;
    onCreate?: (date: string) => void;
}) {
    const date = calendar.date;
    const dayTasks = tasks.filter((task) => anchorDay(task) === date);

    return (
        <DayCell
            as="div"
            id={`${DAY}${date}`}
            date={date}
            onCreate={onCreate}
            className="grid min-h-48 content-start gap-3 border p-3"
        >
            <div className="flex items-center justify-between gap-2">
                <h3 className="text-sm font-medium">
                    {t('team_calendar.day_due')}{' '}
                    <span className="font-normal text-muted-foreground">
                        ({dayTasks.length})
                    </span>
                </h3>
                <CreateButton date={date} onCreate={onCreate} />
            </div>
            {dayTasks.length > 0 ? (
                <ul className="grid gap-1 sm:grid-cols-2 xl:grid-cols-3">
                    {dayTasks.map((task) => (
                        <li key={task.id}>
                            <TeamChip
                                task={task}
                                dragId={`day:${task.id}`}
                                context={context}
                            />
                        </li>
                    ))}
                </ul>
            ) : (
                <p className="text-sm text-muted-foreground">
                    {t('team_calendar.day_empty')}
                </p>
            )}
            <InProgressList tasks={inProgress(tasks, date)} context={context} />
        </DayCell>
    );
}

/** Indicador de carga de una persona un día: icono, cifras y porcentaje (nunca solo color). */
export function LoadBadge({ day }: { day: TeamCalendarDay }) {
    if (day.capacity === null) {
        return null;
    }

    if (day.load === null) {
        return (
            <span
                className="tabular text-xs text-muted-foreground"
                title={t('team_calendar.capacity_only', {
                    capacity: formatMinutes(day.capacity),
                })}
            >
                {t('team_calendar.capacity_short', {
                    capacity: formatMinutes(day.capacity),
                })}
            </span>
        );
    }

    const level = loadLevel(day.load, day.capacity);
    const meta = LOAD_LEVELS[level];
    const Icon = meta.icon;
    const percent = formatLoadPercent(day.load, day.capacity);
    const text = t('team_calendar.load', {
        load: formatMinutes(day.load),
        capacity: formatMinutes(day.capacity),
    });

    return (
        <span
            className={cn(
                'tabular inline-flex items-center gap-1 px-1 text-xs',
                meta.surface,
            )}
            title={`${meta.label}: ${text}${percent ? ` (${percent})` : ''}`}
            data-test="team-load"
            data-level={level}
        >
            <Icon aria-hidden="true" className={cn('size-3.5', meta.tone)} />
            <span className="sr-only">{meta.label}: </span>
            {text}
        </span>
    );
}

function PersonDayMarks({ day }: { day: TeamCalendarDay }) {
    return (
        <>
            {day.holiday ? (
                <span className="inline-flex items-center gap-1 bg-neutral-soft px-1 text-xs">
                    <CalendarOff aria-hidden="true" className="size-3.5" />
                    {t('team_calendar.holiday', { name: day.holiday })}
                </span>
            ) : null}
            {day.absence ? (
                <span
                    className="inline-flex items-center gap-1 bg-neutral-soft px-1 text-xs"
                    data-test="team-absence"
                >
                    <UserX aria-hidden="true" className="size-3.5" />
                    {day.absence.partial
                        ? t('team_calendar.absent_partial')
                        : t('team_calendar.absent')}
                    {day.absence.label ? ` · ${day.absence.label}` : ''}
                </span>
            ) : null}
            <LoadBadge day={day} />
        </>
    );
}

/**
 * Vista «Personas» (D-144): una fila por persona (y «Sin asignar»), una columna por día; en cada
 * celda, sus tareas de ese día, su carga y si está ausente o es festivo. En la semana, las tareas de
 * rango van como franja dentro de su fila; en el día, como «En curso».
 */
function PeopleGrid({
    calendar,
    tasks,
    context,
    onCreate,
}: {
    calendar: TeamCalendarData;
    tasks: TeamCalendarTask[];
    context: TeamChipContext;
    onCreate?: (date: string) => void;
}) {
    const days = daysOf(calendar.from, calendar.to);
    const single = days.length === 1;
    const rowTasks = (row: TeamCalendarRow) =>
        tasks.filter((task) =>
            row.person === null
                ? task.assignee_id === null
                : task.assignee_id === row.person.id,
        );
    // «Sin asignar» solo si tiene tareas.
    const rows = calendar.rows.filter(
        (row) => row.person !== null || rowTasks(row).length > 0,
    );

    return (
        <table
            className={cn(
                'w-full table-fixed border-collapse',
                single ? 'min-w-[32rem]' : 'min-w-[60rem]',
            )}
            data-test="team-people"
        >
            <caption className="sr-only">
                {t('team_calendar.people_caption', {
                    period: calendarTitle(
                        calendar.view,
                        calendar.date,
                        calendar.from,
                        calendar.to,
                    ),
                })}
            </caption>
            <colgroup>
                <col className="w-48" />
                {days.map((date) => (
                    <col key={date} />
                ))}
            </colgroup>
            <thead>
                <tr>
                    <th
                        scope="col"
                        className="border-b px-2 py-1 text-left text-xs font-medium text-muted-foreground"
                    >
                        {t('team_calendar.person')}
                    </th>
                    {days.map((date) => (
                        <th
                            key={date}
                            scope="col"
                            className="border-b px-2 py-1 text-left text-xs font-medium text-muted-foreground"
                        >
                            <DayHead date={date} today={context.today} />
                        </th>
                    ))}
                </tr>
            </thead>
            <tbody>
                {rows.map((row) => {
                    const mine = rowTasks(row);
                    const anchored = byAnchor(mine);
                    const spans = single ? [] : teamSpans(mine, days);
                    const key = row.person === null ? 'none' : row.person.id;

                    return (
                        <tr key={key} data-test="team-person-row">
                            <th
                                scope="row"
                                className="border p-2 text-left align-top font-normal"
                            >
                                {row.person ? (
                                    <span className="flex min-w-0 items-center gap-2">
                                        <UserAvatar
                                            user={row.person}
                                            className="size-7"
                                        />
                                        <span className="min-w-0">
                                            <span className="block truncate text-sm">
                                                {row.person.name}
                                            </span>
                                            <span className="block truncate text-xs text-muted-foreground">
                                                {row.person.department?.name ??
                                                    t(
                                                        'team_calendar.no_department',
                                                    )}
                                            </span>
                                        </span>
                                    </span>
                                ) : (
                                    <span className="flex items-center gap-2 text-sm">
                                        <Users
                                            aria-hidden="true"
                                            className="size-5 text-muted-foreground"
                                        />
                                        {t('task_fields.no_assignee')}
                                    </span>
                                )}
                            </th>
                            {days.map((date, column) => {
                                const day = row.days[date];
                                const dayTasks = anchored.get(date) ?? [];

                                return (
                                    <DayCell
                                        key={date}
                                        id={`${DAY}${date}:${key}`}
                                        date={date}
                                        onCreate={onCreate}
                                        className={cn(
                                            'border p-1 align-top',
                                            (day?.absence &&
                                                !day.absence.partial) ||
                                                day?.holiday
                                                ? 'bg-muted'
                                                : undefined,
                                        )}
                                    >
                                        <span className="sr-only">
                                            {row.person?.name ??
                                                t('task_fields.no_assignee')}
                                            , {dayLabel(date, context.today)}
                                        </span>
                                        {day ? (
                                            <div className="mb-1 flex flex-wrap items-center gap-1">
                                                <PersonDayMarks day={day} />
                                            </div>
                                        ) : null}
                                        <Lanes
                                            spans={spans}
                                            column={column}
                                            columns={days.length}
                                            context={context}
                                        />
                                        {dayTasks.length > 0 ? (
                                            <ul className="grid gap-1">
                                                {dayTasks.map((task) => (
                                                    <li key={task.id}>
                                                        <TeamChip
                                                            task={task}
                                                            dragId={`person:${key}:${task.id}`}
                                                            context={context}
                                                            compact={!single}
                                                        />
                                                    </li>
                                                ))}
                                            </ul>
                                        ) : null}
                                        {single ? (
                                            <InProgressList
                                                tasks={inProgress(mine, date)}
                                                context={context}
                                            />
                                        ) : null}
                                        {/* Plan del día (D-254), en la vista Día. */}
                                        {single &&
                                        row.person &&
                                        calendar.day_plans ? (
                                            <CalendarDayPlan
                                                lines={
                                                    calendar.day_plans[
                                                        row.person.id
                                                    ]
                                                }
                                                name={row.person.name}
                                            />
                                        ) : null}
                                    </DayCell>
                                );
                            })}
                        </tr>
                    );
                })}
            </tbody>
        </table>
    );
}

/** Leyenda: icono y nombre de cada estado y el rombo de los hitos (nunca solo color). */
function Legend({ statuses }: { statuses: TaskStatus[] }) {
    return (
        <ul
            className="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground"
            aria-label={t('planning.calendar.legend')}
        >
            {statuses.map((status) => (
                <li key={status.id} className="inline-flex items-center gap-1">
                    <StatusIcon status={status} />
                    {status.name}
                </li>
            ))}
            <li className="inline-flex items-center gap-1">
                <Diamond
                    aria-hidden="true"
                    className="size-3.5 fill-current text-primary-text"
                />
                {t('task_fields.milestone')}
            </li>
            <li className="inline-flex items-center gap-1">
                <CalendarRange
                    aria-hidden="true"
                    className="size-3.5 text-primary-text"
                />
                {t('team_calendar.legend_range')}
            </li>
        </ul>
    );
}

type MovedTask = { id: number; title: string; day: string };

/**
 * Calendario del equipo (D-144): mes, semana o día y la vista «Personas» (semana y día), con
 * «Hoy», anterior y siguiente. Pulsar una tarea abre su panel; quien puede editarla la cambia de
 * día arrastrándola o con el teclado (siempre con la propuesta de desplazar sucesoras, D-057); un
 * clic en el hueco de un día (o su «+») crea una tarea ese día. En el móvil, el mes es una lista
 * por día.
 */
export function TeamCalendar({
    calendar,
    statuses,
    context: base,
    loading = false,
    onNavigate,
    onCreate,
    onBusyChange,
}: {
    calendar: TeamCalendarData;
    statuses: TaskStatus[];
    /** Mapas de estados, proyectos y responsables y la apertura del panel. */
    context: Pick<
        TeamChipContext,
        'statusById' | 'projectById' | 'assigneeById' | 'onOpen'
    >;
    loading?: boolean;
    onNavigate: (view: TeamCalendarView, date: string, people: boolean) => void;
    onCreate?: (date: string) => void;
    onBusyChange?: (busy: boolean) => void;
}) {
    const isMobile = useIsMobile();
    const helpId = useId();
    const titleId = useId();
    const peopleId = useId();
    const [announcement, setAnnouncement] = useState('');
    const [dragging, setDragging] = useState<TeamCalendarTask | null>(null);
    const reschedule = useReschedule();
    const regionRef = useRef<HTMLDivElement>(null);
    const keepFocusOn = useRef<MovedTask | null>(null);
    const lastMoved = useRef<MovedTask | null>(null);

    useEffect(() => {
        onBusyChange?.(reschedule.busy);
    }, [reschedule.busy, onBusyChange]);

    // Mientras se mueve una tarea, se enseña ya en su día nuevo (vuelve si se cancela o falla).
    const moving = reschedule.moving;
    const tasks = calendar.tasks.map((task) =>
        moving && task.id === moving.taskId
            ? { ...task, ...moving.dates }
            : task,
    );

    /** Lleva el foco a la tarea movida o, si su día no se ve, a la región del calendario. */
    const focusTask = (moved: MovedTask): boolean => {
        const chip = regionRef.current?.querySelector<HTMLElement>(
            `[data-test="team-chip"][data-task-id="${moved.id}"]`,
        );
        const target = chip ?? regionRef.current;

        if (target && document.activeElement !== target) {
            target.focus();
        }

        return chip !== null && chip !== undefined;
    };

    useEffect(() => {
        const moved = keepFocusOn.current;

        if (moved === null || reschedule.pending !== null) {
            return;
        }

        const visible = focusTask(moved);

        if (reschedule.moving === null) {
            keepFocusOn.current = null;

            if (!visible) {
                setAnnouncement(
                    t('planning.keyboard.moved_outside', {
                        task: moved.title,
                        date: formatDate(moved.day),
                    }),
                );
            }
        }
    });

    const move = (task: TeamCalendarTask, day: string, keepFocus = false) => {
        if (
            base.projectById.get(task.project_id)?.can_update !== true ||
            day === anchorDay(task)
        ) {
            return;
        }

        if (reschedule.isBusy()) {
            setAnnouncement(t('planning.keyboard.busy'));

            return;
        }

        const moved = { id: task.id, title: task.title, day };
        keepFocusOn.current = keepFocus ? moved : null;
        lastMoved.current = moved;
        setAnnouncement(
            t('planning.keyboard.moving', {
                task: task.title,
                date: formatDate(day),
            }),
        );
        void reschedule.request(task, movedTeamDates(task, day));
    };

    const context: TeamChipContext = {
        ...base,
        today: calendar.today,
        locked: reschedule.busy,
        helpId,
        onMove: move,
        onAnnounce: setAnnouncement,
    };

    const sensors = useSensors(
        useSensor(MouseSensor, { activationConstraint: { distance: 6 } }),
        useSensor(TouchSensor, {
            activationConstraint: { delay: 250, tolerance: 6 },
        }),
        // Sin el sensor de teclado de dnd-kit: con el teclado, las flechas de cada tarjeta.
    );

    const titleOf = (data: unknown): string =>
        (data as { task?: TeamCalendarTask } | undefined)?.task?.title ?? '';

    const announcements: Announcements = {
        onDragStart: ({ active }) =>
            t('planning.drag.start', { task: titleOf(active.data.current) }),
        onDragOver: ({ active, over }) => {
            const day = dayOfDrop(over?.id);

            return day
                ? t('planning.drag.over', {
                      task: titleOf(active.data.current),
                      date: formatDate(day),
                  })
                : t('planning.drag.outside', {
                      task: titleOf(active.data.current),
                  });
        },
        onDragEnd: ({ active, over }) => {
            const day = dayOfDrop(over?.id);

            return day
                ? t('planning.keyboard.moving', {
                      task: titleOf(active.data.current),
                      date: formatDate(day),
                  })
                : t('planning.keyboard.cancelled', {
                      task: titleOf(active.data.current),
                  });
        },
        onDragCancel: ({ active }) =>
            t('planning.keyboard.cancelled', {
                task: titleOf(active.data.current),
            }),
    };

    const onDragStart = ({ active }: DragStartEvent) =>
        setDragging(
            (active.data.current as { task?: TeamCalendarTask } | undefined)
                ?.task ?? null,
        );

    const onDragEnd = ({ active, over }: DragEndEvent) => {
        setDragging(null);
        const task = (
            active.data.current as { task?: TeamCalendarTask } | undefined
        )?.task;
        const day = dayOfDrop(over?.id);

        if (task && day) {
            move(task, day);
        }
    };

    const view = calendar.view;
    const people = calendar.people_view && view !== 'month';
    const title = calendarTitle(
        view,
        calendar.date,
        calendar.from,
        calendar.to,
    );
    const previousLabel = t(`team_calendar.previous.${view}`);
    const nextLabel = t(`team_calendar.next.${view}`);
    const empty = tasks.length === 0;
    const canEditAny = [...base.projectById.values()].some(
        (project) => project.can_update,
    );

    return (
        <div className="flex min-w-0 flex-col gap-4" data-test="team-calendar">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex flex-wrap items-center gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        className="size-8"
                        onClick={() =>
                            onNavigate(
                                view,
                                shiftDate(view, calendar.date, -1),
                                people,
                            )
                        }
                        aria-label={previousLabel}
                        title={previousLabel}
                    >
                        <ChevronLeft aria-hidden="true" />
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() => onNavigate(view, calendar.today, people)}
                        data-test="team-today"
                    >
                        {t('planning.calendar.today')}
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        className="size-8"
                        onClick={() =>
                            onNavigate(
                                view,
                                shiftDate(view, calendar.date, 1),
                                people,
                            )
                        }
                        aria-label={nextLabel}
                        title={nextLabel}
                    >
                        <ChevronRight aria-hidden="true" />
                    </Button>
                    <h2
                        id={titleId}
                        className="text-lg first-letter:uppercase"
                        data-test="team-title"
                    >
                        {title}
                    </h2>
                </div>
                <div className="flex flex-wrap items-center gap-4">
                    <ToggleGroup
                        type="single"
                        variant="outline"
                        // Mismo alto (h-8) que «Anterior», «Hoy» y «Siguiente».
                        size="sm"
                        value={view}
                        disabled={reschedule.busy}
                        onValueChange={(next) => {
                            if (
                                (next === 'month' ||
                                    next === 'week' ||
                                    next === 'day') &&
                                next !== view
                            ) {
                                onNavigate(next, calendar.date, people);
                            }
                        }}
                        aria-label={t('team_calendar.view')}
                    >
                        <ToggleGroupItem value="month" className="gap-1.5 px-3">
                            <CalendarDays aria-hidden="true" />
                            {t('team_calendar.views.month')}
                        </ToggleGroupItem>
                        <ToggleGroupItem value="week" className="gap-1.5 px-3">
                            <CalendarRange aria-hidden="true" />
                            {t('team_calendar.views.week')}
                        </ToggleGroupItem>
                        <ToggleGroupItem value="day" className="gap-1.5 px-3">
                            <CalendarDays aria-hidden="true" />
                            {t('team_calendar.views.day')}
                        </ToggleGroupItem>
                    </ToggleGroup>
                    <div className="flex items-center gap-2">
                        <Switch
                            id={peopleId}
                            checked={people}
                            disabled={view === 'month' || reschedule.busy}
                            aria-describedby={
                                view === 'month'
                                    ? `${peopleId}-hint`
                                    : undefined
                            }
                            onCheckedChange={(checked) =>
                                onNavigate(view, calendar.date, checked)
                            }
                            data-test="team-people-toggle"
                        />
                        <label htmlFor={peopleId} className="text-sm">
                            {t('team_calendar.by_people')}
                        </label>
                        {view === 'month' ? (
                            <span id={`${peopleId}-hint`} className="sr-only">
                                {t('team_calendar.by_people_hint')}
                            </span>
                        ) : null}
                    </div>
                </div>
            </div>

            <Legend statuses={statuses} />

            <p id={helpId} className="text-xs text-muted-foreground">
                {canEditAny
                    ? t('team_calendar.help')
                    : t('team_calendar.read_only')}
            </p>

            {calendar.truncated ? (
                <p
                    role="status"
                    className="flex items-center gap-2 border border-warning bg-warning-soft px-3 py-2 text-sm"
                    data-test="team-truncated"
                >
                    <TriangleAlert
                        aria-hidden="true"
                        className="size-4 shrink-0 text-warning"
                    />
                    {t('team_calendar.truncated', {
                        shown: calendar.limit,
                        total: calendar.total,
                    })}
                </p>
            ) : null}

            {empty && !people ? (
                <p role="status" className="text-sm text-muted-foreground">
                    {t('team_calendar.empty', { period: title })}
                </p>
            ) : null}

            <DndContext
                sensors={sensors}
                collisionDetection={dropUnderPointer}
                onDragStart={onDragStart}
                onDragEnd={onDragEnd}
                onDragCancel={() => setDragging(null)}
                accessibility={{
                    announcements,
                    screenReaderInstructions: {
                        draggable: t('team_calendar.help'),
                    },
                }}
            >
                <div
                    ref={regionRef}
                    className={cn(
                        'min-w-0 overflow-x-auto transition-opacity',
                        loading && 'opacity-60',
                        FOCUS_RING,
                    )}
                    role="region"
                    aria-labelledby={titleId}
                    aria-busy={loading || undefined}
                    tabIndex={0}
                    data-test="team-region"
                >
                    {people ? (
                        <PeopleGrid
                            calendar={calendar}
                            tasks={tasks}
                            context={context}
                            onCreate={onCreate}
                        />
                    ) : view === 'month' ? (
                        isMobile ? (
                            <MonthList
                                calendar={calendar}
                                tasks={tasks}
                                context={context}
                                onCreate={onCreate}
                            />
                        ) : (
                            <MonthGrid
                                calendar={calendar}
                                tasks={tasks}
                                context={context}
                                onCreate={onCreate}
                            />
                        )
                    ) : view === 'week' ? (
                        <WeekGrid
                            calendar={calendar}
                            tasks={tasks}
                            context={context}
                            onCreate={onCreate}
                        />
                    ) : (
                        <DayList
                            calendar={calendar}
                            tasks={tasks}
                            context={context}
                            onCreate={onCreate}
                        />
                    )}
                </div>
                <DragOverlay>
                    {dragging ? (
                        <div className="w-44 border bg-card px-1.5 py-0.5 text-xs">
                            <span className="block truncate">
                                {dragging.title}
                            </span>
                        </div>
                    ) : null}
                </DragOverlay>
            </DndContext>

            <p className="sr-only" role="status" data-test="team-live">
                {announcement}
            </p>

            <RescheduleDialog
                pending={reschedule.pending}
                saving={reschedule.saving}
                onConfirm={reschedule.confirm}
                onCancel={reschedule.cancel}
                onCloseAutoFocus={(event) => {
                    event.preventDefault();

                    if (lastMoved.current !== null) {
                        focusTask(lastMoved.current);
                    }
                }}
            />
        </div>
    );
}
