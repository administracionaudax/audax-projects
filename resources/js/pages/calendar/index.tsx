import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';
import { CalendarFilterBar } from '@/components/calendar/calendar-filter-bar';
import {
    CALENDAR_PARAMS,
    calendarFilterQuery,
    calendarQuery,
} from '@/components/calendar/calendar-query';
import { TeamCalendar } from '@/components/calendar/team-calendar';
import { NewTaskDialog } from '@/components/gantt/new-task-dialog';
import Heading from '@/components/heading';
import {
    buildTaskLookups,
    TaskLookupsProvider,
} from '@/components/tasks/task-lookups';
import { TaskPanel } from '@/components/tasks/task-panel';
import { withTaskParam } from '@/components/tasks/task-requests';
import { usePersistedQuery } from '@/hooks/use-persisted-query';
import { t } from '@/lib/i18n';
import { index as calendarIndex } from '@/routes/calendar';
import type {
    TeamCalendarFilters,
    TeamCalendarPageProps,
    TeamCalendarView,
} from '@/types/calendar';

/** Lo que cambia al moverse por el calendario o cambiar los filtros (no las opciones). */
const CALENDAR_PROPS = ['calendar', 'filters', 'panel', 'panelLookups'];

/**
 * Calendario del equipo (/calendario, D-144): las tareas con fechas de todos los proyectos que se
 * ven, en mes, semana o día, y la vista «Personas». Filtros, vista y fecha en la URL; la vista y
 * los filtros, guardados por persona en este navegador. Pulsar una tarea abre su panel
 * (?tarea={id}) sin salir del calendario; un clic en un día vacío crea una tarea ese día.
 */
export default function TeamCalendarPage(props: TeamCalendarPageProps) {
    const { filters, calendar, statuses, options, panel, panelLookups } = props;
    const page = usePage();
    const [loading, setLoading] = useState(false);
    const [busy, setBusy] = useState(false);
    const [loadingTaskId, setLoadingTaskId] = useState<number | null>(null);
    const [closedTaskId, setClosedTaskId] = useState<number | null>(null);
    const [createOn, setCreateOn] = useState<string | null>(null);
    // Las últimas opciones del panel: mientras llega otra tarea, el panel no se queda sin ellas.
    const [lookupsSource, setLookupsSource] = useState(panelLookups);

    if (panelLookups !== null && panelLookups !== lookupsSource) {
        setLookupsSource(panelLookups);
    }

    if (panel === null && closedTaskId !== null) {
        setClosedTaskId(null);
    }

    const visit = (query: Record<string, string>, onFinish?: () => void) => {
        router.get(
            calendarIndex.url({ query }),
            {},
            {
                only: CALENDAR_PROPS,
                preserveState: true,
                preserveScroll: true,
                onStart: () => setLoading(true),
                onFinish: () => {
                    setLoading(false);
                    onFinish?.();
                },
            },
        );
    };

    usePersistedQuery(
        `audax.calendar.filters.${props.currentUser.id}`,
        calendarFilterQuery(filters),
        CALENDAR_PARAMS,
        (stored, done) => visit(stored, done),
    );

    const change = (next: TeamCalendarFilters) =>
        visit(calendarQuery(next, calendar.today));

    const navigate = (
        view: TeamCalendarView,
        date: string,
        people: boolean,
    ) => {
        if (!busy) {
            change({ ...filters, view, date, people });
        }
    };

    const openTask = (taskId: number) => {
        setClosedTaskId(null);
        setLoadingTaskId(taskId);
        router.visit(withTaskParam(page.url, taskId), {
            only: ['panel', 'panelLookups'],
            preserveState: true,
            preserveScroll: true,
            onFinish: () => setLoadingTaskId(null),
        });
    };

    const closeTask = () => {
        setClosedTaskId(panel?.task.id ?? null);
        setLoadingTaskId(null);
        router.visit(withTaskParam(page.url, null), {
            only: ['panel', 'panelLookups'],
            preserveState: true,
            preserveScroll: true,
        });
    };

    const create = (date: string) => {
        if (props.creatable !== undefined && props.creatable.length === 0) {
            toast.info(t('team_calendar.no_projects'));

            return;
        }

        setCreateOn(date);

        if (props.creatable === undefined) {
            router.reload({
                only: ['creatable'],
                onSuccess: (next) => {
                    const creatable = (next.props as { creatable?: unknown[] })
                        .creatable;

                    if (Array.isArray(creatable) && creatable.length === 0) {
                        setCreateOn(null);
                        toast.info(t('team_calendar.no_projects'));
                    }
                },
            });
        }
    };

    const statusById = new Map(statuses.map((status) => [status.id, status]));
    const projectById = new Map(
        calendar.projects.map((project) => [project.id, project]),
    );
    const assigneeById = new Map(
        calendar.assignees.map((assignee) => [assignee.id, assignee]),
    );

    const taskPanel = (
        <TaskPanel
            panel={lookupsSource ? panel : null}
            loading={loadingTaskId !== null}
            closing={panel !== null && panel.task.id === closedTaskId}
            onOpen={openTask}
            onClose={closeTask}
        />
    );

    return (
        <>
            <Head title={t('nav.calendar')} />

            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
                <Heading
                    as="h1"
                    title={t('team_calendar.heading')}
                    description={t('team_calendar.description')}
                />

                <CalendarFilterBar
                    filters={filters}
                    options={options}
                    onChange={change}
                />

                <TeamCalendar
                    calendar={calendar}
                    statuses={statuses}
                    context={{
                        statusById,
                        projectById,
                        assigneeById,
                        onOpen: openTask,
                    }}
                    loading={loading}
                    onNavigate={navigate}
                    onCreate={create}
                    onBusyChange={setBusy}
                />
            </div>

            {lookupsSource ? (
                <TaskLookupsProvider value={buildTaskLookups(lookupsSource)}>
                    {taskPanel}
                </TaskLookupsProvider>
            ) : (
                taskPanel
            )}

            <NewTaskDialog
                key={createOn ?? 'closed'}
                open={createOn !== null && props.creatable !== undefined}
                onOpenChange={(open) => {
                    if (!open) {
                        setCreateOn(null);
                    }
                }}
                projects={(props.creatable ?? []).map((project) => ({
                    id: project.id,
                    label: `${project.code} · ${project.name}`,
                    usesBanks: project.uses_hour_banks,
                    banks: project.banks,
                }))}
                departmentId={props.currentUser.department_id}
                reload={['calendar']}
                initialDue={createOn}
            />
        </>
    );
}

TeamCalendarPage.layout = {
    breadcrumbs: [{ title: t('nav.calendar'), href: calendarIndex() }],
};
