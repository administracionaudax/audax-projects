import { Head, Link, setLayoutProps } from '@inertiajs/react';
import {
    CalendarDays,
    ChartColumn,
    Clock,
    Crown,
    ListChecks,
    Milestone,
    ShieldCheck,
    Target,
    TriangleAlert,
    Wallet,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { ProjectStatusBadge } from '@/components/domain/badges';
import { EmptyState } from '@/components/empty-state';
import { useHourBankThresholds } from '@/components/hour-banks/hour-bank-actions';
import { HourBankCard } from '@/components/hour-banks/hour-bank-card';
import { ProjectShell } from '@/components/projects/project-shell';
import { PageSection } from '@/components/projects-list/page-section';
import { ProjectActivity } from '@/components/projects-list/project-activity';
import { Button } from '@/components/ui/button';
import { hasRole, useUser } from '@/hooks/use-auth';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatDate, formatMinutes, formatPercent } from '@/lib/format';
import { t } from '@/lib/i18n';
import { urls } from '@/lib/urls';
import { cn } from '@/lib/utils';
import { index, show } from '@/routes/projects';
import { project as projectReport } from '@/routes/reports';
import type { ProjectShowProps } from '@/types';

/**
 * Resumen del proyecto (SPEC §6): estado, fechas, horas estimadas frente a reales, presupuesto,
 * bolsas con su consumo y comprometidas, equipo, actividad reciente y próximos hitos (Fase 4).
 */
