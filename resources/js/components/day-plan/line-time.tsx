import { router } from '@inertiajs/react';
import { Clock, Link2, Play, Plus, Square, Timer } from 'lucide-react';
import { useEffect, useId, useState } from 'react';
import { toast } from 'sonner';
import { TaskPicker } from '@/components/time/task-picker';
import type { PickedTask } from '@/components/time/task-picker';
import { TimeEntryDialog } from '@/components/time/time-entry-dialog';
import {
    RUNNING_TIMER_ERROR,
    stopTimer,
    timerStopDialog,
} from '@/components/time/timer-actions';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { DropdownMenuItem } from '@/components/ui/dropdown-menu';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatMinutes } from '@/lib/format';
import { t } from '@/lib/i18n';
import {
    entries as entriesRoute,
    link,
    logPlanned,
    timer,
} from '@/routes/day-plan/items';
import type { DayPlanLine } from '@/types/day-plan';

/**
 * Las horas de una línea del plan del día (docs/PLAN-CARGAS.md §6.1.4; D-254): ▶ arranca el
 * temporizador en su tarea (si no tiene, «¿En qué tarea?»: elegir una o crear «<texto>» en su
 * proyecto); ■ lo para. Imputar a mano, «Imputar lo previsto» y «Vincular horas» van en el menú ⋯.
 */

type TimerPayload = { task_id?: number; create_task?: boolean };

/** Arranca el temporizador desde una línea. Los errores, como en el resto de temporizadores. */
export function startLineTimer(
    lineId: number,
    payload: TimerPayload,
    callbacks: {
        onStart?: () => void;
        onFinish?: () => void;
        onSuccess?: () => void;
        onError?: (message: string) => void;
    } = {},
): void {
    router.post(timer.url(lineId), payload, {
        preserveScroll: true,
        preserveState: true,
        errorBag: 'timer',
        onStart: () => callbacks.onStart?.(),
        onFinish: () => callbacks.onFinish?.(),
        onSuccess: () => callbacks.onSuccess?.(),
        onError: (errors) => {
            if (errors[RUNNING_TIMER_ERROR]) {
                timerStopDialog.open(errors);

                return;
            }

            const message = Object.values(errors).filter(Boolean).join(' ');

            if (callbacks.onError) {
                callbacks.onError(message);
            } else {
                toast.error(message);
            }
        },
    });
}

export function LineTimerButton({ line }: { line: DayPlanLine }) {
    const [processing, setProcessing] = useState(false);
    const [choosing, setChoosing] = useState(false);
    const callbacks = {
        onStart: () => setProcessing(true),
        onFinish: () => setProcessing(false),
    };

    if (line.status === 'carried') {
        return null;
    }

    const label = line.running
        ? t('day_plan.timer.stop', { text: line.text })
        : t('day_plan.timer.start', { text: line.text });

    return (
        <>
            <Button
                type="button"
                variant={line.running ? 'secondary' : 'ghost'}
                size="icon"
                className="size-8 shrink-0"
                disabled={processing}
                aria-label={label}
                title={label}
                onClick={() => {
                    if (line.running) {
                        stopTimer(callbacks);
                    } else if (line.task) {
                        startLineTimer(line.id, {}, callbacks);
                    } else {
                        setChoosing(true);
                    }
                }}
                data-test="day-plan-line-timer"
                data-running={line.running || undefined}
            >
                {processing ? (
                    <Spinner />
                ) : line.running ? (
                    <Square aria-hidden="true" className="fill-current" />
                ) : (
                    <Play aria-hidden="true" />
                )}
            </Button>
            {choosing ? (
                <TaskChoiceDialog line={line} onOpenChange={setChoosing} />
            ) : null}
        </>
    );
}

/**
 * «¿En qué tarea?» (§6.1.4): las tareas del proyecto de la línea (las mías y abiertas primero) o,
 * sin proyecto, las de siempre (incluido el proyecto interno); y «Crear la tarea «<texto>» en
 * <proyecto>». La tarea elegida se queda en la línea.
 */
