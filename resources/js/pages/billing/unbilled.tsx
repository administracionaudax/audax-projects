import { Head, Link } from '@inertiajs/react';
import { ChevronRight, Clock, CircleCheck } from 'lucide-react';
import { BillingHeader } from '@/components/billing/billing-header';
import { FilterSheet } from '@/components/billing/filter-sheet';
import { HelpTip } from '@/components/billing/help-tip';
import { KpiGroup } from '@/components/billing/kpi-group';
import { unbilledSources } from '@/components/billing/unbilled-sources';
import { EmptyState } from '@/components/empty-state';
import { KpiCard } from '@/components/reports/kpi-card';
import { R2ScopeNote } from '@/components/reports/r2-report-body';
import { ReportFilterBar } from '@/components/reports/report-filter-bar';
import { Button } from '@/components/ui/button';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatCurrency, formatDate, formatMinutes } from '@/lib/format';
import { t } from '@/lib/i18n';
import { tCount } from '@/lib/people';
import { ROW_CLICK_CLASS, rowClickProps } from '@/lib/row-click';
import { cn } from '@/lib/utils';
import { unbilled as unbilledRoute } from '@/routes/billing';
import type {
    ReportFiltersProps,
    UnbilledClient,
    UnbilledReportData,
} from '@/types';

type Props = {
    filters: ReportFiltersProps;
    report: UnbilledReportData;
    scope: { team_only: boolean };
};

/** El detalle de un cliente (la exportación de horas de siempre) con el periodo de la lista. */
function clientHref(clientId: number, query: ReportFiltersProps['query']) {
    return unbilledRoute.url({ query: { ...query, cliente: [clientId] } });
}

/**
 * Por facturar (I10, D-412): la lista de clientes con algo trabajado o vendido sin facturar, como
 * el informe de lo no facturado de Harvest (horas aprobadas de proyectos por horas, excesos de
 * bolsa y, con view-billing, bolsas vendidas y fees del periodo sin factura). Cada cliente abre el
 * detalle de siempre, con el resumen por proyecto y bolsa y la exportación. Sin view-billing (el
 * módulo apagado, D-402), solo horas.
 */
