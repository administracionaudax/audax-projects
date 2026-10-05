import { Link, router } from '@inertiajs/react';
import {
    CalendarDays,
    History,
    Minus,
    Plus,
    Smile,
    TrendingDown,
    TrendingUp,
    UserMinus,
    Users,
} from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@/components/empty-state';
import { UserAvatar } from '@/components/realtime/presence-indicator';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { AiSummaryPanel } from '@/components/weeklies/insights/ai-summary-panel';
import {
    formatPoints,
    SatisfactionChart,
} from '@/components/weeklies/insights/satisfaction-chart';
import {
    ClientReportCard,
    ClientStatusBadge,
} from '@/components/weeklies/client-report-card';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatDate, formatDateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { aiSummary, teamActivity } from '@/routes/clients';
import { show as showProject } from '@/routes/projects';
import { show as showPerson } from '@/routes/team';
import { show as showCycle } from '@/routes/weeklies';
import {
    join as joinClients,
    leave as leaveClient,
} from '@/routes/weeklies/clients';
import type {
    ClientTeamMember,
    ClientWeeklyHistoryTab,
    ClientWeeklySatisfactionTab,
    ClientWeeklySummaryTab,
    ClientWeeklyTeamTab,
} from '@/types/weekly-insights';

/** Satisfacción con su tendencia frente al cierre anterior (F-096): número, flecha y texto. */
export function SatisfactionTrend({
    score,
    trend,
    className,
}: {
    score: number;
    trend: number | null;
    className?: string;
}) {
    const Icon =
        trend === null || trend === 0
            ? Minus
            : trend > 0
              ? TrendingUp
              : TrendingDown;
    const label =
        trend === null
            ? null
            : trend === 0
              ? t('weeklies.client.trend_flat')
              : trend > 0
                ? t('weeklies.client.trend_up', { points: trend })
                : t('weeklies.client.trend_down', {
                      points: Math.abs(trend),
                  });

    return (
        <span
            className={cn('tabular inline-flex items-center gap-1', className)}
            data-test="client-satisfaction"
        >
            <Smile
                aria-hidden="true"
                className="size-4 text-muted-foreground"
                strokeWidth={1.5}
            />
            <span>{t('weeklies.client.satisfaction_value', { score })}</span>
            {label !== null ? (
                <>
                    <Icon
                        aria-hidden="true"
                        className={cn(
                            'size-4',
                            trend !== null && trend > 0
                                ? 'text-success'
                                : trend !== null && trend < 0
                                  ? 'text-danger'
                                  : 'text-muted-foreground',
                        )}
                    />
                    <span className="sr-only">{label}</span>
                </>
            ) : null}
        </span>
    );
}

/** Pestaña «Resumen»: la última weekly del cliente y el «Resumen del cliente (IA)» (F-129). */
export function ClientSummaryPanel({
    clientId,
    data,
}: {
    clientId: number;
    data: ClientWeeklySummaryTab;
}) {
    return (
        <div className="grid min-w-0 gap-6">
            <AiSummaryPanel
                title={t('weeklies.client.ai_title')}
                description={t('weeklies.client.ai_description')}
                summary={data.ai}
                action={aiSummary.url(clientId)}
                reloadProp="weekly"
                testId="client-ai-summary"
            />
            <section
                aria-labelledby="client-latest-weekly"
                className="grid gap-3"
                data-test="client-latest-weekly"
            >
                <div className="flex flex-wrap items-baseline justify-between gap-2">
                    <div className="space-y-1">
                        <h2 id="client-latest-weekly" className="text-lg">
                            {t('weeklies.client.latest_title')}
                        </h2>
                        {data.latest ? (
                            <p className="text-sm text-muted-foreground">
                                {t('weeklies.client.latest_description', {
                                    week: data.latest.cycle.label,
                                })}
                            </p>
                        ) : null}
                    </div>
                    {data.latest ? (
                        <Link
                            href={showCycle.url(data.latest.cycle.id)}
                            className={cn(
                                'text-sm text-primary-text hover:underline',
                                FOCUS_RING,
                            )}
                        >
                            {t('weeklies.client.open_report')}
                        </Link>
                    ) : null}
                </div>
                {data.latest ? (
                    <div className="border bg-card p-4">
                        <ClientReportCard
                            update={data.latest.update}
                            anchor="client-latest-update"
                        />
                    </div>
                ) : (
                    <EmptyState
                        icon={CalendarDays}
                        title={t('weeklies.client.latest_empty')}
                        description={t(
                            'weeklies.client.latest_empty_description',
                        )}
                    />
                )}
            </section>
        </div>
    );
}

