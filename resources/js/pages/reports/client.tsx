import { Head, Link, setLayoutProps } from '@inertiajs/react';
import { Building2, ChartColumn, Receipt } from 'lucide-react';
import { EmptyState } from '@/components/empty-state';
import { useHourBankThresholds } from '@/components/hour-banks/hour-bank-actions';
import { PageHeader } from '@/components/projects-list/page-header';
import { PageSection } from '@/components/projects-list/page-section';
import { ExportMenu } from '@/components/reports/export-menu';
import { withTable } from '@/components/reports/report-request';
import { R2BreakdownTable } from '@/components/reports/r2-breakdown-table';
import {
    R2BankList,
    R2RenewalHistory,
} from '@/components/reports/r2-client-banks';
import { bucketLabel, monthName } from '@/components/reports/r2-helpers';
import { R2ReportBody, R2ScopeNote } from '@/components/reports/r2-report-body';
import { R2StackedBarsChart } from '@/components/reports/r2-stacked-bars-chart';
import { R2SummaryKpis } from '@/components/reports/r2-summary-kpis';
import type { R2ClientReportProps } from '@/components/reports/r2-types';
import { ReportFilterBar } from '@/components/reports/report-filter-bar';
import { Button } from '@/components/ui/button';
import { useAbilities, useRequiredUser } from '@/hooks/use-auth';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatDate } from '@/lib/format';
import { t } from '@/lib/i18n';
import { urls } from '@/lib/urls';
import { cn } from '@/lib/utils';
import {
    billing,
    client as clientReport,
    project as projectReport,
} from '@/routes/reports';
import type { ReportFilterKey } from '@/types';

/** El cliente es fijo (la URL): la barra no ofrece el filtro de cliente. */
const FILTERS: ReportFilterKey[] = [
    'persona',
    'departamento',
    'proyecto',
    'bolsa',
    'tipo',
    'facturable',
];

/**
 * Informe de un cliente (SPEC §10.2): KPIs con rentabilidad (view-financials), horas por proyecto
 * y por mes (o semana), resumen por proyecto, bolsas con su consumo e histórico de renovaciones.
 * Admins y responsables ven todo el cliente; un gestor, solo sus proyectos (D-044).
 */
