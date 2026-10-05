import {
    closestCorners,
    DndContext,
    DragOverlay,
    KeyboardSensor,
    PointerSensor,
    useDroppable,
    useSensor,
    useSensors,
} from '@dnd-kit/core';
import type {
    Announcements,
    DragEndEvent,
    DragOverEvent,
    DragStartEvent,
    UniqueIdentifier,
} from '@dnd-kit/core';
import {
    SortableContext,
    sortableKeyboardCoordinates,
    useSortable,
    verticalListSortingStrategy,
} from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { router } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowUp,
    CircleCheck,
    EllipsisVertical,
    GripVertical,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { toast } from 'sonner';
import { PriorityBadge } from '@/components/domain/badges';
import {
    buildColumns,
    findColumn,
    moveTask,
    neighbours,
    sameColumns,
} from '@/components/tasks/kanban-state';
import type { KanbanColumns } from '@/components/tasks/kanban-state';
import { QuickAddTask } from '@/components/tasks/quick-add-task';
import { AssigneeLabel } from '@/components/tasks/task-fields';
import { useTaskLookups } from '@/components/tasks/task-lookups';
import {
    TaskDates,
    TaskIndicators,
    loggedBreakdownLabel,
    totalLoggedMinutes,
} from '@/components/tasks/task-meta';
import { TASK_RELOAD, toastErrors } from '@/components/tasks/task-requests';
import { TimerButton } from '@/components/time/timer-button';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatMinutes } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { position as positionRoute } from '@/routes/tasks';
import type { TaskListItem, TaskStatus } from '@/types';

const COLUMN = 'column-';
const CARD = 'task-';

function columnKey(statusId: number): string {
    return `${COLUMN}${statusId}`;
}

function cardKey(taskId: number): string {
    return `${CARD}${taskId}`;
}

function parseKey(id: UniqueIdentifier): {
    type: 'column' | 'card';
    id: number;
} {
    const value = String(id);

    return value.startsWith(COLUMN)
        ? { type: 'column', id: Number(value.slice(COLUMN.length)) }
        : { type: 'card', id: Number(value.slice(CARD.length)) };
}

type MoveHandler = (taskId: number, statusId: number, index: number) => void;

function CardBody({
    task,
    onOpen,
    dragHandle,
    menu,
}: {
    task: TaskListItem;
    onOpen?: (taskId: number) => void;
    dragHandle?: ReactNode;
    menu?: ReactNode;
}) {
    const lookups = useTaskLookups();
    const bank =
        task.hour_bank_id !== null
            ? lookups.bankById.get(task.hour_bank_id)
            : undefined;
    const logged = totalLoggedMinutes(task);

    return (
        <div className="flex flex-col gap-2 p-3">
            <div className="flex items-start gap-1">
                {dragHandle}
                <button
                    type="button"
                    onClick={() => onOpen?.(task.id)}
                    className={cn(
                        'min-w-0 flex-1 rounded-md text-left text-sm break-words hover:underline',
                        task.is_completed &&
                            'text-muted-foreground line-through',
                        FOCUS_RING,
                    )}
                    data-test="kanban-card-title"
                >
                    {task.title}
                </button>
                {menu}
            </div>
            <div className="flex flex-wrap items-center gap-2 text-xs">
                {task.priority === 'high' || task.priority === 'urgent' ? (
                    <PriorityBadge priority={task.priority} />
                ) : null}
                <TaskIndicators task={task} />
            </div>
            {lookups.usesBanks && bank ? (
                <p className="truncate text-xs text-muted-foreground">
                    {bank.name}
                </p>
            ) : null}
            <div className="flex items-center justify-between gap-2 text-xs">
                <AssigneeLabel user={task.assignee} compact />
                <TaskDates task={task} className="text-xs" />
            </div>
            <div className="flex items-center justify-between gap-2 text-xs text-muted-foreground">
                <span className="tabular" title={loggedBreakdownLabel(task)}>
                    {/* Sin las horas de todos (colaborador externo, D-134), solo la estimación. */}
                    {logged === null
                        ? task.effective_estimated_minutes !== null
                            ? t('task_board.estimate', {
                                  estimate: formatMinutes(
                                      task.effective_estimated_minutes,
                                  ),
                              })
                            : null
                        : task.effective_estimated_minutes !== null
                          ? t('task_board.logged_of_estimate', {
                                logged: formatMinutes(logged),
                                estimate: formatMinutes(
                                    task.effective_estimated_minutes,
                                ),
                            })
                          : t('task_board.logged', {
                                logged: formatMinutes(logged),
                            })}
                    {loggedBreakdownLabel(task) ? (
                        <span className="sr-only">
                            {` (${loggedBreakdownLabel(task)})`}
                        </span>
                    ) : null}
                </span>
                {!task.is_milestone ? <TimerButton task={task} /> : null}
            </div>
        </div>
    );
}

