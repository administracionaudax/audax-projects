import { Head, Link } from '@inertiajs/react';
import { ArrowRight, Info, SearchX } from 'lucide-react';
import type { ReactNode } from 'react';
import { BillingTabs } from '@/components/billing/billing-nav';
import {
    InvoicingAgingChart,
    InvoicingOverdueList,
} from '@/components/billing/invoicing-aging';
import { InvoicingBars } from '@/components/billing/invoicing-bars';
import type { AmountBar } from '@/components/billing/invoicing-bars';
import { InvoicingKpis } from '@/components/billing/invoicing-kpis';
import { amount, serviceLabel } from '@/components/billing/invoicing-lib';
import { InvoicingMonthChart } from '@/components/billing/invoicing-month-chart';
import { LastSync } from '@/components/billing/last-sync';
import { ServiceFilters } from '@/components/billing/service-filters';
import { EmptyState } from '@/components/empty-state';
import { PageHeader } from '@/components/projects-list/page-header';
import { ExportMenu } from '@/components/reports/export-menu';
import { ReportFilterBar } from '@/components/reports/report-filter-bar';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatDate } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { index as billingIndex, report as reportRoute } from '@/routes/billing';
import type { InvoicingReport, InvoicingReportPageProps } from '@/types';

/** Panel de una gráfica: sobre la tarjeta (el hueco de 2 px entre marcas es del color de la tarjeta). */
function Panel({
    children,
    className,
}: {
    children: ReactNode;
    className?: string;
}) {
    return (
        <div className={cn('min-w-0 rounded-md border bg-card p-4', className)}>
            {children}
        </div>
    );
}

/** El ranking: los 10 primeros con su enlace, el resto sumado y lo que no tiene cliente casado. */
function clientBars(clients: InvoicingReport['clients']): AmountBar[] {
    const rows: AmountBar[] = clients.top.map((client) => ({
        id: `client-${client.id}`,
        label: client.name,
        amount: client.amount,
        share: client.share,
        cells: {
            label: (
                <Link
                    href={`/clientes/${client.id}/facturacion`}
                    className={cn('rounded-sm hover:underline', FOCUS_RING)}
                >
                    {client.name}
                </Link>
            ),
            count: String(client.count),
        },
    }));

    if (clients.rest !== null) {
        rows.push({
            id: 'rest',
            label: t(
                clients.rest.clients === 1
                    ? 'billing.invoicing.rest_one'
                    : 'billing.invoicing.rest_other',
                { count: clients.rest.clients },
            ),
            amount: clients.rest.amount,
            share: clients.rest.share,
            muted: true,
            cells: { count: String(clients.rest.count) },
        });
    }

    if (clients.unmatched !== null) {
        rows.push({
            id: 'unmatched',
            label: t('billing.invoicing.unmatched'),
            amount: clients.unmatched.amount,
            share: clients.unmatched.share,
            muted: true,
            cells: {
                label: (
                    <Link
                        href="/facturacion/contactos"
                        className={cn('rounded-sm hover:underline', FOCUS_RING)}
                    >
                        {t('billing.invoicing.unmatched')}
                    </Link>
                ),
                count: String(clients.unmatched.count),
            },
        });
    }

    return rows;
}

/**
 * Informe de facturación (D-400), como la «Analítica de ventas» de Holded con el aspecto de Audax:
 * cifras del periodo, facturado por mes frente al año anterior, por servicio, ranking de clientes y
 * antigüedad de lo pendiente con las facturas vencidas. Solo con view-billing; filtros en la URL y
 * exportación a Excel, CSV y PDF como el resto de informes.
 */