export default function ProjectShow({
    project,
    canManage,
    summary,
    managers,
    membersCount,
    hourBanks,
    activity,
}: ProjectShowProps) {
    setLayoutProps({
        breadcrumbs: [
            { title: t('nav.projects'), href: index() },
            { title: project.name, href: show(project.id) },
        ],
    });

    const thresholds = useHourBankThresholds();
    const owner = managers.find(
        (manager) => manager.id === project.owner_user_id,
    );
    const coManagers = managers.filter(
        (manager) => manager.id !== project.owner_user_id,
    );
    // Informe del proyecto (Fase 2, R2): los que ven todas sus horas (ProjectPolicy::viewAllTime).
    const user = useUser();
    const canViewReport =
        hasRole(user, 'admin') ||
        hasRole(user, 'department_manager') ||
        managers.some((manager) => manager.id === user?.id);

    return (
        <>
            <Head title={project.name} />

            <ProjectShell project={project} tab="resumen" canManage={canManage}>
                <div className="grid gap-6 lg:grid-cols-3">
                    <div className="grid content-start gap-6 lg:col-span-2">
                        {canViewReport ? (
                            <div className="flex flex-wrap justify-end">
                                <Button variant="outline" asChild>
                                    <Link href={projectReport.url(project.id)}>
                                        <ChartColumn aria-hidden="true" />
                                        {t('reports_r2.link.project_report')}
                                    </Link>
                                </Button>
                            </div>
                        ) : null}
                        <dl className="grid gap-3 sm:grid-cols-2 2xl:grid-cols-4">
                            <Figure
                                icon={Target}
                                label={t('projects.show.status')}
                            >
                                <ProjectStatusBadge status={project.status} />
                                <span className="block text-xs text-muted-foreground">
                                    {t(
                                        `project.billing_type.${project.billing_type}`,
                                    )}
                                </span>
                            </Figure>
                            <Figure
                                icon={CalendarDays}
                                label={t('projects.show.dates')}
                            >
                                <span className="block text-sm">
                                    {project.start_date
                                        ? t('projects.table.start', {
                                              date: formatDate(
                                                  project.start_date,
                                              ),
                                          })
                                        : t('projects.show.no_start')}
                                </span>
                                <span className="block text-sm">
                                    {project.due_date
                                        ? t('projects.table.due', {
                                              date: formatDate(
                                                  project.due_date,
                                              ),
                                          })
                                        : t('projects.show.no_due')}
                                </span>
                            </Figure>
                            <Figure
                                icon={Clock}
                                label={t('projects.show.hours')}
                            >
                                <HoursFigure
                                    estimated={summary.estimated_minutes}
                                    logged={summary.logged_minutes}
                                />
                            </Figure>
                            <Figure
                                icon={ListChecks}
                                label={t('projects.show.tasks')}
                            >
                                <span className="tabular block text-2xl">
                                    {summary.open_tasks}
                                </span>
                                <span className="block text-xs text-muted-foreground">
                                    {t('projects.show.tasks_detail', {
                                        total: summary.total_tasks,
                                    })}
                                </span>
                            </Figure>
                        </dl>

                        {summary.budget_minutes !== null ? (
                            <BudgetFigure
                                budget={summary.budget_minutes}
                                logged={summary.logged_minutes}
                            />
                        ) : null}

                        {project.description ? (
                            <PageSection title={t('projects.show.description')}>
                                <p className="text-sm whitespace-pre-line">
                                    {project.description}
                                </p>
                            </PageSection>
                        ) : null}

                        {project.billing_type === 'hour_bank' ? (
                            <PageSection
                                title={t('projects.show.hour_banks')}
                                action={
                                    <Link
                                        href={urls.project(
                                            project.id,
                                            'bolsas',
                                        )}
                                        className={cn(
                                            'rounded-[3px] text-sm text-primary-text hover:underline',
                                            FOCUS_RING,
                                        )}
                                    >
                                        {t('projects.show.all_hour_banks')}
                                    </Link>
                                }
                            >
                                {hourBanks.length === 0 ? (
                                    <EmptyState
                                        icon={Wallet}
                                        title={t('projects.show.no_hour_banks')}
                                        description={t(
                                            'projects.show.no_hour_banks_description',
                                        )}
                                    />
                                ) : (
                                    <div className="grid gap-4 xl:grid-cols-2">
                                        {hourBanks.map((bank) => (
                                            <HourBankCard
                                                key={bank.id}
                                                projectId={project.id}
                                                bank={bank}
                                                headingLevel="h3"
                                                thresholds={thresholds}
                                            />
                                        ))}
                                    </div>
                                )}
                            </PageSection>
                        ) : null}

                        <PageSection title={t('projects.show.activity')}>
                            <ProjectActivity items={activity} />
                        </PageSection>
                    </div>

                    <div className="grid content-start gap-6">
                        <PageSection title={t('projects.show.team')}>
                            <ul className="grid gap-2 text-sm">
                                {owner ? (
                                    <li className="flex items-center gap-2">
                                        <Crown
                                            aria-hidden="true"
                                            className="size-4 text-info"
                                        />
                                        <span>
                                            {owner.name}
                                            <span className="block text-xs text-muted-foreground">
                                                {t('projects.members.owner')}
                                            </span>
                                        </span>
                                    </li>
                                ) : null}
                                {coManagers.map((manager) => (
                                    <li
                                        key={manager.id}
                                        className="flex items-center gap-2"
                                    >
                                        <ShieldCheck
                                            aria-hidden="true"
                                            className="size-4 text-muted-foreground"
                                        />
                                        <span>
                                            {manager.name}
                                            <span className="block text-xs text-muted-foreground">
                                                {t('projects.members.manager')}
                                            </span>
                                        </span>
                                    </li>
                                ))}
                            </ul>
                            <p className="text-sm text-muted-foreground">
                                {t('projects.show.members_count', {
                                    count: membersCount,
                                })}
                                {canManage ? (
                                    <>
                                        {' · '}
                                        <Link
                                            href={urls.project(
                                                project.id,
                                                'ajustes',
                                            )}
                                            className={cn(
                                                'rounded-[3px] text-primary-text hover:underline',
                                                FOCUS_RING,
                                            )}
                                        >
                                            {t('projects.show.manage_team')}
                                        </Link>
                                    </>
                                ) : null}
                            </p>
                        </PageSection>

                        <PageSection title={t('projects.show.milestones')}>
                            <EmptyState
                                icon={Milestone}
                                title={t('projects.show.milestones_soon')}
                                description={t(
                                    'projects.show.milestones_description',
                                )}
                                phase={4}
                            />
                        </PageSection>
                    </div>
                </div>
            </ProjectShell>
        </>
    );
}

