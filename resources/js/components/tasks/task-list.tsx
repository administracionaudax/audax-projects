import { ChevronDown, ChevronRight, CornerDownRight } from 'lucide-react';
import { useState } from 'react';
import { PriorityBadge, TaskStatusBadge } from '@/components/domain/badges';
import { QuickAddTask } from '@/components/tasks/quick-add-task';
import { AssigneeLabel } from '@/components/tasks/task-fields';
import { groupTasks } from '@/components/tasks/task-groups';
import type { TaskGroup } from '@/components/tasks/task-groups';
import { useTaskLookups } from '@/components/tasks/task-lookups';
import {
    TaskDates,
    TaskEstimate,
    TaskIndicators,
    totalLoggedMinutes,
} from '@/components/tasks/task-meta';
import { TimerButton } from '@/components/time/timer-button';
import { Checkbox } from '@/components/ui/checkbox';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatMinutes } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { TaskGroupBy, TaskListItem } from '@/types';

type RowProps = {
    task: TaskListItem;
    level: 0 | 1;
    selected: boolean;
    onSelect: (taskId: number, selected: boolean) => void;
    onOpen: (taskId: number) => void;
    expanded?: boolean;
    onToggleExpand?: () => void;
    hiddenSubtasks?: number;
};

function TaskRow({
    task,
    level,
    selected,
    onSelect,
    onOpen,
    expanded,
    onToggleExpand,
    hiddenSubtasks = 0,
}: RowProps) {
    const lookups = useTaskLookups();
    const status = lookups.statusById.get(task.status_id);
    const type =
        task.task_type_id !== null
            ? lookups.typeById.get(task.task_type_id)
            : undefined;
    const bank =
        task.hour_bank_id !== null
            ? lookups.bankById.get(task.hour_bank_id)
            : undefined;
    const subtasks = task.subtasks ?? [];
    const logged =
        level === 0 ? totalLoggedMinutes(task) : (task.logged_minutes ?? 0);

    return (
        <tr
            className={cn(
                'border-b align-middle',
                selected && 'bg-accent',
                task.is_completed && 'text-muted-foreground',
            )}
            data-test="task-row"
        >
            <td className="w-10 px-2 py-2">
                {lookups.can.update ? (
                    <Checkbox
                        checked={selected}
                        onCheckedChange={(value) =>
                            onSelect(task.id, value === true)
                        }
                        aria-label={t('task_list.select_task', {
                            task: task.title,
                        })}
                    />
                ) : null}
            </td>
            <th scope="row" className="px-2 py-2 text-left font-normal">
                <div
                    className={cn(
                        'flex min-w-0 items-center gap-1.5',
                        level === 1 && 'pl-6',
                    )}
                >
                    {level === 0 && subtasks.length > 0 ? (
                        <button
                            type="button"
                            onClick={onToggleExpand}
                            aria-expanded={expanded}
                            aria-label={t(
                                expanded
                                    ? 'task_list.hide_subtasks'
                                    : 'task_list.show_subtasks',
                                { task: task.title },
                            )}
                            className={cn(
                                'rounded-md p-0.5 text-muted-foreground hover:text-foreground',
                                FOCUS_RING,
                            )}
                        >
                            {expanded ? (
                                <ChevronDown
                                    aria-hidden="true"
                                    className="size-4"
                                />
                            ) : (
                                <ChevronRight
                                    aria-hidden="true"
                                    className="size-4"
                                />
                            )}
                        </button>
                    ) : level === 1 ? (
                        <CornerDownRight
                            aria-hidden="true"
                            className="size-4 shrink-0 text-muted-foreground"
                        />
                    ) : (
                        <span aria-hidden="true" className="w-5 shrink-0" />
                    )}
                    <button
                        type="button"
                        onClick={() => onOpen(task.id)}
                        className={cn(
                            'min-w-0 truncate rounded-md text-left hover:underline',
                            task.is_completed && 'line-through',
                            FOCUS_RING,
                        )}
                        data-test="task-title"
                    >
                        {task.title}
                    </button>
                    {task.priority === 'high' || task.priority === 'urgent' ? (
                        <PriorityBadge priority={task.priority} />
                    ) : null}
                    <TaskIndicators task={task} />
                    {hiddenSubtasks > 0 ? (
                        <span className="text-xs text-muted-foreground">
                            {t('task_list.hidden_completed_subtasks', {
                                count: hiddenSubtasks,
                            })}
                        </span>
                    ) : null}
                </div>
            </th>
            <td className="max-w-40 px-2 py-2">
                <AssigneeLabel user={task.assignee} />
            </td>
            {lookups.usesBanks ? (
                <td className="max-w-48 truncate px-2 py-2">
                    {bank ? (
                        bank.name
                    ) : (
                        <span className="text-muted-foreground">—</span>
                    )}
                </td>
            ) : null}
            <td className="px-2 py-2 whitespace-nowrap">
                {type ? (
                    <span className="inline-flex items-center gap-1.5">
                        <span
                            aria-hidden="true"
                            className="size-2 rounded-full"
                            style={{ backgroundColor: type.color }}
                        />
                        {type.name}
                    </span>
                ) : (
                    <span className="text-muted-foreground">—</span>
                )}
            </td>
            <td className="px-2 py-2">
                {status ? (
                    <TaskStatusBadge
                        name={status.name}
                        color={status.color}
                        done={status.category === 'done'}
                    />
                ) : null}
            </td>
            <td className="px-2 py-2">
                <TaskDates task={task} />
            </td>
            <td className="px-2 py-2 text-right">
                <TaskEstimate task={task} />
            </td>
            <td className="tabular px-2 py-2 text-right">
                {logged > 0 ? (
                    formatMinutes(logged)
                ) : (
                    <span className="text-muted-foreground">0:00</span>
                )}
            </td>
            <td className="w-10 px-2 py-2">
                {!task.is_milestone ? <TimerButton task={task} /> : null}
            </td>
        </tr>
    );
}