/** Pestaña «Historial»: la línea de tiempo semanal (F-130). */
export function ClientHistoryPanel({ data }: { data: ClientWeeklyHistoryTab }) {
    if (data.weeks.length === 0) {
        return (
            <EmptyState
                icon={History}
                title={t('weeklies.client.history_empty')}
                description={t('weeklies.client.history_description')}
            />
        );
    }

    return (
        <section aria-labelledby="client-history-title" className="grid gap-4">
            <div className="space-y-1">
                <h2 id="client-history-title" className="text-lg">
                    {t('weeklies.client.history_title')}
                </h2>
                <p className="text-sm text-muted-foreground">
                    {t('weeklies.client.history_description')}
                </p>
            </div>
            <ol className="grid gap-3 border-l pl-4">
                {data.weeks.map((week) => (
                    <li
                        key={week.cycle.id}
                        className="relative grid gap-2 border bg-card p-4"
                        data-test="client-history-week"
                    >
                        <span
                            aria-hidden="true"
                            className="absolute top-5 -left-[1.4rem] size-2.5 bg-primary"
                        />
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <h3 className="text-base">
                                <Link
                                    href={showCycle.url(week.cycle.id)}
                                    className={cn(
                                        'hover:underline',
                                        FOCUS_RING,
                                    )}
                                >
                                    {week.cycle.label}
                                </Link>
                            </h3>
                            {week.update ? (
                                <ClientStatusBadge
                                    status={week.update.status}
                                />
                            ) : null}
                        </div>
                        {week.update ? (
                            <div className="grid gap-2 text-sm">
                                <p className="whitespace-pre-line">
                                    {week.update.executive_summary}
                                </p>
                                {week.update.next_steps.length > 0 ? (
                                    <ul className="list-disc pl-5 text-muted-foreground">
                                        {week.update.next_steps.map(
                                            (step, index) => (
                                                <li key={index}>{step}</li>
                                            ),
                                        )}
                                    </ul>
                                ) : null}
                                {week.update.milestones.length > 0 ? (
                                    <ul className="flex flex-wrap gap-1.5 text-xs">
                                        {week.update.milestones.map(
                                            (milestone, index) => (
                                                <li
                                                    key={index}
                                                    className="border bg-muted px-1.5 py-0.5"
                                                >
                                                    {milestone.date
                                                        ? `${milestone.date} · `
                                                        : ''}
                                                    {milestone.label}
                                                </li>
                                            ),
                                        )}
                                    </ul>
                                ) : null}
                            </div>
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                {t('weeklies.client.history_no_update')}
                            </p>
                        )}
                        {week.entries.length > 0 ? (
                            <details className="group text-sm">
                                <summary
                                    className={cn(
                                        'cursor-pointer text-primary-text',
                                        FOCUS_RING,
                                    )}
                                >
                                    {t('weeklies.client.history_reports', {
                                        count: week.entries.length,
                                    })}
                                </summary>
                                <ul className="mt-2 grid gap-2">
                                    {week.entries.map((entry) => (
                                        <li
                                            key={entry.id}
                                            className="grid gap-1 bg-muted p-3"
                                        >
                                            <p className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                                <span className="font-medium text-foreground">
                                                    {entry.author?.name ?? '—'}
                                                </span>
                                                <span>
                                                    {formatDateTime(
                                                        entry.submitted_at,
                                                    )}
                                                </span>
                                                {entry.project ? (
                                                    <Link
                                                        href={showProject.url(
                                                            entry.project.id,
                                                        )}
                                                        className={cn(
                                                            'tabular border px-1 hover:underline',
                                                            FOCUS_RING,
                                                        )}
                                                    >
                                                        {entry.project.code}
                                                    </Link>
                                                ) : null}
                                            </p>
                                            <p className="whitespace-pre-line">
                                                {entry.body}
                                            </p>
                                        </li>
                                    ))}
                                </ul>
                            </details>
                        ) : null}
                    </li>
                ))}
            </ol>
        </section>
    );
}

function MemberHistoryDialog({
    member,
    clientName,
    open,
    onOpenChange,
}: {
    member: ClientTeamMember;
    clientName: string;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[85vh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>
                        {t('weeklies.client.team_history_title', {
                            name: member.user.name,
                        })}
                    </DialogTitle>
                    <DialogDescription>
                        {t('weeklies.client.team_history_description', {
                            client: clientName,
                        })}
                    </DialogDescription>
                </DialogHeader>
                {member.reports.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {t('weeklies.client.team_history_empty')}
                    </p>
                ) : (
                    <ul className="grid gap-3">
                        {member.reports.map((report, index) => (
                            <li
                                key={`${report.cycle.id}-${index}`}
                                className="grid gap-1 border bg-muted p-3 text-sm"
                            >
                                <p className="flex flex-wrap justify-between gap-2 text-xs text-muted-foreground">
                                    <span className="font-medium text-foreground">
                                        {report.cycle.label}
                                    </span>
                                    <span>
                                        {formatDate(report.submitted_at)}
                                    </span>
                                </p>
                                <p className="whitespace-pre-line">
                                    {report.body}
                                </p>
                            </li>
                        ))}
                    </ul>
                )}
            </DialogContent>
        </Dialog>
    );
}

