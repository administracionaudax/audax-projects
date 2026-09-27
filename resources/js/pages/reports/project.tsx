import { Head, Link, setLayoutProps } from '@inertiajs/react';
import { Building2, ChartColumn, FolderKanban, Target } from 'lucide-react';
import { ProjectStatusBadge } from '@/components/domain/badges';
import { EmptyState } from '@/components/empty-state';
import { PageHeader } from '@/components/projects-list/page-header';
import { PageSection } from '@/components/projects-list/page-section';
import { ExportMenu } from '@/components/reports/export-menu';
import { R2BreakdownTable } from '@/components/reports/r2-breakdown-table';
import {
    R2EstimateByType,
    R2EstimateTable,
} from '@/components/reports/r2-estimate-table';
import { bucketLabel } from '@/components/reports/r2-helpers';
import { R2HoursBars } from '@/components/reports/r2-hours-bars';
import {
    R2Milestones,
    R2TaskStatus,
} from '@/components/reports/r2-project-status';
import { R2ReportBody, R2ScopeNote } from '@/components/reports/r2-report-body';
import { R2StackedBarsChart } from '@/components/reports/r2-stacked-bars-chart';
import { R2SummaryKpis } from '@/components/reports/r2-summary-kpis';
import type { R2ProjectReportProps } from '@/components/reports/r2-types';
import { ReportFilterBar } from '@/components/reports/report-filter-bar';
import { Button } from '@/components/ui/button';
import { formatDate, formatMinutes } from '@/lib/format';
import { t } from '@/lib/i18n';
import { urls } from '@/lib/urls';
import {
    client as clientReport,
    project as projectReport,
} from '@/routes/reports';
import type { ReportFilterKey } from '@/types';

/** El proyecto es fijo (la URL): sin filtros de cliente ni de proyecto. */
const FILTERS: ReportFilterKey[] = [
    'persona',
    'departamento',
    'bolsa',
    'tipo',
    'facturable',
];

/**
 * Informe de un proyecto (SPEC §10.3): KPIs (con ingreso y rentabilidad si hay view-financials),
 * estimado frente a real por tarea y por tipo (regla de subtareas del SPEC §6), horas por persona,
 * por tipo de tarea y por semana, estado de las tareas e hitos. Lo ven admins, responsables y los
 * gestores del proyecto (D-044).
 */
