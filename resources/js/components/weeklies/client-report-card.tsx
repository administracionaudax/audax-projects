import { Link } from '@inertiajs/react';
import {
    ArrowRight,
    Bot,
    CircleAlert,
    CircleCheck,
    Clock,
    Flag,
    ListChecks,
    OctagonX,
    Smile,
    Tag,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { StatusBadge } from '@/components/styleguide/status-badges';
import { formatMinutes } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { show as showClient } from '@/routes/clients';
import type {
    WeeklyClientStatus,
    WeeklyClientUpdate,
    WeeklyProjectSnapshot,
} from '@/types/weeklies';

const STATUS_META = {
    on_track: { tone: 'success', icon: CircleCheck },
    risk: { tone: 'warning', icon: CircleAlert },
    blocked: { tone: 'danger', icon: OctagonX },
} as const;

/** Estado de un cliente en el informe (On Track, Risk o Blocked; icono y texto, nunca solo color). */
export function ClientStatusBadge({ status }: { status: WeeklyClientStatus }) {
    const meta = STATUS_META[status];

    return (
        <StatusBadge tone={meta.tone} icon={meta.icon}>
            {t(`weeklies.client_status.${status}`)}
        </StatusBadge>
    );
}

/** Consumido sobre presupuesto, en %; null sin presupuesto. */
export function projectProgress(project: WeeklyProjectSnapshot): number | null {
    return project.budget_minutes && project.budget_minutes > 0
        ? (project.consumed_minutes / project.budget_minutes) * 100
        : null;
}

/** «+1:30 sobre lo esperado», «En línea con lo esperado» o «-0:45 por debajo de lo esperado». */
export function deviationLabel(project: WeeklyProjectSnapshot): string | null {
    const delta = project.deviation_minutes;

    if (delta === null) {
        return null;
    }

    if (Math.abs(delta) < 1) {
        return t('weeklies.projects.on_expected');
    }

    return delta > 0
        ? t('weeklies.projects.above_expected', {
              time: formatMinutes(delta),
          })
        : t('weeklies.projects.below_expected', {
              time: formatMinutes(Math.abs(delta)),
          });
}

/**
 * Estado de un proyecto en la tarjeta del cliente (F-074, ProjectProgressBar, ProjectDeltaIndicator
 * y ProjectKindChip de WeeklySync, con los datos de Audax): código, tipo, barra de consumo con la
 * marca de lo esperado en un fee, lo que queda o el exceso y la desviación frente a lo esperado.
 */
export function ProjectStatusBar({
    project,
}: {
    project: WeeklyProjectSnapshot;
}) {
    const progress = projectProgress(project);
    const over =
        project.budget_minutes !== null &&
        project.budget_minutes > 0 &&
        project.consumed_minutes > project.budget_minutes;
    const expected =
        project.billing_type === 'monthly_fee' &&
        project.expected_minutes !== null &&
        project.budget_minutes
            ? Math.min(
                  100,
                  (project.expected_minutes / project.budget_minutes) * 100,
              )
            : null;
    const remaining = (project.budget_minutes ?? 0) - project.consumed_minutes;

    return (
        <div
            className="grid gap-2 border bg-card p-3"
            data-test="weekly-project"
        >
            <div className="flex items-start justify-between gap-2">
                <div className="min-w-0 space-y-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="tabular border px-1.5 py-0.5 text-xs font-semibold">
                            {project.code}
                        </span>
                        <span className="text-xs text-muted-foreground">
                            {t(
                                `weeklies.projects.kind.${project.billing_type}`,
                            )}
                        </span>
                    </div>
                    <p className="truncate text-sm">{project.name}</p>
                </div>
                {progress !== null ? (
                    <span
                        className={cn(
                            'tabular shrink-0 text-xs',
                            over ? 'text-danger' : 'text-muted-foreground',
                        )}
                    >
                        {Math.round(progress)} %
                    </span>
                ) : null}
            </div>

            {progress !== null ? (
                <div
                    className="relative h-2 w-full bg-muted"
                    role="img"
                    aria-label={t('weeklies.projects.bar', {
                        consumed: formatMinutes(project.consumed_minutes),
                        budget: formatMinutes(project.budget_minutes ?? 0),
                    })}
                >
                    <span
                        className={cn(
                            'absolute inset-y-0 left-0',
                            over
                                ? 'bg-danger'
                                : progress >= 85
                                  ? 'bg-warning'
                                  : 'bg-primary',
                        )}
                        style={{ width: `${Math.min(100, progress)}%` }}
                    />
                    {expected !== null ? (
                        <span
                            className="absolute inset-y-[-3px] w-0.5 bg-foreground"
                            style={{ left: `${expected}%` }}
                            aria-hidden="true"
                        />
                    ) : null}
                </div>
            ) : null}

            <div className="flex flex-wrap justify-between gap-x-3 gap-y-1 text-xs text-muted-foreground">
                {project.budget_minutes === null ? (
                    <span>{t('weeklies.projects.no_budget')}</span>
                ) : over ? (
                    <span className="text-danger">
                        {t('weeklies.projects.exceeded', {
                            time: formatMinutes(Math.abs(remaining)),
                        })}
                    </span>
                ) : (
                    <span>
                        {t('weeklies.projects.remaining', {
                            time: formatMinutes(remaining),
                        })}
                    </span>
                )}
                <span className="tabular">
                    {project.budget_minutes === null
                        ? t('weeklies.projects.week', {
                              time: formatMinutes(project.week_minutes),
                          })
                        : `${formatMinutes(project.consumed_minutes)} / ${formatMinutes(project.budget_minutes)}`}
                </span>
            </div>

            {project.billing_type === 'monthly_fee' &&
            project.expected_minutes !== null ? (
                <div className="flex flex-wrap justify-between gap-x-3 gap-y-1 text-xs text-muted-foreground">
                    <span>
                        {t('weeklies.projects.expected', {
                            time: formatMinutes(project.expected_minutes),
                        })}
                    </span>
                    <span
                        className={cn(
                            (project.deviation_minutes ?? 0) > 0 &&
                                'text-warning',
                        )}
                    >
                        {deviationLabel(project)}
                    </span>
                </div>
            ) : null}
        </div>
    );
}

/**
 * Tarjeta de un cliente en el informe (F-073, ClientReportCard de WeeklySync): estado, resumen
 * ejecutivo, siguientes pasos, próximos hitos, el estado de sus proyectos, etiquetas y la
 * satisfacción de la semana. El nombre lleva a la ficha del cliente (F-091); a la derecha, su
 * audio (F-086) y «Ver reportes» (F-078).
 */
export function ClientReportCard({
    update,
    anchor,
    audio,
    reportsAction,
}: {
    update: WeeklyClientUpdate;
    anchor: string;
    audio?: ReactNode;
    reportsAction?: ReactNode;
}) {
    return (
        <article
            id={anchor}
            aria-labelledby={`${anchor}-title`}
            className="grid scroll-mt-24 gap-4"
            data-test="weekly-client-card"
        >
            <header className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex min-w-0 flex-wrap items-center gap-3">
                    <span
                        aria-hidden="true"
                        className="h-8 w-1.5 shrink-0 bg-primary"
                    />
                    <h2 id={`${anchor}-title`} className="min-w-0 text-xl">
                        {update.client_id !== null ? (
                            <Link
                                href={showClient.url(update.client_id)}
                                className="hover:underline"
                            >
                                {update.client_name}
                            </Link>
                        ) : (
                            update.client_name
                        )}
                    </h2>
                    <ClientStatusBadge status={update.status} />
                    {update.satisfaction_score !== null ? (
                        <span
                            className="inline-flex items-center gap-1 text-sm text-muted-foreground"
                            data-test="weekly-client-satisfaction"
                        >
                            <Smile aria-hidden="true" className="size-4" />
                            {t('weeklies.report.satisfaction', {
                                score: update.satisfaction_score,
                            })}
                        </span>
                    ) : null}
                </div>
                {audio}
            </header>

            <section className="border bg-card p-4">
                <h3 className="mb-2 flex items-center gap-2 text-xs tracking-wider text-muted-foreground uppercase">
                    <Bot aria-hidden="true" className="size-4" />
                    {t('weeklies.report.executive_summary')}
                </h3>
                <p className="text-sm whitespace-pre-line">
                    {update.executive_summary}
                </p>
            </section>

            <div className="grid gap-4 md:grid-cols-2">
                <section className="border bg-muted/40 p-4">
                    <h3 className="mb-3 flex items-center gap-2 text-sm">
                        <ListChecks
                            aria-hidden="true"
                            className="size-4 text-primary-text"
                        />
                        {t('weeklies.report.next_steps')}
                    </h3>
                    {update.next_steps.length > 0 ? (
                        <ul className="grid gap-2">
                            {update.next_steps.map((step, index) => (
                                <li
                                    key={index}
                                    className="flex items-start gap-2 text-sm"
                                >
                                    <ArrowRight
                                        aria-hidden="true"
                                        className="mt-0.5 size-3.5 shrink-0 text-primary-text"
                                    />
                                    <span>{step}</span>
                                </li>
                            ))}
                        </ul>
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            {t('weeklies.report.no_next_steps')}
                        </p>
                    )}
                </section>
                <section className="border bg-muted/40 p-4">
                    <h3 className="mb-3 flex items-center gap-2 text-sm">
                        <Flag
                            aria-hidden="true"
                            className="size-4 text-warning"
                        />
                        {t('weeklies.report.milestones')}
                    </h3>
                    {update.milestones.length > 0 ? (
                        <ul className="grid gap-2">
                            {update.milestones.map((milestone, index) => (
                                <li
                                    key={index}
                                    className="flex items-center gap-2 text-sm"
                                >
                                    {milestone.date ? (
                                        <span className="tabular min-w-12 border bg-card px-1.5 py-0.5 text-center text-xs">
                                            {milestone.date}
                                        </span>
                                    ) : null}
                                    <span>{milestone.label}</span>
                                </li>
                            ))}
                        </ul>
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            {t('weeklies.report.no_milestones')}
                        </p>
                    )}
                </section>
            </div>

            {update.projects.length > 0 ? (
                <section className="border bg-muted/40 p-4">
                    <h3 className="mb-3 flex items-center gap-2 text-sm">
                        <Clock
                            aria-hidden="true"
                            className="size-4 text-primary-text"
                        />
                        {t('weeklies.report.projects')}
                    </h3>
                    <div className="grid gap-3 md:grid-cols-2">
                        {update.projects.map((project) => (
                            <ProjectStatusBar
                                key={project.project_id}
                                project={project}
                            />
                        ))}
                    </div>
                </section>
            ) : null}

            {update.tags.length > 0 || reportsAction ? (
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <ul
                        className="flex min-w-0 flex-1 flex-wrap gap-2"
                        aria-label={t('weeklies.report.tags')}
                    >
                        {update.tags.map((tag) => (
                            <li
                                key={tag}
                                className="inline-flex items-center gap-1 border bg-card px-2 py-1 text-xs text-muted-foreground"
                            >
                                <Tag aria-hidden="true" className="size-3" />
                                {tag}
                            </li>
                        ))}
                    </ul>
                    {reportsAction}
                </div>
            ) : null}
        </article>
    );
}
