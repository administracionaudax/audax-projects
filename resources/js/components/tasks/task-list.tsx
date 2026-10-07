import { ChevronDown, ChevronRight, CornerDownRight } from 'lucide-react';
import { useState } from 'react';
import {
    CollapsibleGroupHeading,
    GroupFoldControls,
} from '@/components/collapsible-group';
import { PriorityBadge, TaskStatusBadge } from '@/components/domain/badges';
import { HorizontalScroll } from '@/components/horizontal-scroll';
import { QuickAddTask } from '@/components/tasks/quick-add-task';
import { AssigneeLabel } from '@/components/tasks/task-fields';
import { groupTasks } from '@/components/tasks/task-groups';
import type { TaskGroup } from '@/components/tasks/task-groups';
import { useTaskLookups } from '@/components/tasks/task-lookups';
import {
    TaskDates,
    TaskEstimate,
    TaskIndicators,
    loggedBreakdownLabel,
    totalLoggedMinutes,
} from '@/components/tasks/task-meta';
import { TimerButton } from '@/components/time/timer-button';
import { Checkbox } from '@/components/ui/checkbox';
import { useCollapsedGroups } from '@/hooks/use-collapsed-groups';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatMinutes } from '@/lib/format';
import { t } from '@/lib/i18n';
import { ROW_CLICK_CLASS, rowClickProps } from '@/lib/row-click';
import { cn } from '@/lib/utils';
import type { TaskGroupBy, TaskListItem } from '@/types';

/**
 * Anchos de las columnas de la tabla de tareas, en rem (D-329). Con `table-fixed`, las de todos
 * los grupos quedan alineadas y la tarea se queda con lo que sobra (como poco, `TITLE_MIN_REM`):
 * si no cabe, la tabla se desplaza en horizontal con una sombra en el borde.
 */
const COLUMN_REM = {
    select: 2.5,
    assignee: 9.5,
    bank: 8.5,
    type: 7.5,
    status: 7.5,
    dates: 11.5,
    estimate: 6.5,
    logged: 6.5,
    timer: 3,
} as const;

const TITLE_MIN_REM = 14;

type RowProps = {
    task: TaskListItem;
    level: 0 | 1;
    /** Sin columna de estado cuando la lista se agrupa por estado (sería el del grupo). */
    showStatus: boolean;
    /** Estado del grupo (agrupando por estado). */
    groupStatusId?: number;
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
    showStatus,
    groupStatusId,
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
        level === 0
            ? totalLoggedMinutes(task)
            : task.logged_minutes === null
              ? null
              : (task.logged_minutes ?? 0);

    return (
        <tr
            className={cn(
                'border-b align-middle',
                ROW_CLICK_CLASS,
                selected && 'bg-accent',
                task.is_completed && 'text-muted-foreground',
            )}
            data-test="task-row"
            {...rowClickProps}
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
                        title={task.title}
                        className={cn(
                            'min-w-0 truncate rounded-md text-left hover:underline',
                            task.is_completed && 'line-through',
                            FOCUS_RING,
                        )}
                        data-test="task-title"
                        data-row-primary
                    >
                        {task.title}
                    </button>
                    {/* Sin columna de estado, una subtarea con otro estado que su grupo lo dice aquí. */}
                    {!showStatus &&
                    level === 1 &&
                    status &&
                    status.id !== groupStatusId ? (
                        <TaskStatusBadge
                            name={status.name}
                            color={status.color}
                            done={status.category === 'done'}
                        />
                    ) : null}
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
            <td className="px-2 py-2">
                {/* En una línea: «Sin responsable» cabe y los nombres largos se cortan. */}
                <div className="flex min-w-0 whitespace-nowrap">
                    <AssigneeLabel user={task.assignee} />
                </div>
            </td>
            {lookups.usesBanks ? (
                <td className="truncate px-2 py-2" title={bank?.name}>
                    {bank ? (
                        bank.name
                    ) : (
                        <span className="text-muted-foreground">—</span>
                    )}
                </td>
            ) : null}
            <td className="truncate px-2 py-2" title={type?.name}>
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
            {showStatus ? (
                <td className="px-2 py-2">
                    {status ? (
                        <TaskStatusBadge
                            name={status.name}
                            color={status.color}
                            done={status.category === 'done'}
                        />
                    ) : null}
                </td>
            ) : null}
            <td className="px-2 py-2">
                <TaskDates task={task} />
            </td>
            <td className="px-2 py-2 text-right">
                <TaskEstimate task={task} />
            </td>
            {/* Sin las horas de todos (colaborador externo, D-134), sin columna. */}
            {logged === null ? null : (
                <td
                    className="tabular px-2 py-2 text-right"
                    title={level === 0 ? loggedBreakdownLabel(task) : undefined}
                >
                    {logged > 0 ? (
                        formatMinutes(logged)
                    ) : (
                        <span className="text-muted-foreground">0:00</span>
                    )}
                    {level === 0 && loggedBreakdownLabel(task) ? (
                        <>
                            <span
                                aria-hidden="true"
                                className="text-muted-foreground"
                            >
                                {' '}
                                Σ
                            </span>
                            <span className="sr-only">
                                {loggedBreakdownLabel(task)}
                            </span>
                        </>
                    ) : null}
                </td>
            )}
            <td className="w-10 px-2 py-2">
                {!task.is_milestone ? <TimerButton task={task} /> : null}
            </td>
        </tr>
    );
}

