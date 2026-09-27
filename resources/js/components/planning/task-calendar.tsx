import {
    DndContext,
    DragOverlay,
    MouseSensor,
    TouchSensor,
    useDroppable,
    useSensor,
    useSensors,
} from '@dnd-kit/core';
import type {
    Announcements,
    DragEndEvent,
    DragStartEvent,
} from '@dnd-kit/core';
import {
    CalendarDays,
    CalendarRange,
    ChevronLeft,
    ChevronRight,
    Diamond,
} from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { DatePicker } from '@/components/domain/date-picker';
import { CalendarChip, StatusIcon } from '@/components/planning/calendar-chip';
import type { ChipMoveHandler } from '@/components/planning/calendar-chip';
import {
    modeSwitchPeriod,
    monthGrid,
    monthLabel,
    movedDates,
    periodContaining,
    shiftPeriod,
    tasksByDueDate,
    weekOf,
    weekSpans,
} from '@/components/planning/calendar-dates';
import type { WeekSpan } from '@/components/planning/calendar-dates';
import { RescheduleDialog } from '@/components/planning/reschedule-dialog';
import { useReschedule } from '@/components/planning/use-reschedule';
import { useTaskLookups } from '@/components/tasks/task-lookups';
import {
    weekdayLongLabel,
    weekdayShortLabel,
    weekRangeLabel,
} from '@/components/time/week-days';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatDate } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type {
    CalendarMode,
    CalendarTask,
    TaskCalendarData,
} from '@/types/planning';

/** Tareas que caben en un día del mes; el resto, en «+N más». */
export const MONTH_VISIBLE = 3;

const DAY = 'day:';

type ChipContext = {
    today: string;
    canEdit: boolean;
    helpId: string;
    onOpen: (taskId: number) => void;
    onMove: ChipMoveHandler;
    onAnnounce: (message: string) => void;
};

function dayLabel(date: string, today: string): string {
    const label = `${weekdayLongLabel(date)}, ${formatDate(date)}`;

    return date === today
        ? t('planning.calendar.today_label', { date: label })
        : label;
}

/** Periodo visible: «octubre de 2026» o «12 oct – 18 oct 2026». */
export function periodTitle(
    calendar: Pick<TaskCalendarData, 'mode' | 'period' | 'from' | 'to'>,
): string {
    return calendar.mode === 'month'
        ? monthLabel(calendar.period)
        : weekRangeLabel(calendar.from, calendar.to);
}

/** Día de la rejilla donde se puede soltar una tarea. */
function DroppableDay({
    date,
    className,
    children,
    ...rest
}: {
    date: string;
    className?: string;
    children: ReactNode;
    [key: `data-${string}`]: string | undefined;
}) {
    const { setNodeRef, isOver } = useDroppable({ id: `${DAY}${date}` });

    return (
        <td
            ref={setNodeRef}
            className={cn(className, isOver && 'bg-accent')}
            {...rest}
        >
            {children}
        </td>
    );
}

function DayHeader({
    date,
    today,
    muted,
}: {
    date: string;
    today: string;
    muted: boolean;
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
                    'tabular inline-flex size-6 items-center justify-center rounded-[3px]',
                    isToday && 'bg-primary font-medium text-primary-foreground',
                )}
            >
                {Number(date.slice(8, 10))}
            </span>
            {isToday ? (
                <span aria-hidden="true" className="text-primary-text">
                    {t('planning.calendar.today')}
                </span>
            ) : null}
        </p>
    );
}