export default function ProjectReport({
    project,
    filters,
    scope,
    summary,
    comparison,
    byPerson,
    byType,
    weekly,
    estimates,
    tasks,
    milestones,
}: R2ProjectReportProps) {
    const financials = filters.can_see_financials;
    const url = projectReport.url(project.id);
    const exportHref = (table: string) =>
        projectReport.url(project.id, {
            query: { ...filters.query, tabla: table },
        });
    const hasBanks = project.billing_type === 'hour_bank';
    const noHours = summary.logged_minutes === 0;

    setLayoutProps({
        breadcrumbs: [
            { title: t('nav.projects'), href: urls.projects() },
            { title: project.name, href: urls.project(project.id) },
            { title: t('reports_r2.project.breadcrumb'), href: url },
        ],
    });

    return (
        <>
            <Head
                title={t('reports_r2.project.title', {
                    project: project.name,
                })}
            />

            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={t('reports_r2.project.heading', {
                        code: project.code,
                        project: project.name,
                    })}
                    description={t('reports_r2.project.description', {
                        from: formatDate(filters.from),
                        to: formatDate(filters.to),
                    })}
                    actions={
                        <>
                            <Button variant="outline" asChild>
                                <Link href={urls.project(project.id)}>
                                    <FolderKanban aria-hidden="true" />
                                    {t('reports_r2.project.open_project')}
                                </Link>
                            </Button>
                            {project.client ? (
                                <Button variant="outline" asChild>
                                    <Link
                                        href={clientReport.url(
                                            project.client.id,
                                            { query: filters.query },
                                        )}
                                    >
                                        <Building2 aria-hidden="true" />
                                        {t('reports_r2.project.client_report', {
                                            client: project.client.name,
                                        })}
                                    </Link>
                                </Button>
                            ) : null}
                        </>
                    }
                />

                <div className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                    <ProjectStatusBadge status={project.status} />
                    <span>
                        {t(`project.billing_type.${project.billing_type}`)}
                    </span>
                    {project.client ? (
                        <span>· {project.client.name}</span>
                    ) : null}
                    {project.budget_minutes !== null ? (
                        <span className="tabular">
                            ·{' '}
                            {t('reports_r2.project.budget', {
                                hours: formatMinutes(project.budget_minutes),
                            })}
                        </span>
                    ) : null}
                </div>

                <ReportFilterBar filters={filters} show={FILTERS} url={url} />

                {scope.team_only ? (
                    <R2ScopeNote>
                        {t('reports_r2.project.scope_team')}
                    </R2ScopeNote>
                ) : null}

                <R2ReportBody>
                    <R2SummaryKpis
                        summary={summary}
                        comparison={comparison}
                        financials={financials}
                        inBankMinutes={
                            hasBanks ? summary.in_bank_minutes : null
                        }
                        estimation
                    />

                    <PageSection
                        title={t('reports_r2.project.estimates')}
                        description={t(
                            'reports_r2.project.estimates_description',
                        )}
                        action={
                            <ExportMenu
                                href={exportHref('tareas')}
                                label={t('reports_r2.project.export_estimates')}
                            />
                        }
                    >
                        {estimates.tasks.length === 0 ? (
                            <EmptyState
                                icon={Target}
                                title={t('reports_r2.project.no_tasks')}
                                description={t(
                                    'reports_r2.project.no_tasks_description',
                                )}
                            />
                        ) : (
                            <R2EstimateTable
                                projectId={project.id}
                                estimates={estimates}
                            />
                        )}
                    </PageSection>

                    {estimates.by_type.length > 0 ? (
                        <PageSection
                            title={t('reports_r2.project.estimates_by_type')}
                            action={
                                <ExportMenu
                                    href={exportHref('estimado-por-tipo')}
                                    label={t(
                                        'reports_r2.project.export_estimates_by_type',
                                    )}
                                />
                            }
                        >
                            <R2EstimateByType rows={estimates.by_type} />
                        </PageSection>
                    ) : null}

                    <PageSection
                        title={t('reports_r2.project.by_person')}
                        description={t(
                            'reports_r2.project.by_person_description',
                        )}
                        action={
                            <ExportMenu
                                href={exportHref('personas')}
                                label={t('reports_r2.project.export_people')}
                            />
                        }
                    >
                        {byPerson.length === 0 ? (
                            <EmptyState
                                icon={ChartColumn}
                                title={t('reports_r2.empty.title')}
                                description={t('reports_r2.empty.description')}
                            />
                        ) : (
                            <div className="grid gap-4">
                                <R2HoursBars
                                    title={t('reports_r2.project.by_person')}
                                    firstColumn={t('reports_r2.column.person')}
                                    rows={byPerson}
                                />
                                <R2BreakdownTable
                                    caption={t(
                                        'reports_r2.project.by_person_table',
                                    )}
                                    firstColumn={t('reports_r2.column.person')}
                                    rows={byPerson}
                                    financials={financials}
                                    showBank={hasBanks}
                                />
                            </div>
                        )}
                    </PageSection>

                    <PageSection
                        title={t('reports_r2.project.by_type')}
                        action={
                            <ExportMenu
                                href={exportHref('tipos')}
                                label={t('reports_r2.project.export_types')}
                            />
                        }
                    >
                        {byType.length === 0 ? (
                            <EmptyState
                                icon={ChartColumn}
                                title={t('reports_r2.empty.title')}
                                description={t('reports_r2.empty.description')}
                            />
                        ) : (
                            <div className="grid gap-4">
                                <R2HoursBars
                                    title={t('reports_r2.project.by_type')}
                                    firstColumn={t('reports_r2.column.type')}
                                    rows={byType}
                                />
                                <R2BreakdownTable
                                    caption={t(
                                        'reports_r2.project.by_type_table',
                                    )}
                                    firstColumn={t('reports_r2.column.type')}
                                    rows={byType}
                                    financials={financials}
                                    showBank={hasBanks}
                                />
                            </div>
                        )}
                    </PageSection>

                    <PageSection
                        title={t('reports_r2.project.weekly')}
                        action={
                            <ExportMenu
                                href={exportHref('semanas')}
                                label={t('reports_r2.project.export_weeks')}
                            />
                        }
                    >
                        {noHours ? (
                            <EmptyState
                                icon={ChartColumn}
                                title={t('reports_r2.empty.title')}
                                description={t('reports_r2.empty.description')}
                            />
                        ) : (
                            <R2StackedBarsChart
                                title={t('reports_r2.project.weekly_chart')}
                                description={t(
                                    'reports_r2.project.weekly_description',
                                )}
                                bucketColumn={t('reports_r2.column.week')}
                                buckets={weekly.map((week) => ({
                                    id: week.week,
                                    label: bucketLabel(week.week, 'semana'),
                                    longLabel: t('reports_r2.week_of', {
                                        date: formatDate(week.week),
                                    }),
                                }))}
                                series={[
                                    {
                                        key: 'billable',
                                        label: t('reports_r2.chart.billable'),
                                    },
                                    {
                                        key: 'non_billable',
                                        label: t(
                                            'reports_r2.chart.non_billable',
                                        ),
                                    },
                                ]}
                                values={{
                                    billable: Object.fromEntries(
                                        weekly.map((week) => [
                                            week.week,
                                            week.billable_minutes,
                                        ]),
                                    ),
                                    non_billable: Object.fromEntries(
                                        weekly.map((week) => [
                                            week.week,
                                            week.logged_minutes -
                                                week.billable_minutes,
                                        ]),
                                    ),
                                }}
                            />
                        )}
                    </PageSection>

                    <div className="grid gap-8 xl:grid-cols-2">
                        <PageSection
                            title={t('reports_r2.project.task_status')}
                            description={t(
                                'reports_r2.project.task_status_description',
                            )}
                        >
                            <R2TaskStatus tasks={tasks} />
                        </PageSection>

                        <PageSection title={t('reports_r2.project.milestones')}>
                            <R2Milestones milestones={milestones} />
                        </PageSection>
                    </div>
                </R2ReportBody>
            </div>
        </>
    );
}

ProjectReport.layout = {
    breadcrumbs: [{ title: t('nav.projects'), href: urls.projects() }],
};
