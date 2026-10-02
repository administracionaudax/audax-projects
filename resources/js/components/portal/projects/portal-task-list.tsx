import {
    CornerDownRight,
    Diamond,
    ListChecks,
    TriangleAlert,
} from 'lucide-react';
import { useId, useState } from 'react';
import { TaskStatusBadge } from '@/components/domain/badges';
import { EmptyState } from '@/components/empty-state';
import type {
    PortalProjectShowProps,
    PortalTask,
} from '@/components/portal/projects/types';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatDate, formatMinutes } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';

export type PortalTaskFilter = 'all' | 'open' | 'done';

const FILTERS: PortalTaskFilter[] = ['all', 'open', 'done'];

/** Vencida: sin completar y con la entrega antes de hoy (fechas locales AAAA-MM-DD). */
export function isOverdue(task: PortalTask, today: string): boolean {
    return (
        !task.is_completed && task.due_date !== null && task.due_date < today
    );
}

export function filterTasks(
    tasks: ReadonlyArray<PortalTask>,
    filter: PortalTaskFilter,
): PortalTask[] {
    if (filter === 'all') {
        return [...tasks];
    }

    return tasks.filter((task) =>
        filter === 'done' ? task.is_completed : !task.is_completed,
    );
}

/**
 * Tareas de un proyecto en el portal (SPEC §11, D-064): título, estado, entrega y si es hito; las
 * subtareas, sangradas detrás de su tarea; las horas por tarea solo si el equipo las enseña. Sin
 * personas, comentarios ni adjuntos. Filtro de abiertas y completadas y resumen por estado.
 */
