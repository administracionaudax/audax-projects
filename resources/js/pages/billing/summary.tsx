import { Head, Link, router } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import {
    ArrowRight,
    CircleAlert,
    Gauge,
    Link2Off,
    PartyPopper,
    UserRoundX,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { BillingHeader } from '@/components/billing/billing-header';
import { PeriodChip } from '@/components/billing/filter-chips';
import type { PeriodKey } from '@/components/billing/filter-chips';
import { HelpTip } from '@/components/billing/help-tip';
import { toQueryString } from '@/components/billing/invoice-table';
import { YearDelta } from '@/components/billing/invoicing-kpis';
import {
    agingLabel,
    amount,
    compactCurrency,
} from '@/components/billing/invoicing-lib';
import { KpiGroup } from '@/components/billing/kpi-group';
import { SummaryMonthChart } from '@/components/billing/summary-month-chart';
import { unbilledSources } from '@/components/billing/unbilled-sources';
import { sequentialColor } from '@/components/charts/chart-config';
import { EmptyState, HeroEmptyState } from '@/components/empty-state';
import { KpiCard } from '@/components/reports/kpi-card';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatCurrency, formatMinutes } from '@/lib/format';
import { t } from '@/lib/i18n';
import { tCount } from '@/lib/people';
import { cn } from '@/lib/utils';
import type { AgingKey, BillingSummaryData } from '@/types';

type Props = {
    summary: BillingSummaryData;
    explicit_period: boolean;
    can: { viewBanks: boolean };
};

const URL = '/facturacion';
const INVOICES = '/facturacion/facturas';

/** El Resumen no ofrece «Todo»: las cifras son de un periodo y se comparan con el año anterior. */
const PRESETS: Exclude<PeriodKey, 'rango' | 'todo'>[] = [
    'anio',
    'anio-anterior',
    'trimestre',
    'mes',
    '12-meses',
];

/** Enlace de una cifra o una línea: «Ver las 5 facturas →». */
function MoreLink({
    href,
    children,
    dataTest,
}: {
    href: string;
    children: ReactNode;
    dataTest?: string;
}) {
    return (
        <Link
            href={href}
            className={cn(
                'self-start rounded-md text-xs text-primary-text hover:underline',
                FOCUS_RING,
            )}
            data-test={dataTest}
        >
            {children}
            <ArrowRight
                aria-hidden="true"
                className="ml-1 inline size-3.5 align-[-3px]"
            />
        </Link>
    );
}

/**
 * Resumen de Facturación (I1, D-411): la portada orientada a la acción. Arriba, lo que requiere
 * atención (vencidas, facturas sin proyecto, contactos sin casar y bolsas por encima del 85 %) o,
 * si no hay nada, «Todo al día»; después cuatro cifras en dos grupos con su base (sin IVA y con IVA),
 * cada una con su enlace; la gráfica de lo facturado y cobrado por mes, y las listas «Por cobrar» y
 * «Por facturar». El periodo, el año en curso por defecto.
 */