/**
 * Pestaña «Equipo» (F-131 y F-133): el responsable y los miembros con su puesto, sus proyectos y su
 * último reporte, el histórico de cada uno en el cliente, «Analizar actividad del equipo» (IA) y
 * unirse a sus proyectos o dejarlos.
 */
export function ClientTeamPanel({
    clientId,
    clientName,
    data,
}: {
    clientId: number;
    clientName: string;
    data: ClientWeeklyTeamTab;
}) {
    const [historyOf, setHistoryOf] = useState<ClientTeamMember | null>(null);
    const [processing, setProcessing] = useState(false);
    const subscribed = data.subscription.subscribed;
    const options = {
        preserveScroll: true,
        only: ['weekly'],
        onStart: () => setProcessing(true),
        onFinish: () => setProcessing(false),
    };
    const activity = data.ai?.state === 'done' ? data.ai.items : null;

    return (
        <div className="grid min-w-0 gap-6">
            <section
                aria-labelledby="client-team-title"
                className="grid gap-3"
                data-test="client-team"
            >
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="space-y-1">
                        <h2
                            id="client-team-title"
                            className="flex items-center gap-2 text-lg"
                        >
                            <Users aria-hidden="true" className="size-4" />
                            {t('weeklies.client.team_title')}
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            {t('weeklies.client.team_description')}
                        </p>
                    </div>
                </div>
                {data.members.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {t('weeklies.client.team_empty')}
                    </p>
                ) : (
                    <ul className="grid gap-3 md:grid-cols-2">
                        {data.members.map((member) => (
                            <li
                                key={member.user.id}
                                className="grid content-start gap-2 border bg-card p-3"
                                data-test="client-team-member"
                            >
                                <div className="flex items-start gap-3">
                                    <UserAvatar
                                        user={member.user}
                                        className="size-9"
                                    />
                                    <div className="min-w-0 flex-1 space-y-0.5">
                                        <p className="flex flex-wrap items-center gap-2">
                                            <Link
                                                href={showPerson.url(
                                                    member.user.id,
                                                )}
                                                className={cn(
                                                    'font-medium hover:underline',
                                                    FOCUS_RING,
                                                )}
                                            >
                                                {member.user.name}
                                            </Link>
                                            {member.role === 'owner' ? (
                                                <span className="bg-accent px-1.5 py-0.5 text-xs">
                                                    {t(
                                                        'weeklies.client.team_role_owner',
                                                    )}
                                                </span>
                                            ) : null}
                                        </p>
                                        {member.job_title ? (
                                            <p className="text-xs text-muted-foreground">
                                                {member.job_title}
                                            </p>
                                        ) : null}
                                        <p className="text-xs text-muted-foreground">
                                            {member.last_report_at
                                                ? t(
                                                      'weeklies.client.team_last_report',
                                                      {
                                                          date: formatDate(
                                                              member.last_report_at,
                                                          ),
                                                      },
                                                  )
                                                : t(
                                                      'weeklies.client.team_no_reports',
                                                  )}
                                        </p>
                                    </div>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => setHistoryOf(member)}
                                        data-test="client-team-history"
                                    >
                                        <History aria-hidden="true" />
                                        {t('weeklies.client.team_history')}
                                    </Button>
                                </div>
                                {member.projects.length > 0 ? (
                                    <ul className="flex flex-wrap gap-1 pl-12">
                                        {member.projects.map((project) => (
                                            <li
                                                key={project.id}
                                                className="tabular border bg-muted px-1.5 py-0.5 text-xs"
                                            >
                                                {project.code}
                                            </li>
                                        ))}
                                    </ul>
                                ) : null}
                                {activity?.[String(member.user.id)] ? (
                                    <p
                                        className="border-l-2 border-primary pl-2 text-sm"
                                        data-test="client-team-activity"
                                    >
                                        {activity[String(member.user.id)]}
                                    </p>
                                ) : null}
                            </li>
                        ))}
                    </ul>
                )}
            </section>

            <AiSummaryPanel
                title={t('weeklies.ai.analyze_team')}
                description={t('weeklies.client.team_description')}
                summary={data.ai}
                action={teamActivity.url(clientId)}
                reloadProp="weekly"
                generateLabel={t('weeklies.ai.analyze_team')}
                testId="client-team-ai"
            >
                <p className="text-sm text-muted-foreground">
                    {t('weeklies.client.team_activity_hint')}
                </p>
            </AiSummaryPanel>

            <section
                aria-labelledby="client-my-projects"
                className="grid gap-3 border bg-card p-4"
                data-test="client-my-projects"
            >
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h2 id="client-my-projects" className="text-base">
                        {t('weeklies.client.my_projects')}
                    </h2>
                    {subscribed || data.subscription.can_join ? (
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            disabled={processing}
                            aria-pressed={subscribed}
                            data-test="client-join"
                            onClick={() =>
                                subscribed
                                    ? router.delete(
                                          leaveClient.url(clientId),
                                          options,
                                      )
                                    : router.post(
                                          joinClients.url(),
                                          { client_ids: [clientId] },
                                          options,
                                      )
                            }
                        >
                            {subscribed ? (
                                <UserMinus aria-hidden="true" />
                            ) : (
                                <Plus aria-hidden="true" />
                            )}
                            {subscribed
                                ? t('weeklies.client.leave_client')
                                : t('weeklies.client.join')}
                        </Button>
                    ) : null}
                </div>
                <p className="text-sm text-muted-foreground">
                    {t('weeklies.client.join_hint')}
                </p>
                {data.my_projects.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {t('weeklies.client.my_projects_empty')}
                    </p>
                ) : (
                    <ul className="flex flex-wrap gap-1.5">
                        {data.my_projects.map((project) => (
                            <li
                                key={project.id}
                                className="inline-flex items-center gap-1 border bg-muted px-1.5 py-0.5 text-xs"
                            >
                                <Link
                                    href={showProject.url(project.id)}
                                    className={cn(
                                        'hover:underline',
                                        FOCUS_RING,
                                    )}
                                    title={project.name}
                                >
                                    {project.code}
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </section>

            {historyOf ? (
                <MemberHistoryDialog
                    member={historyOf}
                    clientName={clientName}
                    open
                    onOpenChange={(open) => !open && setHistoryOf(null)}
                />
            ) : null}
        </div>
    );
}

/** Pestaña «Satisfacción» (F-132): actual, semanas, tendencias y la gráfica. */
export function ClientSatisfactionPanel({
    clientName,
    data,
}: {
    clientName: string;
    data: ClientWeeklySatisfactionTab;
}) {
    const tiles: { label: string; value: string; delta?: number | null }[] = [
        {
            label: t('weeklies.client.satisfaction_current'),
            value: t('weeklies.client.satisfaction_value', {
                score: data.points.at(-1)?.score ?? data.score,
            }),
        },
        {
            label: t('weeklies.client.satisfaction_weeks'),
            value: String(data.points.length),
        },
        {
            label: t('weeklies.client.trend_weekly'),
            value: formatPoints(data.deltas.weekly),
            delta: data.deltas.weekly,
        },
        {
            label: t('weeklies.client.trend_monthly'),
            value: formatPoints(data.deltas.monthly),
            delta: data.deltas.monthly,
        },
        {
            label: t('weeklies.client.trend_quarterly'),
            value: formatPoints(data.deltas.quarterly),
            delta: data.deltas.quarterly,
        },
    ];

    return (
        <div className="grid min-w-0 gap-6" data-test="client-satisfaction-tab">
            <dl className="grid grid-cols-2 gap-3 md:grid-cols-5">
                {tiles.map((tile) => (
                    <div
                        key={tile.label}
                        className="grid gap-0.5 border bg-card p-3"
                    >
                        <dt className="text-xs text-muted-foreground">
                            {tile.label}
                        </dt>
                        <dd
                            className={cn(
                                'tabular text-xl',
                                tile.delta !== undefined &&
                                    tile.delta !== null &&
                                    Math.round(tile.delta) > 0 &&
                                    'text-success',
                                tile.delta !== undefined &&
                                    tile.delta !== null &&
                                    Math.round(tile.delta) < 0 &&
                                    'text-danger',
                            )}
                        >
                            {tile.value}
                        </dd>
                    </div>
                ))}
            </dl>
            {data.points.length === 0 ? (
                <EmptyState
                    icon={Smile}
                    title={t('weeklies.client.satisfaction_empty')}
                    description={t(
                        'weeklies.client.satisfaction_empty_description',
                    )}
                />
            ) : (
                <div className="border bg-card p-4">
                    <SatisfactionChart
                        clientName={clientName}
                        points={data.points}
                    />
                </div>
            )}
        </div>
    );
}