ProjectShow.layout = {
    breadcrumbs: [{ title: t('nav.projects'), href: index() }],
};

function Figure({
    icon: Icon,
    label,
    children,
}: {
    icon: LucideIcon;
    label: string;
    children: ReactNode;
}) {
    return (
        <div className="grid content-start gap-2 rounded-md border bg-card p-4">
            <dt className="flex items-center gap-1.5 text-xs text-muted-foreground">
                <Icon aria-hidden="true" className="size-3.5" />
                {label}
            </dt>
            <dd className="grid gap-1">{children}</dd>
        </div>
    );
}

/** Horas reales frente a las estimadas (suma de las estimaciones efectivas de las tareas). */
function HoursFigure({
    estimated,
    logged,
}: {
    estimated: number;
    logged: number;
}) {
    const over = estimated > 0 && logged > estimated;

    return (
        <>
            <span className="tabular block text-2xl">
                {formatMinutes(logged)}
                <span className="text-base text-muted-foreground">
                    {' '}
                    / {formatMinutes(estimated)}
                </span>
            </span>
            <span className="block text-xs text-muted-foreground">
                {estimated > 0
                    ? t('projects.show.hours_detail', {
                          ratio: formatPercent(logged / estimated, 0),
                      })
                    : t('projects.show.hours_no_estimate')}
            </span>
            {over ? (
                <span className="inline-flex items-center gap-1 text-xs font-medium text-foreground">
                    <TriangleAlert
                        aria-hidden="true"
                        className="size-3.5 text-warning"
                    />
                    {t('projects.show.hours_over', {
                        minutes: formatMinutes(logged - estimated),
                    })}
                </span>
            ) : null}
        </>
    );
}

/** Presupuesto global de horas del proyecto (aparte de la estimación de las tareas). */
function BudgetFigure({ budget, logged }: { budget: number; logged: number }) {
    const ratio = budget > 0 ? logged / budget : 0;
    const over = logged > budget;

    return (
        <div className="grid gap-2 rounded-md border bg-card p-4">
            <p className="flex flex-wrap items-baseline justify-between gap-2 text-sm">
                <span className="text-muted-foreground">
                    {t('projects.show.budget')}
                </span>
                <span className="tabular">
                    {t('projects.show.budget_figures', {
                        logged: formatMinutes(logged),
                        budget: formatMinutes(budget),
                        ratio: formatPercent(ratio, 0),
                    })}
                </span>
            </p>
            <div
                role="meter"
                aria-label={t('projects.show.budget')}
                aria-valuemin={0}
                aria-valuemax={budget}
                aria-valuenow={Math.min(logged, budget)}
                aria-valuetext={t('projects.show.budget_figures', {
                    logged: formatMinutes(logged),
                    budget: formatMinutes(budget),
                    ratio: formatPercent(ratio, 0),
                })}
                className="h-2 w-full rounded-[3px] bg-neutral-soft"
            >
                <div
                    className={cn(
                        'h-full rounded-[3px]',
                        over ? 'bg-danger' : 'bg-primary',
                    )}
                    style={{ width: `${Math.min(ratio, 1) * 100}%` }}
                />
            </div>
            {over ? (
                <p className="inline-flex items-center gap-1 text-xs font-medium text-danger">
                    <TriangleAlert aria-hidden="true" className="size-3.5" />
                    {t('projects.show.budget_over', {
                        minutes: formatMinutes(logged - budget),
                    })}
                </p>
            ) : null}
        </div>
    );
}
