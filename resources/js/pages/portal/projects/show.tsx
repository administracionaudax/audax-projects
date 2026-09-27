import { Head } from '@inertiajs/react';
import { CircleCheck, Clock, Diamond, ListChecks } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { PortalProjectHeader } from '@/components/portal/projects/portal-project-header';
import { PortalTaskList } from '@/components/portal/projects/portal-task-list';
import type { PortalProjectShowProps } from '@/components/portal/projects/types';
import { formatMinutes } from '@/lib/format';
import { t } from '@/lib/i18n';
import { todayInMadrid } from '@/lib/week';
import { home } from '@/routes/portal';
import { index, show } from '@/routes/portal/projects';

function Stat({
    icon: Icon,
    label,
    value,
}: {
    icon: LucideIcon;
    label: string;
    value: string;
}) {
    // Un solo <div> por grupo dt/dd dentro del <dl>; el icono va aparte, oculto a los lectores.
    return (
        <div className="grid min-w-0 grid-cols-[auto_minmax(0,1fr)] items-start gap-x-3 gap-y-0.5 rounded-md border bg-card p-3 sm:p-4">
            <Icon
                aria-hidden="true"
                className="row-span-2 mt-0.5 size-5 shrink-0 text-muted-foreground"
                strokeWidth={1.5}
            />
            <dt className="col-start-2 min-w-0 text-sm text-muted-foreground">
                {label}
            </dt>
            <dd className="tabular col-start-2 min-w-0 text-xl sm:text-2xl">
                {value}
            </dd>
        </div>
    );
}

/**
 * Vista de un proyecto en el portal (/portal/proyectos/{proyecto}, SPEC §11, D-064): cabecera, un
 * resumen (tareas, completadas, hitos y, si se enseñan, horas) y la lista de tareas con su estado,
 * su entrega y si son hitos. Sin comentarios, adjuntos, personas ni datos económicos.
 */
export default function PortalProjectShow({
    project,
    tasks,
    statuses,
    showHours,
    totals,
    gantt,
}: PortalProjectShowProps) {
    return (
        <>
            <Head title={project.name} />

            <div className="grid gap-8">
                <PortalProjectHeader
                    project={project}
                    tab="tasks"
                    tasks
                    ganttOpen={gantt}
                />

                <section aria-labelledby="portal-project-summary">
                    <h2 id="portal-project-summary" className="sr-only">
                        {t('portal_projects.summary.title')}
                    </h2>
                    <dl className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                        <Stat
                            icon={ListChecks}
                            label={t('portal_projects.summary.tasks')}
                            value={String(totals.tasks)}
                        />
                        <Stat
                            icon={CircleCheck}
                            label={t('portal_projects.summary.done')}
                            value={t('portal_projects.summary.done_value', {
                                done: totals.done,
                                total: totals.tasks,
                            })}
                        />
                        <Stat
                            icon={Diamond}
                            label={t('portal_projects.summary.milestones')}
                            value={String(totals.milestones)}
                        />
                        {showHours && totals.minutes !== null ? (
                            <Stat
                                icon={Clock}
                                label={t('portal_projects.summary.hours')}
                                value={formatMinutes(totals.minutes)}
                            />
                        ) : (
                            <Stat
                                icon={ListChecks}
                                label={t('portal_projects.summary.open')}
                                value={String(totals.open)}
                            />
                        )}
                    </dl>
                </section>

                <section
                    aria-labelledby="portal-project-tasks"
                    className="grid gap-4"
                >
                    <h2
                        id="portal-project-tasks"
                        className="text-lg font-normal"
                    >
                        {t('portal_projects.tasks.title')}
                    </h2>
                    <PortalTaskList
                        projectName={project.name}
                        tasks={tasks}
                        statuses={statuses}
                        showHours={showHours}
                        totals={totals}
                        today={todayInMadrid()}
                    />
                </section>
            </div>
        </>
    );
}

PortalProjectShow.layout = (props: PortalProjectShowProps) => ({
    breadcrumbs: [
        { title: t('portal_nav.home'), href: home() },
        { title: t('portal_nav.projects'), href: index() },
        { title: props.project.name, href: show(props.project.id) },
    ],
});
