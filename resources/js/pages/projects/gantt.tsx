import { Head, router } from '@inertiajs/react';
import { CalendarX2, ChartGantt, Plus } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@/components/empty-state';
import { GanttView } from '@/components/gantt/gantt-view';
import { NewTaskDialog } from '@/components/gantt/new-task-dialog';
import { preferencesQuery } from '@/components/gantt/preferences';
import type {
    GanttPreferences,
    ProjectGanttPageProps,
} from '@/components/gantt/types';
import { ProjectShell } from '@/components/projects/project-shell';
import { Button } from '@/components/ui/button';
import { t } from '@/lib/i18n';
import { urls } from '@/lib/urls';
import { project as projectGantt } from '@/routes/gantt';

/** Props que se recargan tras mover, enlazar o crear (recarga parcial de Inertia). */
const RELOAD = ['tasks', 'dependencies', 'range'];

/**
 * Pestaña Gantt del proyecto (SPEC §6.1, D-060): barras e hitos de las tareas con sus
 * dependencias, escala y colores en la URL, lista «Sin fechas» y alta de tareas con fechas.
 * Mover, redimensionar y enlazar pasan por las rutas comunes de reprogramar y dependencias
 * (D-056, D-057); quien no puede editar las tareas lo ve en solo lectura.
 */
export default function ProjectGantt(props: ProjectGanttPageProps) {
    const { project, tasks, can } = props;
    const [creating, setCreating] = useState(false);
    const usesBanks = project.billing_type === 'hour_bank';

    const changePreferences = (preferences: GanttPreferences) => {
        router.get(
            projectGantt.url(project.id, {
                query: preferencesQuery(preferences),
            }),
            {},
            {
                only: ['preferences'],
                preserveState: true,
                preserveScroll: true,
                replace: true,
            },
        );
    };

    const newTaskButton = can.create ? (
        <Button
            type="button"
            onClick={() => setCreating(true)}
            data-test="gantt-new-task"
        >
            <Plus aria-hidden="true" />
            {t('gantt.new_task.button')}
        </Button>
    ) : null;

    return (
        <>
            <Head title={t('gantt.page.title', { project: project.name })} />

            <ProjectShell
                project={project}
                tab="gantt"
                canManage={props.canManage}
                actions={newTaskButton}
            >
                {tasks.length === 0 ? (
                    <EmptyState
                        icon={ChartGantt}
                        title={t('gantt.empty.no_tasks')}
                        description={
                            can.create
                                ? t('gantt.empty.no_tasks_description')
                                : t('gantt.empty.no_tasks_readonly')
                        }
                    />
                ) : (
                    <GanttView
                        label={t('gantt.chart_label', {
                            project: project.name,
                        })}
                        tasks={tasks}
                        dependencies={props.dependencies}
                        statuses={props.statuses}
                        range={props.range}
                        today={props.today}
                        preferences={props.preferences}
                        onPreferencesChange={changePreferences}
                        reload={RELOAD}
                        showUnscheduled
                        emptyChart={
                            <EmptyState
                                icon={CalendarX2}
                                title={t('gantt.empty.no_dates')}
                                description={
                                    can.update
                                        ? t('gantt.empty.no_dates_description')
                                        : t('gantt.empty.no_dates_readonly')
                                }
                            />
                        }
                    />
                )}
            </ProjectShell>

            {can.create ? (
                <NewTaskDialog
                    open={creating}
                    onOpenChange={setCreating}
                    projects={[
                        {
                            id: project.id,
                            label: project.name,
                            usesBanks,
                            banks: props.banks,
                        },
                    ]}
                    departmentId={props.currentUser.department_id}
                    reload={RELOAD}
                />
            ) : null}
        </>
    );
}

ProjectGantt.layout = (props: ProjectGanttPageProps) => ({
    breadcrumbs: [
        { title: t('nav.projects'), href: urls.projects() },
        { title: props.project.name, href: urls.project(props.project.id) },
        {
            title: t('project_tabs.gantt'),
            href: urls.projectGantt(props.project.id),
        },
    ],
});
