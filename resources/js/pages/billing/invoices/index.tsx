import { Head, Link, router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, FileText, Search, X } from 'lucide-react';
import type { FormEvent } from 'react';
import { useId, useState } from 'react';
import { BillingTabs } from '@/components/billing/billing-nav';
import { InvoiceTable } from '@/components/billing/invoice-table';
import { LastSync } from '@/components/billing/last-sync';
import { COLLECTION_STATUSES } from '@/components/billing/sold-vs-actual-lib';
import { DatePicker } from '@/components/domain/date-picker';
import { EmptyState } from '@/components/empty-state';
import { PageHeader } from '@/components/projects-list/page-header';
import { KpiCard } from '@/components/reports/kpi-card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatCurrency, formatNumber } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { HoldedInvoiceSummary, HoldedSyncSummary } from '@/types';

type Filters = {
    buscar: string;
    estado: string | null;
    tipo: string | null;
    cliente: number | null;
    enlace: string | null;
    desde: string | null;
    hasta: string | null;
};

type Props = {
    invoices: {
        data: HoldedInvoiceSummary[];
        current_page: number;
        last_page: number;
        total: number;
        prev_url: string | null;
        next_url: string | null;
    };
    totals: {
        count: number;
        subtotal: string;
        total: string;
        paid: string;
        pending: string;
    };
    filters: Filters;
    clients: { id: number; name: string }[];
    unlinked: number;
    last_sync: HoldedSyncSummary | null;
};

const URL = '/facturacion/facturas';
const ALL = '__all__';

/**
 * Facturas leídas de Holded (Fase 12, F1; D-385): solo lectura (Holded sigue emitiendo). Búsqueda
 * por número o cliente, filtros por estado de cobro, tipo, cliente, enlace y fechas, y el sumatorio
 * de lo filtrado (sin las anuladas).
 */
