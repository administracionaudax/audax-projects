import { router } from '@inertiajs/react';
import { toastVisitErrors } from '@/components/admin/visit-errors';
import { ListChecks } from 'lucide-react';
import { useEffect, useId, useState } from 'react';
import { EmptyState } from '@/components/empty-state';
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
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatDate } from '@/lib/format';
import { t } from '@/lib/i18n';
import { fromTasks, suggestions } from '@/routes/day-plan';
import type { DayPlanSuggestedTask } from '@/types/day-plan';

/**
 * Pide las tareas sugeridas para un día (GET /dia/tareas-sugeridas?fecha=). Exportada para los tests.
 */
export async function fetchSuggestedTasks(
    date: string,
    signal?: AbortSignal,
): Promise<DayPlanSuggestedTask[]> {
    const response = await fetch(suggestions.url({ query: { fecha: date } }), {
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
        signal,
    });

    if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
    }

    const data = (await response.json()) as { tasks?: DayPlanSuggestedTask[] };

    return Array.isArray(data.tasks) ? data.tasks : [];
}

/**
 * «Desde mis tareas» (docs/PLAN-CARGAS.md §4.2): mis tareas que vencen ese día o están vencidas, las
 * que están en curso y aquellas en las que imputé hace poco. Cada una elegida es una línea con su
 * título y la tarea enlazada.
 */
export function FromTasksDialog({
    date,
    open,
    onOpenChange,
}: {
    date: string;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                {open ? (
                    <FromTasksForm
                        date={date}
                        onDone={() => onOpenChange(false)}
                    />
                ) : null}
            </DialogContent>
        </Dialog>
    );
}

function FromTasksForm({ date, onDone }: { date: string; onDone: () => void }) {
    const id = useId();
    const [tasks, setTasks] = useState<DayPlanSuggestedTask[] | null>(null);
    const [failed, setFailed] = useState(false);
    const [selected, setSelected] = useState<number[]>([]);
    const [processing, setProcessing] = useState(false);

    useEffect(() => {
        const controller = new AbortController();

        fetchSuggestedTasks(date, controller.signal)
            .then(setTasks)
            .catch((error: unknown) => {
                if (
                    !(
                        error instanceof DOMException &&
                        error.name === 'AbortError'
                    )
                ) {
                    setFailed(true);
                }
            });

        return () => controller.abort();
    }, [date]);

    const toggle = (taskId: number, checked: boolean) =>
        setSelected((current) =>
            checked
                ? [...current, taskId]
                : current.filter((value) => value !== taskId),
        );

    const submit = () =>
        router.post(
            fromTasks.url(),
            { date, task_ids: selected },
            {
                preserveScroll: true,
                preserveState: true,
                errorBag: 'dayPlan',
                // Que ningún error del servidor se pierda en silencio (D-310).
                onError: toastVisitErrors,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onSuccess: onDone,
            },
        );

    return (
        <>
            <DialogTitle>{t('day_plan.from_tasks.title')}</DialogTitle>
            <DialogDescription>
                {t('day_plan.from_tasks.description')}
            </DialogDescription>
            {tasks === null && !failed ? (
                <p
                    className="flex items-center gap-2 text-sm text-muted-foreground"
                    role="status"
                >
                    <Spinner />
                    {t('common.loading')}
                </p>
            ) : null}
            {failed ? (
                <p className="text-sm text-danger" role="alert">
                    {t('day_plan.from_tasks.error')}
                </p>
            ) : null}
            {tasks !== null && tasks.length === 0 ? (
                <EmptyState
                    icon={ListChecks}
                    title={t('day_plan.from_tasks.empty')}
                />
            ) : null}
            {tasks !== null && tasks.length > 0 ? (
                <ul
                    className="grid max-h-96 gap-2 overflow-y-auto"
                    data-test="day-plan-from-tasks-list"
                >
                    {tasks.map((task) => (
                        <li key={task.id} className="flex items-start gap-2">
                            <Checkbox
                                id={`${id}-${task.id}`}
                                checked={selected.includes(task.id)}
                                onCheckedChange={(checked) =>
                                    toggle(task.id, checked === true)
                                }
                                className="mt-0.5"
                            />
                            <Label
                                htmlFor={`${id}-${task.id}`}
                                className="grid gap-0.5 font-normal"
                            >
                                <span>{task.title}</span>
                                <span className="flex flex-wrap items-center gap-x-2 text-xs text-muted-foreground">
                                    <span className="inline-flex items-center gap-1">
                                        <span
                                            aria-hidden="true"
                                            className="size-2 rounded-full"
                                            style={{
                                                backgroundColor:
                                                    task.project.color,
                                            }}
                                        />
                                        {task.project.code}
                                    </span>
                                    <span>
                                        {t(
                                            `day_plan.from_tasks.reason.${task.reason}`,
                                        )}
                                    </span>
                                    {task.due_date ? (
                                        <span>
                                            {t('day_plan.from_tasks.due', {
                                                date: formatDate(task.due_date),
                                            })}
                                        </span>
                                    ) : null}
                                </span>
                            </Label>
                        </li>
                    ))}
                </ul>
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
                    disabled={processing || selected.length === 0}
                    onClick={submit}
                    data-test="day-plan-from-tasks-add"
                >
                    {processing ? <Spinner /> : null}
                    {t('day_plan.from_tasks.add', { count: selected.length })}
                </Button>
            </DialogFooter>
        </>
    );
}