function SortableCard({
    task,
    statusId,
    index,
    count,
    statuses,
    canMove,
    onOpen,
    onMove,
}: {
    task: TaskListItem;
    statusId: number;
    index: number;
    count: number;
    statuses: TaskStatus[];
    canMove: boolean;
    onOpen: (taskId: number) => void;
    onMove: MoveHandler;
}) {
    const {
        attributes,
        listeners,
        setNodeRef,
        setActivatorNodeRef,
        transform,
        transition,
        isDragging,
    } = useSortable({
        id: cardKey(task.id),
        disabled: !canMove,
        attributes: { roleDescription: t('task_board.role_description') },
    });

    return (
        <li
            ref={setNodeRef}
            style={{
                transform: CSS.Translate.toString(transform),
                transition,
            }}
            className={cn(
                'rounded-md border bg-card',
                isDragging && 'opacity-40',
            )}
            data-test="kanban-card"
            data-task-id={task.id}
            data-kanban-status={statusId}
            data-kanban-index={index}
        >
            <CardBody
                task={task}
                onOpen={onOpen}
                dragHandle={
                    canMove ? (
                        <button
                            type="button"
                            ref={setActivatorNodeRef}
                            {...attributes}
                            {...listeners}
                            aria-label={t('task_board.drag_handle', {
                                task: task.title,
                            })}
                            className={cn(
                                '-ml-1 cursor-grab touch-none rounded-md p-0.5 text-muted-foreground hover:text-foreground',
                                FOCUS_RING,
                            )}
                            data-test="kanban-handle"
                        >
                            <GripVertical
                                aria-hidden="true"
                                className="size-4"
                            />
                        </button>
                    ) : null
                }
                menu={
                    canMove ? (
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    className="size-6"
                                    aria-label={t('task_board.move_menu', {
                                        task: task.title,
                                    })}
                                >
                                    <EllipsisVertical aria-hidden="true" />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                <DropdownMenuLabel>
                                    {t('task_board.move_to')}
                                </DropdownMenuLabel>
                                {statuses
                                    .filter((status) => status.id !== statusId)
                                    .map((status) => (
                                        <DropdownMenuItem
                                            key={status.id}
                                            onSelect={() =>
                                                onMove(
                                                    task.id,
                                                    status.id,
                                                    Number.MAX_SAFE_INTEGER,
                                                )
                                            }
                                        >
                                            {status.category === 'done' ? (
                                                <CircleCheck aria-hidden="true" />
                                            ) : (
                                                <span
                                                    aria-hidden="true"
                                                    className="size-2 rounded-full"
                                                    style={{
                                                        backgroundColor:
                                                            status.color,
                                                    }}
                                                />
                                            )}
                                            {status.name}
                                        </DropdownMenuItem>
                                    ))}
                                <DropdownMenuSeparator />
                                <DropdownMenuItem
                                    disabled={index === 0}
                                    onSelect={() =>
                                        onMove(task.id, statusId, index - 1)
                                    }
                                >
                                    <ArrowUp aria-hidden="true" />
                                    {t('task_board.move_up')}
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                    disabled={index >= count - 1}
                                    onSelect={() =>
                                        onMove(task.id, statusId, index + 1)
                                    }
                                >
                                    <ArrowDown aria-hidden="true" />
                                    {t('task_board.move_down')}
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    ) : null
                }
            />
        </li>
    );
}

