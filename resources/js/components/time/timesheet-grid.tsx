import { Link, router } from '@inertiajs/react';
import { CircleCheck, Lock, TriangleAlert, X } from 'lucide-react';
import { useRef, useState } from 'react';
import type { FocusEvent, KeyboardEvent } from 'react';
import { toast } from 'sonner';
import { DurationInput } from '@/components/domain/duration-input';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { parseDuration } from '@/lib/duration';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatMinutes } from '@/lib/format';
import { t } from '@/lib/i18n';
import { urls } from '@/lib/urls';
import { cn } from '@/lib/utils';
import { destroy, store, update } from '@/routes/time/entries';
import type { DayTotals, LoggableTask, TimeEntry } from '@/types';
import { CapacityCell } from './capacity-cell';
import {
    dayMonthLabel,
    weekdayLongLabel,
    weekdayShortLabel,
} from './week-days';

export type GridRow = {
    task: LoggableTask;
    cells: TimeEntry[][];
    total: number;
    /** Fila añadida a mano (o copiada de la semana anterior) que aún no tiene horas. */
    extra?: boolean;
};

type SaveResult = boolean;

/**
 * Cola de guardado: las celdas se guardan de una en una y en orden, para que una visita no
 * cancele a la anterior mientras se escribe deprisa.
 */
function useSaveQueue() {
    const queue = useRef<Promise<unknown>>(Promise.resolve());

    return function enqueue(
        job: () => Promise<SaveResult>,
    ): Promise<SaveResult> {
        const run = queue.current.then(job, job);
        queue.current = run.catch(() => false);

        return run;
    };
}

type Visit = (options: {
    onError: (errors: Record<string, string>) => void;
    onSuccess: () => void;
    onFinish: () => void;
}) => void;

function visitPromise(visit: Visit): Promise<SaveResult> {
    return new Promise((resolve) => {
        let ok = false;

        visit({
            onSuccess: () => {
                ok = true;
            },
            onError: (errors) => {
                [...new Set(Object.values(errors))].forEach((message) =>
                    toast.error(message),
                );
            },
            onFinish: () => resolve(ok),
        });
    });
}

/**
 * Rejilla tareas × días de la hoja semanal (SPEC §7), editable como una hoja de cálculo:
 * - una celda vacía o con una sola entrada en borrador se edita directamente (DurationInput),
 * - con varias entradas, abre su lista (onOpenCell),
 * - Enter guarda y baja, Mayús + Enter sube, las flechas se mueven entre celdas (izquierda y
 *   derecha cuando el cursor está en el borde del texto), Tab avanza y Escape deshace,
 * - vaciar una celda con una entrada la borra,
 * - fila de totales por día y de la semana frente a la capacidad, con el semáforo de carga.
 */
