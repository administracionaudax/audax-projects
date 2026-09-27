import { Link } from '@inertiajs/react';
import { CalendarX, CircleCheck, Diamond } from 'lucide-react';
import { EmptyState } from '@/components/empty-state';
import type { R1Overdue } from '@/components/reports/r1-types';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatDate } from '@/lib/format';
import { t } from '@/lib/i18n';
import { urls } from '@/lib/urls';
import { cn } from '@/lib/utils';

/**
 * Tareas vencidas del alcance (SPEC §10.1): cuántas hay y las más antiguas, con su proyecto, su
 * responsable, la fecha límite y los días de retraso, y enlace al panel de la tarea.
 */
export function R1OverdueTasks({ overdue }: { overdue: R1Overdue }) {
    if (overdue.count === 0) {
        return (
            <EmptyState
                icon={CircleCheck}
                title={t('reports_r1.overdue.empty')}
            />
        );
    }

    return (
        <div className="grid gap-3">
            <p className="inline-flex items-center gap-2 text-sm">
                <CalendarX aria-hidden="true" className="size-4 text-danger" />
                {t('reports_r1.overdue.count', { count: overdue.count })}
            </p>
            <ul className="divide-y rounded-md border" data-test="r1-overdue">
                {overdue.tasks.map((task) => (
                    <li
                        key={task.id}
                        className="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2"
                    >
                        <div className="min-w-0 flex-1">
                            <Link
                                href={urls.task(task.project_id, task.id)}
                                className={cn(
                                    'inline-flex max-w-full items-center gap-1.5 rounded-sm text-sm hover:underline',
                                    FOCUS_RING,
                                )}
                            >
                                {task.is_milestone ? (
                                    <Diamond
                                        aria-label={t(
                                            'reports_r1.overdue.milestone',
                                        )}
                                        className="size-3.5 shrink-0 text-muted-foreground"
                                    />
                                ) : null}
                                <span className="truncate">{task.title}</span>
                            </Link>
                            <p className="flex flex-wrap items-center gap-x-2 text-xs text-muted-foreground">
                                <span className="inline-flex items-center gap-1">
                                    <span
                                        aria-hidden="true"
                                        className="size-2 rounded-full"
                                        style={{
                                            backgroundColor: task.project.color,
                                        }}
                                    />
                                    {task.project.code}
                                </span>
                                <span>
                                    {task.assignee ??
                                        t('reports_r1.overdue.unassigned')}
                                </span>
                            </p>
                        </div>
                        <p className="text-right text-xs">
                            <span className="block">
                                {t('reports_r1.overdue.due', {
                                    date: formatDate(task.due_date),
                                })}
                            </span>
                            <span className="block text-muted-foreground">
                                {t('reports_r1.overdue.days', {
                                    days: task.days_overdue,
                                })}
                            </span>
                        </p>
                    </li>
                ))}
            </ul>
            {overdue.count > overdue.tasks.length ? (
                <p className="text-sm text-muted-foreground">
                    {t('reports_r1.overdue.more', {
                        shown: overdue.tasks.length,
                        count: overdue.count,
                    })}
                </p>
            ) : null}
        </div>
    );
}
