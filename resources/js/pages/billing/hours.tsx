import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowLeft,
    Building2,
    Clock,
    FileWarning,
    Receipt,
    SearchX,
} from 'lucide-react';
import { useId } from 'react';
import { BillingHeader } from '@/components/billing/billing-header';
import {
    countQueryFilters,
    FilterSheet,
} from '@/components/billing/filter-sheet';
import { EmptyState } from '@/components/empty-state';
import { PageSection } from '@/components/projects-list/page-section';
import { ExportMenu } from '@/components/reports/export-menu';
import { KpiCard } from '@/components/reports/kpi-card';
import { R2BillingTable } from '@/components/reports/r2-billing-table';
import { formatOverage } from '@/components/reports/r2-helpers';
import { R2ReportBody, R2ScopeNote } from '@/components/reports/r2-report-body';
import type {
    R2BillingProps,
    R2BillingSummary,
} from '@/components/reports/r2-types';
import { useIsMobile } from '@/hooks/use-mobile';
import { ReportFilterBar } from '@/components/reports/report-filter-bar';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { FOCUS_RING } from '@/lib/focus-ring';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    formatCurrency,
    formatDate,
    formatMinutes,
    formatNumber,
} from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { unbilled as billingHours } from '@/routes/billing';
import { client as clientReport } from '@/routes/reports';
import type { ReportFilterKey } from '@/types';

/** El cliente se elige aparte (uno y obligatorio); el resto de filtros, en la barra. */
const FILTERS: ReportFilterKey[] = [
    'proyecto',
    'bolsa',
    'persona',
    'facturable',
];

/**
 * Por facturar, el detalle de un cliente (D-405 y D-412; antes «Horas para facturar», SPEC §10
 * «Exportación», D-045; en Facturación desde D-401). Sin cliente, la página es la lista de clientes
 * pendientes (billing/unbilled, I10). Un cliente y un periodo, con el resumen por proyecto y bolsa (dentro de la bolsa y exceso por separado, pendientes de
 * aprobar y, con view-financials, tarifas e importes) y la descarga del detalle de cada entrada.
 */
