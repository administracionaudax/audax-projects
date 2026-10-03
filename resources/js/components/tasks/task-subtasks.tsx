import { TaskStatusBadge } from '@/components/domain/badges';
import { QuickAddTask } from '@/components/tasks/quick-add-task';
import { AssigneeLabel } from '@/components/tasks/task-fields';
import { isDoneStatus, useTaskLookups } from '@/components/tasks/task-lookups';
import { TaskEstimate } from '@/components/tasks/task-meta';
import { updateTask } from '@/components/tasks/task-requests';
import { Checkbox } from '@/components/ui/checkbox';
import { FOCUS_RING } from '@/lib/focus-ring';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { TaskPanelData } from '@/types';

/**
 * Subtareas (un solo nivel, siempre con la bolsa del padre, D-037): lista con casilla para
 * completarlas y creación rápida.
 */
export function TaskSubtasks({
    panel,
    onOpen,
}: {
    panel: TaskPanelData;
    onOpen: (taskId: number) => void;
}) {
    const lookups = useTaskLookups();
    const doneStatus = lookups.statuses.find(
        (status) => status.category === 'done',
    );
    const reopenStatus =
        lookups.statuses.find((status) => status.is_default) ??
        lookups.statuses[0];

    return (
        <div className="grid gap-3">
            {panel.subtasks.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    {t('task_panel.no_subtasks')}
                </p>
            ) : (
                <ul
                    className="divide-y rounded-md border"
                    data-test="subtask-list"
                >
                    {panel.subtasks.map((subtask) => {
                        const status = lookups.statusById.get(
                            subtask.status_id,
                        );
                        const done = isDoneStatus(
                            lookups.statusById,
                            subtask.status_id,
                        );

                        return (
                            <li
                                key={subtask.id}
                                className="flex flex-wrap items-center gap-2 px-3 py-2 text-sm"
                            >
                                {panel.can.update &&
                                doneStatus &&
                                reopenStatus ? (
                                    <Checkbox
                                        checked={done}
                                        onCheckedChange={(checked) =>
                                            updateTask(subtask.id, {
                                                status_id:
                                                    checked === true
                                                        ? doneStatus.id
                                                        : reopenStatus.id,
                                            })
                                        }
                                        aria-label={t(
                                            done
                                                ? 'task_panel.reopen_subtask'
                                                : 'task_panel.complete_subtask',
                                            {
                                                task: subtask.title,
                                            },
                                        )}
                                    />
                                ) : null}
                                <button
                                    type="button"
                                    onClick={() => onOpen(subtask.id)}
                                    className={cn(
                                        'min-w-0 flex-1 truncate rounded-md text-left hover:underline',
                                        done &&
                                            'text-muted-foreground line-through',
                                        FOCUS_RING,
                                    )}
                                >
                                    {subtask.title}
                                </button>
                                {status ? (
                                    <TaskStatusBadge
                                        name={status.name}
                                        color={status.color}
                                        done={done}
                                    />
                                ) : null}
                                <AssigneeLabel
                                    user={subtask.assignee}
                                    compact
                                />
                                <span className="text-xs text-muted-foreground">
                                    <TaskEstimate task={subtask} />
                                </span>
                            </li>
                        );
                    })}
                </ul>
            )}
            {panel.can.update && lookups.can.create ? (
                <QuickAddTask
                    parentId={panel.task.id}
                    label={t('quick_add.subtask_label', {
                        task: panel.task.title,
                    })}
                    compact
                />
            ) : null}
        </div>
    );
}