export default function BillingSummaryPage({
    summary,
    explicit_period: explicitPeriod,
    can,
}: Props) {
    const { attention, kpis } = summary;
    const reportQuery = toQueryString(summary.report_query);
    const visitPeriod = (patch: {
        periodo: PeriodKey | null;
        desde?: string | null;
        hasta?: string | null;
    }) =>
        router.get(URL + toQueryString(patch), undefined, {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        });
    const nothing =
        attention.overdue.count === 0 &&
        attention.unlinked.count === 0 &&
        attention.contacts.count === 0 &&
        attention.banks.count === 0;

    return (
        <>
            <Head title={t('billing.summary.title')} />

            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
                <BillingHeader
                    current="resumen"
                    title={t('billing.summary.title')}
                    description={t('billing.summary.description')}
                />

                <div className="flex flex-wrap items-center gap-2">
                    <PeriodChip
                        period={summary.period}
                        explicit={explicitPeriod}
                        today={summary.today}
                        presets={PRESETS}
                        dataTest="summary-period"
                        onChange={visitPeriod}
                        onClear={() => visitPeriod({ periodo: null })}
                    />
                </div>

                {nothing ? (
                    <HeroEmptyState
                        icon={PartyPopper}
                        titleAs="h2"
                        eyebrow={t('billing.summary.attention.title')}
                        title={t('billing.summary.attention.all_clear')}
                        description={t(
                            'billing.summary.attention.all_clear_description',
                        )}
                        className="py-8 sm:py-10"
                    />
                ) : (
                    <AttentionList summary={summary} can={can} />
                )}

                <div className="grid gap-5 lg:grid-cols-2">
                    <KpiGroup
                        title={t('billing.summary.group_invoiced')}
                        help={<p>{t('billing.summary.help_invoiced')}</p>}
                        columns="grid-cols-2"
                    >
                        <KpiCard
                            label={t('billing.summary.kpi.invoiced')}
                            definition={t(
                                'billing.summary.kpi.invoiced_definition',
                            )}
                            value={compactCurrency(kpis.invoiced)}
                        >
                            {kpis.variation_pct === null ? (
                                <p className="text-xs text-muted-foreground">
                                    {t(
                                        'billing.invoicing.kpis.no_previous_year',
                                        { year: summary.previous_year },
                                    )}
                                </p>
                            ) : (
                                <YearDelta
                                    current={amount(kpis.invoiced)}
                                    previous={amount(kpis.previous_invoiced)}
                                    year={summary.previous_year}
                                />
                            )}
                            <MoreLink
                                href={`/facturacion/ventas${reportQuery}`}
                                dataTest="kpi-invoiced"
                            >
                                {tCount(
                                    'billing.summary.kpi.invoices',
                                    kpis.invoices,
                                )}
                            </MoreLink>
                        </KpiCard>
                        <KpiCard
                            label={t('billing.summary.kpi.unbilled')}
                            definition={t(
                                'billing.summary.kpi.unbilled_definition',
                            )}
                            value={compactCurrency(kpis.unbilled)}
                        >
                            <MoreLink
                                href={`/facturacion/por-facturar${reportQuery}`}
                                dataTest="kpi-unbilled"
                            >
                                {tCount(
                                    'billing.summary.kpi.unbilled_clients',
                                    kpis.unbilled_clients,
                                )}
                            </MoreLink>
                        </KpiCard>
                    </KpiGroup>
                    <KpiGroup
                        title={t('billing.summary.group_collected')}
                        help={<p>{t('billing.summary.help_collected')}</p>}
                        columns="grid-cols-2"
                    >
                        <KpiCard
                            label={t('billing.summary.kpi.outstanding')}
                            definition={t(
                                'billing.summary.kpi.outstanding_definition',
                            )}
                            value={compactCurrency(kpis.outstanding)}
                        >
                            <MoreLink
                                href={`${INVOICES}?vista=por-cobrar`}
                                dataTest="kpi-outstanding"
                            >
                                {tCount(
                                    'billing.summary.kpi.outstanding_count',
                                    kpis.outstanding_count,
                                )}
                            </MoreLink>
                        </KpiCard>
                        <KpiCard
                            label={t('billing.summary.kpi.overdue')}
                            definition={t(
                                'billing.summary.kpi.overdue_definition',
                            )}
                            value={compactCurrency(kpis.overdue)}
                        >
                            <MoreLink
                                href={`${INVOICES}?vista=vencidas`}
                                dataTest="kpi-overdue"
                            >
                                {tCount(
                                    'billing.summary.kpi.overdue_count',
                                    kpis.overdue_count,
                                )}
                            </MoreLink>
                        </KpiCard>
                    </KpiGroup>
                </div>

                <section className="rounded-md border bg-card p-4">
                    <SummaryMonthChart
                        months={summary.months}
                        previousYear={summary.previous_year}
                    />
                </section>

                <div className="grid gap-5 lg:grid-cols-2">
                    <Receivable summary={summary} />
                    <Unbilled summary={summary} reportQuery={reportQuery} />
                </div>
            </div>
        </>
    );
}

type AttentionItem = {
    key: string;
    icon: LucideIcon;
    iconClass: string;
    text: string;
    detail?: string;
    action: string;
    href: string | null;
};