export default function BillingReport({
    filters,
    client,
    clients,
    summary,
    scope,
    export_limit: exportLimit,
    can,
    report_request: reportRequest,
}: R2BillingProps) {
    const id = useId();
    const mobile = useIsMobile();
    const url = billingHours.url();
    const financials = filters.can_see_financials;
    // Más entradas de las que caben en el fichero: se pide acotar en lugar de recortarlo.
    const tooManyRows =
        summary !== null && summary.totals.entries > exportLimit;

    // Los proyectos y bolsas elegidos son del cliente anterior: al cambiar de cliente se quitan.
    const chooseClient = (value: string) => {
        const query = Object.fromEntries(
            Object.entries(filters.query).filter(
                ([key]) => key !== 'proyecto' && key !== 'bolsa',
            ),
        );

        router.get(
            url,
            { ...query, cliente: [Number(value)] },
            { preserveState: true, preserveScroll: true },
        );
    };

    return (
        <>
            <Head title={t('billing.nav.unbilled')} />

            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
                <BillingHeader
                    current="por-facturar"
                    title={client ? client.name : t('billing.nav.unbilled')}
                    kicker={
                        <Link
                            href={billingHours.url({
                                query: Object.fromEntries(
                                    Object.entries(filters.query).filter(
                                        ([key]) =>
                                            ![
                                                'cliente',
                                                'proyecto',
                                                'bolsa',
                                                'persona',
                                                'facturable',
                                            ].includes(key),
                                    ),
                                ),
                            })}
                            className={cn(
                                'inline-flex items-center gap-1 rounded-md hover:text-foreground',
                                FOCUS_RING,
                            )}
                            data-test="unbilled-back"
                        >
                            <ArrowLeft aria-hidden="true" className="size-4" />
                            {t('billing.unbilled.back')}
                        </Link>
                    }
                    description={t('billing.unbilled.description')}
                    actions={
                        client ? (
                            <>
                                {can.viewReport ? (
                                    <Button variant="outline" asChild>
                                        <Link
                                            href={clientReport.url(client.id, {
                                                query: filters.query,
                                            })}
                                        >
                                            <Building2 aria-hidden="true" />
                                            {t(
                                                'reports_r2.billing.client_report',
                                            )}
                                        </Link>
                                    </Button>
                                ) : null}
                                {tooManyRows ||
                                reportRequest === null ? null : (
                                    <ExportMenu
                                        request={reportRequest}
                                        title={`${t('reports_r2.billing.title')} · ${client.name}`}
                                        label={t('reports_r2.billing.export')}
                                    />
                                )}
                            </>
                        ) : null
                    }
                />

                <FilterSheet
                    count={countQueryFilters(filters.query, [
                        'proyecto',
                        'bolsa',
                        'persona',
                        'facturable',
                    ])}
                    className="grid gap-4"
                >
                    <div className="grid gap-1 sm:max-w-sm">
                        <Label htmlFor={`${id}-client`}>
                            {t('reports_r2.billing.client')}
                        </Label>
                        <Select
                            value={client ? String(client.id) : ''}
                            onValueChange={chooseClient}
                        >
                            <SelectTrigger
                                id={`${id}-client`}
                                className="w-full"
                            >
                                <SelectValue
                                    placeholder={t(
                                        'reports_r2.billing.client_placeholder',
                                    )}
                                />
                            </SelectTrigger>
                            <SelectContent>
                                {clients.map((option) => (
                                    <SelectItem
                                        key={option.id}
                                        value={String(option.id)}
                                    >
                                        {option.is_active
                                            ? option.name
                                            : t('reports_r2.billing.inactive', {
                                                  name: option.name,
                                              })}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>

                    {/* Facturación no compara con el periodo anterior: sin el interruptor. */}
                    <ReportFilterBar
                        filters={filters}
                        show={FILTERS}
                        url={url}
                        compare={false}
                    />
                </FilterSheet>

                {scope.team_only ? (
                    <R2ScopeNote>
                        {t('reports_r2.billing.scope_team')}
                    </R2ScopeNote>
                ) : null}

                <R2ReportBody>
                    {client === null || summary === null ? (
                        <EmptyState
                            icon={Receipt}
                            title={t('reports_r2.billing.choose')}
                            description={t(
                                'reports_r2.billing.choose_description',
                            )}
                        />
                    ) : summary.rows.length === 0 ? (
                        <EmptyState
                            icon={SearchX}
                            title={t('reports_r2.billing.empty', {
                                client: client.name,
                            })}
                            description={t(
                                'reports_r2.billing.empty_description',
                                {
                                    from: formatDate(filters.from),
                                    to: formatDate(filters.to),
                                },
                            )}
                        />
                    ) : (
                        <>
                            <section
                                aria-label={t('reports_r2.kpi.label')}
                                className={cn(
                                    'grid grid-cols-2 gap-3',
                                    financials
                                        ? 'lg:grid-cols-3 2xl:grid-cols-6'
                                        : 'lg:grid-cols-5',
                                )}
                            >
                                <KpiCard
                                    label={t('reports.metric.logged.label')}
                                    definition={t(
                                        'reports.metric.logged.definition',
                                    )}
                                    value={formatMinutes(
                                        summary.totals.logged_minutes,
                                    )}
                                />
                                <KpiCard
                                    label={t('reports_r2.billing.in_bank')}
                                    definition={t(
                                        'reports_r2.billing.in_bank_definition',
                                    )}
                                    value={formatMinutes(
                                        summary.totals.in_bank_minutes,
                                    )}
                                />
                                <KpiCard
                                    label={t('reports.metric.overage.label')}
                                    definition={t(
                                        'reports.metric.overage.definition',
                                    )}
                                    value={formatOverage(
                                        summary.totals.overage_minutes,
                                    )}
                                    className={cn(
                                        summary.totals.overage_minutes > 0 &&
                                            '[&_[data-slot=card-content]>p:first-child]:text-danger',
                                    )}
                                />
                                <KpiCard
                                    label={t('reports.metric.billable.label')}
                                    definition={t(
                                        'reports.metric.billable.definition',
                                    )}
                                    value={formatMinutes(
                                        summary.totals.billable_minutes,
                                    )}
                                />
                                <KpiCard
                                    label={t('reports_r2.billing.pending')}
                                    definition={t(
                                        'reports_r2.billing.pending_definition',
                                    )}
                                    value={formatMinutes(
                                        summary.totals.pending_minutes,
                                    )}
                                />
                                {financials ? (
                                    <KpiCard
                                        label={t('reports.metric.income.label')}
                                        definition={t(
                                            'reports.metric.income.definition',
                                        )}
                                        value={formatCurrency(
                                            summary.totals.income ?? '0',
                                        )}
                                    />
                                ) : null}
                            </section>

                            {tooManyRows ? (
                                <p
                                    role="status"
                                    className="flex items-start gap-2 rounded-md bg-warning-soft px-3 py-2 text-sm text-foreground"
                                >
                                    <FileWarning
                                        aria-hidden="true"
                                        className="mt-0.5 size-4 shrink-0 text-warning"
                                    />
                                    {t('reports_r2.billing.too_many_rows', {
                                        entries: formatNumber(
                                            summary.totals.entries,
                                        ),
                                        max: formatNumber(exportLimit),
                                    })}
                                </p>
                            ) : null}

                            {summary.totals.pending_minutes > 0 ? (
                                <p className="flex items-start gap-2 rounded-md bg-warning-soft px-3 py-2 text-sm text-foreground">
                                    <Clock
                                        aria-hidden="true"
                                        className="mt-0.5 size-4 shrink-0 text-warning"
                                    />
                                    {t('reports_r2.billing.pending_warning', {
                                        hours: formatMinutes(
                                            summary.totals.pending_minutes,
                                        ),
                                    })}
                                </p>
                            ) : null}

                            <PageSection
                                title={t('reports_r2.billing.summary', {
                                    client: client.name,
                                })}
                                description={t(
                                    'reports_r2.billing.summary_description',
                                    {
                                        from: formatDate(filters.from),
                                        to: formatDate(filters.to),
                                    },
                                )}
                            >
                                {mobile ? (
                                    <SummaryCards
                                        summary={summary}
                                        financials={financials}
                                    />
                                ) : (
                                    <R2BillingTable
                                        summary={summary}
                                        financials={financials}
                                    />
                                )}
                            </PageSection>
                        </>
                    )}
                </R2ReportBody>
            </div>
        </>
    );
}

/**
 * El resumen por proyecto y bolsa en el móvil (I7, D-415): una tarjeta por fila con las horas, el
 * exceso, lo pendiente de aprobar y, con importes, el ingreso. Sin desplazamiento lateral.
 */
function SummaryCards({
    summary,
    financials,
}: {
    summary: R2BillingSummary;
    financials: boolean;
}) {
    return (
        <ul className="grid gap-2" data-test="unbilled-detail-cards">
            {summary.rows.map((row) => (
                <li
                    key={`${row.project.id}-${row.bank?.id ?? 'none'}`}
                    className="grid gap-2 rounded-md border bg-card p-3 text-sm"
                >
                    <div className="flex items-baseline justify-between gap-3">
                        <span className="min-w-0">
                            <span className="text-muted-foreground">
                                {row.project.code}
                            </span>{' '}
                            {row.project.name}
                            <span className="block truncate text-xs text-muted-foreground">
                                {row.bank
                                    ? row.bank.name
                                    : t('reports_r2.billing.no_bank')}
                            </span>
                        </span>
                        {financials ? (
                            <span className="tabular shrink-0">
                                {formatCurrency(row.income ?? '0')}
                            </span>
                        ) : null}
                    </div>
                    <div className="tabular grid grid-cols-3 gap-2 text-xs text-muted-foreground">
                        <span>
                            {t('reports.metric.logged.label')}
                            <span className="block text-sm text-foreground">
                                {formatMinutes(row.logged_minutes)}
                            </span>
                        </span>
                        <span>
                            {t('reports.metric.overage.label')}
                            <span
                                className={cn(
                                    'block text-sm text-foreground',
                                    row.overage_minutes > 0 && 'text-danger',
                                )}
                            >
                                {formatMinutes(row.overage_minutes)}
                            </span>
                        </span>
                        <span>
                            {t('reports_r2.billing.pending')}
                            <span className="block text-sm text-foreground">
                                {formatMinutes(row.pending_minutes)}
                            </span>
                        </span>
                    </div>
                </li>
            ))}
            <li className="tabular flex items-baseline justify-between gap-3 rounded-md border bg-muted px-3 py-2 text-sm font-medium">
                <span>
                    {t('billing.unbilled.detail_total', {
                        hours: formatMinutes(summary.totals.logged_minutes),
                    })}
                </span>
                {financials ? (
                    <span>{formatCurrency(summary.totals.income ?? '0')}</span>
                ) : null}
            </li>
        </ul>
    );
}

BillingReport.layout = {
    breadcrumbs: [
        // Sin el módulo `billing`, /facturacion no existe (D-402): la sección enlaza a esta página.
        { title: t('billing.section'), href: billingHours() },
        { title: t('billing.nav.unbilled'), href: billingHours() },
    ],
};