function MoreTasks({
    date,
    tasks,
    hidden,
    context,
}: {
    date: string;
    tasks: CalendarTask[];
    /** Las que no caben en el día. */
    hidden: number;
    context: ChipContext;
}) {
    const [open, setOpen] = useState(false);

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <button
                    type="button"
                    className={cn(
                        'w-full rounded-[3px] px-1.5 py-0.5 text-left text-xs text-primary-text hover:underline',
                        FOCUS_RING,
                    )}
                    aria-label={t('planning.calendar.more_label', {
                        count: hidden,
                        date: formatDate(date),
                    })}
                    data-test="calendar-more"
                >
                    {t('planning.calendar.more', { count: hidden })}
                </button>
            </PopoverTrigger>
            <PopoverContent className="w-72 p-2" align="start">
                <p className="mb-2 px-1 text-sm font-medium capitalize">
                    {dayLabel(date, context.today)}
                </p>
                <ul className="grid max-h-72 gap-1 overflow-y-auto">
                    {tasks.map((task) => (
                        <li key={task.id}>
                            <CalendarChip
                                task={task}
                                dragId={`more:${task.id}`}
                                draggable={false}
                                today={context.today}
                                canEdit={context.canEdit}
                                helpId={context.helpId}
                                onOpen={(taskId) => {
                                    setOpen(false);
                                    context.onOpen(taskId);
                                }}
                                onMove={(moved, newDue, keepFocus) => {
                                    setOpen(false);
                                    context.onMove(moved, newDue, keepFocus);
                                }}
                                onAnnounce={context.onAnnounce}
                            />
                        </li>
                    ))}
                </ul>
            </PopoverContent>
        </Popover>
    );
}

