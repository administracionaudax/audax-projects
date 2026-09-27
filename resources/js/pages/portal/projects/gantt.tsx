import { Head } from '@inertiajs/react';
import { CalendarX2, ChartGantt } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@/components/empty-state';
import type { GanttTask } from '@/components/gantt/types';
import { GanttView } from '@/components/gantt/gantt-view';
import { PortalProjectHeader } from '@/components/portal/projects/portal-project-header';
import { PortalTaskDialog } from '@/components/portal/projects/portal-task-dialog';
import type { PortalProjectGanttProps } from '@/components/portal/projects/types';
import { t } from '@/lib/i18n';
import { home } from '@/routes/portal';
import { gantt, index, show } from '@/routes/portal/projects';

/**
 * Gantt de solo lectura del portal (/portal/proyectos/{proyecto}/gantt, SPEC §11, D-060, D-064): el
 * componente de la Fase 4 con `readOnly` y `hideAssignees` (barras, hitos en rombo, dependencias,
 * escalas y su tabla accesible), sin arrastrar, sin menú de edición, sin responsables ni horas. Una
 * tarea se abre en un diálogo de solo lectura.
 */
export default function PortalProjectGantt({
    project,
    tasks,
    dependencies,
    statuses,
    range,
    preferences,
    today,
    view,
}: PortalProjectGanttProps) {
    const [selected, setSelected] = useState<GanttTask | null>(null);
    const [open, setOpen] = useState(false);

    return (
        <>
            <Head
                title={t('portal_projects.gantt.title', {
                    project: project.name,
                })}
            />

            <div className="grid gap-8">
                <PortalProjectHeader
                    project={project}
                    tab="gantt"
                    tasks={view}
                    ganttOpen
                />

                <section
                    aria-labelledby="portal-gantt-heading"
                    className="grid gap-4"
                >
                    <div className="grid gap-1">
                        <h2
                            id="portal-gantt-heading"
                            className="text-lg font-normal"
                        >
                            {t('portal_projects.gantt.heading')}
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            {t('portal_projects.gantt.description')}
                        </p>
                    </div>

                    {tasks.length === 0 ? (
                        <EmptyState
                            icon={ChartGantt}
                            title={t('portal_projects.gantt.empty')}
                            description={t(
                                'portal_projects.gantt.empty_description',
                            )}
                        />
                    ) : (
                        <GanttView
                            label={t('portal_projects.gantt.chart_label', {
                                project: project.name,
                            })}
                            tasks={tasks}
                            dependencies={dependencies}
                            statuses={statuses}
                            range={range}
                            today={today}
                            preferences={preferences}
                            reload={[]}
                            readOnly
                            hideAssignees
                            showUnscheduled
                            onOpenTask={(task) => {
                                setSelected(task);
                                setOpen(true);
                            }}
                            emptyChart={
                                <EmptyState
                                    icon={CalendarX2}
                                    title={t('portal_projects.gantt.no_dates')}
                                    description={t(
                                        'portal_projects.gantt.no_dates_description',
                                    )}
                                />
                            }
                        />
                    )}
                </section>
            </div>

            <PortalTaskDialog
                open={open}
                task={selected}
                tasks={tasks}
                dependencies={dependencies}
                onOpenChange={setOpen}
            />
        </>
    );
}

PortalProjectGantt.layout = (props: PortalProjectGanttProps) => ({
    breadcrumbs: [
        { title: t('portal_nav.home'), href: home() },
        { title: t('portal_nav.projects'), href: index() },
        {
            title: props.project.name,
            href: props.view ? show(props.project.id) : gantt(props.project.id),
        },
        {
            title: t('portal_projects.tabs.gantt'),
            href: gantt(props.project.id),
        },
    ],
});
