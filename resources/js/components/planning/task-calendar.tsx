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
    CalendarRange,
    ChevronLeft,
    ChevronRight,
    Diamond,
} from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import type { CSSProperties, ReactNode } from 'react';
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

/**
 * El día de destino es el que queda bajo el puntero (las tarjetas son anchas y pueden salirse de
 * su día: con el rectángulo arrastrado caerían en el de al lado). Sin puntero (teclado de dnd-kit),
 * el de más intersección.
 */
const dropUnderPointer: CollisionDetection = (args) => {
    const underPointer = pointerWithin(args);

    return underPointer.length > 0 ? underPointer : rectIntersection(args);
};

/** Tareas que caben en un día del mes; el resto, en «+N más». */
export const MONTH_VISIBLE = 3;

const DAY = 'day:';

/** Tarea movida (con su día nuevo), para devolverle el foco y anunciar dónde ha ido. */
type MovedTask = { id: number; title: string; due: string };

type ChipContext = {
    today: string;
    canEdit: boolean;
    /** Hay un cambio de día en curso: no se puede mover otra tarea hasta que termine. */
    locked: boolean;
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
                    'tabular inline-flex size-6 items-center justify-center rounded-md',
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
    // Tras mover una tarea desde aquí, el foco lo lleva el calendario (a la tarea en su día
    // nuevo): el popover no lo devuelve a «+N más».
    const moved = useRef(false);

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <button
                    type="button"
                    className={cn(
                        'w-full rounded-md px-1.5 py-0.5 text-left text-xs text-primary-text hover:underline',
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
            <PopoverContent
                className="w-72 p-2"
                align="start"
                onCloseAutoFocus={(event) => {
                    if (moved.current) {
                        moved.current = false;
                        event.preventDefault();
                    }
                }}
            >
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
                                locked={context.locked}
                                helpId={context.helpId}
                                onOpen={(taskId) => {
                                    setOpen(false);
                                    context.onOpen(taskId);
                                }}
                                onMove={(task, newDue, keepFocus) => {
                                    moved.current = true;
                                    setOpen(false);
                                    context.onMove(task, newDue, keepFocus);
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

/**
 * Franja de una tarea del inicio a la entrega (D-061): un botón que abre su panel, con las fechas
 * en su nombre accesible (y en el título, por si el texto no cabe). No se arrastra: la tarea se
 * mueve desde su tarjeta del día de entrega. Si sigue antes del lunes o después del domingo, lo
 * dice con una flecha y sin esquina redonda.
 */
function SpanButton({
    span,
    onOpen,
    className,
    style,
    ...rest
}: {
    span: WeekSpan;
    onOpen: (taskId: number) => void;
    className?: string;
    style?: CSSProperties;
    [key: `data-${string}`]: string | number | undefined;
}) {
    const { task } = span;
    const label = t('planning.calendar.span_label', {
        task: task.title,
        start: formatDate(task.start_date),
        due: formatDate(task.due_date),
    });

    return (
        <button
            type="button"
            onClick={() => onOpen(task.id)}
            className={cn(
                'flex min-w-0 items-center gap-1 border border-info bg-info-soft px-1.5 py-0.5 text-left text-xs text-foreground hover:underline',
                span.continuesBefore
                    ? 'rounded-l-none border-l-0'
                    : 'rounded-l-md',
                span.continuesAfter
                    ? 'rounded-r-none border-r-0'
                    : 'rounded-r-md',
                FOCUS_RING,
                className,
            )}
            style={style}
            aria-label={label}
            title={label}
            data-test="calendar-span"
            data-task-id={task.id}
            {...rest}
        >
            {span.continuesBefore ? (
                <ChevronLeft aria-hidden="true" className="size-3 shrink-0" />
            ) : null}
            <CalendarRange
                aria-hidden="true"
                className="size-3.5 shrink-0 text-primary-text"
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

/**
 * Filas de franjas de una semana del mes: en cada fila, la franja que empieza en cada columna
 * (0 = lunes) o null. Todas las celdas de la semana pintan las mismas filas, así que cada franja
 * queda a la misma altura en todos los días que cruza.
 */
function monthLanes(spans: WeekSpan[]): (WeekSpan | null)[][] {
    const lanes: (WeekSpan | null)[][] = [];

    for (const span of spans) {
        while (lanes.length <= span.lane) {
            lanes.push(Array.from({ length: 7 }, () => null));
        }

        lanes[span.lane][span.startColumn] = span;
    }

    return lanes;
}

/**
 * Ancho de una franja del mes que se pinta en la celda de su primer día y ocupa `days` días: esos
 * días más lo que hay entre ellos (el relleno de las dos celdas, 2 × 0,25 rem, y el borde de 1 px).
 */
function spanWidth(days: number): string {
    return days === 1
        ? '100%'
        : `calc(${days} * 100% + ${days - 1} * (0.5rem + 1px))`;
}

/**
 * Rejilla del mes. En cada semana, primero las franjas de las tareas con inicio (del inicio a la
 * entrega, weekSpans, como en la vista semana) y después las tarjetas de las tareas que vencen ese
 * día. Las franjas se pintan DENTRO de las celdas de los días (en la del primer día que cruzan esa
 * semana, por encima de las siguientes): así toda la celda de cada día sigue siendo donde se suelta
 * una tarea, y el día de destino es siempre el que queda bajo el puntero (dropUnderPointer).
 */
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
                {weeks.map((week) => {
                    const lanes = monthLanes(weekSpans(calendar.tasks, week));

                    return (
                        <tr key={week[0]}>
                            {week.map((date, column) => {
                                const tasks = byDay.get(date) ?? [];
                                const visible = tasks.slice(0, MONTH_VISIBLE);
                                const hidden = tasks.length - visible.length;
                                const outside = !date.startsWith(
                                    calendar.period,
                                );

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
                                        {lanes.length > 0 ? (
                                            <div
                                                className="mb-1 grid gap-0.5"
                                                data-test="calendar-lanes"
                                            >
                                                {lanes.map((lane, index) => {
                                                    const span = lane[column];

                                                    if (span === null) {
                                                        return (
                                                            <div
                                                                key={index}
                                                                aria-hidden="true"
                                                                className="h-5"
                                                            />
                                                        );
                                                    }

                                                    const days =
                                                        span.endColumn -
                                                        span.startColumn +
                                                        1;

                                                    return (
                                                        <SpanButton
                                                            key={index}
                                                            span={span}
                                                            onOpen={
                                                                context.onOpen
                                                            }
                                                            // Por encima de los días siguientes que cruza.
                                                            className="relative z-10 h-5 py-0"
                                                            style={{
                                                                width: spanWidth(
                                                                    days,
                                                                ),
                                                            }}
                                                            data-days={days}
                                                        />
                                                    );
                                                })}
                                            </div>
                                        ) : null}
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
                    );
                })}
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
                                        'rounded-md bg-primary px-1 text-primary-foreground',
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
                                    <SpanButton
                                        span={cell.span}
                                        onOpen={context.onOpen}
                                        className="w-full"
                                    />
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
            className="grid content-start gap-2 rounded-md border bg-card p-3"
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
                                    disabled={context.locked}
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
 * con cada tarea el día de su entrega y, si tiene inicio, su franja del inicio a la entrega (en el
 * mes y en la semana), los hitos con su rombo y una lista lateral de las tareas sin fecha. Pulsar
 * una tarea (o su franja) abre su panel; quien puede editar la cambia de día arrastrándola, con el
 * teclado o con «Asignar fecha», siempre con la propuesta de desplazar sucesoras (D-057). Los
 * demás, en solo lectura.
 */
export function TaskCalendar({
    calendar,
    loading = false,
    onOpen,
    onNavigate,
    onBusyChange,
}: {
    calendar: TaskCalendarData;
    loading?: boolean;
    onOpen: (taskId: number) => void;
    /** Cambia de periodo o de escala (la página lo lleva a la URL: ?mes= o ?semana=). */
    onNavigate: (mode: CalendarMode, period: string) => void;
    /** Avisa de que hay (o ya no hay) un movimiento en curso: la página bloquea el cambio de vista. */
    onBusyChange?: (busy: boolean) => void;
}) {
    const lookups = useTaskLookups();
    const helpId = useId();
    const titleId = useId();
    const [announcement, setAnnouncement] = useState('');
    const [dragging, setDragging] = useState<CalendarTask | null>(null);
    const reschedule = useReschedule();
    const canEdit = lookups.can.update;
    const busy = reschedule.busy;

    // Mientras se pide la propuesta, se pregunta o se guarda un movimiento, la página no deja
    // cambiar de vista (se perdería sin aviso); al desmontarse, queda libre.
    useEffect(() => {
        onBusyChange?.(busy);
    }, [busy, onBusyChange]);

    useEffect(() => () => onBusyChange?.(false), [onBusyChange]);

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

    const regionRef = useRef<HTMLDivElement>(null);
    // Tarea que debe conservar el foco al moverla con el teclado: al cambiar de día su botón se
    // vuelve a crear en otra celda (y otra vez si se cancela), así que se le devuelve el foco
    // cuando no hay un diálogo abierto; se olvida al terminar el movimiento.
    const keepFocusOn = useRef<MovedTask | null>(null);
    // Última tarea movida: al cerrar el diálogo de conflictos, el foco vuelve a ella.
    const lastMoved = useRef<MovedTask | null>(null);

    /**
     * Lleva el foco a la tarea movida: a su botón; si su día está en la rejilla pero la tarea
     * queda tras «+N más», a ese botón; y si su día queda fuera del periodo, a la región del
     * calendario (nunca se pierde en la página, WCAG 2.4.3).
     */
    const focusTask = (moved: MovedTask): 'chip' | 'more' | 'outside' => {
        const chip = document.querySelector<HTMLElement>(
            `[data-test="calendar-chip"][data-task-id="${moved.id}"]`,
        );

        if (chip) {
            if (document.activeElement !== chip) {
                chip.focus();
            }

            return 'chip';
        }

        const due =
            all.find((task) => task.id === moved.id)?.due_date ?? moved.due;
        const more = regionRef.current?.querySelector<HTMLElement>(
            `[data-date="${due}"] [data-test="calendar-more"]`,
        );
        const fallback = more ?? regionRef.current;

        if (fallback && document.activeElement !== fallback) {
            fallback.focus();
        }

        return more ? 'more' : 'outside';
    };

    useEffect(() => {
        const moved = keepFocusOn.current;

        if (moved === null || reschedule.pending !== null) {
            return;
        }

        const where = focusTask(moved);

        if (reschedule.moving === null) {
            keepFocusOn.current = null;

            // Guardada en un día que no se ve: se dice dónde ha ido.
            if (where === 'outside') {
                setAnnouncement(
                    t('planning.keyboard.moved_outside', {
                        task: moved.title,
                        date: formatDate(moved.due),
                    }),
                );
            }
        }
    });

    const move: ChipMoveHandler = (task, newDue, keepFocus = false) => {
        if (!canEdit || newDue === task.due_date) {
            return;
        }

        // Un cambio de día a la vez: el segundo no puede cerrar ni sustituir el diálogo del primero.
        if (reschedule.isBusy()) {
            setAnnouncement(t('planning.keyboard.busy'));

            return;
        }

        const moved = { id: task.id, title: task.title, due: newDue };
        keepFocusOn.current = keepFocus ? moved : null;
        lastMoved.current = moved;
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
        locked: reschedule.busy,
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
    // Sin entregas en el periodo. Las franjas de las tareas que solo lo cruzan (empiezan antes o
    // vencen después) sí se ven, en el mes y en la semana, pero no son entregas: el aviso lo dice.
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
                collisionDetection={dropUnderPointer}
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
                        ref={regionRef}
                        className={cn(
                            'min-w-0 overflow-x-auto rounded-md transition-opacity',
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
                                // Con las fechas de la tarea que se está moviendo, como la semana.
                                calendar={{ ...calendar, tasks: dated }}
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
                        <div className="w-40 rounded-md border bg-card px-1.5 py-0.5 text-xs shadow-md">
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
                        focusTask(lastMoved.current);
                    }
                }}
            />
        </div>
    );
}