export function TimesheetGrid({
    rows,
    days,
    totals,
    capacity,
    editable,
    today,
    allowFuture,
    personId,
    onOpenCell,
    onRemoveRow,
}: {
    rows: GridRow[];
    days: string[];
    totals: DayTotals;
    capacity: DayTotals;
    editable: boolean;
    today: string;
    allowFuture: boolean;
    /** Persona de la hoja (para crear entradas en su nombre si no es quien mira). */
    personId: number;
    onOpenCell: (row: GridRow, dayIndex: number) => void;
    onRemoveRow: (taskId: number) => void;
}) {
    const container = useRef<HTMLDivElement>(null);
    const enqueue = useSaveQueue();
    const [status, setStatus] = useState('');

    const focusCell = (rowIndex: number, dayIndex: number): boolean => {
        const input = container.current?.querySelector<HTMLInputElement>(
            `[data-cell="${rowIndex}:${dayIndex}"] input`,
        );

        if (!input) {
            return false;
        }

        input.focus();
        input.select();

        return true;
    };

    /** Busca la siguiente celda editable en esa dirección (salta las que no lo son). */
    const move = (
        rowIndex: number,
        dayIndex: number,
        rowStep: number,
        dayStep: number,
    ): boolean => {
        let row = rowIndex + rowStep;
        let day = dayIndex + dayStep;

        while (row >= 0 && row < rows.length && day >= 0 && day < days.length) {
            if (focusCell(row, day)) {
                return true;
            }

            row += rowStep;
            day += dayStep;
        }

        return false;
    };

    const save = (
        row: GridRow,
        dayIndex: number,
        entry: TimeEntry | null,
        minutes: number | null,
    ): Promise<SaveResult> =>
        enqueue(() =>
            visitPromise((callbacks) => {
                const options = {
                    preserveScroll: true,
                    preserveState: true,
                    errorBag: 'timesheet',
                    ...callbacks,
                    onSuccess: () => {
                        callbacks.onSuccess();
                        setStatus(t('hours.sheet.saved'));
                    },
                };

                setStatus(t('hours.sheet.saving'));

                if (entry && minutes === null) {
                    router.delete(
                        destroy.url(entry.id, { query: { quiet: 1 } }),
                        options,
                    );
                } else if (entry) {
                    router.put(
                        update.url(entry.id),
                        {
                            task_id: entry.task_id,
                            user_id: entry.user_id,
                            date: entry.date,
                            minutes,
                            description: entry.description,
                            is_billable: entry.is_billable,
                            quiet: 1,
                        },
                        options,
                    );
                } else {
                    router.post(
                        store.url(),
                        {
                            task_id: row.task.id,
                            user_id: personId,
                            date: days[dayIndex],
                            minutes,
                            quiet: 1,
                        },
                        options,
                    );
                }
            }),
        );

    return (
        <div className="grid gap-2">
            <div
                className={cn('overflow-x-auto rounded-md border', FOCUS_RING)}
                role="region"
                aria-label={t('hours.sheet.grid_label')}
                tabIndex={0}
                ref={container}
            >
                <table className="w-full min-w-[48rem] border-collapse text-sm sm:min-w-[56rem]">
                    <caption className="sr-only">
                        {t('hours.sheet.grid_caption')}
                    </caption>
                    <thead>
                        <tr className="border-b bg-muted text-left">
                            <th
                                scope="col"
                                className="sticky left-0 z-10 w-36 min-w-36 bg-muted px-3 py-2 font-medium sm:w-auto sm:min-w-56"
                            >
                                {t('hours.sheet.task')}
                            </th>
                            {days.map((day) => (
                                <th
                                    key={day}
                                    scope="col"
                                    className={cn(
                                        'w-24 px-2 py-2 text-center font-medium',
                                        day === today && 'text-primary-text',
                                    )}
                                    aria-current={
                                        day === today ? 'date' : undefined
                                    }
                                >
                                    <abbr
                                        title={`${weekdayLongLabel(day)} ${dayMonthLabel(day)}`}
                                        className="no-underline"
                                    >
                                        <span className="block capitalize">
                                            {weekdayShortLabel(day)}
                                        </span>
                                        <span className="tabular block text-xs font-normal text-muted-foreground">
                                            {dayMonthLabel(day)}
                                        </span>
                                    </abbr>
                                </th>
                            ))}
                            <th
                                scope="col"
                                className="w-32 px-3 py-2 text-right font-medium"
                            >
                                {t('hours.sheet.total')}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((row, rowIndex) => (
                            <tr
                                key={row.task.id}
                                className="border-b last:border-b-0"
                                data-test="timesheet-row"
                            >
                                <th
                                    scope="row"
                                    className="sticky left-0 z-10 bg-background px-3 py-1.5 text-left align-middle font-normal"
                                >
                                    <RowHeader
                                        row={row}
                                        editable={editable}
                                        onRemove={() =>
                                            onRemoveRow(row.task.id)
                                        }
                                    />
                                </th>
                                {days.map((day, dayIndex) => (
                                    <td
                                        key={day}
                                        className={cn(
                                            'px-1 py-1 align-middle',
                                            day === today && 'bg-muted/60',
                                        )}
                                        data-cell={`${rowIndex}:${dayIndex}`}
                                    >
                                        <GridCell
                                            row={row}
                                            day={day}
                                            entries={row.cells[dayIndex] ?? []}
                                            editable={
                                                editable &&
                                                (allowFuture || day <= today) &&
                                                !row.task.is_deleted &&
                                                !row.task.is_milestone
                                            }
                                            onSave={(entry, minutes) =>
                                                save(
                                                    row,
                                                    dayIndex,
                                                    entry,
                                                    minutes,
                                                )
                                            }
                                            onOpen={() =>
                                                onOpenCell(row, dayIndex)
                                            }
                                            onNavigate={(rowStep, dayStep) =>
                                                move(
                                                    rowIndex,
                                                    dayIndex,
                                                    rowStep,
                                                    dayStep,
                                                )
                                            }
                                        />
                                    </td>
                                ))}
                                <td className="tabular px-3 py-1.5 text-right font-medium">
                                    {formatMinutes(row.total)}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                    <tfoot>
                        <tr className="border-t bg-muted">
                            <th
                                scope="row"
                                className="sticky left-0 z-10 bg-muted px-3 py-2 text-left font-medium"
                            >
                                {t('hours.sheet.day_totals')}
                            </th>
                            {days.map((day) => (
                                <td
                                    key={day}
                                    className="px-1 py-2 text-center"
                                    data-test="day-total"
                                >
                                    <CapacityCell
                                        logged={totals.days[day] ?? 0}
                                        capacity={capacity.days[day] ?? 0}
                                        compact
                                        className="justify-center"
                                    />
                                </td>
                            ))}
                            <td
                                className="px-3 py-2 text-right"
                                data-test="week-total"
                            >
                                <CapacityCell
                                    logged={totals.week}
                                    capacity={capacity.week}
                                    compact
                                    className="justify-end font-medium whitespace-nowrap"
                                />
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <p
                className="min-h-4 text-xs text-muted-foreground"
                aria-live="polite"
            >
                {status}
            </p>
        </div>
    );
}

function RowHeader({
    row,
    editable,
    onRemove,
}: {
    row: GridRow;
    editable: boolean;
    onRemove: () => void;
}) {
    const { task } = row;

    return (
        <div className="flex min-w-0 items-center gap-2">
            <span
                aria-hidden="true"
                className="size-2 shrink-0 rounded-full"
                style={{ backgroundColor: task.project.color }}
            />
            <div className="min-w-0 flex-1">
                <Link
                    href={urls.task(task.project_id, task.id)}
                    className={cn(
                        'block truncate rounded-sm hover:underline',
                        FOCUS_RING,
                    )}
                >
                    {task.title}
                </Link>
                <span className="flex items-center gap-1 truncate text-xs text-muted-foreground">
                    {task.project.code}
                    {task.is_completed ? (
                        <>
                            <CircleCheck
                                aria-hidden="true"
                                className="size-3 text-success"
                            />
                            {t('hours.sheet.task_completed')}
                        </>
                    ) : null}
                    {task.is_deleted ? (
                        <>
                            <TriangleAlert
                                aria-hidden="true"
                                className="size-3 text-warning"
                            />
                            {t('hours.sheet.task_deleted')}
                        </>
                    ) : null}
                </span>
            </div>
            {row.extra && row.total === 0 && editable ? (
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="size-7"
                    onClick={onRemove}
                    aria-label={t('hours.sheet.remove_row', {
                        task: task.title,
                    })}
                    title={t('hours.sheet.remove_row', { task: task.title })}
                >
                    <X aria-hidden="true" />
                </Button>
            ) : null}
        </div>
    );
}

function GridCell({
    row,
    day,
    entries,
    editable,
    onSave,
    onOpen,
    onNavigate,
}: {
    row: GridRow;
    day: string;
    entries: TimeEntry[];
    editable: boolean;
    onSave: (
        entry: TimeEntry | null,
        minutes: number | null,
    ) => Promise<boolean>;
    onOpen: () => void;
    onNavigate: (rowStep: number, dayStep: number) => boolean;
}) {
    const total = entries.reduce((sum, entry) => sum + entry.minutes, 0);
    const overage = entries.some((entry) => entry.overage_minutes > 0);
    const locked = entries.some((entry) => entry.status === 'locked');
    const direct =
        editable &&
        (entries.length === 0 ||
            (entries.length === 1 && entries[0].status === 'draft'));
    const label = t('hours.sheet.cell_label', {
        task: row.task.title,
        day: `${weekdayLongLabel(day)} ${dayMonthLabel(day)}`,
    });

    if (direct) {
        return (
            <CellInput
                entry={entries[0] ?? null}
                label={label}
                overage={overage}
                onSave={onSave}
                onNavigate={onNavigate}
            />
        );
    }

    if (entries.length === 0) {
        return (
            <span className="block text-center text-muted-foreground">
                <span aria-hidden="true">–</span>
                <span className="sr-only">
                    {t('hours.sheet.cell_empty', { cell: label })}
                </span>
            </span>
        );
    }

    return (
        <button
            type="button"
            onClick={onOpen}
            className={cn(
                'tabular flex h-9 w-full items-center justify-center gap-1 rounded-md border border-dashed px-1 hover:bg-accent',
                FOCUS_RING,
            )}
            aria-label={t('hours.sheet.cell_open', {
                cell: label,
                minutes: formatMinutes(total),
                count: entries.length,
            })}
        >
            {locked ? (
                <Lock
                    aria-hidden="true"
                    className="size-3 text-muted-foreground"
                />
            ) : null}
            {formatMinutes(total)}
            {entries.length > 1 ? (
                <span className="text-xs text-muted-foreground">
                    ×{entries.length}
                </span>
            ) : null}
            {overage ? <OverageMark /> : null}
        </button>
    );
}

function OverageMark() {
    return (
        <span className="inline-flex" title={t('hours.sheet.overage')}>
            <TriangleAlert aria-hidden="true" className="size-3 text-danger" />
            <span className="sr-only">{t('hours.sheet.overage')}</span>
        </span>
    );
}

function CellInput({
    entry,
    label,
    overage,
    onSave,
    onNavigate,
}: {
    entry: TimeEntry | null;
    label: string;
    overage: boolean;
    onSave: (
        entry: TimeEntry | null,
        minutes: number | null,
    ) => Promise<boolean>;
    onNavigate: (rowStep: number, dayStep: number) => boolean;
}) {
    const original = entry?.minutes ?? null;
    const [draft, setDraft] = useState<number | null>(original);
    const [synced, setSynced] = useState<number | null>(original);
    const [saving, setSaving] = useState(false);
    const [resetKey, setResetKey] = useState(0);
    const pending = useRef<number | null | undefined>(undefined);
    const wrapper = useRef<HTMLDivElement>(null);

    // Cuando el servidor devuelve otro valor (guardado o cambio externo), se adopta.
    if (original !== synced) {
        setSynced(original);
        setDraft(original);
    }

    const commit = (input: HTMLInputElement) => {
        const text = input.value.trim();

        if (text !== '' && parseDuration(text) === null) {
            return; // Formato no válido: se queda marcado y no se guarda.
        }

        const minutes = text === '' ? null : parseDuration(text);

        if (minutes === original || minutes === pending.current) {
            return;
        }

        pending.current = minutes;
        setSaving(true);
        void onSave(entry, minutes).then((ok) => {
            pending.current = undefined;
            setSaving(false);

            if (!ok) {
                setDraft(original);
                setResetKey((key) => key + 1);
            }
        });
    };

    const onKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
        const input = event.target as HTMLInputElement;

        if (input.tagName !== 'INPUT') {
            return;
        }

        const atStart = input.selectionStart === 0 && input.selectionEnd === 0;
        const atEnd =
            input.selectionStart === input.value.length &&
            input.selectionEnd === input.value.length;

        switch (event.key) {
            case 'Enter': {
                event.preventDefault();
                if (!onNavigate(event.shiftKey ? -1 : 1, 0)) {
                    input.blur();
                }
                break;
            }
            case 'ArrowDown':
                event.preventDefault();
                onNavigate(1, 0);
                break;
            case 'ArrowUp':
                event.preventDefault();
                onNavigate(-1, 0);
                break;
            case 'ArrowLeft':
                if (atStart && onNavigate(0, -1)) {
                    event.preventDefault();
                }
                break;
            case 'ArrowRight':
                if (atEnd && onNavigate(0, 1)) {
                    event.preventDefault();
                }
                break;
            case 'Escape': {
                event.preventDefault();
                setDraft(original);
                setResetKey((key) => key + 1);
                // Tras volver a montar el campo, el foco sigue en la celda.
                window.requestAnimationFrame(() => {
                    wrapper.current?.querySelector('input')?.focus();
                });
                break;
            }
        }
    };

    const onBlur = (event: FocusEvent<HTMLDivElement>) => {
        const input = event.target as HTMLInputElement;

        if (input.tagName === 'INPUT') {
            commit(input);
        }
    };

    return (
        <div
            ref={wrapper}
            className="relative"
            onKeyDown={onKeyDown}
            onBlur={onBlur}
        >
            <DurationInput
                key={resetKey}
                value={draft}
                onChange={setDraft}
                placeholder=""
                aria-label={label}
                className="gap-0 [&>input]:h-9 [&>input]:px-1.5 [&>input]:text-center [&>input]:tabular-nums [&>p]:sr-only"
            />
            {saving ? (
                <Spinner className="absolute top-1 right-1 size-3 text-muted-foreground" />
            ) : overage ? (
                <span className="absolute top-0.5 right-0.5">
                    <OverageMark />
                </span>
            ) : null}
        </div>
    );
}
