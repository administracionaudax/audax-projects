import { Head, Link } from '@inertiajs/react';
import { FileText, Info, SearchX } from 'lucide-react';
import { SaleFilters } from '@/components/billing/sale-filters';
import { SoldVsActualChart } from '@/components/billing/sold-vs-actual-chart';
import { SoldVsActualKpis } from '@/components/billing/sold-vs-actual-kpis';
import { SoldVsActualTable } from '@/components/billing/sold-vs-actual-table';
import { EmptyState } from '@/components/empty-state';
import { PageHeader } from '@/components/projects-list/page-header';
import { PageSection } from '@/components/projects-list/page-section';
import { ExportMenu } from '@/components/reports/export-menu';
import { R2ScopeNote } from '@/components/reports/r2-report-body';
import { ReportFilterBar } from '@/components/reports/report-filter-bar';
import { Button } from '@/components/ui/button';
import { useAbilities } from '@/hooks/use-auth';
import { formatDate } from '@/lib/format';
import { t } from '@/lib/i18n';
import { index as reportsIndex } from '@/routes/reports';
import type { SoldVsActualPageProps } from '@/types';

const URL = '/informes/vendido-frente-a-real';

/**
 * «Vendido frente a real» (Fase 12, F1; D-390; PLAN-FACTURACION §4.6): horas e importe vendidos de
 * cada bolsa, precio cerrado, fee y proyecto por horas frente a las horas reales y, con
 * view-financials, lo facturado y cobrado en Holded y el margen. Filtros en la URL y exportación
 * como el resto de informes.
 */
export default function SoldVsActual({
    filters,
    kinds,
    manager,
    managers,
    report,
    report_request: reportRequest,
    scope,
}: SoldVsActualPageProps) {
    const can = useAbilities();
    const financials = report.financials;

    return (
        <>
            <Head title={t('billing.report.title')} />

            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={t('billing.report.title')}
                    description={t('billing.report.description')}
                    actions={
                        <>
                            {can.viewBilling ? (
                                <Button variant="outline" asChild>
                                    <Link href="/facturacion/facturas">
                                        <FileText aria-hidden="true" />
                                        {t('billing.nav.invoices')}
                                    </Link>
                                </Button>
                            ) : null}
                            <ExportMenu
                                request={reportRequest}
                                title={t('billing.report.title')}
                            />
                        </>
                    }
                />

                <div className="grid gap-4">
                    <ReportFilterBar
                        filters={filters}
                        show={['cliente']}
                        url={URL}
                        compare={false}
                    />
                    <SaleFilters
                        url={URL}
                        query={filters.query}
                        kinds={kinds}
                        manager={manager}
                        managers={managers}
                    />
                </div>

                {scope.own_projects ? (
                    <R2ScopeNote>{t('billing.report.scope_own')}</R2ScopeNote>
                ) : null}
                {!financials ? (
                    <p className="flex items-start gap-2 text-sm text-muted-foreground">
                        <Info
                            aria-hidden="true"
                            className="mt-0.5 size-4 shrink-0"
                        />
                        {t('billing.report.hours_only')}
                    </p>
                ) : null}

                {report.units.length === 0 ? (
                    <EmptyState
                        icon={SearchX}
                        title={t('billing.empty.title')}
                        description={t('billing.empty.period', {
                            from: formatDate(report.from),
                            to: formatDate(report.to),
                        })}
                    />
                ) : (
                    <>
                        <SoldVsActualKpis
                            totals={report.totals}
                            financials={financials}
                        />

                        <SoldVsActualChart
                            units={report.units}
                            title={t('billing.chart.title')}
                            description={t('billing.chart.description')}
                        />

                        <PageSection
                            title={t('billing.table.title')}
                            description={t('billing.table.description')}
                        >
                            <SoldVsActualTable
                                units={report.units}
                                totals={report.totals}
                                financials={financials}
                                caption={t('billing.table.caption')}
                            />
                        </PageSection>
                    </>
                )}
            </div>
        </>
    );
}

SoldVsActual.layout = {
    breadcrumbs: [
        { title: t('nav.reports'), href: reportsIndex() },
        { title: t('billing.report.title'), href: URL },
    ],
};