function MonthGrid({
    calendar,
    byDay,
    context,
}: {
    calendar: TaskCalendarData;
    byDay: Map<string, CalendarTask[]>;
    context: ChipContext;
}) {
    const weeks = monthGrid(calendar.period);

    return (
        <table
            className="w-full min-w-[44rem] table-fixed border-collapse"
            data-test="calendar-month"
        >
            <caption className="sr-only">
                {t('planning.calendar.caption', {
                    period: periodTitle(calendar),
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
                {weeks.map((week) => (
                    <tr key={week[0]}>
                        {week.map((date) => {
                            const tasks = byDay.get(date) ?? [];
                            const visible = tasks.slice(0, MONTH_VISIBLE);
                            const hidden = tasks.length - visible.length;
                            const outside = !date.startsWith(calendar.period);

                            return (
                                <DroppableDay
                                    key={date}
                                    date={date}
                                    className={cn(
                                        'h-28 border p-1 align-top',
                                        outside && 'bg-muted',
                                    )}
                                    data-date={date}
                                >
                                    <DayHeader
                                        date={date}
                                        today={context.today}
                                        muted={outside}
                                    />
                                    {tasks.length > 0 ? (
                                        <ul
                                            className="grid gap-1"
                                            aria-label={t(
                                                'planning.calendar.day_tasks',
                                                {
                                                    date: formatDate(date),
                                                    count: tasks.length,
                                                },
                                            )}
                                        >
                                            {visible.map((task) => (
                                                <li key={task.id}>
                                                    <CalendarChip
                                                        task={task}
                                                        dragId={`grid:${task.id}`}
                                                        {...context}
                                                    />
                                                </li>
                                            ))}
                                            {hidden > 0 ? (
                                                <li>
                                                    <MoreTasks
                                                        date={date}
                                                        tasks={tasks}
                                                        hidden={hidden}
                                                        context={context}
                                                    />
                                                </li>
                                            ) : null}
                                        </ul>
                                    ) : null}
                                </DroppableDay>
                            );
                        })}
                    </tr>
                ))}
            </tbody>
        </table>
    );
}

/** Filas de franjas de la vista semana: cada fila reparte sus franjas en celdas con colSpan. */
function spanRows(
    spans: WeekSpan[],
): { lane: number; cells: ({ span: WeekSpan } | { empty: number })[] }[] {
    const lanes = new Map<number, WeekSpan[]>();

    for (const span of spans) {
        lanes.set(span.lane, [...(lanes.get(span.lane) ?? []), span]);
    }

    return [...lanes.entries()]
        .sort(([a], [b]) => a - b)
        .map(([lane, items]) => {
            const cells: ({ span: WeekSpan } | { empty: number })[] = [];
            let column = 0;

            for (const span of [...items].sort(
                (a, b) => a.startColumn - b.startColumn,
            )) {
                if (span.startColumn > column) {
                    cells.push({ empty: span.startColumn - column });
                }

                cells.push({ span });
                column = span.endColumn + 1;
            }

            if (column < 7) {
                cells.push({ empty: 7 - column });
            }

            return { lane, cells };
        });
}

function WeekGrid({
    calendar,
    byDay,
    context,
}: {
    calendar: TaskCalendarData;
    byDay: Map<string, CalendarTask[]>;
    context: ChipContext;
}) {
    const days = weekOf(calendar.period);
    const rows = spanRows(weekSpans(calendar.tasks, days));

    return (
        <table
            className="w-full min-w-[44rem] table-fixed border-collapse"
            data-test="calendar-week"
        >
            <caption className="sr-only">
                {t('planning.calendar.caption', {
                    period: periodTitle(calendar),
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
                            <span className="capitalize">
                                {weekdayShortLabel(date)}
                            </span>{' '}
                            <span
                                className={cn(
                                    'tabular',
                                    date === context.today &&
                                        'rounded-[3px] bg-primary px-1 text-primary-foreground',
                                )}
                            >
                                {formatDate(date).slice(0, 5)}
                            </span>
                            {date === context.today ? (
                                <span className="sr-only">
                                    {' '}
                                    {t('planning.calendar.today')}
                                </span>
                            ) : null}
                        </th>
                    ))}
                </tr>
            </thead>
            <tbody>
                {rows.map((row) => (
                    <tr key={row.lane} data-test="calendar-span-row">
                        {row.cells.map((cell, index) =>
                            'span' in cell ? (
                                <td
                                    key={`span-${cell.span.task.id}`}
                                    colSpan={
                                        cell.span.endColumn -
                                        cell.span.startColumn +
                                        1
                                    }
                                    className="px-0.5 py-0.5"
                                >
                                    <button
                                        type="button"
                                        onClick={() =>
                                            context.onOpen(cell.span.task.id)
                                        }
                                        className={cn(
                                            'flex w-full min-w-0 items-center gap-1 border border-info bg-info-soft px-1.5 py-0.5 text-left text-xs text-foreground hover:underline',
                                            cell.span.continuesBefore
                                                ? 'rounded-l-none border-l-0'
                                                : 'rounded-l-[3px]',
                                            cell.span.continuesAfter
                                                ? 'rounded-r-none border-r-0'
                                                : 'rounded-r-[3px]',
                                            FOCUS_RING,
                                        )}
                                        aria-label={t(
                                            'planning.calendar.span_label',
                                            {
                                                task: cell.span.task.title,
                                                start: formatDate(
                                                    cell.span.task.start_date,
                                                ),
                                                due: formatDate(
                                                    cell.span.task.due_date,
                                                ),
                                            },
                                        )}
                                        data-test="calendar-span"
                                        data-task-id={cell.span.task.id}
                                    >
                                        {cell.span.continuesBefore ? (
                                            <ChevronLeft
                                                aria-hidden="true"
                                                className="size-3 shrink-0"
                                            />
                                        ) : null}
                                        <CalendarRange
                                            aria-hidden="true"
                                            className="size-3.5 shrink-0 text-primary-text"
                                        />
                                        <span className="truncate">
                                            {cell.span.task.title}
                                        </span>
                                        {cell.span.continuesAfter ? (
                                            <ChevronRight
                                                aria-hidden="true"
                                                className="ml-auto size-3 shrink-0"
                                            />
                                        ) : null}
                                    </button>
                                </td>
                            ) : (
                                <td
                                    key={`empty-${index}`}
                                    colSpan={cell.empty}
                                    aria-hidden="true"
                                />
                            ),
                        )}
                    </tr>
                ))}
                <tr>
                    {days.map((date) => {
                        const tasks = byDay.get(date) ?? [];

                        return (
                            <DroppableDay
                                key={date}
                                date={date}
                                className="h-64 border p-1 align-top"
                                data-date={date}
                            >
                                <span className="sr-only">
                                    {dayLabel(date, context.today)}
                                </span>
                                {tasks.length > 0 ? (
                                    <ul
                                        className="grid gap-1"
                                        aria-label={t(
                                            'planning.calendar.day_tasks',
                                            {
                                                date: formatDate(date),
                                                count: tasks.length,
                                            },
                                        )}
                                    >
                                        {tasks.map((task) => (
                                            <li key={task.id}>
                                                <CalendarChip
                                                    task={task}
                                                    dragId={`grid:${task.id}`}
                                                    showDates
                                                    {...context}
                                                />
                                            </li>
                                        ))}
                                    </ul>
                                ) : null}
                            </DroppableDay>
                        );
                    })}
                </tr>
            </tbody>
        </table>
    );
}

function UndatedList({
    tasks,
    total,
    context,
}: {
    tasks: CalendarTask[];
    total: number;
    context: ChipContext;
}) {
    const headingId = useId();

    return (
        <section
            aria-labelledby={headingId}
            className="grid content-start gap-2 rounded-[3px] border bg-card p-3"
            data-test="calendar-undated"
        >
            <h3 id={headingId} className="text-sm font-medium">
                {t('planning.undated.title')}{' '}
                <span className="font-normal text-muted-foreground">
                    ({total})
                </span>
            </h3>
            <p className="text-xs text-muted-foreground">
                {t('planning.undated.description')}
            </p>
            {tasks.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    {t('planning.undated.empty')}
                </p>
            ) : (
                <ul className="grid max-h-[32rem] gap-2 overflow-y-auto">
                    {tasks.map((task) => (
                        <li key={task.id} className="grid gap-1">
                            <CalendarChip
                                task={task}
                                dragId={`undated:${task.id}`}
                                showDates
                                {...context}
                            />
                            {context.canEdit ? (
                                <DatePicker
                                    value={null}
                                    clearable={false}
                                    placeholder={t('planning.undated.assign')}
                                    aria-label={t(
                                        'planning.undated.assign_label',
                                        { task: task.title },
                                    )}
                                    onChange={(date) => {
                                        if (date) {
                                            context.onMove(task, date, true);
                                        }
                                    }}
                                    className="h-7 w-auto self-start px-2 text-xs"
                                />
                            ) : null}
                        </li>
                    ))}
                </ul>
            )}
            {total > tasks.length ? (
                <p className="text-xs text-muted-foreground">
                    {t('planning.undated.truncated', {
                        shown: tasks.length,
                        total,
                    })}
                </p>
            ) : null}
        </section>
    );
}

/** Leyenda: icono y nombre de cada estado y el rombo de los hitos (nunca solo color). */
function Legend() {
    const { statuses } = useTaskLookups();

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
        </ul>
    );
}

/**
 * Vista Calendario de la pestaña Tareas (D-061): un mes (semanas desde el lunes) o una semana,
 * con cada tarea el día de su entrega (y, en la semana, la franja del inicio a la entrega), los
 * hitos con su rombo y una lista lateral de las tareas sin fecha. Pulsar una tarea abre su
 * panel; quien puede editar la cambia de día arrastrándola, con el teclado o con «Asignar
 * fecha», siempre con la propuesta de desplazar sucesoras (D-057). Los demás, en solo lectura.
 */
export function TaskCalendar({
    calendar,
    loading = false,
    onOpen,
    onNavigate,
}: {
    calendar: TaskCalendarData;
    loading?: boolean;
    onOpen: (taskId: number) => void;
    /** Cambia de periodo o de escala (la página lo lleva a la URL: ?mes= o ?semana=). */
    onNavigate: (mode: CalendarMode, period: string) => void;
}) {
    const lookups = useTaskLookups();
    const helpId = useId();
    const titleId = useId();
    const [announcement, setAnnouncement] = useState('');
    const [dragging, setDragging] = useState<CalendarTask | null>(null);
    const reschedule = useReschedule();
    const canEdit = lookups.can.update;

    // Mientras se mueve una tarea se enseña ya en su día nuevo (vuelve si se cancela o falla).
    const moving = reschedule.moving;
    const all = [...calendar.tasks, ...calendar.undated].map((task) =>
        moving && task.id === moving.taskId
            ? { ...task, ...moving.dates }
            : task,
    );
    const dated = all.filter((task) => task.due_date !== null);
    const undated = all.filter((task) => task.due_date === null);
    const byDay = tasksByDueDate(dated);

    // Tarea que debe conservar el foco al moverla con el teclado: al cambiar de día su botón se
    // vuelve a crear en otra celda (y otra vez si se cancela), así que se le devuelve el foco
    // cuando no hay un diálogo abierto; se olvida al terminar el movimiento.
    const keepFocusOn = useRef<number | null>(null);
    // Última tarea movida: al cerrar el diálogo de conflictos, el foco vuelve a ella.
    const lastMoved = useRef<number | null>(null);

    const focusChip = (taskId: number) => {
        const chip = document.querySelector<HTMLElement>(
            `[data-test="calendar-chip"][data-task-id="${taskId}"]`,
        );

        if (chip && document.activeElement !== chip) {
            chip.focus();
        }
    };

    useEffect(() => {
        const taskId = keepFocusOn.current;

        if (taskId === null || reschedule.pending !== null) {
            return;
        }

        focusChip(taskId);

        if (reschedule.moving === null) {
            keepFocusOn.current = null;
        }
    });

    const move: ChipMoveHandler = (task, newDue, keepFocus = false) => {
        if (!canEdit || newDue === task.due_date) {
            return;
        }

        keepFocusOn.current = keepFocus ? task.id : null;
        lastMoved.current = task.id;
        const dates = movedDates(task, newDue);
        setAnnouncement(
            t('planning.keyboard.moving', {
                task: task.title,
                date: formatDate(newDue),
            }),
        );
        void reschedule.request(task, dates);
    };

    const context: ChipContext = {
        today: calendar.today,
        canEdit,
        helpId,
        onOpen,
        onMove: move,
        onAnnounce: setAnnouncement,
    };

    const sensors = useSensors(
        useSensor(MouseSensor, { activationConstraint: { distance: 6 } }),
        useSensor(TouchSensor, {
            activationConstraint: { delay: 250, tolerance: 6 },
        }),
    );

    const titleOf = (data: unknown): string =>
        (data as { task?: CalendarTask } | undefined)?.task?.title ?? '';
    const dayOf = (id: string | number | undefined): string | null =>
        typeof id === 'string' && id.startsWith(DAY)
            ? id.slice(DAY.length)
            : null;

    const announcements: Announcements = {
        onDragStart: ({ active }) =>
            t('planning.drag.start', { task: titleOf(active.data.current) }),
        onDragOver: ({ active, over }) => {
            const day = dayOf(over?.id);

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
            const day = dayOf(over?.id);

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

    const onDragStart = ({ active }: DragStartEvent) => {
        setDragging(
            (active.data.current as { task?: CalendarTask } | undefined)
                ?.task ?? null,
        );
    };

    const onDragEnd = ({ active, over }: DragEndEvent) => {
        setDragging(null);
        const task = (
            active.data.current as { task?: CalendarTask } | undefined
        )?.task;
        const day = dayOf(over?.id);

        if (task && day) {
            move(task, day);
        }
    };

    const navigate = (delta: number) =>
        onNavigate(
            calendar.mode,
            shiftPeriod(calendar.mode, calendar.period, delta),
        );
    const periodLabel = periodTitle(calendar);
    // Sin entregas en el periodo (las franjas que solo lo cruzan no se ven en el mes).
    const empty = !dated.some(
        (task) =>
            task.due_date !== null &&
            task.due_date >= calendar.from &&
            task.due_date <= calendar.to,
    );

    return (
        <div className="flex min-w-0 flex-col gap-4" data-test="task-calendar">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex flex-wrap items-center gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        className="size-8"
                        onClick={() => navigate(-1)}
                        aria-label={
                            calendar.mode === 'month'
                                ? t('planning.calendar.previous_month')
                                : t('planning.calendar.previous_week')
                        }
                    >
                        <ChevronLeft aria-hidden="true" />
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() =>
                            onNavigate(
                                calendar.mode,
                                periodContaining(calendar.mode, calendar.today),
                            )
                        }
                    >
                        {t('planning.calendar.today')}
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        className="size-8"
                        onClick={() => navigate(1)}
                        aria-label={
                            calendar.mode === 'month'
                                ? t('planning.calendar.next_month')
                                : t('planning.calendar.next_week')
                        }
                    >
                        <ChevronRight aria-hidden="true" />
                    </Button>
                    <h2
                        id={titleId}
                        className="text-lg first-letter:uppercase"
                        data-test="calendar-title"
                    >
                        {periodLabel}
                    </h2>
                </div>
                <ToggleGroup
                    type="single"
                    variant="outline"
                    value={calendar.mode}
                    onValueChange={(next) => {
                        if (
                            (next === 'month' || next === 'week') &&
                            next !== calendar.mode
                        ) {
                            onNavigate(next, modeSwitchPeriod(calendar, next));
                        }
                    }}
                    aria-label={t('planning.calendar.scale')}
                >
                    <ToggleGroupItem value="month" className="gap-1.5 px-3">
                        <CalendarDays aria-hidden="true" />
                        {t('planning.calendar.month')}
                    </ToggleGroupItem>
                    <ToggleGroupItem value="week" className="gap-1.5 px-3">
                        <CalendarRange aria-hidden="true" />
                        {t('planning.calendar.week')}
                    </ToggleGroupItem>
                </ToggleGroup>
            </div>

            <Legend />

            <p id={helpId} className="text-xs text-muted-foreground">
                {canEdit
                    ? t('planning.calendar.help')
                    : t('planning.calendar.read_only')}
            </p>

            {empty ? (
                <p role="status" className="text-sm text-muted-foreground">
                    {t('planning.calendar.empty', { period: periodLabel })}
                </p>
            ) : null}

            <DndContext
                sensors={sensors}
                onDragStart={onDragStart}
                onDragEnd={onDragEnd}
                onDragCancel={() => setDragging(null)}
                accessibility={{
                    announcements,
                    screenReaderInstructions: {
                        draggable: t('planning.calendar.help'),
                    },
                }}
            >
                <div className="grid min-w-0 gap-4 lg:grid-cols-[minmax(0,1fr)_16rem]">
                    <div
                        className={cn(
                            'min-w-0 overflow-x-auto rounded-[3px] transition-opacity',
                            loading && 'opacity-60',
                            FOCUS_RING,
                        )}
                        role="region"
                        aria-labelledby={titleId}
                        aria-busy={loading || undefined}
                        tabIndex={0}
                    >
                        {calendar.mode === 'month' ? (
                            <MonthGrid
                                calendar={calendar}
                                byDay={byDay}
                                context={context}
                            />
                        ) : (
                            <WeekGrid
                                calendar={{ ...calendar, tasks: dated }}
                                byDay={byDay}
                                context={context}
                            />
                        )}
                    </div>
                    <UndatedList
                        tasks={undated}
                        total={
                            calendar.undated_total -
                            (calendar.undated.length - undated.length)
                        }
                        context={context}
                    />
                </div>
                <DragOverlay>
                    {dragging ? (
                        <div className="w-40 rounded-[3px] border bg-card px-1.5 py-0.5 text-xs shadow-md">
                            <span className="block truncate">
                                {dragging.title}
                            </span>
                        </div>
                    ) : null}
                </DragOverlay>
            </DndContext>

            <p className="sr-only" role="status" data-test="calendar-live">
                {announcement}
            </p>

            <RescheduleDialog
                pending={reschedule.pending}
                saving={reschedule.saving}
                onConfirm={reschedule.confirm}
                onCancel={reschedule.cancel}
                // Al cerrar, el foco vuelve a la tarea movida (en su día nuevo o en el de antes).
                onCloseAutoFocus={(event) => {
                    event.preventDefault();

                    if (lastMoved.current !== null) {
                        focusChip(lastMoved.current);
                    }
                }}
            />
        </div>
    );
}
