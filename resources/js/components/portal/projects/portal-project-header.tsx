import { Link } from '@inertiajs/react';
import { CalendarRange, ChartGantt, ListChecks } from 'lucide-react';
import { ProjectStatusBadge } from '@/components/domain/badges';
import type { PortalProjectSummary } from '@/components/portal/projects/types';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatDate } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { gantt, show } from '@/routes/portal/projects';

type Tab = 'tasks' | 'gantt';

/** Fechas del proyecto en texto: «Del 01/09/2026 al 18/12/2026», «Entrega el …», etc. */
export function projectDates(project: PortalProjectSummary): string | null {
    const { start_date: start, due_date: due } = project;

    if (start && due) {
        return t('portal_projects.dates.range', {
            start: formatDate(start),
            end: formatDate(due),
        });
    }

    if (due) {
        return t('portal_projects.dates.due', { date: formatDate(due) });
    }

    if (start) {
        return t('portal_projects.dates.start', { date: formatDate(start) });
    }

    return null;
}

/**
 * Cabecera de un proyecto en el portal: código, nombre (h1), estado y fechas, y las pestañas que
 * el equipo ha abierto (Tareas y Gantt). Sin personas ni datos económicos.
 */
export function PortalProjectHeader({
    project,
    tab,
    tasks,
    ganttOpen,
}: {
    project: PortalProjectSummary;
    tab: Tab;
    /** La vista de tareas está abierta. */
    tasks: boolean;
    /** El Gantt está abierto. */
    ganttOpen: boolean;
}) {
    const dates = projectDates(project);
    const tabs = [
        ...(tasks
            ? [
                  {
                      id: 'tasks' as const,
                      href: show.url(project.id),
                      label: t('portal_projects.tabs.tasks'),
                      icon: ListChecks,
                  },
              ]
            : []),
        ...(ganttOpen
            ? [
                  {
                      id: 'gantt' as const,
                      href: gantt.url(project.id),
                      label: t('portal_projects.tabs.gantt'),
                      icon: ChartGantt,
                  },
              ]
            : []),
    ];

    return (
        <div className="grid gap-5">
            <header className="grid gap-2">
                <p className="text-sm text-muted-foreground">{project.code}</p>
                <h1 className="text-2xl font-normal tracking-tight break-words sm:text-3xl">
                    {project.name}
                </h1>
                <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
                    <ProjectStatusBadge status={project.status} />
                    {dates ? (
                        <span className="flex items-center gap-1.5 text-sm text-muted-foreground">
                            <CalendarRange
                                aria-hidden="true"
                                className="size-4"
                                strokeWidth={1.5}
                            />
                            {dates}
                        </span>
                    ) : null}
                </div>
            </header>

            {tabs.length > 1 ? (
                <nav
                    aria-label={t('portal_projects.tabs.label')}
                    className="-mx-4 overflow-x-auto border-b px-4 sm:mx-0 sm:px-0"
                >
                    <ul className="flex min-w-max gap-1">
                        {tabs.map((item) => {
                            const current = item.id === tab;

                            return (
                                <li key={item.id}>
                                    <Link
                                        href={item.href}
                                        aria-current={
                                            current ? 'page' : undefined
                                        }
                                        className={cn(
                                            '-mb-px flex items-center gap-2 border-b-2 px-3 py-2 text-sm',
                                            current
                                                ? 'border-primary font-medium text-foreground'
                                                : 'border-transparent text-muted-foreground hover:text-foreground',
                                            FOCUS_RING,
                                        )}
                                        data-test={`portal-project-tab-${item.id}`}
                                    >
                                        <item.icon
                                            aria-hidden="true"
                                            className="size-4"
                                            strokeWidth={1.5}
                                        />
                                        {item.label}
                                    </Link>
                                </li>
                            );
                        })}
                    </ul>
                </nav>
            ) : null}
        </div>
    );
}
