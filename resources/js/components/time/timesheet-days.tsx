import { CalendarOff, Info, Plus, TreePalm } from 'lucide-react';
import { LOAD_LEVELS, loadLevel } from '@/components/charts/thresholds';
import { absenceTypeLabel } from '@/components/absences/absence-meta';
import type { AbsenceType } from '@/components/absences/types';
import { TimeEntryStatusBadge } from '@/components/domain/badges';
import { Button } from '@/components/ui/button';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatMinutes, formatTimeRange } from '@/lib/format';
import { t } from '@/lib/i18n';
import { ROW_CLICK_CLASS, rowClickProps } from '@/lib/row-click';
import { cn } from '@/lib/utils';
import type {
    DayTotals,
    LoggableTask,
    TimeEntry,
    TimesheetDayNote,
} from '@/types';
import type { GridRow } from './timesheet-grid';
import { weekdayLongLabel } from './week-days';

const dayLabel = new Intl.DateTimeFormat('es-ES', {
    day: 'numeric',
    month: 'long',
    timeZone: 'UTC',
});

/** «5 de octubre» de una fecha "YYYY-MM-DD", sin conversión de zona. */
function dayMonthLong(date: string): string {
    const [year, month, day] = date.split('-').map(Number);

    return dayLabel.format(new Date(Date.UTC(year, month - 1, day)));
}

function capitalize(value: string): string {
    return value.charAt(0).toUpperCase() + value.slice(1);
}

export type DayEntry = { entry: TimeEntry; task: LoggableTask };

/**
 * Las entradas de un día, de todas las filas de la hoja, por hora de inicio (las que la tienen) y
 * después en el orden de la hoja. No calcula nada nuevo: son las mismas celdas de la rejilla.
 */
export function entriesOfDay(rows: GridRow[], dayIndex: number): DayEntry[] {
    const list = rows.flatMap((row) =>
        (row.cells[dayIndex] ?? []).map((entry) => ({ entry, task: row.task })),
    );

    return list
        .map((item, order) => ({ item, order }))
        .sort((a, b) => {
            const startA = a.item.entry.started_at;
            const startB = b.item.entry.started_at;

            if (startA && startB && startA !== startB) {
                return startA < startB ? -1 : 1;
            }

            if (startA && !startB) {
                return -1;
            }

            if (!startA && startB) {
                return 1;
            }

            return a.order - b.order;
        })
        .map(({ item }) => item);
}

function DayTotal({ logged, capacity }: { logged: number; capacity: number }) {
    const level =
        capacity <= 0 && logged > 0 ? 'over' : loadLevel(logged, capacity);
    const meta = LOAD_LEVELS[level];
    const Icon = meta.icon;

    return (
        <span
            className="inline-flex items-center gap-1.5 text-sm"
            data-test="day-total"
        >
            <Icon
                aria-hidden="true"
                className={cn('size-4 shrink-0', meta.tone)}
            />
            <span className="tabular">
                {capacity > 0
                    ? t('hours.days.total_of', {
                          logged: formatMinutes(logged),
                          capacity: formatMinutes(capacity),
                      })
                    : formatMinutes(logged)}
            </span>
            <span className="sr-only">
                {capacity > 0
                    ? `(${meta.label})`
                    : t('hours.capacity.no_capacity')}
            </span>
        </span>
    );
}

function DayNoteBadges({ note }: { note: TimesheetDayNote | undefined }) {
    if (!note || (note.holiday === null && note.absence === null)) {
        return null;
    }

    return (
        <>
            {note.holiday !== null ? (
                <span
                    className="inline-flex items-center gap-1 rounded-md bg-neutral-soft px-1.5 py-0.5 text-xs"
                    data-test="day-holiday"
                >
                    <CalendarOff aria-hidden="true" className="size-3.5" />
                    {t('hours.days.holiday', { name: note.holiday })}
                </span>
            ) : null}
            {note.absence !== null ? (
                <span
                    className="inline-flex items-center gap-1 rounded-md bg-neutral-soft px-1.5 py-0.5 text-xs"
                    data-test="day-absence"
                >
                    <TreePalm aria-hidden="true" className="size-3.5" />
                    {note.absence.type !== null
                        ? absenceTypeLabel(note.absence.type as AbsenceType)
                        : t('hours.days.absence')}
                    {note.absence.partial
                        ? ` · ${t('hours.days.absence_partial')}`
                        : ''}
                </span>
            ) : null}
        </>
    );
}

