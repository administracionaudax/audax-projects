import { Head, Link, router } from '@inertiajs/react';
import { CalendarCheck2, History, ListFilter, UserX } from 'lucide-react';
import { useState } from 'react';
import { PriorityBadge, TaskStatusBadge } from '@/components/domain/badges';
import { AddToMyDayButton } from '@/components/day-plan/add-to-my-day';
import { EmptyState } from '@/components/empty-state';
import Heading from '@/components/heading';
import {
    MyTaskFilterBar,
    MyTaskSortSelect,
} from '@/components/my-tasks/my-task-filter-bar';
import {
    hasMyTaskFilters,
    MY_TASK_PARAMS,
    myTaskQuery,
} from '@/components/my-tasks/my-task-query';
import {
    TaskDates,
    loggedBreakdownLabel,
    totalLoggedMinutes,
} from '@/components/tasks/task-meta';
import { TimerButton } from '@/components/time/timer-button';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useAbilities, useRequiredUser } from '@/hooks/use-auth';
import { usePersistedQuery } from '@/hooks/use-persisted-query';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatDate, formatMinutes } from '@/lib/format';
import { t } from '@/lib/i18n';
import { urls } from '@/lib/urls';
import { cn } from '@/lib/utils';
import { index as myTasksIndex } from '@/routes/my-tasks';
import type {
    MyTaskFilters,
    MyTaskItem,
    MyTaskSectionKey,
    MyTasksPageProps,
    TaskStatus,
} from '@/types';

/** Props que trae «Cargar más» (la página siguiente). */
const PAGE_PROPS = ['tasks', 'cursor', 'next_cursor'];

function MyTaskRow({
    task,
    status,
    today,
    showLastLogged,
}: {
    task: MyTaskItem;
    status: TaskStatus | undefined;
    today: string;
    showLastLogged: boolean;
}) {
    // Registrado total: lo propio y lo de sus subtareas (D-170).
    const logged = totalLoggedMinutes(task);
    const can = useAbilities();

    return (
        <li
            className="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:gap-4"
            data-test="my-task"
            data-task-id={task.id}
        >
            <div className="min-w-0 flex-1">
                <Link
                    href={urls.task(task.project.id, task.id)}
                    className={cn(
                        'block truncate text-sm font-medium hover:underline',
                        FOCUS_RING,
                    )}
                >
                    {task.title}
                </Link>
                <p className="flex min-w-0 flex-wrap items-center gap-x-2 text-xs text-muted-foreground">
                    <span className="inline-flex min-w-0 items-center gap-1">
                        <span
                            aria-hidden="true"
                            className="size-2 shrink-0 rounded-full"
                            style={{ backgroundColor: task.project.color }}
                        />
                        <span className="truncate">
                            {task.project.code} · {task.project.name}
                        </span>
                    </span>
                    {task.parent ? (
                        <span className="truncate">
                            {t('my_tasks.in_parent', {
                                task: task.parent.title,
                            })}
                        </span>
                    ) : null}
                    {task.hour_bank ? (
                        <span className="truncate">{task.hour_bank.name}</span>
                    ) : null}
                    {showLastLogged && task.my_last_logged_on ? (
                        <span className="inline-flex items-center gap-1">
                            <History aria-hidden="true" className="size-3" />
                            {t('my_tasks.last_logged', {
                                date: formatDate(task.my_last_logged_on),
                            })}
                        </span>
                    ) : null}
                </p>
            </div>
            <div className="flex flex-wrap items-center gap-2 sm:justify-end">
                {!task.assigned_to_me ? (
                    <span
                        className="inline-flex items-center gap-1 border bg-muted px-1.5 py-0.5 text-xs"
                        title={
                            task.assignee
                                ? t('my_tasks.not_assigned_to', {
                                      name: task.assignee.name,
                                  })
                                : t('my_tasks.unassigned_hint')
                        }
                        data-test="my-task-not-assigned"
                    >
                        <UserX aria-hidden="true" className="size-3.5" />
                        {t('my_tasks.not_assigned')}
                        <span className="sr-only">
                            {task.assignee
                                ? `: ${task.assignee.name}`
                                : `: ${t('task_fields.no_assignee')}`}
                        </span>
                    </span>
                ) : null}
                {status ? (
                    <TaskStatusBadge
                        name={status.name}
                        color={status.color}
                        done={status.category === 'done'}
                    />
                ) : null}
                {task.priority !== 'normal' ? (
                    <PriorityBadge priority={task.priority} />
                ) : null}
                <TaskDates task={task} today={today} className="text-xs" />
                {logged ? (
                    <span
                        className="tabular text-xs text-muted-foreground"
                        title={loggedBreakdownLabel(task)}
                    >
                        {t('my_tasks.logged', {
                            minutes: formatMinutes(logged),
                        })}
                        {loggedBreakdownLabel(task) ? (
                            <span className="sr-only">
                                {` (${loggedBreakdownLabel(task)})`}
                            </span>
                        ) : null}
                    </span>
                ) : null}
                {/* Plan del día (D-254): «Añadir a mi día» (o a mañana). */}
                {can.useDayPlan === true &&
                !task.is_milestone &&
                !task.is_completed ? (
                    <AddToMyDayButton task={task} />
                ) : null}
                {!task.is_milestone && !task.is_completed ? (
                    <TimerButton task={task} />
                ) : null}
            </div>
        </li>
    );
}