export default function UnbilledClients({ filters, report, scope }: Props) {
    const url = unbilledRoute.url();
    const financials = report.financials;
    const { totals } = report;

    return (
        <>
            <Head title={t('billing.nav.unbilled')} />

            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
                <BillingHeader
                    current="por-facturar"
                    title={t('billing.nav.unbilled')}
                    description={t('billing.unbilled.list_description')}
                />

                <FilterSheet count={0}>
                    <ReportFilterBar
                        filters={filters}
                        show={[]}
                        url={url}
                        compare={false}
                    />
                </FilterSheet>

                {scope.team_only ? (
                    <R2ScopeNote>
                        {t('reports_r2.billing.scope_team')}
                    </R2ScopeNote>
                ) : null}

                {report.clients.length === 0 ? (
                    <EmptyState
                        icon={CircleCheck}
                        title={t('billing.unbilled.empty')}
                        description={t('billing.unbilled.empty_description', {
                            from: formatDate(report.from),
                            to: formatDate(report.to),
                        })}
                    >
                        <Button variant="outline" size="sm" asChild>
                            <Link
                                href={unbilledRoute.url({
                                    query: { periodo: 'anio' },
                                })}
                            >
                                {t('billing.unbilled.empty_action')}
                            </Link>
                        </Button>
                    </EmptyState>
                ) : (
                    <>
                        <div className="grid gap-5 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                            {financials ? (
                                <KpiGroup
                                    title={t('billing.unbilled.group_amount')}
                                    columns="grid-cols-2"
                                >
                                    <KpiCard
                                        label={t('billing.unbilled.amount')}
                                        definition={t(
                                            'billing.unbilled.amount_definition',
                                        )}
                                        value={formatCurrency(
                                            totals.amount ?? '0',
                                        )}
                                        detail={tCount(
                                            'billing.unbilled.clients',
                                            totals.clients,
                                        )}
                                    />
                                    <KpiCard
                                        label={t('billing.unbilled.minutes')}
                                        definition={t(
                                            'billing.unbilled.minutes_definition',
                                        )}
                                        value={formatMinutes(totals.minutes)}
                                    />
                                </KpiGroup>
                            ) : (
                                <KpiGroup
                                    title={t('billing.unbilled.group_hours')}
                                    columns="grid-cols-2"
                                >
                                    <KpiCard
                                        label={t('billing.unbilled.minutes')}
                                        definition={t(
                                            'billing.unbilled.minutes_definition_hours',
                                        )}
                                        value={formatMinutes(totals.minutes)}
                                        detail={tCount(
                                            'billing.unbilled.clients',
                                            totals.clients,
                                        )}
                                    />
                                    <KpiCard
                                        label={t('billing.unbilled.pending')}
                                        definition={t(
                                            'billing.unbilled.pending_definition',
                                        )}
                                        value={formatMinutes(
                                            totals.pending_minutes,
                                        )}
                                    />
                                </KpiGroup>
                            )}
                            {financials ? (
                                <KpiGroup
                                    title={t('billing.unbilled.group_hours')}
                                    columns="grid-cols-1"
                                >
                                    <KpiCard
                                        label={t('billing.unbilled.pending')}
                                        definition={t(
                                            'billing.unbilled.pending_definition',
                                        )}
                                        value={formatMinutes(
                                            totals.pending_minutes,
                                        )}
                                    />
                                </KpiGroup>
                            ) : null}
                        </div>

                        <section
                            aria-labelledby="unbilled-title"
                            className="grid gap-3"
                        >
                            <div className="flex flex-wrap items-center gap-1">
                                <h2
                                    id="unbilled-title"
                                    className="text-lg font-normal"
                                >
                                    {t('billing.unbilled.by_client')}
                                </h2>
                                <HelpTip
                                    topic={t('billing.unbilled.by_client')}
                                >
                                    <p>
                                        {t(
                                            financials
                                                ? 'billing.unbilled.help'
                                                : 'billing.unbilled.help_hours',
                                        )}
                                    </p>
                                </HelpTip>
                                <p className="w-full text-sm text-muted-foreground">
                                    {t(
                                        'billing.unbilled.by_client_description',
                                        {
                                            from: formatDate(report.from),
                                            to: formatDate(report.to),
                                        },
                                    )}
                                </p>
                            </div>
                            <UnbilledTable
                                rows={report.clients}
                                query={filters.query}
                                financials={financials}
                                totals={totals}
                            />
                            <UnbilledCards
                                rows={report.clients}
                                query={filters.query}
                                financials={financials}
                            />
                        </section>
                    </>
                )}
            </div>
        </>
    );
}