function GroupTable({
    group,
    showStatus,
    showCompleted,
    selection,
    onSelect,
    onOpen,
    headingId,
}: {
    group: TaskGroup;
    showStatus: boolean;
    showCompleted: boolean;
    selection: Set<number>;
    onSelect: (taskIds: number[], selected: boolean) => void;
    onOpen: (taskId: number) => void;
    headingId: string;
}) {
    const lookups = useTaskLookups();
    const [collapsed, setCollapsed] = useState<Set<number>>(new Set());
    // Un colaborador externo no ve las horas de todos (D-134): le llegan a null y no hay columna.
    const showLogged = !group.tasks.some(
        (task) => task.logged_minutes === null,
    );
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
    const widths = [
        COLUMN_REM.select,
        COLUMN_REM.assignee,
        ...(lookups.usesBanks ? [COLUMN_REM.bank] : []),
        COLUMN_REM.type,
        ...(showStatus ? [COLUMN_REM.status] : []),
        COLUMN_REM.dates,
        COLUMN_REM.estimate,
        ...(showLogged ? [COLUMN_REM.logged] : []),
        COLUMN_REM.timer,
    ];
    const minWidth = widths.reduce((sum, rem) => sum + rem, TITLE_MIN_REM);
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
        <HorizontalScroll
            className={cn('rounded-md border', FOCUS_RING)}
            role="region"
            aria-labelledby={headingId}
            tabIndex={0}
        >
            <table
                className="w-full table-fixed text-sm"
                style={{ minWidth: `${minWidth}rem` }}
            >
                <caption className="sr-only">{group.label}</caption>
                <colgroup>
                    <col style={{ width: `${COLUMN_REM.select}rem` }} />
                    <col />
                    <col style={{ width: `${COLUMN_REM.assignee}rem` }} />
                    {lookups.usesBanks ? (
                        <col style={{ width: `${COLUMN_REM.bank}rem` }} />
                    ) : null}
                    <col style={{ width: `${COLUMN_REM.type}rem` }} />
                    {showStatus ? (
                        <col style={{ width: `${COLUMN_REM.status}rem` }} />
                    ) : null}
                    <col style={{ width: `${COLUMN_REM.dates}rem` }} />
                    <col style={{ width: `${COLUMN_REM.estimate}rem` }} />
                    {showLogged ? (
                        <col style={{ width: `${COLUMN_REM.logged}rem` }} />
                    ) : null}
                    <col style={{ width: `${COLUMN_REM.timer}rem` }} />
                </colgroup>
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
                        {showStatus ? (
                            <th scope="col" className="px-2 py-2 font-medium">
                                {t('task_list.column.status')}
                            </th>
                        ) : null}
                        <th scope="col" className="px-2 py-2 font-medium">
                            {t('task_list.column.dates')}
                        </th>
                        <th
                            scope="col"
                            className="px-2 py-2 text-right font-medium"
                        >
                            {t('task_list.column.estimate')}
                        </th>
                        {showLogged ? (
                            <th
                                scope="col"
                                className="px-2 py-2 text-right font-medium"
                            >
                                {t('task_list.column.logged')}
                            </th>
                        ) : null}
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
                                showStatus={showStatus}
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
                                          showStatus={showStatus}
                                          groupStatusId={
                                              group.defaults.status_id
                                          }
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
                </tbody>
            </table>
        </HorizontalScroll>
    );
}