function EntryRow({
    item,
    editable,
    onEdit,
}: {
    item: DayEntry;
    editable: boolean;
    onEdit: (entry: TimeEntry) => void;
}) {
    const { entry, task } = item;
    const range = formatTimeRange(entry.started_at, entry.ended_at);
    const title = entry.task?.title ?? task.title;

    return (
        <li
            className={cn(
                'flex flex-wrap items-start gap-x-3 gap-y-1 px-3 py-2.5 sm:flex-nowrap',
                editable && ROW_CLICK_CLASS,
            )}
            {...(editable ? rowClickProps : {})}
            data-test="day-entry"
            data-entry-id={entry.id}
        >
            <span
                aria-hidden="true"
                className="mt-1.5 size-2.5 shrink-0 rounded-full"
                style={{ backgroundColor: task.project.color }}
            />
            <div className="grid min-w-0 flex-1 gap-0.5">
                {editable ? (
                    <button
                        type="button"
                        onClick={() => onEdit(entry)}
                        className={cn(
                            'min-w-0 rounded-md text-left text-sm break-words hover:underline',
                            FOCUS_RING,
                        )}
                        aria-label={t('hours.days.edit_entry', {
                            task: title,
                            minutes: formatMinutes(entry.minutes),
                        })}
                        data-row-primary
                    >
                        {title}
                    </button>
                ) : (
                    <span className="text-sm break-words">{title}</span>
                )}
                <span className="truncate text-xs text-muted-foreground">
                    {task.project.code} · {task.project.name}
                    {task.hour_bank ? ` · ${task.hour_bank.name}` : ''}
                </span>
                {entry.description ? (
                    <span className="text-xs break-words text-muted-foreground">
                        {entry.description}
                    </span>
                ) : null}
            </div>
            <div className="ml-auto flex shrink-0 items-center gap-3 pl-5 sm:pl-0">
                {range !== '' ? (
                    <span className="tabular text-xs text-muted-foreground">
                        <span className="sr-only">
                            {t('task_time.range_label')}{' '}
                        </span>
                        {range}
                    </span>
                ) : null}
                <span className="tabular w-12 text-right text-sm font-medium">
                    {formatMinutes(entry.minutes)}
                </span>
                <TimeEntryStatusBadge status={entry.status} />
            </div>
        </li>
    );
}

/**
 * Hoja semanal «Por días» (D-321), como la lista de ClickUp: un bloque por día con su fecha, lo
 * imputado frente a la jornada («6:30 de 8:00») y sus entradas (tarea, proyecto, duración,
 * descripción y estado). «Añadir horas» en cada día; un clic en una entrada la edita. Los días sin
 * horas también salen, con un aviso discreto si eran laborables y ya han pasado; los festivos y
 * las ausencias se marcan. Usa las mismas filas, totales y capacidad que la rejilla.
 */
export function TimesheetDays({
    rows,
    days,
    totals,
    capacity,
    dayNotes,
    today,
    allowFuture,
    editable,
    canEditEntry,
    onAdd,
    onEdit,
}: {
    rows: GridRow[];
    days: string[];
    totals: DayTotals;
    capacity: DayTotals;
    dayNotes: Record<string, TimesheetDayNote>;
    today: string;
    allowFuture: boolean;
    editable: boolean;
    canEditEntry: (entry: TimeEntry) => boolean;
    onAdd: (date: string) => void;
    onEdit: (entry: TimeEntry) => void;
}) {
    return (
        <div className="grid gap-4" data-test="timesheet-days">
            <p className="text-sm text-muted-foreground" data-test="week-total">
                {t('hours.days.week_total')}{' '}
                <DayTotal logged={totals.week} capacity={capacity.week} />
            </p>
            {days.map((day, dayIndex) => {
                const entries = entriesOfDay(rows, dayIndex);
                const logged = totals.days[day] ?? 0;
                const dayCapacity = capacity.days[day] ?? 0;
                const isToday = day === today;
                const future = day > today;
                const headingId = `timesheet-day-${day}`;
                const canAdd = editable && (allowFuture || !future);
                const missing =
                    entries.length === 0 && dayCapacity > 0 && day < today;

                return (
                    <section
                        key={day}
                        aria-labelledby={headingId}
                        className={cn(
                            'rounded-md border',
                            isToday && 'border-primary',
                        )}
                        data-test="timesheet-day"
                        data-date={day}
                    >
                        <div className="flex flex-wrap items-center gap-x-3 gap-y-2 border-b bg-muted px-3 py-2">
                            <h2
                                id={headingId}
                                className="flex flex-wrap items-baseline gap-x-2 text-base font-medium"
                            >
                                <span>
                                    {capitalize(weekdayLongLabel(day))}{' '}
                                    {dayMonthLong(day)}
                                </span>
                                {isToday ? (
                                    <span className="text-sm font-normal text-primary-text">
                                        {t('hours.days.today')}
                                    </span>
                                ) : null}
                            </h2>
                            <DayNoteBadges note={dayNotes[day]} />
                            <div className="ml-auto flex items-center gap-2">
                                <DayTotal
                                    logged={logged}
                                    capacity={dayCapacity}
                                />
                                {canAdd ? (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={() => onAdd(day)}
                                        aria-label={t('hours.days.add_on', {
                                            day: `${weekdayLongLabel(day)} ${dayMonthLong(day)}`,
                                        })}
                                        data-test="day-add"
                                    >
                                        <Plus aria-hidden="true" />
                                        {t('hours.sheet.add_entry')}
                                    </Button>
                                ) : null}
                            </div>
                        </div>
                        {entries.length > 0 ? (
                            <ul
                                className="divide-y"
                                aria-label={t('hours.days.entries_of', {
                                    day: `${weekdayLongLabel(day)} ${dayMonthLong(day)}`,
                                })}
                            >
                                {entries.map((item) => (
                                    <EntryRow
                                        key={item.entry.id}
                                        item={item}
                                        editable={canEditEntry(item.entry)}
                                        onEdit={onEdit}
                                    />
                                ))}
                            </ul>
                        ) : (
                            <p
                                className="flex items-center gap-1.5 px-3 py-2.5 text-sm text-muted-foreground"
                                data-test={
                                    missing ? 'day-missing' : 'day-empty'
                                }
                            >
                                {missing ? (
                                    <Info
                                        aria-hidden="true"
                                        className="size-4 shrink-0 text-warning"
                                    />
                                ) : null}
                                {missing
                                    ? t('hours.days.missing')
                                    : t('hours.days.empty')}
                            </p>
                        )}
                    </section>
                );
            })}
        </div>
    );
}