export default function InvoicesIndex({
    invoices,
    totals,
    filters,
    clients,
    unlinked,
    last_sync: lastSync,
}: Props) {
    const id = useId();
    const [search, setSearch] = useState(filters.buscar);

    const visit = (patch: Partial<Filters>) => {
        const next = { ...filters, ...patch };
        const query = Object.fromEntries(
            Object.entries(next).filter(
                ([, value]) => value !== null && value !== '',
            ),
        );
        router.get(URL, query, { preserveState: true, preserveScroll: true });
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        visit({ buscar: search.trim() });
    };

    const active =
        filters.buscar !== '' ||
        [
            filters.estado,
            filters.tipo,
            filters.cliente,
            filters.enlace,
            filters.desde,
            filters.hasta,
        ].some((value) => value !== null);

    return (
        <>
            <Head title={t('billing.invoices.title')} />

            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={t('billing.section')}
                    description={t('billing.invoices.description')}
                    actions={<LastSync sync={lastSync} />}
                />
                <BillingTabs current="facturas" />

                <section
                    aria-label={t('billing.invoices.totals')}
                    className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4"
                >
                    <KpiCard
                        label={t('billing.invoices.kpi_invoiced')}
                        definition={t(
                            'billing.invoices.kpi_invoiced_definition',
                        )}
                        value={formatCurrency(totals.subtotal)}
                        detail={t('billing.invoices.kpi_count', {
                            count: formatNumber(totals.count),
                        })}
                    />
                    <KpiCard
                        label={t('billing.invoices.kpi_total')}
                        definition={t('billing.invoices.kpi_total_definition')}
                        value={formatCurrency(totals.total)}
                    />
                    <KpiCard
                        label={t('billing.invoices.kpi_paid')}
                        definition={t('billing.invoices.kpi_paid_definition')}
                        value={formatCurrency(totals.paid)}
                    />
                    <KpiCard
                        label={t('billing.invoices.kpi_pending')}
                        definition={t(
                            'billing.invoices.kpi_pending_definition',
                        )}
                        value={formatCurrency(totals.pending)}
                    />
                </section>

                {unlinked > 0 && filters.enlace !== 'sin' ? (
                    <p className="flex flex-wrap items-center gap-2 rounded-md bg-warning-soft px-3 py-2 text-sm text-foreground">
                        {t('billing.invoices.unlinked_notice', {
                            count: unlinked,
                        })}
                        <Button
                            variant="link"
                            size="sm"
                            className="h-auto p-0"
                            onClick={() => visit({ enlace: 'sin' })}
                        >
                            {t('billing.invoices.show_unlinked')}
                        </Button>
                    </p>
                ) : null}

                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-7">
                    <form
                        role="search"
                        onSubmit={submit}
                        className="grid gap-1 sm:col-span-2"
                    >
                        <Label htmlFor={`${id}-search`}>
                            {t('billing.filters.search')}
                        </Label>
                        <div className="flex gap-2">
                            <Input
                                id={`${id}-search`}
                                value={search}
                                onChange={(event) =>
                                    setSearch(event.target.value)
                                }
                                placeholder={t(
                                    'billing.filters.search_placeholder',
                                )}
                            />
                            <Button
                                type="submit"
                                variant="outline"
                                size="icon"
                                aria-label={t('billing.filters.search_submit')}
                            >
                                <Search aria-hidden="true" />
                            </Button>
                        </div>
                    </form>
                    <FilterSelect
                        id={`${id}-status`}
                        label={t('billing.filters.status')}
                        value={filters.estado}
                        options={COLLECTION_STATUSES.map((status) => ({
                            value: status,
                            label: t(`billing.collection.${status}`),
                        }))}
                        onChange={(value) => visit({ estado: value })}
                    />
                    <FilterSelect
                        id={`${id}-kind`}
                        label={t('billing.filters.document')}
                        value={filters.tipo}
                        options={[
                            {
                                value: 'invoice',
                                label: t('billing.document.invoice'),
                            },
                            {
                                value: 'credit_note',
                                label: t('billing.document.credit_note'),
                            },
                        ]}
                        onChange={(value) => visit({ tipo: value })}
                    />
                    <FilterSelect
                        id={`${id}-client`}
                        label={t('billing.filters.client')}
                        value={
                            filters.cliente === null
                                ? null
                                : String(filters.cliente)
                        }
                        options={clients.map((client) => ({
                            value: String(client.id),
                            label: client.name,
                        }))}
                        onChange={(value) =>
                            visit({
                                cliente: value === null ? null : Number(value),
                            })
                        }
                    />
                    <FilterSelect
                        id={`${id}-link`}
                        label={t('billing.filters.link')}
                        value={filters.enlace}
                        options={[
                            {
                                value: 'con',
                                label: t('billing.filters.linked'),
                            },
                            {
                                value: 'sin',
                                label: t('billing.filters.unlinked'),
                            },
                        ]}
                        onChange={(value) => visit({ enlace: value })}
                    />
                    <div className="grid gap-1">
                        <Label htmlFor={`${id}-from`}>
                            {t('billing.filters.from')}
                        </Label>
                        <DatePicker
                            id={`${id}-from`}
                            value={filters.desde}
                            onChange={(value) => visit({ desde: value })}
                        />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor={`${id}-to`}>
                            {t('billing.filters.to')}
                        </Label>
                        <DatePicker
                            id={`${id}-to`}
                            value={filters.hasta}
                            onChange={(value) => visit({ hasta: value })}
                        />
                    </div>
                </div>
                {active ? (
                    <div>
                        <Button variant="ghost" size="sm" asChild>
                            <Link href={URL} preserveScroll>
                                <X aria-hidden="true" />
                                {t('billing.filters.clear')}
                            </Link>
                        </Button>
                    </div>
                ) : null}

                {invoices.data.length === 0 ? (
                    <EmptyState
                        icon={FileText}
                        title={t(
                            active
                                ? 'billing.invoices.none_filtered'
                                : 'billing.invoices.none',
                        )}
                        description={t(
                            active
                                ? 'billing.invoices.none_filtered_description'
                                : 'billing.invoices.none_description',
                        )}
                    />
                ) : (
                    <>
                        <InvoiceTable
                            invoices={invoices.data}
                            caption={t('billing.invoices.title')}
                        />
                        {invoices.last_page > 1 ? (
                            <nav
                                aria-label={t('billing.invoices.pagination')}
                                className="flex items-center justify-between gap-2"
                            >
                                <p className="text-sm text-muted-foreground">
                                    {t('billing.invoices.page', {
                                        page: invoices.current_page,
                                        pages: invoices.last_page,
                                    })}
                                </p>
                                <div className="flex gap-2">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        asChild
                                        disabled={!invoices.prev_url}
                                    >
                                        <Link
                                            href={invoices.prev_url ?? URL}
                                            preserveScroll
                                        >
                                            <ChevronLeft aria-hidden="true" />
                                            {t('billing.invoices.previous')}
                                        </Link>
                                    </Button>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        asChild
                                        disabled={!invoices.next_url}
                                    >
                                        <Link
                                            href={invoices.next_url ?? URL}
                                            preserveScroll
                                        >
                                            {t('billing.invoices.next')}
                                            <ChevronRight aria-hidden="true" />
                                        </Link>
                                    </Button>
                                </div>
                            </nav>
                        ) : null}
                    </>
                )}
            </div>
        </>
    );
}

function FilterSelect({
    id,
    label,
    value,
    options,
    onChange,
}: {
    id: string;
    label: string;
    value: string | null;
    options: { value: string; label: string }[];
    onChange: (value: string | null) => void;
}) {
    return (
        <div className="grid gap-1">
            <Label htmlFor={id}>{label}</Label>
            <Select
                value={value ?? ALL}
                onValueChange={(next) => onChange(next === ALL ? null : next)}
            >
                <SelectTrigger id={id} className="w-full">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value={ALL}>
                        {t('billing.filters.all')}
                    </SelectItem>
                    {options.map((option) => (
                        <SelectItem key={option.value} value={option.value}>
                            {option.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </div>
    );
}

InvoicesIndex.layout = {
    breadcrumbs: [
        { title: t('billing.section'), href: URL },
        { title: t('billing.invoices.title'), href: URL },
    ],
};
