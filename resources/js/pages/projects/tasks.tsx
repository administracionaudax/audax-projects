import { Head, router, usePage } from '@inertiajs/react';
import { ListTodo } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@/components/empty-state';
import { periodQuery } from '@/components/planning/calendar-dates';
import { TaskCalendar } from '@/components/planning/task-calendar';
import { ProjectShell } from '@/components/projects/project-shell';
import { TaskBulkBar } from '@/components/tasks/task-bulk-bar';
import { TaskToolbar } from '@/components/tasks/task-filters';
import { TaskKanban } from '@/components/tasks/task-kanban';
import { TaskList } from '@/components/tasks/task-list';
import {
    buildTaskLookups,
    TaskLookupsProvider,
} from '@/components/tasks/task-lookups';
import { TaskPanel } from '@/components/tasks/task-panel';
import {
    filtersToQuery,
    withTaskParam,
} from '@/components/tasks/task-requests';
import { t } from '@/lib/i18n';
import { urls } from '@/lib/urls';
import { tasks as projectTasks } from '@/routes/projects';
import type { ProjectTasksPageProps, TaskFilters, TaskView } from '@/types';
import type { CalendarMode } from '@/types/planning';

/**
 * Pestaña Tareas del proyecto (SPEC §6): lista agrupable, kanban o calendario (D-061), filtros en
 * la URL, creación rápida, acciones masivas y panel lateral de edición (?tarea={id}, sin página
 * nueva).
 */