function GroupTable({
    group,
    showCompleted,
    selection,
    onSelect,
    onOpen,
    headingId,
}: {
    group: TaskGroup;
    showCompleted: boolean;
    selection: Set<number>;
    onSelect: (taskIds: number[], selected: boolean) => void;
    onOpen: (taskId: number) => void;
    headingId: string;
}) {
    const lookups = useTaskLookups();
    const [collapsed, setCollapsed] = useState<Set<number>>(new Set());
    const visibleIds = group.tasks.flatMap((task) => [
        task.id,
        ...(collapsed.has(task.id)
            ? []
            : (task.subtasks ?? [])
                  .filter((subtask) => showCompleted || !subtask.is_completed)
                  .map((subtask) => subtask.id)),
    ]);
    const allSelected =
        visibleIds.length > 0 && visibleIds.every((id) => selection.has(id));
    const someSelected = visibleIds.some((id) => selection.has(id));

    const toggle = (taskId: number) =>
        setCollapsed((current) => {
            const next = new Set(current);

            if (next.has(taskId)) {
                next.delete(taskId);
            } else {
                next.add(taskId);
            }

            return next;
        });

    return (
        <div
            className={cn('overflow-x-auto rounded-md border', FOCUS_RING)}
            role="region"
            aria-labelledby={headingId}
            tabIndex={0}
        >
            <table className="w-full min-w-[62rem] text-sm">
                <caption className="sr-only">{group.label}</caption>
                <thead>
                    <tr className="border-b text-left text-xs text-muted-foreground">
                        <th scope="col" className="w-10 px-2 py-2">
                            {lookups.can.update && visibleIds.length > 0 ? (
                                <Checkbox
                                    checked={
                                        allSelected
                                            ? true
                                            : someSelected
                                              ? 'indeterminate'
                                              : false
                                    }
                                    onCheckedChange={(value) =>
                                        onSelect(visibleIds, value === true)
                                    }
                                    aria-label={t('task_list.select_group', {
                                        group: group.label,
                                    })}
                                />
                            ) : (
                                <span className="sr-only">
                                    {t('task_list.column.select')}
                                </span>
                            )}
                        </th>
                        <th scope="col" className="px-2 py-2 font-medium">
                            {t('task_list.column.title')}
                        </th>
                        <th scope="col" className="px-2 py-2 font-medium">
                            {t('task_list.column.assignee')}
                        </th>
                        {lookups.usesBanks ? (
                            <th scope="col" className="px-2 py-2 font-medium">
                                {t('task_list.column.bank')}
                            </th>
                        ) : null}
                        <th scope="col" className="px-2 py-2 font-medium">
                            {t('task_list.column.type')}
                        </th>
                        <th scope="col" className="px-2 py-2 font-medium">
                            {t('task_list.column.status')}
                        </th>
                        <th scope="col" className="px-2 py-2 font-medium">
                            {t('task_list.column.dates')}
                        </th>
                        <th
                            scope="col"
                            className="px-2 py-2 text-right font-medium"
                        >
                            {t('task_list.column.estimate')}
                        </th>
                        <th
                            scope="col"
                            className="px-2 py-2 text-right font-medium"
                        >
                            {t('task_list.column.logged')}
                        </th>
                        <th scope="col" className="w-10 px-2 py-2">
                            <span className="sr-only">
                                {t('task_list.column.timer')}
                            </span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    {group.tasks.map((task) => {
                        const subtasks = task.subtasks ?? [];
                        const visibleSubtasks = subtasks.filter(
                            (subtask) => showCompleted || !subtask.is_completed,
                        );
                        const expanded = !collapsed.has(task.id);

                        return [
                            <TaskRow
                                key={task.id}
                                task={task}
                                level={0}
                                selected={selection.has(task.id)}
                                onSelect={(id, selected) =>
                                    onSelect([id], selected)
                                }
                                onOpen={onOpen}
                                expanded={expanded}
                                onToggleExpand={() => toggle(task.id)}
                                hiddenSubtasks={
                                    expanded
                                        ? subtasks.length -
                                          visibleSubtasks.length
                                        : 0
                                }
                            />,
                            ...(expanded
                                ? visibleSubtasks.map((subtask) => (
                                      <TaskRow
                                          key={subtask.id}
                                          task={subtask}
                                          level={1}
                                          selected={selection.has(subtask.id)}
                                          onSelect={(id, selected) =>
                                              onSelect([id], selected)
                                          }
                                          onOpen={onOpen}
                                      />
                                  ))
                                : []),
                        ];
                    })}
                    {group.tasks.length === 0 ? (
                        <tr>
                            <td
                                colSpan={lookups.usesBanks ? 10 : 9}
                                className="px-3 py-3 text-sm text-muted-foreground"
                            >
                                {t('task_list.group_empty')}
                            </td>
                        </tr>
                    ) : null}
                </tbody>
            </table>
        </div>
    );
}