function UnbilledTable({
    rows,
    query,
    financials,
    totals,
}: {
    rows: UnbilledClient[];
    query: ReportFiltersProps['query'];
    financials: boolean;
    totals: UnbilledReportData['totals'];
}) {
    const caption = t('billing.unbilled.caption');

    return (
        <div
            className={cn(
                'hidden overflow-x-auto rounded-md border md:block',
                FOCUS_RING,
            )}
            role="region"
            aria-label={caption}
            tabIndex={0}
        >
            <table
                className="tabular w-full text-sm"
                data-test="unbilled-table"
            >
                <caption className="sr-only">{caption}</caption>
                <thead>
                    <tr className="border-b">
                        <th scope="col" className="px-3 py-2 text-left">
                            {t('billing.unbilled.col.client')}
                        </th>
                        <th scope="col" className="px-3 py-2 text-left">
                            {t('billing.unbilled.col.what')}
                        </th>
                        <th scope="col" className="px-3 py-2 text-left">
                            {t('billing.unbilled.col.oldest')}
                        </th>
                        <th scope="col" className="px-3 py-2 text-right">
                            {t('billing.unbilled.col.hours')}
                        </th>
                        {financials ? (
                            <th scope="col" className="px-3 py-2 text-right">
                                {t('billing.unbilled.col.amount')}
                            </th>
                        ) : null}
                        <th scope="col" className="w-10 px-3 py-2">
                            <span className="sr-only">
                                {t('billing.unbilled.col.open')}
                            </span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row) => (
                        <tr
                            key={row.client.id}
                            className={cn(
                                'h-11 border-b last:border-0 even:bg-muted',
                                ROW_CLICK_CLASS,
                            )}
                            {...rowClickProps}
                        >
                            <th
                                scope="row"
                                className="px-3 py-1.5 text-left font-normal"
                            >
                                <Link
                                    href={clientHref(row.client.id, query)}
                                    data-row-primary
                                    className={cn(
                                        'rounded-md text-primary-text hover:underline',
                                        FOCUS_RING,
                                    )}
                                    data-test="unbilled-client"
                                >
                                    {row.client.name}
                                </Link>
                            </th>
                            <td className="px-3 py-1.5 text-muted-foreground">
                                {unbilledSources(row.sources)}
                            </td>
                            <td className="px-3 py-1.5 whitespace-nowrap">
                                {row.oldest ? formatDate(row.oldest) : '—'}
                            </td>
                            <td className="px-3 py-1.5 text-right whitespace-nowrap">
                                {row.minutes > 0
                                    ? formatMinutes(row.minutes)
                                    : '—'}
                                {row.pending_minutes > 0 ? (
                                    <span className="block text-xs text-muted-foreground">
                                        {t('billing.pending_hours', {
                                            hours: formatMinutes(
                                                row.pending_minutes,
                                            ),
                                        })}
                                    </span>
                                ) : null}
                            </td>
                            {financials ? (
                                <td className="px-3 py-1.5 text-right whitespace-nowrap">
                                    {formatCurrency(row.amount ?? '0')}
                                </td>
                            ) : null}
                            <td className="px-3 py-1.5 text-muted-foreground">
                                <ChevronRight
                                    aria-hidden="true"
                                    className="size-4"
                                />
                            </td>
                        </tr>
                    ))}
                </tbody>
                <tfoot>
                    <tr className="border-t bg-muted">
                        <th
                            scope="row"
                            colSpan={3}
                            className="px-3 py-2 text-left font-medium"
                        >
                            {tCount('billing.unbilled.clients', totals.clients)}
                        </th>
                        <td className="px-3 py-2 text-right font-medium whitespace-nowrap">
                            {formatMinutes(totals.minutes)}
                        </td>
                        {financials ? (
                            <td className="px-3 py-2 text-right font-medium whitespace-nowrap">
                                {formatCurrency(totals.amount ?? '0')}
                            </td>
                        ) : null}
                        <td />
                    </tr>
                </tfoot>
            </table>
        </div>
    );
}

/** En el móvil (I7), una tarjeta por cliente: nombre e importe; qué hay y desde cuándo. */
function UnbilledCards({
    rows,
    query,
    financials,
}: {
    rows: UnbilledClient[];
    query: ReportFiltersProps['query'];
    financials: boolean;
}) {
    return (
        <ul className="grid gap-2 md:hidden" data-test="unbilled-cards">
            {rows.map((row) => (
                <li key={row.client.id}>
                    <Link
                        href={clientHref(row.client.id, query)}
                        className={cn(
                            'grid gap-1 rounded-md border bg-card p-3 text-sm hover:bg-accent/60',
                            FOCUS_RING,
                        )}
                    >
                        <span className="flex items-baseline justify-between gap-3">
                            <span className="min-w-0 truncate text-primary-text">
                                {row.client.name}
                            </span>
                            <span className="tabular shrink-0">
                                {financials
                                    ? formatCurrency(row.amount ?? '0')
                                    : formatMinutes(row.minutes)}
                            </span>
                        </span>
                        <span className="flex items-baseline justify-between gap-3 text-xs text-muted-foreground">
                            <span className="min-w-0">
                                {unbilledSources(row.sources)}
                            </span>
                            <span className="tabular inline-flex shrink-0 items-center gap-1">
                                {financials && row.minutes > 0 ? (
                                    <>
                                        <Clock
                                            aria-hidden="true"
                                            className="size-3"
                                        />
                                        {formatMinutes(row.minutes)}
                                    </>
                                ) : row.oldest ? (
                                    t('billing.unbilled.since', {
                                        date: formatDate(row.oldest),
                                    })
                                ) : null}
                            </span>
                        </span>
                    </Link>
                </li>
            ))}
        </ul>
    );
}

UnbilledClients.layout = {
    breadcrumbs: [
        // Sin el módulo `billing`, /facturacion no existe (D-402): la sección enlaza a esta página.
        { title: t('billing.section'), href: unbilledRoute.url() },
        { title: t('billing.nav.unbilled'), href: unbilledRoute.url() },
    ],
};