export default function ProjectTasks(props: ProjectTasksPageProps) {
    const { project, view, filters, tasks, statuses, panel } = props;
    const page = usePage();
    const lookups = buildTaskLookups(props);
    const [selection, setSelection] = useState<Set<number>>(new Set());
    const [loadingTaskId, setLoadingTaskId] = useState<number | null>(null);
    const [closedTaskId, setClosedTaskId] = useState<number | null>(null);
    const [navigating, setNavigating] = useState(false);
    // Un movimiento del calendario en curso bloquea el cambio de vista (con su motivo).
    const [calendarBusy, setCalendarBusy] = useState(false);
    const calendar = props.calendar ?? null;

    // Cuando el servidor confirma el cierre (panel vacío), se olvida: así «Atrás» en el navegador
    // vuelve a abrir la tarea (patrón de estado derivado de React, sin efectos).
    if (panel === null && closedTaskId !== null) {
        setClosedTaskId(null);
    }

    // La selección solo conserva tareas que siguen en la lista.
    const visibleIds = new Set(
        tasks.flatMap((task) => [
            task.id,
            ...(task.subtasks ?? []).map((subtask) => subtask.id),
        ]),
    );
    const selected = new Set([...selection].filter((id) => visibleIds.has(id)));

    const openTask = (taskId: number) => {
        setClosedTaskId(null);
        setLoadingTaskId(taskId);
        router.visit(withTaskParam(page.url, taskId), {
            only: ['panel'],
            preserveState: true,
            preserveScroll: true,
            onFinish: () => setLoadingTaskId(null),
        });
    };

    const closeTask = () => {
        // Se cierra al momento; la recarga parcial quita ?tarea= de la URL por detrás.
        setClosedTaskId(panel?.task.id ?? null);
        setLoadingTaskId(null);
        router.visit(withTaskParam(page.url, null), {
            only: ['panel'],
            preserveState: true,
            preserveScroll: true,
        });
    };

    const changeFilters = (nextView: TaskView, nextFilters: TaskFilters) => {
        setSelection(new Set());
        router.get(
            projectTasks.url(project.id, {
                query: {
                    ...filtersToQuery(nextView, nextFilters),
                    // En el calendario, los filtros no cambian el mes o la semana que se ve.
                    ...(nextView === 'calendar' && calendar
                        ? periodQuery(calendar.mode, calendar.period)
                        : {}),
                },
            }),
            {},
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: [
                    'tasks',
                    'filters',
                    'view',
                    'hiddenCompletedCount',
                    'panel',
                    'calendar',
                ],
            },
        );
    };

    /** Mes o semana del calendario (?mes= o ?semana=), con los filtros de la pestaña. */
    const navigateCalendar = (mode: CalendarMode, period: string) => {
        router.get(
            projectTasks.url(project.id, {
                query: {
                    ...filtersToQuery('calendar', filters),
                    ...periodQuery(mode, period),
                },
            }),
            {},
            {
                preserveState: true,
                preserveScroll: true,
                only: ['calendar', 'panel'],
                onStart: () => setNavigating(true),
                onFinish: () => setNavigating(false),
            },
        );
    };

    const select = (taskIds: number[], checked: boolean) =>
        setSelection(() => {
            const next = new Set(selected);

            for (const id of taskIds) {
                if (checked) {
                    next.add(id);
                } else {
                    next.delete(id);
                }
            }

            return next;
        });

    const filtered =
        filters.assignee !== null ||
        filters.bank !== null ||
        filters.type !== null ||
        filters.priority !== null ||
        filters.status !== null ||
        filters.mine;
    const kanbanStatuses =
        filters.status !== null
            ? statuses.filter((status) => status.id === filters.status)
            : statuses;

    return (
        <TaskLookupsProvider value={lookups}>
            <Head title={t('task_page.title', { project: project.name })} />

            <ProjectShell
                project={project}
                tab="tareas"
                canManage={props.canManage}
            >
                <div className="flex min-w-0 flex-col gap-6">
                    <TaskToolbar
                        view={view}
                        filters={filters}
                        onChange={changeFilters}
                        viewLocked={view === 'calendar' && calendarBusy}
                    />

                    {view !== 'calendar' && tasks.length === 0 ? (
                        <EmptyState
                            icon={ListTodo}
                            title={
                                filtered
                                    ? t('task_page.empty_filtered')
                                    : t('task_page.empty')
                            }
                            description={
                                filtered
                                    ? t('task_page.empty_filtered_description')
                                    : props.can.create
                                      ? t('task_page.empty_description')
                                      : t(
                                            'task_page.empty_description_readonly',
                                        )
                            }
                        />
                    ) : null}

                    {view === 'calendar' ? (
                        calendar ? (
                            <TaskCalendar
                                calendar={calendar}
                                loading={navigating}
                                onOpen={openTask}
                                onNavigate={navigateCalendar}
                                onBusyChange={setCalendarBusy}
                            />
                        ) : null
                    ) : view === 'kanban' ? (
                        <TaskKanban
                            tasks={tasks}
                            statuses={kanbanStatuses}
                            onOpen={openTask}
                            hiddenCompletedCount={props.hiddenCompletedCount}
                            onShowCompleted={() =>
                                changeFilters(view, {
                                    ...filters,
                                    completed: true,
                                })
                            }
                        />
                    ) : (
                        <TaskList
                            tasks={tasks}
                            groupBy={filters.group}
                            showCompleted={filters.completed}
                            selection={selected}
                            onSelect={select}
                            onOpen={openTask}
                        />
                    )}

                    {view === 'list' &&
                    !filters.completed &&
                    props.hiddenCompletedCount > 0 ? (
                        <p className="text-sm text-muted-foreground">
                            {t('task_page.hidden_completed', {
                                count: props.hiddenCompletedCount,
                            })}{' '}
                            <button
                                type="button"
                                onClick={() =>
                                    changeFilters(view, {
                                        ...filters,
                                        completed: true,
                                    })
                                }
                                className="rounded-[3px] text-primary-text underline"
                            >
                                {t('task_board.show_completed')}
                            </button>
                        </p>
                    ) : null}

                    {view === 'list' && selected.size > 0 ? (
                        <TaskBulkBar
                            selection={selected}
                            onClear={() => setSelection(new Set())}
                        />
                    ) : null}
                </div>
            </ProjectShell>

            <TaskPanel
                panel={panel}
                loading={loadingTaskId !== null}
                closing={panel !== null && panel.task.id === closedTaskId}
                onOpen={openTask}
                onClose={closeTask}
            />
        </TaskLookupsProvider>
    );
}

ProjectTasks.layout = (props: ProjectTasksPageProps) => ({
    breadcrumbs: [
        { title: t('nav.projects'), href: urls.projects() },
        { title: props.project.name, href: urls.project(props.project.id) },
        {
            title: t('project_tabs.tasks'),
            href: urls.project(props.project.id, 'tareas'),
        },
    ],
});