/**
 * Vista Lista (SPEC §6): grupos (por estado, responsable, bolsa o tipo) con sus tareas raíz y
 * subtareas desplegables, selección para las acciones masivas y creación rápida en cada grupo.
 */
export function TaskList({
    tasks,
    groupBy,
    showCompleted,
    selection,
    onSelect,
    onOpen,
}: {
    tasks: TaskListItem[];
    groupBy: TaskGroupBy;
    showCompleted: boolean;
    selection: Set<number>;
    onSelect: (taskIds: number[], selected: boolean) => void;
    onOpen: (taskId: number) => void;
}) {
    const lookups = useTaskLookups();
    const groups = groupTasks(tasks, groupBy, lookups, showCompleted);

    return (
        <div className="flex flex-col gap-8">
            {groups.map((group) => {
                const headingId = `task-group-${group.key}`;
                const count = group.tasks.length;

                return (
                    <section
                        key={group.key}
                        aria-labelledby={headingId}
                        className="flex flex-col gap-3"
                        data-test="task-group"
                    >
                        {groupBy !== 'none' ? (
                            <h2
                                id={headingId}
                                className="flex items-center gap-2 text-base font-medium"
                            >
                                {group.color ? (
                                    <span
                                        aria-hidden="true"
                                        className="size-2.5 rounded-full"
                                        style={{ backgroundColor: group.color }}
                                    />
                                ) : null}
                                {group.label}
                                <span className="text-sm font-normal text-muted-foreground">
                                    {t('task_list.group_count', { count })}
                                </span>
                            </h2>
                        ) : (
                            <h2 id={headingId} className="sr-only">
                                {group.label}
                            </h2>
                        )}
                        <GroupTable
                            group={group}
                            showCompleted={showCompleted}
                            selection={selection}
                            onSelect={onSelect}
                            onOpen={onOpen}
                            headingId={headingId}
                        />
                        <QuickAddTask
                            defaults={group.defaults}
                            label={
                                groupBy === 'none'
                                    ? t('quick_add.label')
                                    : t('quick_add.label_in', {
                                          group: group.label,
                                      })
                            }
                        />
                    </section>
                );
            })}
        </div>
    );
}