function Column({
    status,
    taskIds,
    tasksById,
    statuses,
    canMove,
    onOpen,
    onMove,
    hiddenCompleted,
    onShowCompleted,
}: {
    status: TaskStatus;
    taskIds: number[];
    tasksById: Map<number, TaskListItem>;
    statuses: TaskStatus[];
    canMove: boolean;
    onOpen: (taskId: number) => void;
    onMove: MoveHandler;
    hiddenCompleted: number;
    onShowCompleted: () => void;
}) {
    const { setNodeRef, isOver } = useDroppable({ id: columnKey(status.id) });
    const headingId = `kanban-column-${status.id}`;

    return (
        <section
            aria-labelledby={headingId}
            className="flex w-72 shrink-0 flex-col gap-3 rounded-md border bg-muted p-3"
            data-test="kanban-column"
            data-kanban-column={status.id}
        >
            <h2
                id={headingId}
                className="flex items-center gap-2 text-sm font-medium"
            >
                {status.category === 'done' ? (
                    <CircleCheck
                        aria-hidden="true"
                        className="size-4 text-success"
                    />
                ) : (
                    <span
                        aria-hidden="true"
                        className="size-2.5 rounded-full"
                        style={{ backgroundColor: status.color }}
                    />
                )}
                {status.name}
                <span className="font-normal text-muted-foreground">
                    {t('task_list.group_count', { count: taskIds.length })}
                </span>
            </h2>
            <SortableContext
                id={columnKey(status.id)}
                items={taskIds.map(cardKey)}
                strategy={verticalListSortingStrategy}
            >
                <ul
                    ref={setNodeRef}
                    className={cn(
                        'flex min-h-16 flex-col gap-2 rounded-md',
                        isOver && 'bg-accent',
                    )}
                    aria-label={t('task_board.column_tasks', {
                        status: status.name,
                    })}
                    data-kanban-list={status.id}
                >
                    {taskIds.map((taskId, index) => {
                        const task = tasksById.get(taskId);

                        return task ? (
                            <SortableCard
                                key={taskId}
                                task={task}
                                statusId={status.id}
                                index={index}
                                count={taskIds.length}
                                statuses={statuses}
                                canMove={canMove}
                                onOpen={onOpen}
                                onMove={onMove}
                            />
                        ) : null;
                    })}
                </ul>
            </SortableContext>
            {hiddenCompleted > 0 ? (
                <p className="text-xs text-muted-foreground">
                    {t('task_board.hidden_completed', {
                        count: hiddenCompleted,
                    })}{' '}
                    <button
                        type="button"
                        onClick={onShowCompleted}
                        className={cn(
                            'rounded-md text-primary-text underline',
                            FOCUS_RING,
                        )}
                    >
                        {t('task_board.show_completed')}
                    </button>
                </p>
            ) : null}
            <QuickAddTask
                compact
                defaults={{ status_id: status.id }}
                label={t('quick_add.label_in', { group: status.name })}
            />
        </section>
    );
}

/**
 * Vista Kanban (SPEC §6): una columna por estado, en su orden. Arrastrar y soltar con ratón,
 * táctil o teclado (asa de cada tarjeta: espacio para coger, flechas para mover, espacio para
 * soltar y Escape para cancelar), con anuncios en español para lectores de pantalla y, además,
 * un menú «Mover a…» por tarjeta. Al soltar se cambia el estado y la posición en el servidor; la
 * tarjeta se mueve al instante y vuelve a su sitio si el servidor lo rechaza.
 */
