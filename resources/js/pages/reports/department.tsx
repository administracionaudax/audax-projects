import { Head, setLayoutProps } from '@inertiajs/react';
import { CalendarClock, SearchX, Users } from 'lucide-react';
import { defineSeries } from '@/components/charts/chart-config';
import { EmptyState } from '@/components/empty-state';
import { PageHeader } from '@/components/projects-list/page-header';
import { PageSection } from '@/components/projects-list/page-section';
import { ExportMenu } from '@/components/reports/export-menu';
import { R1BarChart } from '@/components/reports/r1-bar-chart';
import {
    breakdownBars,
    R1BreakdownTable,
} from '@/components/reports/r1-breakdown-table';
import { inProgress, R1KpiGrid } from '@/components/reports/r1-kpi-grid';
import { R1MembersTable } from '@/components/reports/r1-members-table';
import {
    ReportContent,
    useReportVisit,
} from '@/components/reports/r1-report-state';
import type { DepartmentReportProps } from '@/components/reports/r1-types';
import { periodQuery, reportUrls } from '@/components/reports/r1-urls';
import { ReportFilterBar } from '@/components/reports/report-filter-bar';
import { t } from '@/lib/i18n';
import { department as departmentRoute, index } from '@/routes/reports';

/**
 * Ocupación → --chart-1 y facturabilidad → --chart-2 (orden fijo, D-012), en porcentaje.
 */
const MEMBER_SERIES = defineSeries([
    { key: 'occupancy', label: t('reports.metric.occupancy.label') },
    { key: 'billability', label: t('reports.metric.billability.label') },
] as const);

const LOGGED_SERIES = defineSeries([
    { key: 'logged', label: t('reports_r1.evolution.logged') },
] as const);

/**
 * Dashboard de un departamento (SPEC §10.4, D-044): KPIs del equipo, ocupación y facturabilidad
 * de cada miembro (tabla con estado y barras), reparto por cliente y, en la Fase 3, la carga
 * futura.
 */
export default function DepartmentReport({
    department,
    filters,
    summary,
    comparison,
    comparison_partial: comparisonPartial,
    members,
    clients,
    occupancy_thresholds: thresholds,
}: DepartmentReportProps) {
    const state = useReportVisit();
    const financials = filters.can_see_financials;
    const period = periodQuery(filters.query);
    const url = departmentRoute.url(department.id);

    setLayoutProps({
        breadcrumbs: [
            { title: t('nav.reports'), href: index() },
            { title: department.name, href: url },
        ],
    });

    return (
        <>
            <Head
                title={t('reports_r1.department.title', {
                    department: department.name,
                })}
            />

            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={t('reports_r1.department.heading', {
                        department: department.name,
                    })}
                    description={t('reports_r1.department.description')}
                />

                <ReportFilterBar
                    filters={filters}
                    url={url}
                    show={[
                        'persona',
                        'cliente',
                        'proyecto',
                        'bolsa',
                        'tipo',
                        'facturable',
                    ]}
                />

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
                        title={t('reports_r1.department.members')}
                        description={[
                            t('reports_r1.department.members_description', {
                                low: thresholds.low,
                                high: thresholds.high,
                            }),
                            inProgress(summary)
                                ? t('reports_r1.department.members_to_date')
                                : null,
                        ]
                            .filter(Boolean)
                            .join(' ')}
                        action={
                            <ExportMenu
                                href={reportUrls.department(
                                    department.id,
                                    filters.query,
                                )}
                                label={t('reports_r1.export')}
                            />
                        }
                    >
                        {members.length === 0 ? (
                            <EmptyState
                                icon={Users}
                                title={t('reports_r1.department.no_members')}
                            />
                        ) : (
                            <>
                                <R1MembersTable
                                    members={members}
                                    thresholds={thresholds}
                                    financials={financials}
                                    personHref={(member) =>
                                        reportUrls.person(member.id, period)
                                    }
                                />
                                <div className="rounded-md border bg-card p-4">
                                    <R1BarChart
                                        title={t(
                                            'reports_r1.department.members_chart',
                                        )}
                                        categoryLabel={t(
                                            'reports_r1.columns.person',
                                        )}
                                        rows={members.map((member) => ({
                                            id: String(member.id),
                                            label: member.name,
                                            values: {
                                                occupancy: member.occupancy,
                                                billability: member.billability,
                                            },
                                        }))}
                                        series={MEMBER_SERIES}
                                        format="percent"
                                    />
                                </div>
                            </>
                        )}
                    </PageSection>

                    <PageSection
                        title={t('reports_r1.department.by_client')}
                        action={
                            <ExportMenu
                                href={reportUrls.department(department.id, {
                                    ...filters.query,
                                    tabla: 'clientes',
                                })}
                                label={t('reports_r1.export')}
                            />
                        }
                    >
                        {clients.rows.length === 0 ? (
                            <EmptyState
                                icon={SearchX}
                                title={t('reports_r1.no_hours')}
                            />
                        ) : (
                            <div className="grid gap-4 xl:grid-cols-2">
                                <div className="rounded-md border bg-card p-4">
                                    <R1BarChart
                                        title={t(
                                            'reports_r1.department.by_client_chart',
                                        )}
                                        categoryLabel={t(
                                            'reports_r1.columns.client',
                                        )}
                                        rows={breakdownBars(clients)}
                                        series={LOGGED_SERIES}
                                        format="minutes"
                                    />
                                </div>
                                <R1BreakdownTable
                                    caption={t(
                                        'reports_r1.department.by_client',
                                    )}
                                    nameLabel={t('reports_r1.columns.client')}
                                    top={clients}
                                    totalMinutes={summary.logged_minutes}
                                    financials={financials}
                                    emptyLabel={t('reports_r1.no_hours')}
                                    href={(row) =>
                                        row.key === null || !row.linkable
                                            ? null
                                            : reportUrls.client(
                                                  Number(row.key),
                                                  period,
                                              )
                                    }
                                />
                            </div>
                        )}
                    </PageSection>

                    <PageSection
                        title={t('reports_r1.department.future_load')}
                        description={t(
                            'reports_r1.department.future_load_description',
                        )}
                    >
                        <EmptyState
                            icon={CalendarClock}
                            title={t('reports_r1.department.future_load_empty')}
                            phase={3}
                        />
                    </PageSection>
                </ReportContent>
            </div>
        </>
    );
}
