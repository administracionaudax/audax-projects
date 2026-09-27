import { Head, Link, setLayoutProps } from '@inertiajs/react';
import { Info, SearchX } from 'lucide-react';
import { defineSeries } from '@/components/charts/chart-config';
import { EmptyState } from '@/components/empty-state';
import { PageHeader } from '@/components/projects-list/page-header';
import { PageSection } from '@/components/projects-list/page-section';
import { ExportMenu } from '@/components/reports/export-menu';
import { R1AtRiskBanks } from '@/components/reports/r1-at-risk-banks';
import { R1BarChart } from '@/components/reports/r1-bar-chart';
import {
    breakdownBars,
    R1BreakdownTable,
} from '@/components/reports/r1-breakdown-table';
import { R1KpiGrid } from '@/components/reports/r1-kpi-grid';
import { R1OverdueTasks } from '@/components/reports/r1-overdue-tasks';
import {
    ReportContent,
    useReportVisit,
} from '@/components/reports/r1-report-state';
import {
    R1HoursTrendChart,
    R1IncomeTrendChart,
} from '@/components/reports/r1-trend-chart';
import type { DirectionReportProps } from '@/components/reports/r1-types';
import { periodQuery, reportUrls } from '@/components/reports/r1-urls';
import { ReportFilterBar } from '@/components/reports/report-filter-bar';
import { FOCUS_RING } from '@/lib/focus-ring';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { direction, index } from '@/routes/reports';

/** Una sola serie (horas imputadas) → --chart-1. */
const LOGGED_SERIES = defineSeries([
    { key: 'logged', label: t('reports_r1.evolution.logged') },
] as const);

/**
 * Dashboard de dirección (SPEC §10.1, D-044): KPIs con variación, evolución, reparto por
 * departamento y cliente, top 10 de clientes y proyectos, bolsas en riesgo y tareas vencidas. Un
 * responsable lo ve limitado a sus departamentos. Datos económicos solo con view-financials.
 */