/** Tareas seguidas de la misma sección (orden por vencimiento), para pintarlas con su título. */
function bySection(
    tasks: MyTaskItem[],
): { key: MyTaskSectionKey; tasks: MyTaskItem[] }[] {
    const groups: { key: MyTaskSectionKey; tasks: MyTaskItem[] }[] = [];

    for (const task of tasks) {
        const last = groups.at(-1);

        if (last && last.key === task.section) {
            last.tasks.push(task);
        } else {
            groups.push({ key: task.section, tasks: [task] });
        }
    }

    return groups;
}

/**
 * Las tareas cargadas: la primera página y las que ha ido trayendo «Cargar más». Una recarga sin
 * cursor (filtros, orden o cualquier otra visita) vuelve a empezar; con cursor, se añade a lo que
 * ya había (sin repetir).
 */
function useLoadedTasks(props: MyTasksPageProps): MyTaskItem[] {
    const [state, setState] = useState({
        page: props.tasks,
        items: props.tasks,
    });

    if (state.page !== props.tasks) {
        if (props.cursor === null) {
            setState({ page: props.tasks, items: props.tasks });
        } else {
            const seen = new Set(state.items.map((task) => task.id));
            setState({
                page: props.tasks,
                items: [
                    ...state.items,
                    ...props.tasks.filter((task) => !seen.has(task.id)),
                ],
            });
        }
    }

    return state.page === props.tasks ? state.items : props.tasks;
}

/**
 * Mis tareas (SPEC §6, D-037 y D-143): mis tareas abiertas y las que tienen horas mías del último
 * mes, por defecto ordenadas por «Imputadas recientemente». Filtros y orden en la URL y guardados
 * por persona en este navegador (se recuperan al volver sin filtros en la URL); de 50 en 50 con
 * «Cargar más». Por vencimiento, con las secciones Vencidas, Hoy, Esta semana, Próximas y Sin fecha.
 */
