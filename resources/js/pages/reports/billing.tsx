import { Head, Link, router } from '@inertiajs/react';
import { Building2, Clock, FileWarning, Receipt, SearchX } from 'lucide-react';
import { useId } from 'react';
import { EmptyState } from '@/components/empty-state';
import { PageHeader } from '@/components/projects-list/page-header';
import { PageSection } from '@/components/projects-list/page-section';
import { ExportMenu } from '@/components/reports/export-menu';
import { KpiCard } from '@/components/reports/kpi-card';
import { R2BillingTable } from '@/components/reports/r2-billing-table';
import { formatOverage } from '@/components/reports/r2-helpers';
import { R2ReportBody, R2ScopeNote } from '@/components/reports/r2-report-body';
import type { R2BillingProps } from '@/components/reports/r2-types';
import { ReportFilterBar } from '@/components/reports/report-filter-bar';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
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
import {
    billing,
    client as clientReport,
    index as reportsIndex,
} from '@/routes/reports';
import type { ReportFilterKey } from '@/types';

/** El cliente se elige aparte (uno y obligatorio); el resto de filtros, en la barra. */
const FILTERS: ReportFilterKey[] = [
    'proyecto',
    'bolsa',
    'persona',
    'facturable',
];

/**
 * Exportación de horas para facturar (SPEC §10 «Exportación», D-045): un cliente y un periodo,
 * con el resumen por proyecto y bolsa (dentro de la bolsa y exceso por separado, pendientes de
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
}: R2BillingProps) {
    const id = useId();
    const url = billing.url();
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
            <Head title={t('reports_r2.billing.title')} />

            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={t('reports_r2.billing.heading')}
                    description={t('reports_r2.billing.description')}
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
                                {tooManyRows ? null : (
                                    <ExportMenu
                                        href={billing.url({
                                            query: {
                                                ...filters.query,
                                                cliente: [client.id],
                                            },
                                        })}
                                        label={t('reports_r2.billing.export')}
                                    />
                                )}
                            </>
                        ) : null
                    }
                />

                <div className="grid gap-1 sm:max-w-sm">
                    <Label htmlFor={`${id}-client`}>
                        {t('reports_r2.billing.client')}
                    </Label>
                    <Select
                        value={client ? String(client.id) : undefined}
                        onValueChange={chooseClient}
                    >
                        <SelectTrigger id={`${id}-client`} className="w-full">
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
                                    'grid gap-3 sm:grid-cols-2',
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
                                <R2BillingTable
                                    summary={summary}
                                    financials={financials}
                                />
                            </PageSection>
                        </>
                    )}
                </R2ReportBody>
            </div>
        </>
    );
}

BillingReport.layout = {
    breadcrumbs: [
        { title: t('nav.reports'), href: reportsIndex() },
        { title: t('reports_r2.billing.title'), href: billing() },
    ],
};