export function TaskKanban({
    tasks,
    statuses,
    onOpen,
    hiddenCompletedCount,
    onShowCompleted,
}: {
    tasks: TaskListItem[];
    statuses: TaskStatus[];
    onOpen: (taskId: number) => void;
    hiddenCompletedCount: number;
    onShowCompleted: () => void;
}) {
    const lookups = useTaskLookups();
    const [sourceTasks, setSourceTasks] = useState(tasks);
    const [sourceStatuses, setSourceStatuses] = useState(statuses);
    const [columns, setColumns] = useState<KanbanColumns>(() =>
        buildColumns(tasks, statuses),
    );
    const [activeId, setActiveId] = useState<number | null>(null);
    const snapshot = useRef<KanbanColumns | null>(null);
    // Columnas más recientes para los manejadores del arrastre (que pueden llegar antes de pintar).
    const latest = useRef(columns);

    useEffect(() => {
        latest.current = columns;
    }, [columns]);

    const commit = (next: KanbanColumns) => {
        latest.current = next;
        setColumns(next);
    };

    // Nuevas tareas del servidor (tras guardar o filtrar): se vuelve a montar el tablero, salvo en
    // mitad de un arrastre (patrón de estado derivado de React, sin efectos).
    if (
        (tasks !== sourceTasks || statuses !== sourceStatuses) &&
        activeId === null
    ) {
        setSourceTasks(tasks);
        setSourceStatuses(statuses);
        setColumns(buildColumns(tasks, statuses));
    }

    const tasksById = new Map(tasks.map((task) => [task.id, task]));
    const statusById = new Map(statuses.map((status) => [status.id, status]));
    const canMove = lookups.can.update;
    const firstDone = statuses.find((status) => status.category === 'done');

    const sensors = useSensors(
        useSensor(PointerSensor, { activationConstraint: { distance: 6 } }),
        useSensor(KeyboardSensor, {
            coordinateGetter: sortableKeyboardCoordinates,
        }),
    );

    const titleOf = (id: UniqueIdentifier) => {
        const key = parseKey(id);

        return tasksById.get(key.id)?.title ?? '';
    };

    const describe = (
        id: UniqueIdentifier | undefined,
        taskId: number,
    ): string | null => {
        if (id === undefined) {
            return null;
        }

        const key = parseKey(id);
        const statusId =
            key.type === 'column' ? key.id : findColumn(latest.current, key.id);

        if (statusId === null) {
            return null;
        }

        const ids = latest.current[statusId] ?? [];
        const index = ids.indexOf(taskId);

        return t('task_board.position', {
            status: statusById.get(statusId)?.name ?? '',
            position: index === -1 ? ids.length + 1 : index + 1,
            total: index === -1 ? ids.length + 1 : ids.length,
        });
    };

    const announcements: Announcements = {
        onDragStart: ({ active }) =>
            t('task_board.announce.start', {
                task: titleOf(active.id),
                position: describe(active.id, parseKey(active.id).id) ?? '',
            }),
        onDragOver: ({ active, over }) => {
            const where = describe(over?.id, parseKey(active.id).id);

            return where
                ? t('task_board.announce.over', {
                      task: titleOf(active.id),
                      position: where,
                  })
                : t('task_board.announce.outside', {
                      task: titleOf(active.id),
                  });
        },
        onDragEnd: ({ active, over }) => {
            const where = describe(over?.id, parseKey(active.id).id);

            return where
                ? t('task_board.announce.end', {
                      task: titleOf(active.id),
                      position: where,
                  })
                : t('task_board.announce.cancel', { task: titleOf(active.id) });
        },
        onDragCancel: ({ active }) =>
            t('task_board.announce.cancel', { task: titleOf(active.id) }),
    };

    /** Envía el cambio; si el servidor lo rechaza, el tablero vuelve a como estaba. */
    const persist = (
        taskId: number,
        next: KanbanColumns,
        previous: KanbanColumns,
    ) => {
        const statusId = findColumn(next, taskId);

        if (statusId === null || sameColumns(next, previous)) {
            return;
        }

        commit(next);

        const revert = () => commit(previous);

        router.patch(
            positionRoute.url(taskId),
            { status_id: statusId, ...neighbours(next, statusId, taskId) },
            {
                preserveScroll: true,
                preserveState: true,
                only: TASK_RELOAD,
                onError: (errors) => {
                    revert();
                    toastErrors(errors);
                },
                onHttpException: () => {
                    revert();
                    toast.error(t('task_errors.move_failed'));

                    return false;
                },
                onNetworkError: () => {
                    revert();
                    toast.error(t('task_errors.network'));

                    return false;
                },
            },
        );
    };

    /** Mover con el menú de la tarjeta (alternativa al arrastre). */
    const moveWithMenu: MoveHandler = (taskId, statusId, index) => {
        persist(
            taskId,
            moveTask(latest.current, taskId, statusId, index),
            latest.current,
        );
    };

    const onDragStart = ({ active }: DragStartEvent) => {
        snapshot.current = latest.current;
        setActiveId(parseKey(active.id).id);
    };

    const onDragOver = ({ active, over }: DragOverEvent) => {
        if (!over) {
            return;
        }

        const taskId = parseKey(active.id).id;
        const target = parseKey(over.id);
        const from = findColumn(latest.current, taskId);
        const to =
            target.type === 'column'
                ? target.id
                : findColumn(latest.current, target.id);

        if (from === null || to === null || from === to) {
            return;
        }

        const ids = latest.current[to] ?? [];
        const index =
            target.type === 'column' ? ids.length : ids.indexOf(target.id);

        commit(
            moveTask(
                latest.current,
                taskId,
                to,
                index === -1 ? ids.length : index,
            ),
        );
    };

    const onDragEnd = ({ active, over }: DragEndEvent) => {
        const previous = snapshot.current ?? latest.current;
        snapshot.current = null;
        setActiveId(null);

        if (!over) {
            commit(previous);

            return;
        }

        const taskId = parseKey(active.id).id;
        const target = parseKey(over.id);
        const to =
            target.type === 'column'
                ? target.id
                : findColumn(latest.current, target.id);

        if (to === null) {
            commit(previous);

            return;
        }

        const ids = latest.current[to] ?? [];
        const index =
            target.type === 'column' || target.id === taskId
                ? ids.indexOf(taskId) === -1
                    ? ids.length
                    : ids.indexOf(taskId)
                : ids.indexOf(target.id);

        persist(taskId, moveTask(latest.current, taskId, to, index), previous);
    };

    const onDragCancel = () => {
        commit(snapshot.current ?? latest.current);
        snapshot.current = null;
        setActiveId(null);
    };

    const active = activeId !== null ? tasksById.get(activeId) : undefined;

    return (
        <DndContext
            sensors={sensors}
            collisionDetection={closestCorners}
            onDragStart={onDragStart}
            onDragOver={onDragOver}
            onDragEnd={onDragEnd}
            onDragCancel={onDragCancel}
            accessibility={{
                announcements,
                screenReaderInstructions: {
                    draggable: t('task_board.instructions'),
                },
            }}
        >
            <div
                className={cn(
                    '-mx-4 overflow-x-auto px-4 pb-2 md:mx-0 md:px-0',
                    FOCUS_RING,
                )}
                role="region"
                aria-label={t('task_board.label')}
                tabIndex={0}
                data-test="kanban"
            >
                <div className="flex min-w-max items-start gap-4">
                    {statuses.map((status) => (
                        <Column
                            key={status.id}
                            status={status}
                            taskIds={columns[status.id] ?? []}
                            tasksById={tasksById}
                            statuses={statuses}
                            canMove={canMove}
                            onOpen={onOpen}
                            onMove={moveWithMenu}
                            hiddenCompleted={
                                status.id === firstDone?.id
                                    ? hiddenCompletedCount
                                    : 0
                            }
                            onShowCompleted={onShowCompleted}
                        />
                    ))}
                </div>
            </div>
            <DragOverlay>
                {active ? (
                    <div
                        className="w-72 rounded-md border bg-card shadow-md"
                        data-kanban-overlay={active.id}
                    >
                        <CardBody task={active} />
                    </div>
                ) : null}
            </DragOverlay>
        </DndContext>
    );
}