export default function ClientReport({
    client,
    filters,
    scope,
    summary,
    comparison,
    banked,
    projects,
    timeline,
    banks,
    history,
    report_request: reportRequest,
}: R2ClientReportProps) {
    const user = useRequiredUser();
    const can = useAbilities();
    const thresholds = useHourBankThresholds();
    const financials = filters.can_see_financials;
    const url = clientReport.url(client.id);
    const title = t('reports_r2.client.heading', { client: client.name });
    const canBill = user.roles.includes('admin') || can.viewFinancials;

    setLayoutProps({
        breadcrumbs: [
            { title: t('nav.clients'), href: urls.clients() },
            { title: client.name, href: urls.client(client.id) },
            { title: t('reports_r2.client.breadcrumb'), href: url },
        ],
    });

    const bucketKind = timeline.bucket;
    const empty = summary.logged_minutes === 0;

    return (
        <>
            <Head
                title={t('reports_r2.client.title', { client: client.name })}
            />

            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={title}
                    description={t('reports_r2.client.description', {
                        from: formatDate(filters.from),
                        to: formatDate(filters.to),
                    })}
                    actions={
                        <>
                            <ExportMenu request={reportRequest} title={title} />
                            <Button variant="outline" asChild>
                                <Link href={urls.client(client.id)}>
                                    <Building2 aria-hidden="true" />
                                    {t('reports_r2.client.open_client')}
                                </Link>
                            </Button>
                            {canBill ? (
                                <Button variant="outline" asChild>
                                    <Link
                                        href={billing.url({
                                            query: {
                                                ...filters.query,
                                                cliente: [client.id],
                                            },
                                        })}
                                    >
                                        <Receipt aria-hidden="true" />
                                        {t('reports_r2.client.billing')}
                                    </Link>
                                </Button>
                            ) : null}
                        </>
                    }
                />

                <ReportFilterBar filters={filters} show={FILTERS} url={url} />

                {scope.projects_only ? (
                    <R2ScopeNote>
                        {t('reports_r2.client.scope_projects')}
                    </R2ScopeNote>
                ) : scope.team_only ? (
                    <R2ScopeNote>
                        {t('reports_r2.client.scope_team')}
                    </R2ScopeNote>
                ) : null}

                <R2ReportBody>
                    <R2SummaryKpis
                        summary={summary}
                        comparison={comparison}
                        financials={financials}
                        inBankMinutes={
                            banked.has_bank ? banked.in_bank_minutes : null
                        }
                    />

                    <PageSection
                        title={t(
                            bucketKind === 'mes'
                                ? 'reports_r2.client.timeline_month'
                                : 'reports_r2.client.timeline_week',
                        )}
                        action={
                            <ExportMenu
                                request={withTable(reportRequest, 'meses')}
                                title={title}
                                label={t('reports_r2.client.export_timeline')}
                                scope="table"
                            />
                        }
                    >
                        {empty ? (
                            <EmptyState
                                icon={ChartColumn}
                                title={t('reports_r2.empty.title')}
                                description={t('reports_r2.empty.description')}
                            />
                        ) : (
                            <R2StackedBarsChart
                                title={t('reports_r2.client.chart_title')}
                                description={t(
                                    'reports_r2.client.chart_description',
                                )}
                                bucketColumn={t(
                                    bucketKind === 'mes'
                                        ? 'reports_r2.column.month'
                                        : 'reports_r2.column.week',
                                )}
                                buckets={timeline.buckets.map((bucket) => ({
                                    id: bucket,
                                    label: bucketLabel(bucket, bucketKind),
                                    longLabel:
                                        bucketKind === 'mes'
                                            ? monthName(bucket)
                                            : t('reports_r2.week_of', {
                                                  date: formatDate(bucket),
                                              }),
                                }))}
                                series={timeline.series.map((serie) => ({
                                    key: serie.key,
                                    label: serie.name,
                                }))}
                                values={timeline.cells}
                            />
                        )}
                    </PageSection>

                    <PageSection
                        title={t('reports_r2.client.projects')}
                        description={t(
                            'reports_r2.client.projects_description',
                        )}
                        action={
                            <ExportMenu
                                request={withTable(reportRequest, 'proyectos')}
                                title={title}
                                label={t('reports_r2.client.export_projects')}
                                scope="table"
                            />
                        }
                    >
                        {projects.length === 0 ? (
                            <EmptyState
                                icon={ChartColumn}
                                title={t('reports_r2.empty.title')}
                                description={t('reports_r2.empty.description')}
                            />
                        ) : (
                            <R2BreakdownTable
                                caption={t('reports_r2.client.projects')}
                                firstColumn={t('reports_r2.column.project')}
                                rows={projects}
                                total={summary}
                                financials={financials}
                                renderName={(row) =>
                                    row.key === null ? (
                                        row.name
                                    ) : (
                                        <Link
                                            href={projectReport.url(
                                                Number(row.key),
                                                { query: filters.query },
                                            )}
                                            className={cn(
                                                'rounded-md text-primary-text hover:underline',
                                                FOCUS_RING,
                                            )}
                                        >
                                            {row.name}
                                        </Link>
                                    )
                                }
                            />
                        )}
                    </PageSection>

                    <PageSection
                        title={t('reports_r2.client.banks')}
                        description={t('reports_r2.client.banks_description')}
                        action={
                            <ExportMenu
                                request={withTable(reportRequest, 'bolsas')}
                                title={title}
                                label={t('reports_r2.client.export_banks')}
                                scope="table"
                            />
                        }
                    >
                        <R2BankList banks={banks} thresholds={thresholds} />
                    </PageSection>

                    <PageSection
                        title={t('reports_r2.client.history')}
                        description={t('reports_r2.client.history_description')}
                    >
                        <R2RenewalHistory chains={history} />
                    </PageSection>
                </R2ReportBody>
            </div>
        </>
    );
}

ClientReport.layout = {
    breadcrumbs: [{ title: t('nav.clients'), href: urls.clients() }],
};
