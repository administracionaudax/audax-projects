import { Head, router } from '@inertiajs/react';
import { CalendarX2, FolderSearch, Plus, TriangleAlert } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@/components/empty-state';
import { GanttFiltersBar } from '@/components/gantt/gantt-filters';
import { GanttView } from '@/components/gantt/gantt-view';
import { NewTaskDialog } from '@/components/gantt/new-task-dialog';
import type { NewTaskProject } from '@/components/gantt/new-task-dialog';
import { filtersQuery, preferencesQuery } from '@/components/gantt/preferences';
import type {
    GanttFilters,
    GanttIndexPageProps,
    GanttPreferences,
} from '@/components/gantt/types';
import { PageHeader } from '@/components/projects-list/page-header';
import { Button } from '@/components/ui/button';
import { formatNumber } from '@/lib/format';
import { t } from '@/lib/i18n';
import { urls } from '@/lib/urls';
import { index as ganttIndex } from '@/routes/gantt';

/** Props que dependen de los filtros o de los cambios en las tareas. */
const RELOAD = ['limit', 'projects', 'tasks', 'dependencies', 'range'];

/**
 * Gantt multiproyecto (/gantt, SPEC §6.1, D-060): los proyectos filtrados como grupos plegables
 * con su barra resumen y sus tareas. Las mismas interacciones que el Gantt de proyecto (las
 * dependencias, solo dentro de cada proyecto): lista «Sin fechas» por proyecto con «Asignar
 * fechas» y «Nueva tarea» (eligiendo el proyecto). Con más de 60 proyectos o 1.500 tareas se
 * pide filtrar.
 */
export default function GanttIndex(props: GanttIndexPageProps) {
    const { filters, limit, projects } = props;
    const [creating, setCreating] = useState(false);

    // Proyectos en los que puede crear tareas (TaskPolicy::create), con sus bolsas abiertas.
    const banksByProject = new Map(
        props.banks.map((group) => [group.project_id, group.banks]),
    );
    const creatable: NewTaskProject[] = projects
        .filter((project) => project.can.create)
        .map((project) => ({
            id: project.id,
            label: `${project.code} · ${project.name}`,
            usesBanks: project.uses_hour_banks,
            banks: banksByProject.get(project.id) ?? [],
        }));

    // Cambiar la escala o los colores solo toca la URL: los datos no dependen de ellos, así que
    // no se vuelven a pedir las tareas (hasta 1.500).
    const visit = (
        next: GanttFilters,
        preferences: GanttPreferences,
        only: string[],
    ) => {
        router.get(
            ganttIndex.url({
                query: {
                    ...filtersQuery(next),
                    ...preferencesQuery(preferences),
                },
            }),
            {},
            {
                only,
                preserveState: true,
                preserveScroll: true,
                replace: true,
            },
        );
    };

    return (
        <>
            <Head title={t('gantt.index.title')} />

            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={t('gantt.index.heading')}
                    description={t('gantt.index.description')}
                    actions={
                        creatable.length > 0 ? (
                            <Button
                                type="button"
                                onClick={() => setCreating(true)}
                                data-test="gantt-new-task"
                            >
                                <Plus aria-hidden="true" />
                                {t('gantt.new_task.button')}
                            </Button>
                        ) : null
                    }
                />

                <GanttFiltersBar
                    filters={filters}
                    options={props.options}
                    onChange={(next) =>
                        visit(next, props.preferences, [
                            'filters',
                            'preferences',
                            'banks',
                            ...RELOAD,
                        ])
                    }
                />

                {limit.exceeded !== null ? (
                    <div
                        role="alert"
                        className="flex items-start gap-3 rounded-md border border-warning bg-warning-soft p-4 text-sm text-foreground"
                        data-test="gantt-limit"
                    >
                        <TriangleAlert
                            aria-hidden="true"
                            className="mt-0.5 size-5 shrink-0 text-warning"
                        />
                        <div className="grid gap-1">
                            <p className="font-medium">
                                {t('gantt.limit.title')}
                            </p>
                            <p>
                                {limit.exceeded === 'projects'
                                    ? t('gantt.limit.projects', {
                                          count: limit.projects,
                                          max: limit.max_projects,
                                      })
                                    : t('gantt.limit.tasks', {
                                          count: formatNumber(limit.tasks, 0),
                                          max: formatNumber(limit.max_tasks, 0),
                                      })}
                            </p>
                        </div>
                    </div>
                ) : projects.length === 0 ? (
                    <EmptyState
                        icon={FolderSearch}
                        title={t('gantt.index.empty')}
                        description={t('gantt.index.empty_description')}
                    />
                ) : (
                    <GanttView
                        label={t('gantt.index.chart_label')}
                        tasks={props.tasks}
                        dependencies={props.dependencies}
                        statuses={props.statuses}
                        range={props.range}
                        today={props.today}
                        preferences={props.preferences}
                        onPreferencesChange={(preferences) =>
                            visit(filters, preferences, ['preferences'])
                        }
                        reload={RELOAD}
                        projects={projects}
                        projectHref={(project) => urls.projectGantt(project.id)}
                        showUnscheduled
                        emptyChart={
                            <EmptyState
                                icon={CalendarX2}
                                title={t('gantt.empty.no_dates')}
                                description={t('gantt.index.no_dates')}
                            />
                        }
                    />
                )}
            </div>

            {creatable.length > 0 ? (
                <NewTaskDialog
                    open={creating}
                    onOpenChange={setCreating}
                    projects={creatable}
                    departmentId={props.currentUser.department_id}
                    reload={RELOAD}
                />
            ) : null}
        </>
    );
}

GanttIndex.layout = {
    breadcrumbs: [
        { title: t('nav.projects'), href: urls.projects() },
        { title: t('project_tabs.gantt'), href: urls.gantt() },
    ],
};