export function TaskChoiceDialog({
    line,
    onOpenChange,
}: {
    line: DayPlanLine;
    onOpenChange: (open: boolean) => void;
}) {
    const id = useId();
    const [task, setTask] = useState<PickedTask | null>(null);
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const start = (payload: TimerPayload) =>
        startLineTimer(line.id, payload, {
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onSuccess: () => onOpenChange(false),
            onError: setError,
        });

    return (
        <Dialog open onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <DialogTitle>{t('day_plan.timer.choose_title')}</DialogTitle>
                <DialogDescription>
                    {t('day_plan.timer.choose_description', {
                        text: line.text,
                    })}
                </DialogDescription>
                <div className="grid gap-1.5">
                    <Label htmlFor={`${id}-task`}>
                        {line.project
                            ? t('day_plan.timer.tasks_of', {
                                  project: line.project.code,
                              })
                            : t('day_plan.timer.task')}
                    </Label>
                    <TaskPicker
                        id={`${id}-task`}
                        value={task}
                        onChange={setTask}
                        projectId={line.project?.id}
                    />
                </div>
                {line.project ? (
                    <Button
                        type="button"
                        variant="outline"
                        className="justify-start"
                        disabled={processing}
                        onClick={() => start({ create_task: true })}
                        data-test="day-plan-create-task"
                    >
                        <Plus aria-hidden="true" />
                        <span className="truncate">
                            {t('day_plan.timer.create_task', {
                                text: line.text,
                                project: line.project.code,
                            })}
                        </span>
                    </Button>
                ) : null}
                {error ? (
                    <p role="alert" className="text-sm text-danger">
                        {error}
                    </p>
                ) : null}
                <DialogFooter className="gap-2">
                    <DialogClose asChild>
                        <Button
                            type="button"
                            variant="secondary"
                            disabled={processing}
                        >
                            {t('common.cancel')}
                        </Button>
                    </DialogClose>
                    <Button
                        type="button"
                        disabled={processing || task === null}
                        onClick={() => task && start({ task_id: task.id })}
                        data-test="day-plan-start-chosen"
                    >
                        {processing ? (
                            <Spinner />
                        ) : (
                            <Timer aria-hidden="true" />
                        )}
                        {t('day_plan.timer.start_chosen')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/** Acciones de horas del menú ⋯ de una línea (imputar, imputar lo previsto y vincular). */
export function LineTimeActions({
    line,
    onLog,
    onLink,
}: {
    line: DayPlanLine;
    onLog: () => void;
    onLink: () => void;
}) {
    if (line.status === 'carried') {
        return null;
    }

    const canLogPlanned =
        line.status === 'done' &&
        line.task !== null &&
        line.planned_minutes !== null &&
        !line.logged_minutes;

    return (
        <>
            <DropdownMenuItem onSelect={onLog} data-test="day-plan-line-log">
                <Clock aria-hidden="true" />
                {t('day_plan.time.log')}
            </DropdownMenuItem>
            {canLogPlanned ? (
                <DropdownMenuItem
                    onSelect={() =>
                        router.post(
                            logPlanned.url(line.id),
                            {},
                            {
                                preserveScroll: true,
                                preserveState: true,
                                errorBag: 'dayPlan',
                                onError: (errors) =>
                                    toast.error(
                                        Object.values(errors).join(' '),
                                    ),
                            },
                        )
                    }
                    data-test="day-plan-line-log-planned"
                >
                    <Timer aria-hidden="true" />
                    {t('day_plan.time.log_planned', {
                        time: formatMinutes(line.planned_minutes ?? 0),
                    })}
                </DropdownMenuItem>
            ) : null}
            <DropdownMenuItem onSelect={onLink} data-test="day-plan-line-link">
                <Link2 aria-hidden="true" />
                {t('day_plan.time.link')}
            </DropdownMenuItem>
        </>
    );
}

/** El diálogo de horas de siempre (D-172), con la tarea y la línea rellenas. */
export function LineLogDialog({
    line,
    onOpenChange,
}: {
    line: DayPlanLine;
    onOpenChange: (open: boolean) => void;
}) {
    return (
        <TimeEntryDialog
            open
            onOpenChange={onOpenChange}
            date={line.date}
            minutes={
                line.planned_minutes && !line.logged_minutes
                    ? line.planned_minutes
                    : null
            }
            task={
                line.task
                    ? {
                          id: line.task.id,
                          title: line.task.title,
                          project_id: line.task.project_id,
                      }
                    : null
            }
            dayPlanItemId={line.id}
        />
    );
}

type LinkableEntry = {
    id: number;
    minutes: number;
    description: string | null;
    task: string;
    project: string;
    linked: boolean;
    locked: boolean;
};

/** «Vincular horas»: mis entradas de ese día sin línea (o ya de esta línea). */
export function LinkEntriesDialog({
    line,
    onOpenChange,
}: {
    line: DayPlanLine;
    onOpenChange: (open: boolean) => void;
}) {
    const id = useId();
    const [entries, setEntries] = useState<LinkableEntry[] | null>(null);
    const [selected, setSelected] = useState<number[]>([]);
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        const controller = new AbortController();

        fetch(entriesRoute.url(line.id), {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            signal: controller.signal,
        })
            .then((response) =>
                response.ok
                    ? response.json()
                    : Promise.reject(new Error(String(response.status))),
            )
            .then((data: { entries?: LinkableEntry[] }) => {
                const list = Array.isArray(data.entries) ? data.entries : [];
                setEntries(list);
                setSelected(
                    list
                        .filter((entry) => entry.linked)
                        .map((entry) => entry.id),
                );
            })
            .catch((reason: unknown) => {
                if (
                    !(
                        reason instanceof DOMException &&
                        reason.name === 'AbortError'
                    )
                ) {
                    setError(t('day_plan.time.link_error'));
                }
            });

        return () => controller.abort();
    }, [line.id]);

    const save = () =>
        router.post(
            link.url(line.id),
            { entry_ids: selected },
            {
                preserveScroll: true,
                preserveState: true,
                errorBag: 'dayPlan',
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onSuccess: () => onOpenChange(false),
                onError: (errors) => setError(Object.values(errors).join(' ')),
            },
        );

    return (
        <Dialog open onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <DialogTitle>{t('day_plan.time.link_title')}</DialogTitle>
                <DialogDescription>
                    {t('day_plan.time.link_description', { text: line.text })}
                </DialogDescription>
                {entries === null && error === null ? (
                    <p
                        role="status"
                        className="flex items-center gap-2 text-sm text-muted-foreground"
                    >
                        <Spinner />
                        {t('common.loading')}
                    </p>
                ) : null}
                {entries !== null && entries.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {t('day_plan.time.link_empty')}
                    </p>
                ) : null}
                {entries !== null && entries.length > 0 ? (
                    <ul
                        className="grid max-h-80 gap-2 overflow-y-auto"
                        data-test="day-plan-link-entries"
                    >
                        {entries.map((entry) => (
                            <li
                                key={entry.id}
                                className="flex items-start gap-2"
                            >
                                <Checkbox
                                    id={`${id}-${entry.id}`}
                                    checked={selected.includes(entry.id)}
                                    disabled={entry.locked}
                                    onCheckedChange={(checked) =>
                                        setSelected((current) =>
                                            checked === true
                                                ? [...current, entry.id]
                                                : current.filter(
                                                      (value) =>
                                                          value !== entry.id,
                                                  ),
                                        )
                                    }
                                    className="mt-0.5"
                                />
                                <Label
                                    htmlFor={`${id}-${entry.id}`}
                                    className="grid gap-0.5 font-normal"
                                >
                                    <span>
                                        <span className="tabular">
                                            {formatMinutes(entry.minutes)}
                                        </span>{' '}
                                        · {entry.project} · {entry.task}
                                    </span>
                                    {entry.description ? (
                                        <span className="text-xs text-muted-foreground">
                                            {entry.description}
                                        </span>
                                    ) : null}
                                </Label>
                            </li>
                        ))}
                    </ul>
                ) : null}
                {error ? (
                    <p role="alert" className="text-sm text-danger">
                        {error}
                    </p>
                ) : null}
                <DialogFooter className="gap-2">
                    <DialogClose asChild>
                        <Button
                            type="button"
                            variant="secondary"
                            disabled={processing}
                        >
                            {t('common.cancel')}
                        </Button>
                    </DialogClose>
                    <Button
                        type="button"
                        disabled={processing || entries === null}
                        onClick={save}
                        data-test="day-plan-link-save"
                    >
                        {processing ? <Spinner /> : null}
                        {t('day_plan.time.link_save')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