/** «Requiere atención» (I1): una línea por tipo con su número, su importe y su enlace. */
function AttentionList({
    summary,
    can,
}: {
    summary: BillingSummaryData;
    can: { viewBanks: boolean };
}) {
    const { attention } = summary;
    const items: AttentionItem[] = [];

    if (attention.overdue.count > 0) {
        items.push({
            key: 'overdue',
            icon: CircleAlert,
            iconClass: 'text-danger',
            text: tCount(
                'billing.summary.attention.overdue',
                attention.overdue.count,
                { amount: formatCurrency(attention.overdue.amount) },
            ),
            detail:
                attention.overdue.oldest_days === null
                    ? undefined
                    : tCount(
                          'billing.summary.attention.oldest',
                          attention.overdue.oldest_days,
                      ),
            action: t('billing.summary.attention.overdue_action'),
            href: `${INVOICES}?vista=vencidas&orden=vencimiento&dir=asc`,
        });
    }

    if (attention.unlinked.count > 0) {
        items.push({
            key: 'unlinked',
            icon: Link2Off,
            iconClass: 'text-warning',
            text: tCount(
                'billing.summary.attention.unlinked',
                attention.unlinked.count,
                { amount: formatCurrency(attention.unlinked.amount) },
            ),
            detail: t('billing.summary.attention.unlinked_detail'),
            action: t('billing.summary.attention.review_action'),
            href: '/facturacion/por-revisar?tipo=facturas',
        });
    }

    if (attention.contacts.count > 0) {
        items.push({
            key: 'contacts',
            icon: UserRoundX,
            iconClass: 'text-warning',
            text: tCount(
                'billing.summary.attention.contacts',
                attention.contacts.count,
                { amount: formatCurrency(attention.contacts.amount) },
            ),
            detail: t('billing.summary.attention.contacts_detail'),
            action: t('billing.summary.attention.match_action'),
            href: '/facturacion/por-revisar?tipo=contactos',
        });
    }

    if (attention.banks.count > 0) {
        items.push({
            key: 'banks',
            icon: Gauge,
            iconClass: 'text-warning',
            text: tCount(
                'billing.summary.attention.banks',
                attention.banks.count,
                {
                    pct: attention.banks.threshold,
                },
            ),
            detail:
                attention.banks.over > 0
                    ? tCount(
                          'billing.summary.attention.banks_over',
                          attention.banks.over,
                      )
                    : undefined,
            action: t('billing.summary.attention.banks_action'),
            href: can.viewBanks ? '/bolsas' : null,
        });
    }

    return (
        <section
            aria-labelledby="attention-title"
            className="grid gap-2"
            data-test="summary-attention"
        >
            <div className="flex items-center gap-1">
                <h2
                    id="attention-title"
                    className="text-xs font-medium tracking-[0.12em] text-muted-foreground uppercase"
                >
                    {t('billing.summary.attention.title')}
                </h2>
                <HelpTip topic={t('billing.summary.attention.title')}>
                    <p>{t('billing.summary.attention.help')}</p>
                </HelpTip>
            </div>
            <ul className="divide-y rounded-md border bg-card">
                {items.map((item) => (
                    <li
                        key={item.key}
                        className="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 px-4 py-3"
                        data-test={`attention-${item.key}`}
                    >
                        <span className="flex min-w-0 items-start gap-2.5">
                            <item.icon
                                aria-hidden="true"
                                className={cn(
                                    'mt-0.5 size-4 shrink-0',
                                    item.iconClass,
                                )}
                            />
                            <span className="min-w-0 text-sm">
                                {item.text}
                                {item.detail ? (
                                    <span className="text-muted-foreground">
                                        {' · '}
                                        {item.detail}
                                    </span>
                                ) : null}
                            </span>
                        </span>
                        {item.href ? (
                            <Link
                                href={item.href}
                                className={cn(
                                    'ml-6.5 inline-flex shrink-0 items-center gap-1 rounded-md text-sm text-primary-text hover:underline sm:ml-0',
                                    FOCUS_RING,
                                )}
                            >
                                {item.action}
                                <ArrowRight
                                    aria-hidden="true"
                                    className="size-4"
                                />
                            </Link>
                        ) : null}
                    </li>
                ))}
            </ul>
        </section>
    );
}

