import { Link, router } from '@inertiajs/react';
import {
    Archive,
    ArchiveRestore,
    CalendarDays,
    Pencil,
    Trash2,
    UserRound,
} from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { PriorityBadge } from '@/components/domain/badges';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    EditTaskDialog,
    MY_TASKS_RELOAD,
} from '@/components/weeklies/tasks/my-space-task-dialogs';
import { TaskNotesField } from '@/components/weeklies/tasks/task-notes-field';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatDate } from '@/lib/format';
import { t } from '@/lib/i18n';
import { ROW_CLICK_CLASS, rowClickProps } from '@/lib/row-click';
import { urls } from '@/lib/urls';
import { cn } from '@/lib/utils';
import {
    archive as archiveRoute,
    unarchive as unarchiveRoute,
} from '@/routes/my-space/tasks';
import { destroy as destroyTask, update as updateTask } from '@/routes/tasks';
import type { MySpaceTask } from '@/types/weeklies';

/**
 * Una tarea de «Mi espacio» (TaskView de WeeklySync): marcar hecha o pendiente con un clic (F-059),
 * el título con su enlace, la fecha de creación, «De: …» si me la asignó otra persona, la prioridad y
 * la entrega (F-063), las notas con dictado (F-060), y editar, archivar o recuperar (F-057) y eliminar
 * (F-061, solo sin horas, D-037).
 */
export function MySpaceTaskRow({
    task,
    statuses,
}: {
    task: MySpaceTask;
    statuses: { open: number | null; done: number | null };
}) {
    const [editing, setEditing] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const [processing, setProcessing] = useState(false);
    const nextStatus = task.completed ? statuses.open : statuses.done;
    const options = {
        preserveScroll: true,
        preserveState: true,
        only: MY_TASKS_RELOAD,
        onStart: () => setProcessing(true),
        onFinish: () => setProcessing(false),
    };

    const toggle = () => {
        if (nextStatus === null) {
            return;
        }

        const completing = !task.completed;

        router.patch(
            updateTask.url(task.id),
            { status_id: nextStatus },
            {
                ...options,
                // Aviso como en WeeklySync (10.9b): hecha o pendiente de nuevo, y si falla.
                onSuccess: () =>
                    toast.success(
                        t(
                            completing
                                ? 'my_space.tasks.toast.done'
                                : 'my_space.tasks.toast.reopened',
                            { task: task.title },
                        ),
                    ),
                onError: () => toast.error(t('my_space.tasks.toast.failed')),
            },
        );
    };

    return (
        <li
            className={cn(
                'grid gap-3 border-b p-3 last:border-b-0 md:grid-cols-[minmax(16rem,26rem)_minmax(0,1fr)_auto] md:items-start md:gap-4 md:p-4',
                ROW_CLICK_CLASS,
                task.completed && 'bg-muted/40',
            )}
            {...rowClickProps}
            data-test={`my-space-task-${task.id}`}
        >
            <div className="flex min-w-0 items-start gap-3">
                <Checkbox
                    checked={task.completed}
                    onCheckedChange={toggle}
                    disabled={
                        !task.can.update || nextStatus === null || processing
                    }
                    aria-label={t(
                        task.completed
                            ? 'my_space.tasks.mark_open'
                            : 'my_space.tasks.mark_done',
                        { task: task.title },
                    )}
                    className="mt-0.5"
                    data-test="my-space-task-toggle"
                />
                <div className="grid min-w-0 gap-1.5">
                    <Link
                        href={urls.task(task.project.id, task.id)}
                        data-row-primary
                        className={cn(
                            'text-sm break-words hover:underline',
                            task.completed &&
                                'text-muted-foreground line-through',
                            FOCUS_RING,
                        )}
                    >
                        {task.title}
                    </Link>
                    <div className="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
                        <span className="tabular">{task.project.code}</span>
                        {task.created_at ? (
                            <span>
                                {t('my_space.tasks.created', {
                                    date: formatDate(task.created_at),
                                })}
                            </span>
                        ) : null}
                        {task.due_date ? (
                            <span className="inline-flex items-center gap-1">
                                <CalendarDays
                                    aria-hidden="true"
                                    className="size-3"
                                />
                                {t('my_space.tasks.due', {
                                    date: formatDate(task.due_date),
                                })}
                            </span>
                        ) : null}
                        {task.priority !== 'normal' ? (
                            <PriorityBadge priority={task.priority} />
                        ) : null}
                        {task.assigner ? (
                            <span className="inline-flex items-center gap-1 border bg-muted px-1.5">
                                <UserRound
                                    aria-hidden="true"
                                    className="size-3"
                                />
                                {t('my_space.tasks.from', {
                                    name: task.assigner.name,
                                })}
                            </span>
                        ) : null}
                    </div>
                </div>
            </div>

            {/* Las notas se escriben aquí: un clic en su zona no abre la tarea (D-324). */}
            <div data-row-ignore className="min-w-0 cursor-auto">
                <TaskNotesField task={task} />
            </div>

            <div className="flex flex-wrap items-center gap-1 md:justify-end">
                {!task.archived && task.can.update ? (
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        onClick={() => setEditing(true)}
                        aria-label={t('my_space.tasks.edit.action', {
                            task: task.title,
                        })}
                        title={t('my_space.tasks.edit.short')}
                    >
                        <Pencil aria-hidden="true" />
                    </Button>
                ) : null}
                {task.archived ? (
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        disabled={processing}
                        onClick={() =>
                            router.delete(unarchiveRoute.url(task.id), options)
                        }
                        aria-label={t('my_space.tasks.unarchive', {
                            task: task.title,
                        })}
                        title={t('my_space.tasks.unarchive_short')}
                        data-test="my-space-task-unarchive"
                    >
                        <ArchiveRestore aria-hidden="true" />
                    </Button>
                ) : (
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        disabled={processing}
                        onClick={() =>
                            router.post(archiveRoute.url(task.id), {}, options)
                        }
                        aria-label={t('my_space.tasks.archive', {
                            task: task.title,
                        })}
                        title={t('my_space.tasks.archive_short')}
                        data-test="my-space-task-archive"
                    >
                        <Archive aria-hidden="true" />
                    </Button>
                )}
                {task.can.delete ? (
                    <ConfirmDialog
                        open={deleting}
                        onOpenChange={setDeleting}
                        trigger={
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                aria-label={t('my_space.tasks.delete.action', {
                                    task: task.title,
                                })}
                                title={t('my_space.tasks.delete.short')}
                            >
                                <Trash2 aria-hidden="true" />
                            </Button>
                        }
                        title={t('my_space.tasks.delete.title')}
                        description={t('my_space.tasks.delete.description', {
                            task: task.title,
                        })}
                        confirmLabel={t('my_space.tasks.delete.confirm')}
                        processing={processing}
                        onConfirm={() =>
                            router.delete(destroyTask.url(task.id), {
                                ...options,
                                onSuccess: () => setDeleting(false),
                            })
                        }
                    />
                ) : task.can.update && task.has_time ? (
                    // Con horas no se borra (D-037): el botón queda, desactivado y explicado.
                    <span
                        className="inline-flex"
                        title={t('my_space.tasks.delete.has_time')}
                    >
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            disabled
                            aria-label={t('my_space.tasks.delete.has_time')}
                            data-test="my-space-task-delete-blocked"
                        >
                            <Trash2 aria-hidden="true" />
                        </Button>
                    </span>
                ) : null}
            </div>

            <EditTaskDialog
                task={task}
                open={editing}
                onOpenChange={setEditing}
            />
        </li>
    );
}