export default function DirectionReport({
    filters,
    limited_to: limitedTo,
    summary,
    comparison,
    comparison_partial: comparisonPartial,
    series,
    departments,
    clients,
    projects,
    at_risk: atRisk,
    overdue,
}: DirectionReportProps) {
    const state = useReportVisit();
    const financials = filters.can_see_financials;
    const period = periodQuery(filters.query);
    const exportHref = (tabla: string) =>
        reportUrls.direction({ ...filters.query, tabla });
    const departmentBars = departments.map((row) => ({
        id: row.key ?? 'none',
        label: row.name,
        values: { logged: row.logged_minutes },
    }));

    setLayoutProps({
        breadcrumbs: [
            { title: t('nav.reports'), href: index() },
            { title: t('reports_r1.direction.title'), href: direction() },
        ],
    });

    return (
        <>
            <Head title={t('reports_r1.direction.title')} />

            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={t('reports_r1.direction.heading')}
                    description={t('reports_r1.direction.description')}
                />

                {limitedTo !== null ? (
                    <p
                        className="flex items-start gap-2 rounded-md border bg-info-soft px-3 py-2 text-sm text-foreground"
                        data-test="r1-limited"
                    >
                        <Info
                            aria-hidden="true"
                            className="mt-0.5 size-4 shrink-0 text-info"
                        />
                        {t('reports_r1.direction.limited', {
                            departments: limitedTo.join(', '),
                        })}
                    </p>
                ) : null}

                <ReportFilterBar filters={filters} url={direction.url()} />

                <ReportContent state={state}>
                    <PageSection title={t('reports_r1.sections.kpis')}>
                        <R1KpiGrid
                            summary={summary}
                            comparison={comparison}
                            comparisonPartial={comparisonPartial}
                            loading={state.loading}
                            kpis={[
                                'logged',
                                'capacity',
                                'occupancy',
                                'billability',
                                'billable_productivity',
                                'estimation',
                                'income',
                                'margin',
                            ]}
                        />
                    </PageSection>

                    <PageSection
                        title={t('reports_r1.sections.evolution')}
                        description={t(
                            series.bucket === 'semana'
                                ? 'reports_r1.evolution.weekly'
                                : 'reports_r1.evolution.monthly',
                        )}
                    >
                        <div className="grid gap-6 rounded-md border bg-card p-4">
                            <R1HoursTrendChart
                                points={series.points}
                                bucket={series.bucket}
                                title={t('reports_r1.evolution.hours_title')}
                            />
                            {financials ? (
                                <R1IncomeTrendChart
                                    points={series.points}
                                    bucket={series.bucket}
                                    total={summary.income}
                                    title={t(
                                        'reports_r1.evolution.income_title',
                                    )}
                                />
                            ) : null}
                        </div>
                    </PageSection>

                    <div className="grid gap-8 xl:grid-cols-2">
                        <PageSection
                            title={t('reports_r1.direction.by_department')}
                            action={
                                <ExportMenu
                                    href={exportHref('departamentos')}
                                    label={t('reports_r1.export')}
                                />
                            }
                        >
                            {departments.length === 0 ? (
                                <EmptyState
                                    icon={SearchX}
                                    title={t('reports_r1.no_hours')}
                                />
                            ) : (
                                <div className="grid gap-3 rounded-md border bg-card p-4">
                                    <R1BarChart
                                        title={t(
                                            'reports_r1.direction.by_department_chart',
                                        )}
                                        categoryLabel={t(
                                            'reports_r1.columns.department',
                                        )}
                                        rows={departmentBars}
                                        series={LOGGED_SERIES}
                                        format="minutes"
                                    />
                                    <DepartmentLinks
                                        departments={departments}
                                        query={period}
                                    />
                                </div>
                            )}
                        </PageSection>

                        <PageSection
                            title={t('reports_r1.direction.by_client')}
                        >
                            {clients.rows.length === 0 ? (
                                <EmptyState
                                    icon={SearchX}
                                    title={t('reports_r1.no_hours')}
                                />
                            ) : (
                                <div className="rounded-md border bg-card p-4">
                                    <R1BarChart
                                        title={t(
                                            'reports_r1.direction.by_client_chart',
                                        )}
                                        categoryLabel={t(
                                            'reports_r1.columns.client',
                                        )}
                                        rows={breakdownBars(clients)}
                                        series={LOGGED_SERIES}
                                        format="minutes"
                                    />
                                </div>
                            )}
                        </PageSection>
                    </div>

                    <div className="grid gap-8 xl:grid-cols-2">
                        <PageSection
                            title={t('reports_r1.direction.top_clients')}
                            action={
                                <ExportMenu
                                    href={exportHref('clientes')}
                                    label={t('reports_r1.export')}
                                />
                            }
                        >
                            <R1BreakdownTable
                                caption={t('reports_r1.direction.top_clients')}
                                nameLabel={t('reports_r1.columns.client')}
                                top={clients}
                                totalMinutes={summary.logged_minutes}
                                financials={financials}
                                emptyLabel={t('reports_r1.no_hours')}
                                href={(row) =>
                                    row.key === null
                                        ? null
                                        : reportUrls.client(
                                              Number(row.key),
                                              period,
                                          )
                                }
                            />
                        </PageSection>

                        <PageSection
                            title={t('reports_r1.direction.top_projects')}
                            action={
                                <ExportMenu
                                    href={exportHref('proyectos')}
                                    label={t('reports_r1.export')}
                                />
                            }
                        >
                            <R1BreakdownTable
                                caption={t('reports_r1.direction.top_projects')}
                                nameLabel={t('reports_r1.columns.project')}
                                top={projects}
                                totalMinutes={summary.logged_minutes}
                                financials={financials}
                                emptyLabel={t('reports_r1.no_hours')}
                                href={(row) =>
                                    row.key === null
                                        ? null
                                        : reportUrls.project(
                                              Number(row.key),
                                              period,
                                          )
                                }
                            />
                        </PageSection>
                    </div>

                    <div className="grid gap-8 xl:grid-cols-2">
                        <PageSection
                            title={t('reports_r1.at_risk.title')}
                            description={t('reports_r1.at_risk.description', {
                                threshold: atRisk.threshold,
                            })}
                        >
                            <R1AtRiskBanks atRisk={atRisk} />
                        </PageSection>

                        <PageSection
                            title={t('reports_r1.overdue.title')}
                            description={t('reports_r1.overdue.description')}
                        >
                            <R1OverdueTasks overdue={overdue} />
                        </PageSection>
                    </div>
                </ReportContent>
            </div>
        </>
    );
}

function DepartmentLinks({
    departments,
    query,
}: {
    departments: DirectionReportProps['departments'];
    query: ReturnType<typeof periodQuery>;
}) {
    const linked = departments.filter((row) => row.key !== null);

    if (linked.length === 0) {
        return null;
    }

    return (
        <p className="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
            <span className="text-muted-foreground">
                {t('reports_r1.direction.department_reports')}
            </span>
            {linked.map((row) => (
                <Link
                    key={row.key}
                    href={reportUrls.department(Number(row.key), query)}
                    className={cn(
                        'rounded-sm text-primary-text hover:underline',
                        FOCUS_RING,
                    )}
                >
                    {row.name}
                </Link>
            ))}
        </p>
    );
}
