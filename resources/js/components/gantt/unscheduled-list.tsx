import { CalendarPlus, CalendarX2 } from 'lucide-react';
import { useId } from 'react';
import type { GanttTask } from '@/components/gantt/types';
import { TaskStatusBadge } from '@/components/domain/badges';
import { Button } from '@/components/ui/button';
import { t } from '@/lib/i18n';

/** Tareas sin fechas de un proyecto (en el Gantt multiproyecto, con su nombre). */
export type UnscheduledGroup = {
    key: string;
    /** Título del grupo: el proyecto en el Gantt multiproyecto; null en el de un proyecto. */
    label: string | null;
    tasks: ReadonlyArray<GanttTask>;
};

type ItemProps = {
    parents: ReadonlyMap<number, string>;
    readOnly: boolean;
    onAssign: (task: GanttTask) => void;
    onOpen: (task: GanttTask) => void;
};

/**
 * Tareas sin fechas (SPEC §6.1): se listan aparte, con «Asignar fechas» (si se pueden editar) y
 * el enlace a la tarea. Las subtareas indican su tarea. En el Gantt multiproyecto, agrupadas por
 * proyecto. `data-gantt-focus` marca adónde va el foco cuando la tarea llega a la lista (tras
 * quitarle las fechas): a «Asignar fechas» o, si no se puede, a su título.
 */
export function UnscheduledList({
    groups,
    parents,
    readOnly = false,
    onAssign,
    onOpen,
}: {
    groups: ReadonlyArray<UnscheduledGroup>;
    /** Títulos de las tareas padre, por id. */
    parents: ReadonlyMap<number, string>;
    readOnly?: boolean;
    onAssign: (task: GanttTask) => void;
    onOpen: (task: GanttTask) => void;
}) {
    const headingId = useId();
    const filled = groups.filter((group) => group.tasks.length > 0);
    const count = filled.reduce(
        (total, group) => total + group.tasks.length,
        0,
    );

    if (count === 0) {
        return null;
    }

    const item = { parents, readOnly, onAssign, onOpen };

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
                {t('gantt.unscheduled.title', { count })}
            </h2>
            <p className="text-sm text-muted-foreground">
                {t('gantt.unscheduled.description')}
            </p>
            {filled.map((group) =>
                group.label === null ? (
                    <TaskList key={group.key} tasks={group.tasks} {...item} />
                ) : (
                    <UnscheduledProject
                        key={group.key}
                        group={group}
                        label={group.label}
                        {...item}
                    />
                ),
            )}
        </section>
    );
}

function UnscheduledProject({
    group,
    label,
    ...item
}: ItemProps & { group: UnscheduledGroup; label: string }) {
    const headingId = useId();

    return (
        <div className="grid gap-1" data-test="gantt-unscheduled-project">
            <h3
                id={headingId}
                className="text-sm font-medium text-muted-foreground"
            >
                {t('gantt.unscheduled.project', {
                    project: label,
                    count: group.tasks.length,
                })}
            </h3>
            <TaskList tasks={group.tasks} labelledBy={headingId} {...item} />
        </div>
    );
}

function TaskList({
    tasks,
    labelledBy,
    parents,
    readOnly,
    onAssign,
    onOpen,
}: ItemProps & {
    tasks: ReadonlyArray<GanttTask>;
    labelledBy?: string;
}) {
    return (
        <ul className="grid divide-y" aria-labelledby={labelledBy}>
            {tasks.map((task) => {
                const assignable = !readOnly && task.can.update;
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
                                data-gantt-focus={
                                    assignable ? undefined : task.id
                                }
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
                        {assignable ? (
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => onAssign(task)}
                                data-gantt-focus={task.id}
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
    );
}