export function PortalTaskList({
    projectName,
    tasks,
    statuses,
    showHours,
    totals,
    today,
}: Pick<
    PortalProjectShowProps,
    'tasks' | 'statuses' | 'showHours' | 'totals'
> & {
    projectName: string;
    today: string;
}) {
    const id = useId();
    const [filter, setFilter] = useState<PortalTaskFilter>('all');

    if (tasks.length === 0) {
        return (
            <EmptyState
                icon={ListChecks}
                title={t('portal_projects.tasks.empty')}
                description={t('portal_projects.tasks.empty_description')}
            />
        );
    }

    const titles = new Map(tasks.map((task) => [task.id, task.title]));
    const visible = filterTasks(tasks, filter);
    const shown = new Set(visible.map((task) => task.id));
    const counts: Record<PortalTaskFilter, number> = {
        all: totals.tasks,
        open: totals.open,
        done: totals.done,
    };
    const byStatus = statuses
        .map((status) => ({
            status,
            count: tasks.filter((task) => task.status?.id === status.id).length,
        }))
        .filter((item) => item.count > 0);

    return (
        <div className="grid gap-4">
            <div className="flex flex-wrap items-end justify-between gap-x-6 gap-y-3">
                <div className="grid gap-1">
                    <span
                        id={`${id}-filter`}
                        className="text-xs text-muted-foreground"
                    >
                        {t('portal_projects.tasks.filter')}
                    </span>
                    <ToggleGroup
                        type="single"
                        variant="outline"
                        value={filter}
                        onValueChange={(next) => {
                            const found = FILTERS.find((item) => item === next);

                            if (found) {
                                setFilter(found);
                            }
                        }}
                        aria-labelledby={`${id}-filter`}
                    >
                        {FILTERS.map((item) => (
                            <ToggleGroupItem
                                key={item}
                                value={item}
                                className="gap-1.5 px-3"
                                data-test={`portal-tasks-filter-${item}`}
                            >
                                {t(`portal_projects.tasks.filter_${item}`)}{' '}
                                <span className="tabular text-muted-foreground">
                                    {counts[item]}
                                </span>
                            </ToggleGroupItem>
                        ))}
                    </ToggleGroup>
                </div>

                {byStatus.length > 0 ? (
                    <ul
                        aria-label={t('portal_projects.tasks.by_status')}
                        className="flex flex-wrap items-center gap-2"
                    >
                        {byStatus.map(({ status, count }) => (
                            <li
                                key={status.id}
                                className="flex items-center gap-1.5 text-sm"
                            >
                                <TaskStatusBadge
                                    name={status.name}
                                    color={status.color}
                                    done={status.category === 'done'}
                                />
                                <span className="tabular text-muted-foreground">
                                    {count}
                                </span>
                            </li>
                        ))}
                    </ul>
                ) : null}
            </div>

            {visible.length === 0 ? (
                <EmptyState
                    icon={ListChecks}
                    title={t(`portal_projects.tasks.none_${filter}`)}
                />
            ) : (
                <div
                    className={cn(
                        'overflow-x-auto rounded-md border',
                        FOCUS_RING,
                    )}
                    role="region"
                    aria-label={t('portal_projects.tasks.table', {
                        project: projectName,
                    })}
                    tabIndex={0}
                    data-test="portal-tasks"
                >
                    <table className="w-full min-w-[34rem] text-sm">
                        <caption className="sr-only">
                            {t('portal_projects.tasks.table', {
                                project: projectName,
                            })}
                        </caption>
                        <thead>
                            <tr className="border-b text-left">
                                <th
                                    scope="col"
                                    className="px-3 py-2 font-medium"
                                >
                                    {t('portal_projects.tasks.column_task')}
                                </th>
                                <th
                                    scope="col"
                                    className="px-3 py-2 font-medium"
                                >
                                    {t('portal_projects.tasks.column_status')}
                                </th>
                                <th
                                    scope="col"
                                    className="px-3 py-2 font-medium"
                                >
                                    {t('portal_projects.tasks.column_due')}
                                </th>
                                {showHours ? (
                                    <th
                                        scope="col"
                                        className="px-3 py-2 text-right font-medium"
                                    >
                                        {t(
                                            'portal_projects.tasks.column_hours',
                                        )}
                                    </th>
                                ) : null}
                            </tr>
                        </thead>
                        <tbody>
                            {visible.map((task) => {
                                const nested =
                                    task.depth === 1 &&
                                    task.parent_task_id !== null &&
                                    shown.has(task.parent_task_id);
                                const parent =
                                    task.parent_task_id !== null
                                        ? (titles.get(task.parent_task_id) ??
                                          null)
                                        : null;
                                const overdue = isOverdue(task, today);

                                return (
                                    <tr
                                        key={task.id}
                                        className="border-b last:border-b-0 even:bg-muted"
                                        data-test="portal-task"
                                    >
                                        <th
                                            scope="row"
                                            className="px-3 py-2 text-left font-normal"
                                        >
                                            <span
                                                className={cn(
                                                    'flex items-start gap-1.5',
                                                    nested && 'pl-5',
                                                )}
                                            >
                                                {task.depth === 1 ? (
                                                    <CornerDownRight
                                                        aria-hidden="true"
                                                        className="mt-0.5 size-3.5 shrink-0 text-muted-foreground"
                                                    />
                                                ) : null}
                                                {task.is_milestone ? (
                                                    <Diamond
                                                        aria-hidden="true"
                                                        className="mt-0.5 size-3.5 shrink-0 text-muted-foreground"
                                                    />
                                                ) : null}
                                                <span className="min-w-0 break-words">
                                                    {task.title}
                                                    {task.is_milestone ? (
                                                        <span className="ml-2 text-xs text-muted-foreground">
                                                            {t(
                                                                'portal_projects.tasks.milestone',
                                                            )}
                                                        </span>
                                                    ) : null}
                                                    {task.depth === 1 &&
                                                    parent ? (
                                                        <span
                                                            className={cn(
                                                                'block text-xs text-muted-foreground',
                                                                nested &&
                                                                    'sr-only',
                                                            )}
                                                        >
                                                            {t(
                                                                'portal_projects.tasks.subtask_of',
                                                                {
                                                                    task: parent,
                                                                },
                                                            )}
                                                        </span>
                                                    ) : null}
                                                    {task.subtasks_count > 0 ? (
                                                        <span className="block text-xs text-muted-foreground">
                                                            {task.subtasks_count ===
                                                            1
                                                                ? t(
                                                                      'portal_projects.tasks.subtasks_one',
                                                                  )
                                                                : t(
                                                                      'portal_projects.tasks.subtasks_many',
                                                                      {
                                                                          count: task.subtasks_count,
                                                                      },
                                                                  )}
                                                        </span>
                                                    ) : null}
                                                </span>
                                            </span>
                                        </th>
                                        <td className="px-3 py-2">
                                            {task.status ? (
                                                <TaskStatusBadge
                                                    name={task.status.name}
                                                    color={task.status.color}
                                                    done={
                                                        task.status.category ===
                                                        'done'
                                                    }
                                                />
                                            ) : (
                                                '—'
                                            )}
                                        </td>
                                        <td className="tabular px-3 py-2 whitespace-nowrap">
                                            {task.due_date
                                                ? formatDate(task.due_date)
                                                : '—'}
                                            {overdue ? (
                                                <span className="flex items-center gap-1 text-xs text-danger">
                                                    <TriangleAlert
                                                        aria-hidden="true"
                                                        className="size-3.5"
                                                    />
                                                    {t(
                                                        'portal_projects.tasks.overdue',
                                                    )}
                                                </span>
                                            ) : null}
                                        </td>
                                        {showHours ? (
                                            <td className="tabular px-3 py-2 text-right whitespace-nowrap">
                                                {formatMinutes(
                                                    task.minutes ?? 0,
                                                )}
                                            </td>
                                        ) : null}
                                    </tr>
                                );
                            })}
                        </tbody>
                        {showHours && totals.minutes !== null ? (
                            <tfoot>
                                <tr className="border-t">
                                    <th
                                        scope="row"
                                        colSpan={3}
                                        className="px-3 py-2 text-left font-medium"
                                    >
                                        {t('portal_projects.tasks.total_hours')}
                                    </th>
                                    <td className="tabular px-3 py-2 text-right font-medium whitespace-nowrap">
                                        {formatMinutes(totals.minutes)}
                                    </td>
                                </tr>
                            </tfoot>
                        ) : null}
                    </table>
                </div>
            )}

            {showHours ? (
                <p className="text-xs text-muted-foreground">
                    {t('portal_projects.tasks.hours_note')}
                </p>
            ) : null}
        </div>
    );
}