/**
 * Vista Lista (SPEC §6): grupos (por estado, responsable, bolsa o tipo) con sus tareas raíz y
 * subtareas desplegables, selección para las acciones masivas y creación rápida en cada grupo.
 * Cada grupo se pliega desde su encabezado y se recuerda por persona y proyecto en este navegador
 * (`storageKey`, D-320); el estado «done» nace plegado.
 */
export function TaskList({
    tasks,
    groupBy,
    showCompleted,
    selection,
    onSelect,
    onOpen,
    storageKey = null,
}: {
    tasks: TaskListItem[];
    groupBy: TaskGroupBy;
    showCompleted: boolean;
    selection: Set<number>;
    onSelect: (taskIds: number[], selected: boolean) => void;
    onOpen: (taskId: number) => void;
    /** Clave del navegador para recordar los grupos plegados (null: solo en memoria). */
    storageKey?: string | null;
}) {
    const lookups = useTaskLookups();
    const groups = groupTasks(tasks, groupBy, lookups, showCompleted);
    const folds = useCollapsedGroups(storageKey);
    const foldable = groupBy !== 'none';
    const isCollapsed = (group: TaskGroup) =>
        foldable && folds.isCollapsed(group.key, group.collapsedByDefault);
    const keys = groups.map((group) => group.key);

    return (
        <div className="flex flex-col gap-4">
            {foldable && groups.length > 1 ? (
                <GroupFoldControls
                    className="-mb-2 self-end"
                    onExpandAll={() => folds.setMany(keys, false)}
                    onCollapseAll={() => folds.setMany(keys, true)}
                    allExpanded={groups.every((group) => !isCollapsed(group))}
                    allCollapsed={groups.every(isCollapsed)}
                />
            ) : null}
            {groups.map((group) => {
                const headingId = `task-group-${group.key}`;
                const contentId = `task-group-content-${group.key}`;
                const count = group.tasks.length;
                const collapsed = isCollapsed(group);

                return (
                    <section
                        key={group.key}
                        aria-labelledby={headingId}
                        className="flex flex-col gap-3"
                        data-test="task-group"
                        data-collapsed={collapsed ? 'true' : undefined}
                    >
                        {foldable ? (
                            <CollapsibleGroupHeading
                                id={headingId}
                                contentId={contentId}
                                expanded={!collapsed}
                                onToggle={() =>
                                    folds.setCollapsed(group.key, !collapsed)
                                }
                                label={group.label}
                                count={t('task_list.group_count', { count })}
                                marker={
                                    group.color ? (
                                        <span
                                            aria-hidden="true"
                                            className="size-2.5 shrink-0 rounded-full"
                                            style={{
                                                backgroundColor: group.color,
                                            }}
                                        />
                                    ) : undefined
                                }
                                data-test="task-group-toggle"
                            />
                        ) : (
                            <h2 id={headingId} className="sr-only">
                                {group.label}
                            </h2>
                        )}
                        <div
                            id={contentId}
                            hidden={collapsed}
                            className="flex flex-col gap-3"
                        >
                            {collapsed ? null : (
                                <>
                                    {count === 0 ? (
                                        // Sin tareas, sin tabla vacía: una línea (D-328).
                                        <p
                                            className="text-sm text-muted-foreground"
                                            data-test="task-group-empty"
                                        >
                                            {t('task_list.group_empty')}
                                        </p>
                                    ) : (
                                        <GroupTable
                                            group={group}
                                            showStatus={groupBy !== 'status'}
                                            showCompleted={showCompleted}
                                            selection={selection}
                                            onSelect={onSelect}
                                            onOpen={onOpen}
                                            headingId={headingId}
                                        />
                                    )}
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
                                </>
                            )}
                        </div>
                    </section>
                );
            })}
        </div>
    );
}
