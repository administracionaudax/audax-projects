import { CalendarPlus, CalendarX2 } from 'lucide-react';
import { useId } from 'react';
import type { GanttTask } from '@/components/gantt/types';
import { TaskStatusBadge } from '@/components/domain/badges';
import { Button } from '@/components/ui/button';
import { t } from '@/lib/i18n';

/**
 * Tareas sin fechas (SPEC §6.1): se listan aparte, con «Asignar fechas» (si se pueden editar) y
 * el enlace a la tarea. Las subtareas indican su tarea.
 */
export function UnscheduledList({
    tasks,
    parents,
    readOnly = false,
    onAssign,
    onOpen,
}: {
    tasks: ReadonlyArray<GanttTask>;
    /** Títulos de las tareas padre, por id. */
    parents: ReadonlyMap<number, string>;
    readOnly?: boolean;
    onAssign: (task: GanttTask) => void;
    onOpen: (task: GanttTask) => void;
}) {
    const headingId = useId();

    if (tasks.length === 0) {
        return null;
    }

    return (
        <section
            aria-labelledby={headingId}
            className="grid gap-3 rounded-md border p-4"
            data-test="gantt-unscheduled"
        >
            <h2
                id={headingId}
                className="flex items-center gap-2 text-base font-normal"
            >
                <CalendarX2
                    aria-hidden="true"
                    className="size-4 text-muted-foreground"
                />
                {t('gantt.unscheduled.title', { count: tasks.length })}
            </h2>
            <p className="text-sm text-muted-foreground">
                {t('gantt.unscheduled.description')}
            </p>
            <ul className="grid divide-y">
                {tasks.map((task) => {
                    const parent =
                        task.parent_task_id !== null
                            ? parents.get(task.parent_task_id)
                            : undefined;

                    return (
                        <li
                            key={task.id}
                            className="flex flex-wrap items-center gap-x-3 gap-y-1 py-2"
                            data-test="gantt-unscheduled-task"
                        >
                            <div className="min-w-0 flex-1">
                                <button
                                    type="button"
                                    onClick={() => onOpen(task)}
                                    className="max-w-full truncate rounded-[3px] text-left text-sm text-foreground underline-offset-2 hover:underline"
                                >
                                    {task.title}
                                </button>
                                {parent ? (
                                    <p className="truncate text-xs text-muted-foreground">
                                        {t('gantt.unscheduled.subtask_of', {
                                            task: parent,
                                        })}
                                    </p>
                                ) : null}
                            </div>
                            {task.status ? (
                                <TaskStatusBadge
                                    name={task.status.name}
                                    color={task.status.color}
                                    done={task.status.category === 'done'}
                                />
                            ) : null}
                            {!readOnly && task.can.update ? (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => onAssign(task)}
                                    aria-label={t(
                                        'gantt.unscheduled.assign_label',
                                        { task: task.title },
                                    )}
                                >
                                    <CalendarPlus aria-hidden="true" />
                                    {t('gantt.unscheduled.assign')}
                                </Button>
                            ) : null}
                        </li>
                    );
                })}
            </ul>
        </section>
    );
}