export default function InvoicingReportPage({
    filters,
    services,
    report,
    report_request: reportRequest,
    last_sync: lastSync,
}: InvoicingReportPageProps) {
    const url = reportRoute.url();
    const k = report.kpis;
    const empty =
        k.count === 0 &&
        amount(k.invoiced) === 0 &&
        amount(k.planned) === 0 &&
        amount(k.outstanding) === 0;
    const filtered = report.services_filter.length > 0;

    return (
        <>
            <Head title={t('billing.invoicing.title')} />

            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={t('billing.invoicing.title')}
                    description={t('billing.invoicing.description')}
                    actions={
                        <ExportMenu
                            request={reportRequest}
                            title={t('billing.invoicing.title')}
                        />
                    }
                />

                <BillingTabs current="informe" />

                <div className="grid gap-4">
                    <ReportFilterBar
                        filters={filters}
                        show={['cliente']}
                        url={url}
                        compareLabel={t('billing.invoicing.filters.compare')}
                    />
                    <div className="flex flex-wrap items-end justify-between gap-3">
                        <ServiceFilters
                            url={url}
                            query={filters.query}
                            services={services}
                            selected={report.services_filter}
                        />
                        <LastSync sync={lastSync} />
                    </div>
                </div>

                {filtered ? (
                    <p className="flex items-start gap-2 text-sm text-muted-foreground">
                        <Info
                            aria-hidden="true"
                            className="mt-0.5 size-4 shrink-0"
                        />
                        {t('billing.invoicing.services_note')}
                    </p>
                ) : null}

                {empty ? (
                    <EmptyState
                        icon={SearchX}
                        title={t('billing.invoicing.empty_title')}
                        description={t('billing.invoicing.empty_period', {
                            from: formatDate(report.from),
                            to: formatDate(report.to),
                        })}
                    />
                ) : (
                    <>
                        <InvoicingKpis report={report} />

                        <Panel>
                            <InvoicingMonthChart
                                months={report.months}
                                compare={report.compare}
                            />
                        </Panel>

                        <div className="grid gap-6 xl:grid-cols-2">
                            <Panel>
                                <InvoicingBars
                                    test="invoicing-services"
                                    title={t(
                                        'billing.invoicing.charts.services',
                                    )}
                                    description={t(
                                        filtered
                                            ? 'billing.invoicing.charts.services_filtered'
                                            : 'billing.invoicing.charts.services_description',
                                    )}
                                    categoryLabel={t(
                                        'billing.invoicing.columns.service',
                                    )}
                                    rows={report.services.map((row) => ({
                                        id: row.key,
                                        label: serviceLabel(row.key),
                                        amount: row.amount,
                                        share: row.share,
                                        muted: row.key === 'sin_desglose',
                                    }))}
                                />
                            </Panel>
                            <Panel>
                                <InvoicingBars
                                    test="invoicing-clients"
                                    title={t(
                                        'billing.invoicing.charts.clients',
                                    )}
                                    description={t(
                                        'billing.invoicing.charts.clients_description',
                                    )}
                                    categoryLabel={t(
                                        'billing.invoicing.columns.client',
                                    )}
                                    rows={clientBars(report.clients)}
                                    extraColumns={[
                                        {
                                            key: 'count',
                                            label: t(
                                                'billing.invoicing.columns.count',
                                            ),
                                            numeric: true,
                                        },
                                    ]}
                                    footer={
                                        report.clients.unmatched !== null ? (
                                            <Link
                                                href="/facturacion/contactos"
                                                data-test="invoicing-unmatched"
                                                className={cn(
                                                    'inline-flex items-center gap-1.5 justify-self-start rounded-sm text-sm text-muted-foreground hover:text-foreground',
                                                    FOCUS_RING,
                                                )}
                                            >
                                                {t(
                                                    report.clients.unmatched
                                                        .count === 1
                                                        ? 'billing.invoicing.unmatched_note_one'
                                                        : 'billing.invoicing.unmatched_note_other',
                                                    {
                                                        count: report.clients
                                                            .unmatched.count,
                                                    },
                                                )}
                                                <ArrowRight
                                                    aria-hidden="true"
                                                    className="size-4"
                                                />
                                            </Link>
                                        ) : null
                                    }
                                />
                            </Panel>
                        </div>

                        <div className="grid gap-6 xl:grid-cols-[minmax(0,5fr)_minmax(0,6fr)]">
                            <Panel>
                                <InvoicingAgingChart aging={report.aging} />
                            </Panel>
                            <Panel>
                                <InvoicingOverdueList
                                    overdue={report.overdue}
                                />
                            </Panel>
                        </div>
                    </>
                )}
            </div>
        </>
    );
}

InvoicingReportPage.layout = {
    breadcrumbs: [
        { title: t('billing.section'), href: billingIndex() },
        { title: t('billing.invoicing.title'), href: reportRoute() },
    ],
};