export default function MyTasks(props: MyTasksPageProps) {
    const { filters, options, statuses, today } = props;
    const user = useRequiredUser();
    const tasks = useLoadedTasks(props);
    const [loadingMore, setLoadingMore] = useState(false);
    const [navigating, setNavigating] = useState(false);
    const statusById = new Map(statuses.map((status) => [status.id, status]));
    const filtered = hasMyTaskFilters(filters);
    const query = myTaskQuery(filters);

    const visit = (
        nextQuery: Record<string, string>,
        onFinish?: () => void,
    ) => {
        router.get(
            myTasksIndex.url({ query: nextQuery }),
            {},
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                onStart: () => setNavigating(true),
                onFinish: () => {
                    setNavigating(false);
                    onFinish?.();
                },
            },
        );
    };

    usePersistedQuery(
        `audax.my-tasks.filters.${user.id}`,
        query,
        MY_TASK_PARAMS,
        (stored, done) => visit(stored, done),
    );

    const change = (next: MyTaskFilters) => visit(myTaskQuery(next));

    const loadMore = () => {
        if (props.next_cursor === null || loadingMore) {
            return;
        }

        router.get(
            myTasksIndex.url({
                query: { ...query, cursor: props.next_cursor },
            }),
            {},
            {
                only: PAGE_PROPS,
                preserveState: true,
                preserveScroll: true,
                preserveUrl: true,
                onStart: () => setLoadingMore(true),
                onFinish: () => setLoadingMore(false),
            },
        );
    };

    const groups =
        filters.sort === 'due'
            ? bySection(tasks)
            : [{ key: null, tasks } as const];

    return (
        <>
            <Head title={t('nav.my_tasks')} />

            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <Heading
                    as="h1"
                    title={t('my_tasks.heading')}
                    description={t('my_tasks.description')}
                />

                <div className="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                    <MyTaskFilterBar
                        filters={filters}
                        options={options}
                        statuses={statuses}
                        onChange={change}
                    />
                    <MyTaskSortSelect
                        value={filters.sort}
                        onChange={(sort) => change({ ...filters, sort })}
                    />
                </div>

                <p
                    role="status"
                    className="text-sm text-muted-foreground"
                    data-test="my-tasks-count"
                >
                    {props.next_cursor !== null
                        ? t('my_tasks.count_more', { count: tasks.length })
                        : tasks.length === 1
                          ? t('my_tasks.count_one')
                          : t('my_tasks.count', { count: tasks.length })}
                </p>

                {tasks.length === 0 ? (
                    <EmptyState
                        icon={filtered ? ListFilter : CalendarCheck2}
                        title={
                            filtered
                                ? t('my_tasks.empty_filtered')
                                : t('my_tasks.empty')
                        }
                        description={
                            filtered
                                ? t('my_tasks.empty_filtered_description')
                                : t('my_tasks.empty_description')
                        }
                    />
                ) : (
                    <div
                        className={cn(
                            'flex flex-col gap-6 transition-opacity',
                            navigating && 'opacity-60',
                        )}
                        aria-busy={navigating || undefined}
                    >
                        {groups.map((group, index) =>
                            group.key === null ? (
                                <ul
                                    key="all"
                                    className="divide-y border-y"
                                    aria-label={t('my_tasks.list_label')}
                                >
                                    {group.tasks.map((task) => (
                                        <MyTaskRow
                                            key={task.id}
                                            task={task}
                                            status={statusById.get(
                                                task.status_id,
                                            )}
                                            today={today}
                                            showLastLogged={
                                                filters.sort === 'logged'
                                            }
                                        />
                                    ))}
                                </ul>
                            ) : (
                                <section
                                    key={`${group.key}-${index}`}
                                    aria-labelledby={`my-tasks-${group.key}-${index}`}
                                    className="flex flex-col gap-2"
                                    data-test={`my-tasks-${group.key}`}
                                >
                                    <h2
                                        id={`my-tasks-${group.key}-${index}`}
                                        className="flex items-center gap-2 text-base font-medium"
                                    >
                                        {t(`my_tasks.section.${group.key}`)}
                                        <span className="text-sm font-normal text-muted-foreground">
                                            {t('task_list.group_count', {
                                                count: group.tasks.length,
                                            })}
                                        </span>
                                    </h2>
                                    <ul className="divide-y border-y">
                                        {group.tasks.map((task) => (
                                            <MyTaskRow
                                                key={task.id}
                                                task={task}
                                                status={statusById.get(
                                                    task.status_id,
                                                )}
                                                today={today}
                                                showLastLogged={false}
                                            />
                                        ))}
                                    </ul>
                                </section>
                            ),
                        )}

                        {props.next_cursor !== null ? (
                            <Button
                                type="button"
                                variant="outline"
                                className="w-fit self-center"
                                onClick={loadMore}
                                disabled={loadingMore}
                                data-test="my-tasks-load-more"
                            >
                                {loadingMore ? <Spinner /> : null}
                                {loadingMore
                                    ? t('my_tasks.loading_more')
                                    : t('my_tasks.load_more')}
                            </Button>
                        ) : null}
                    </div>
                )}
            </div>
        </>
    );
}

MyTasks.layout = {
    breadcrumbs: [{ title: t('nav.my_tasks'), href: myTasksIndex() }],
};
