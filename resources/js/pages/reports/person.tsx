import { Head, Link, setLayoutProps } from '@inertiajs/react';
import { Building2, SearchX, Table2 } from 'lucide-react';
import { defineSeries } from '@/components/charts/chart-config';
import { CalendarHeatmap } from '@/components/charts/calendar-heatmap';
import { EmptyState } from '@/components/empty-state';
import { PageHeader } from '@/components/projects-list/page-header';
import { PageSection } from '@/components/projects-list/page-section';
import { ExportMenu } from '@/components/reports/export-menu';
import { R1BarChart } from '@/components/reports/r1-bar-chart';
import { breakdownBars } from '@/components/reports/r1-breakdown-table';
import { R1KpiGrid } from '@/components/reports/r1-kpi-grid';
import {
    ReportContent,
    useReportVisit,
} from '@/components/reports/r1-report-state';
import type {
    PersonReportProps,
    R1TopRows,
} from '@/components/reports/r1-types';
import { R1UnloggedDays } from '@/components/reports/r1-unlogged-days';
import { periodQuery, reportUrls } from '@/components/reports/r1-urls';
import { ReportFilterBar } from '@/components/reports/report-filter-bar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { t } from '@/lib/i18n';
import { urls } from '@/lib/urls';
import { index, person as personRoute } from '@/routes/reports';

const LOGGED_SERIES = defineSeries([
    { key: 'logged', label: t('reports_r1.evolution.logged') },
] as const);

function Breakdown({
    title,
    categoryLabel,
    top,
}: {
    title: string;
    categoryLabel: string;
    top: R1TopRows;
}) {
    return (
        <div className="min-w-0 rounded-md border bg-card p-4">
            {top.rows.length === 0 ? (
                <div className="grid gap-3">
                    <p className="text-base font-medium">{title}</p>
                    <EmptyState
                        icon={SearchX}
                        title={t('reports_r1.no_hours')}
                    />
                </div>
            ) : (
                <R1BarChart
                    title={title}
                    categoryLabel={categoryLabel}
                    rows={breakdownBars(top)}
                    series={LOGGED_SERIES}
                    format="minutes"
                />
            )}
        </div>
    );
}

/**
 * Dashboard de una persona (SPEC §10.5, D-044): capacidad, imputadas, facturables y ocupación,
 * precisión de estimación, reparto por cliente, proyecto y tipo, calendario de calor diario y días
 * sin imputar. Lo ven la propia persona, quien la supervisa y los admins; datos económicos solo con
 * view-financials.
 */
export default function PersonReport({
    person,
    is_self: isSelf,
    filters,
    summary,
    comparison,
    comparison_partial: comparisonPartial,
    clients,
    projects,
    types,
    days,
    unlogged,
}: PersonReportProps) {
    const state = useReportVisit();
    const period = periodQuery(filters.query);
    const url = personRoute.url(person.id);
    const title = isSelf
        ? t('reports_r1.person.mine')
        : t('reports_r1.person.title', { name: person.name });

    setLayoutProps({
        breadcrumbs: [
            { title: t('nav.reports'), href: index() },
            {
                title: isSelf ? t('reports_r1.person.mine') : person.name,
                href: url,
            },
        ],
    });

    return (
        <>
            <Head title={title} />

            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={title}
                    description={
                        person.department
                            ? t('reports_r1.person.description_department', {
                                  department: person.department.name,
                              })
                            : t('reports_r1.person.description')
                    }
                    actions={
                        <>
                            {person.department?.can_view ? (
                                <Button asChild variant="outline">
                                    <Link
                                        href={reportUrls.department(
                                            person.department.id,
                                            period,
                                        )}
                                    >
                                        <Building2 aria-hidden="true" />
                                        {t(
                                            'reports_r1.person.department_report',
                                        )}
                                    </Link>
                                </Button>
                            ) : null}
                            <Button asChild variant="outline">
                                <Link
                                    href={reportUrls.detail({
                                        ...period,
                                        persona: [person.id],
                                    })}
                                >
                                    <Table2 aria-hidden="true" />
                                    {t('reports_r1.person.detail_report')}
                                </Link>
                            </Button>
                        </>
                    }
                />

                {person.is_active ? null : (
                    <Badge variant="outline" className="self-start font-normal">
                        {t('reports_r1.person.inactive')}
                    </Badge>
                )}

                <ReportFilterBar
                    filters={filters}
                    url={url}
                    show={[
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
                                'capacity',
                                'logged',
                                'billable',
                                'occupancy',
                                'billability',
                                'billable_productivity',
                                'estimation',
                                'income',
                                'cost',
                                'margin',
                            ]}
                        />
                    </PageSection>

                    <div className="grid gap-8 xl:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
                        <PageSection
                            title={t('reports_r1.person.calendar')}
                            action={
                                <ExportMenu
                                    href={reportUrls.person(
                                        person.id,
                                        filters.query,
                                    )}
                                    label={t('reports_r1.person.export')}
                                />
                            }
                        >
                            <div className="min-w-0 overflow-x-auto rounded-md border bg-card p-4">
                                <CalendarHeatmap
                                    days={days}
                                    title={t(
                                        'reports_r1.person.calendar_chart',
                                    )}
                                />
                            </div>
                        </PageSection>

                        <PageSection
                            title={t('reports_r1.person.unlogged')}
                            description={t(
                                'reports_r1.person.unlogged_description',
                            )}
                        >
                            <R1UnloggedDays
                                days={unlogged}
                                timesheetHref={(day) =>
                                    isSelf
                                        ? urls.timesheet(day.week)
                                        : `${urls.timesheet(day.week)}&persona=${person.id}`
                                }
                            />
                        </PageSection>
                    </div>

                    <PageSection title={t('reports_r1.person.distribution')}>
                        <div className="grid gap-4 lg:grid-cols-2 2xl:grid-cols-3">
                            <Breakdown
                                title={t('reports_r1.person.by_client')}
                                categoryLabel={t('reports_r1.columns.client')}
                                top={clients}
                            />
                            <Breakdown
                                title={t('reports_r1.person.by_project')}
                                categoryLabel={t('reports_r1.columns.project')}
                                top={projects}
                            />
                            <Breakdown
                                title={t('reports_r1.person.by_type')}
                                categoryLabel={t('reports_r1.columns.type')}
                                top={types}
                            />
                        </div>
                    </PageSection>
                </ReportContent>
            </div>
        </>
    );
}
