import {
    Circle,
    CircleAlert,
    CircleCheck,
    CircleDot,
    Clock,
    Diamond,
    ListChecks,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { EmptyState, PhaseBadge } from '@/components/empty-state';
import type {
    R2Milestone,
    R2TaskStatusSummary,
} from '@/components/reports/r2-types';
import { StatusBadge } from '@/components/styleguide/status-badges';
import { formatDate, formatPercent } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { TaskStatusCategory } from '@/types';

const CATEGORIES: {
    key: TaskStatusCategory;
    icon: LucideIcon;
    tone: string;
}[] = [
    { key: 'todo', icon: Circle, tone: 'text-muted-foreground' },
    { key: 'in_progress', icon: CircleDot, tone: 'text-info' },
    { key: 'done', icon: CircleCheck, tone: 'text-success' },
];

/**
 * Estado actual de las tareas del proyecto (SPEC §10.3): cuántas hay por categoría y por estado,
 * cuántas abiertas están vencidas y qué parte está hecha. Sin hitos (van aparte).
 */
export function R2TaskStatus({ tasks }: { tasks: R2TaskStatusSummary }) {
    if (tasks.total === 0) {
        return (
            <EmptyState
                icon={ListChecks}
                title={t('reports_r2.tasks.empty')}
                description={t('reports_r2.tasks.empty_description')}
            />
        );
    }

    const done = tasks.by_category.done / tasks.total;

    return (
        <div className="grid gap-4">
            <dl className="grid grid-cols-2 gap-3 md:grid-cols-4">
                {CATEGORIES.map(({ key, icon: Icon, tone }) => (
                    <div
                        key={key}
                        className="grid content-start gap-1 rounded-md border bg-card p-3"
                    >
                        <dt className="flex items-center gap-1.5 text-xs text-muted-foreground">
                            <Icon
                                aria-hidden="true"
                                className={cn('size-3.5 shrink-0', tone)}
                            />
                            {t(`reports_r2.tasks.category.${key}`)}
                        </dt>
                        <dd className="tabular text-2xl">
                            {tasks.by_category[key]}
                        </dd>
                    </div>
                ))}
                <div
                    className={cn(
                        'grid content-start gap-1 rounded-md border p-3',
                        tasks.overdue > 0 ? 'bg-danger-soft' : 'bg-card',
                    )}
                >
                    <dt className="flex items-center gap-1.5 text-xs text-foreground">
                        <CircleAlert
                            aria-hidden="true"
                            className={cn(
                                'size-3.5 shrink-0',
                                tasks.overdue > 0
                                    ? 'text-danger'
                                    : 'text-muted-foreground',
                            )}
                        />
                        {t('reports_r2.tasks.overdue')}
                    </dt>
                    <dd className="tabular text-2xl">{tasks.overdue}</dd>
                </div>
            </dl>

            <div className="grid gap-2">
                <p className="text-sm">
                    {t('reports_r2.tasks.done_share', {
                        done: tasks.by_category.done,
                        total: tasks.total,
                        pct: formatPercent(done, 0),
                    })}
                </p>
                <div
                    aria-hidden="true"
                    className="flex h-2.5 w-full overflow-hidden rounded-md bg-neutral-soft"
                >
                    <div
                        className="h-full bg-success"
                        style={{
                            width: `${(tasks.by_category.done / tasks.total) * 100}%`,
                        }}
                    />
                    <div
                        className="h-full border-l-2 border-card bg-info"
                        style={{
                            width: `${(tasks.by_category.in_progress / tasks.total) * 100}%`,
                        }}
                    />
                </div>
            </div>

            <ul
                aria-label={t('reports_r2.tasks.by_status')}
                className="flex flex-wrap gap-2"
            >
                {tasks.by_status
                    .filter((status) => status.count > 0)
                    .map((status) => (
                        <li
                            key={status.id}
                            className="inline-flex items-center gap-2 rounded-md bg-muted px-2.5 py-1 text-sm"
                        >
                            <span
                                aria-hidden="true"
                                className="size-2.5 shrink-0 rounded-full"
                                style={{ backgroundColor: status.color }}
                            />
                            {status.name}
                            <span className="tabular text-muted-foreground">
                                {status.count}
                            </span>
                        </li>
                    ))}
            </ul>
        </div>
    );
}

/**
 * Hitos del proyecto (SPEC §10.3), por fecha: completados, vencidos o pendientes, siempre con
 * icono y texto. El Gantt con los hitos llega en la Fase 4 (PhaseBadge).
 */
export function R2Milestones({
    milestones,
}: {
    milestones: ReadonlyArray<R2Milestone>;
}) {
    if (milestones.length === 0) {
        return (
            <EmptyState
                icon={Diamond}
                title={t('reports_r2.milestones.empty')}
                description={t('reports_r2.milestones.empty_description')}
                phase={4}
            />
        );
    }

    return (
        <div className="grid gap-3">
            <ol className="grid gap-2">
                {milestones.map((milestone) => (
                    <li
                        key={milestone.id}
                        className="flex flex-wrap items-center justify-between gap-2 rounded-md border px-3 py-2"
                    >
                        <span className="flex min-w-0 items-center gap-2">
                            <Diamond
                                aria-hidden="true"
                                className="size-4 shrink-0 text-muted-foreground"
                            />
                            <span className="min-w-0 break-words">
                                {milestone.title}
                            </span>
                        </span>
                        <span className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                            <span className="tabular">
                                {milestone.due_date
                                    ? formatDate(milestone.due_date)
                                    : t('reports_r2.milestones.no_date')}
                            </span>
                            <MilestoneState milestone={milestone} />
                        </span>
                    </li>
                ))}
            </ol>
            <p className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                {t('reports_r2.milestones.gantt')}
                <PhaseBadge phase={4} />
            </p>
        </div>
    );
}

function MilestoneState({ milestone }: { milestone: R2Milestone }) {
    if (milestone.completed) {
        return (
            <StatusBadge tone="success" icon={CircleCheck}>
                {t('reports_r2.milestones.completed')}
            </StatusBadge>
        );
    }

    if (milestone.overdue) {
        return (
            <StatusBadge tone="danger" icon={CircleAlert}>
                {t('reports_r2.milestones.overdue')}
            </StatusBadge>
        );
    }

    return (
        <StatusBadge tone="neutral" icon={Clock}>
            {t('reports_r2.milestones.pending')}
        </StatusBadge>
    );
}