/** Enlace del listado de facturas para un tramo de antigüedad (vencidas por fecha, o por vencer). */
function agingHref(key: AgingKey): string {
    return key === 'current'
        ? `${INVOICES}?vista=por-cobrar&cobro=por-vencer`
        : `${INVOICES}?vista=vencidas&orden=vencimiento&dir=asc`;
}

/**
 * «Por cobrar» (a hoy): la barra de antigüedad por tramos con la rampa secuencial de --chart-1
 * (más intensa cuanto más antigua, D-400) y hueco de 2 px entre tramos; cada tramo es un enlace al
 * listado, y debajo los cinco clientes que más deben.
 */
function Receivable({ summary }: { summary: BillingSummaryData }) {
    const { aging, clients } = summary.receivable;
    const values = aging.map((bucket) => Math.max(0, amount(bucket.amount)));
    const total = values.reduce((sum, value) => sum + value, 0);

    return (
        <section
            aria-labelledby="receivable-title"
            className="grid content-start gap-3 rounded-md border bg-card p-4"
            data-test="summary-receivable"
        >
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <div className="flex items-center gap-1">
                    <h2 id="receivable-title" className="text-base font-medium">
                        {t('billing.summary.receivable.title')}
                    </h2>
                    <HelpTip topic={t('billing.summary.receivable.title')}>
                        <p>{t('billing.summary.receivable.help')}</p>
                    </HelpTip>
                </div>
                <span className="tabular text-sm text-muted-foreground">
                    {t('billing.summary.receivable.total', {
                        amount: formatCurrency(summary.kpis.outstanding),
                    })}
                </span>
            </div>

            {total === 0 ? (
                <EmptyState
                    title={t('billing.summary.receivable.empty')}
                    description={t(
                        'billing.summary.receivable.empty_description',
                    )}
                />
            ) : (
                <>
                    <div
                        aria-hidden="true"
                        className="flex h-3 gap-[2px] bg-card"
                    >
                        {aging.map((bucket, index) =>
                            values[index] > 0 ? (
                                <span
                                    key={bucket.key}
                                    title={`${agingLabel(bucket.key)}: ${formatCurrency(bucket.amount)}`}
                                    style={{
                                        flexGrow: values[index] / total,
                                        flexBasis: 0,
                                        minWidth: 4,
                                        backgroundColor: sequentialColor(
                                            index + 1,
                                        ),
                                    }}
                                />
                            ) : null,
                        )}
                    </div>
                    <ul className="grid grid-cols-3 gap-x-3 gap-y-1 sm:grid-cols-5">
                        {aging.map((bucket, index) => (
                            <li key={bucket.key} className="min-w-0">
                                <Link
                                    href={agingHref(bucket.key)}
                                    className={cn(
                                        'grid rounded-md px-1 py-0.5 text-sm hover:bg-accent/60',
                                        bucket.count === 0 &&
                                            'pointer-events-none text-muted-foreground',
                                        FOCUS_RING,
                                    )}
                                    aria-disabled={bucket.count === 0}
                                    tabIndex={
                                        bucket.count === 0 ? -1 : undefined
                                    }
                                    data-test={`aging-${bucket.key}`}
                                >
                                    <span className="flex items-center gap-1.5 text-xs text-muted-foreground">
                                        <span
                                            aria-hidden="true"
                                            className="size-2.5 shrink-0"
                                            style={{
                                                backgroundColor:
                                                    sequentialColor(index + 1),
                                            }}
                                        />
                                        {t(
                                            `billing.invoicing.aging_short.${bucket.key}`,
                                        )}
                                    </span>
                                    <span className="tabular">
                                        {compactCurrency(bucket.amount)}
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>

                    <h3 className="pt-2 text-sm text-muted-foreground">
                        {t('billing.summary.receivable.clients')}
                    </h3>
                    <ul className="divide-y" data-test="receivable-clients">
                        {clients.map((row) => {
                            const name =
                                row.client?.name ??
                                row.contact_name ??
                                t('billing.invoice.no_client');
                            const href = row.client
                                ? `${INVOICES}?vista=por-cobrar&cliente=${row.client.id}`
                                : `${INVOICES}?vista=por-cobrar${toQueryString({ buscar: row.contact_name }).replace('?', '&')}`;

                            return (
                                <li
                                    key={`${row.client?.id ?? 'c'}-${row.contact_name ?? ''}`}
                                >
                                    <Link
                                        href={href}
                                        className={cn(
                                            'flex items-baseline justify-between gap-3 rounded-md px-1 py-2 text-sm hover:bg-accent/60',
                                            FOCUS_RING,
                                        )}
                                    >
                                        <span className="min-w-0">
                                            <span className="block truncate text-primary-text">
                                                {name}
                                            </span>
                                            <span className="block text-xs text-muted-foreground">
                                                {row.overdue_count > 0
                                                    ? tCount(
                                                          'billing.summary.receivable.overdue',
                                                          row.overdue_count,
                                                      )
                                                    : tCount(
                                                          'billing.summary.receivable.invoices',
                                                          row.count,
                                                      )}
                                            </span>
                                        </span>
                                        <span className="tabular shrink-0">
                                            {formatCurrency(row.amount)}
                                        </span>
                                    </Link>
                                </li>
                            );
                        })}
                    </ul>
                </>
            )}
        </section>
    );
}

/** «Por facturar»: los clientes con más por facturar en el periodo (I10), con sus horas e importe. */
function Unbilled({
    summary,
    reportQuery,
}: {
    summary: BillingSummaryData;
    reportQuery: string;
}) {
    const { clients, total_clients: totalClients } = summary.unbilled;
    const base = '/facturacion/por-facturar';

    return (
        <section
            aria-labelledby="unbilled-title"
            className="grid content-start gap-3 rounded-md border bg-card p-4"
            data-test="summary-unbilled"
        >
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <div className="flex items-center gap-1">
                    <h2 id="unbilled-title" className="text-base font-medium">
                        {t('billing.nav.unbilled')}
                    </h2>
                    <HelpTip topic={t('billing.nav.unbilled')}>
                        <p>{t('billing.unbilled.help')}</p>
                    </HelpTip>
                </div>
                <span className="tabular text-sm text-muted-foreground">
                    {t('billing.summary.unbilled.total', {
                        amount: formatCurrency(summary.kpis.unbilled),
                    })}
                </span>
            </div>

            {clients.length === 0 ? (
                <EmptyState
                    title={t('billing.unbilled.empty')}
                    description={t(
                        'billing.summary.unbilled.empty_description',
                    )}
                />
            ) : (
                <>
                    <ul className="divide-y" data-test="unbilled-clients">
                        {clients.map((row) => (
                            <li key={row.client.id}>
                                <Link
                                    href={`${base}${reportQuery}${reportQuery === '' ? '?' : '&'}cliente[]=${row.client.id}`}
                                    className={cn(
                                        'flex items-baseline justify-between gap-3 rounded-md px-1 py-2 text-sm hover:bg-accent/60',
                                        FOCUS_RING,
                                    )}
                                >
                                    <span className="min-w-0">
                                        <span className="block truncate text-primary-text">
                                            {row.client.name}
                                        </span>
                                        <span className="block truncate text-xs text-muted-foreground">
                                            {unbilledSources(row.sources)}
                                        </span>
                                    </span>
                                    <span className="grid shrink-0 justify-items-end">
                                        <span className="tabular">
                                            {formatCurrency(row.amount ?? '0')}
                                        </span>
                                        {row.minutes > 0 ? (
                                            <span className="tabular text-xs text-muted-foreground">
                                                {t(
                                                    'billing.summary.unbilled.hours',
                                                    {
                                                        hours: formatMinutes(
                                                            row.minutes,
                                                        ),
                                                    },
                                                )}
                                            </span>
                                        ) : null}
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                    <MoreLink href={`${base}${reportQuery}`}>
                        {tCount('billing.summary.unbilled.all', totalClients)}
                    </MoreLink>
                </>
            )}
        </section>
    );
}

BillingSummaryPage.layout = {
    breadcrumbs: [
        { title: t('billing.section'), href: URL },
        { title: t('billing.summary.title'), href: URL },
    ],
};
