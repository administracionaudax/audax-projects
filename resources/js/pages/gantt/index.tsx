import { Head, router } from '@inertiajs/react';
import { CalendarX2, FolderSearch, TriangleAlert } from 'lucide-react';
import { EmptyState } from '@/components/empty-state';
import { GanttFiltersBar } from '@/components/gantt/gantt-filters';
import { GanttView } from '@/components/gantt/gantt-view';
import { filtersQuery, preferencesQuery } from '@/components/gantt/preferences';
import type {
    GanttFilters,
    GanttIndexPageProps,
    GanttPreferences,
} from '@/components/gantt/types';
import { PageHeader } from '@/components/projects-list/page-header';
import { formatNumber } from '@/lib/format';
import { t } from '@/lib/i18n';
import { urls } from '@/lib/urls';
import { index as ganttIndex } from '@/routes/gantt';

/** Props que dependen de los filtros o de los cambios en las tareas. */
const RELOAD = ['limit', 'projects', 'tasks', 'dependencies', 'range'];

/**
 * Gantt multiproyecto (/gantt, SPEC §6.1, D-060): los proyectos filtrados como grupos plegables
 * con su barra resumen y sus tareas. Las mismas interacciones que el Gantt de proyecto (las
 * dependencias, solo dentro de cada proyecto). Con más de 60 proyectos o 1.500 tareas se pide
 * filtrar.
 */
export default function GanttIndex(props: GanttIndexPageProps) {
    const { filters, limit, projects } = props;

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
                />

                <GanttFiltersBar
                    filters={filters}
                    options={props.options}
                    onChange={(next) =>
                        visit(next, props.preferences, [
                            'filters',
                            'preferences',
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
        </>
    );
}

GanttIndex.layout = {
    breadcrumbs: [
        { title: t('nav.projects'), href: urls.projects() },
        { title: t('project_tabs.gantt'), href: urls.gantt() },
    ],
};
