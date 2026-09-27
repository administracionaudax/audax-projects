import { Link } from '@inertiajs/react';
import { CalendarClock, CalendarDays, Diamond, Milestone } from 'lucide-react';
import { EmptyState } from '@/components/empty-state';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatDate } from '@/lib/format';
import { t } from '@/lib/i18n';
import { urls } from '@/lib/urls';
import { cn } from '@/lib/utils';
import type {
    HomeMilestone,
    MilestoneItem,
    ProjectMilestones,
} from '@/types/planning';

/** «Hoy», «Mañana», «En 5 días», «Hace 3 días»… a partir de los días hasta la entrega. */
export function relativeDays(days: number): string {
    if (days === 0) {
        return t('planning.milestones.today');
    }

    if (days === 1) {
        return t('planning.milestones.tomorrow');
    }

    if (days === -1) {
        return t('planning.milestones.yesterday');
    }

    return days > 0
        ? t('planning.milestones.in_days', { count: days })
        : t('planning.milestones.days_ago', { count: -days });
}

/**
 * Lista de hitos: rombo, título con enlace a su tarea (abre el panel en su proyecto), fecha de
 * entrega y, si está vencido, icono y texto «Vencido» (nunca solo color).
 */
export function MilestoneList({
    items,
    label,
}: {
    items: (MilestoneItem & { project?: HomeMilestone['project'] })[];
    label: string;
}) {
    return (
        <ul className="divide-y rounded-md border" aria-label={label}>
            {items.map((item) => (
                <li
                    key={item.id}
                    className="flex items-start gap-2 px-3 py-2"
                    data-test="milestone-item"
                >
                    <Diamond
                        aria-hidden="true"
                        className="mt-0.5 size-4 shrink-0 fill-current text-primary-text"
                    />
                    <div className="min-w-0 flex-1">
                        <Link
                            href={urls.task(item.project_id, item.id)}
                            className={cn(
                                'block truncate rounded-sm text-sm hover:underline',
                                FOCUS_RING,
                            )}
                        >
                            {item.title}
                        </Link>
                        <p className="flex flex-wrap items-center gap-x-2 text-xs text-muted-foreground">
                            {item.project ? (
                                <span className="inline-flex items-center gap-1">
                                    <span
                                        aria-hidden="true"
                                        className="size-2 rounded-full"
                                        style={{
                                            backgroundColor: item.project.color,
                                        }}
                                    />
                                    <span title={item.project.name}>
                                        {item.project.code}
                                    </span>
                                </span>
                            ) : null}
                            <span className="tabular">
                                {t('planning.milestones.due', {
                                    date: formatDate(item.due_date),
                                })}
                            </span>
                            {item.is_overdue ? (
                                <span
                                    className="inline-flex items-center gap-1 font-medium text-danger"
                                    data-test="milestone-overdue"
                                >
                                    <CalendarClock
                                        aria-hidden="true"
                                        className="size-3.5"
                                    />
                                    {t('planning.milestones.overdue_badge')}
                                    <span className="font-normal">
                                        ({relativeDays(item.days)})
                                    </span>
                                </span>
                            ) : (
                                <span>{relativeDays(item.days)}</span>
                            )}
                        </p>
                    </div>
                </li>
            ))}
        </ul>
    );
}

/**
 * «Próximos hitos» del resumen del proyecto (D-062): los vencidos destacados (con su total si hay
 * más de los que se enseñan), los 5 siguientes y cuántos no tienen fecha, con acceso al
 * calendario. Estado vacío si no hay ninguno.
 */
export function ProjectMilestonesCard({
    projectId,
    milestones,
}: {
    projectId: number;
    milestones: ProjectMilestones;
}) {
    const { overdue, upcoming, overdue_total: overdueTotal } = milestones;
    const calendarLink = (
        <Link
            href={urls.projectCalendar(projectId)}
            className={cn(
                'inline-flex items-center gap-1 self-start rounded-sm text-sm text-primary-text hover:underline',
                FOCUS_RING,
            )}
        >
            <CalendarDays aria-hidden="true" className="size-4" />
            {t('planning.milestones.open_calendar')}
        </Link>
    );

    if (overdue.length === 0 && upcoming.length === 0) {
        return (
            <div className="grid gap-3" data-test="project-milestones">
                <EmptyState
                    icon={Milestone}
                    title={t('planning.milestones.empty')}
                    description={
                        milestones.undated_count > 0
                            ? t('planning.milestones.only_undated', {
                                  count: milestones.undated_count,
                              })
                            : t('planning.milestones.empty_description')
                    }
                />
                {calendarLink}
            </div>
        );
    }

    return (
        <div className="grid gap-4" data-test="project-milestones">
            {overdue.length > 0 ? (
                <div className="grid gap-2">
                    <h3 className="flex items-center gap-1 text-sm font-medium">
                        <CalendarClock
                            aria-hidden="true"
                            className="size-4 text-danger"
                        />
                        {t('planning.milestones.overdue', {
                            count: overdueTotal,
                        })}
                    </h3>
                    <MilestoneList
                        items={overdue}
                        label={t('planning.milestones.overdue', {
                            count: overdueTotal,
                        })}
                    />
                    {overdueTotal > overdue.length ? (
                        <p className="text-xs text-muted-foreground">
                            {t('planning.milestones.more_overdue', {
                                shown: overdue.length,
                                total: overdueTotal,
                            })}
                        </p>
                    ) : null}
                </div>
            ) : null}
            <div className="grid gap-2">
                <h3 className="text-sm font-medium">
                    {t('planning.milestones.upcoming')}
                </h3>
                {upcoming.length > 0 ? (
                    <MilestoneList
                        items={upcoming}
                        label={t('planning.milestones.upcoming')}
                    />
                ) : (
                    <p className="text-sm text-muted-foreground">
                        {t('planning.milestones.no_upcoming')}
                    </p>
                )}
            </div>
            {milestones.undated_count > 0 ? (
                <p className="text-xs text-muted-foreground">
                    {t('planning.milestones.undated', {
                        count: milestones.undated_count,
                    })}
                </p>
            ) : null}
            {calendarLink}
        </div>
    );
}

/** Tarjeta «Mis próximos hitos» de Inicio (D-062): lista o estado vacío. */
export function MyMilestones({ milestones }: { milestones: HomeMilestone[] }) {
    if (milestones.length === 0) {
        return (
            <EmptyState
                className="flex-1"
                icon={Milestone}
                title={t('planning.home.empty')}
                description={t('planning.home.empty_description')}
            />
        );
    }

    return (
        <MilestoneList items={milestones} label={t('planning.home.title')} />
    );
}
