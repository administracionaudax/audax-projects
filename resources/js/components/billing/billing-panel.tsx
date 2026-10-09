import { Link } from '@inertiajs/react';
import { ArrowRight, FileText, SearchX } from 'lucide-react';
import { EmptyState } from '@/components/empty-state';
import { PageSection } from '@/components/projects-list/page-section';
import { Button } from '@/components/ui/button';
import { formatDate } from '@/lib/format';
import { t } from '@/lib/i18n';
import { tCount } from '@/lib/people';
import type { BillingPanelData } from '@/types';
import { InvoiceTable } from './invoice-table';
import { SoldVsActualChart } from './sold-vs-actual-chart';
import { SoldVsActualKpis } from './sold-vs-actual-kpis';
import { SoldVsActualTable } from './sold-vs-actual-table';

/**
 * «Vendido frente a real» de una ficha (D-392): proyecto, cliente o bolsa. Las cifras, la gráfica
 * (si hay más de una unidad con horas vendidas), la tabla y, con view-billing, sus facturas de
 * Holded con el enlace al listado completo.
 */
export function BillingPanel({
    panel,
    invoicesHref,
    showClient = false,
    compact = false,
}: {
    panel: BillingPanelData;
    /** Listado de facturas filtrado (con view-billing). */
    invoicesHref?: string;
    showClient?: boolean;
    /** En la ficha de una bolsa: sin gráfica y sin repetir el título de la sección. */
    compact?: boolean;
}) {
    const { report } = panel;
    const financials = report.financials;
    const charted = report.units.filter((unit) => unit.sold_minutes).length;

    return (
        <div className="grid gap-6" data-test="billing-panel">
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
                        compact={compact && report.units.length === 1}
                    />
                    {!compact && charted > 1 ? (
                        <SoldVsActualChart
                            units={report.units}
                            title={t('billing.chart.title')}
                            description={t('billing.chart.description')}
                        />
                    ) : null}
                    {!compact || report.units.length > 1 ? (
                        <SoldVsActualTable
                            units={report.units}
                            totals={report.totals}
                            financials={financials}
                            caption={t('billing.table.caption')}
                            showClient={showClient}
                        />
                    ) : null}
                </>
            )}

            {panel.invoices !== null ? (
                <PageSection
                    title={t('billing.invoices.title')}
                    description={tCount(
                        'billing.invoices.panel_description',
                        panel.invoice_count,
                    )}
                    action={
                        invoicesHref && panel.invoice_count > 0 ? (
                            <Button variant="outline" size="sm" asChild>
                                <Link href={invoicesHref}>
                                    {t('billing.invoices.see_all')}
                                    <ArrowRight aria-hidden="true" />
                                </Link>
                            </Button>
                        ) : undefined
                    }
                >
                    {panel.invoices.length === 0 ? (
                        <EmptyState
                            icon={FileText}
                            title={t('billing.invoices.none')}
                            description={t('billing.invoices.none_description')}
                        />
                    ) : (
                        <InvoiceTable
                            invoices={panel.invoices}
                            caption={t('billing.invoices.title')}
                            showClient={showClient}
                        />
                    )}
                </PageSection>
            ) : null}
        </div>
    );
}
