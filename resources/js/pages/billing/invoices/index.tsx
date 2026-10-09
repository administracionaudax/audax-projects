import { Head, Link, router } from '@inertiajs/react';
import { FileText, Search, X } from 'lucide-react';
import { useEffect, useId, useState } from 'react';
import { BillingHeader } from '@/components/billing/billing-header';
import { CollectionBar } from '@/components/billing/collection-bar';
import type {
    CollectionBarData,
    CollectionKey,
} from '@/components/billing/collection-bar';
import {
    ChipSelect,
    PeriodChip,
    RemovableChip,
} from '@/components/billing/filter-chips';
import type { PeriodKey } from '@/components/billing/filter-chips';
import {
    InvoiceTable,
    toQueryString,
} from '@/components/billing/invoice-table';
import type {
    InvoiceQuery,
    InvoiceSortColumn,
    InvoiceTotals,
} from '@/components/billing/invoice-table';
import { FilterSheet } from '@/components/billing/filter-sheet';
import { ViewTabs } from '@/components/billing/view-tabs';
import { EmptyState } from '@/components/empty-state';
import { ListPagination } from '@/components/projects-list/list-pagination';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { t } from '@/lib/i18n';
import { tCount } from '@/lib/people';
import type { HoldedInvoiceSummary } from '@/types';

export type InvoiceView =
    | 'todas'
    | 'por-cobrar'
    | 'vencidas'
    | 'sin-proyecto'
    | 'borradores'
    | 'rectificativas';

const VIEWS: InvoiceView[] = [
    'todas',
    'por-cobrar',
    'vencidas',
    'sin-proyecto',
    'borradores',
    'rectificativas',
];

type Filters = {
    vista: InvoiceView;
    periodo: PeriodKey | null;
    desde: string | null;
    hasta: string | null;
    buscar: string;
    cliente: number | null;
    servicio: string[];
    cobro: CollectionKey | null;
    orden: InvoiceSortColumn;
    dir: 'asc' | 'desc';
    estado: string | null;
    tipo: string | null;
    enlace: string | null;
};

type Props = {
    invoices: {
        data: HoldedInvoiceSummary[];
        meta: {
            current_page: number;
            last_page: number;
            from: number | null;
            to: number | null;
            total: number;
        };
        links: { prev: string | null; next: string | null };
    };
    filters: Filters;
    /** La query de la URL que reproduce el listado (sin la página). */
    list_query: InvoiceQuery;
    period: { key: string; from: string | null; to: string | null };
    views: Record<InvoiceView, number>;
    bar: CollectionBarData;
    totals: InvoiceTotals;
    clients: { id: number; name: string }[];
    services: string[];
    today: string;
};

const URL = '/facturacion/facturas';

/** Cliente y número, de la A a la Z; fechas e importes, de más a menos (como InvoiceList). */
const defaultDirection = (column: InvoiceSortColumn) =>
    column === 'cliente' || column === 'numero' ? 'asc' : 'desc';

/**
 * Facturas leídas de Holded (Fase 12, F1; D-385, D-406 y D-407): solo lectura (Holded sigue
 * emitiendo). Vistas por tarea con su número, la barra de importes que filtra, los filtros en una
 * línea de chips (búsqueda al escribir, periodo, cliente y servicio), orden por columnas, totales
 * al pie y paginación de 50. Todo en la URL: se puede compartir y la ficha vuelve aquí con los mismos
 * filtros.
 */
export default function InvoicesIndex({
    invoices,
    filters,
    list_query: listQuery,
    period,
    views,
    bar,
    totals,
    clients,
    services,
    today,
}: Props) {
    const id = useId();
    const [search, setSearch] = useState(filters.buscar);

    const visit = (patch: InvoiceQuery) => {
        const next: InvoiceQuery = { ...listQuery, ...patch };

        router.get(URL + toQueryString(next), undefined, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    // La búsqueda filtra al dejar de escribir (300 ms), como los demás filtros, sin pulsar nada.
    useEffect(() => {
        if (search.trim() === filters.buscar.trim()) {
            return;
        }

        const timer = window.setTimeout(
            () =>
                router.get(
                    URL +
                        toQueryString({
                            ...listQuery,
                            buscar: search.trim() || null,
                        }),
                    undefined,
                    {
                        preserveState: true,
                        preserveScroll: true,
                        replace: true,
                    },
                ),
            300,
        );

        return () => window.clearTimeout(timer);
    }, [search, filters.buscar, listQuery]);

    // Otra vista conserva la búsqueda, el periodo y los filtros; no el tramo de cobro, que es de la vista.
    const viewHref = (view: InvoiceView) =>
        URL +
        toQueryString({
            ...listQuery,
            vista: view === 'todas' ? null : view,
            cobro: null,
        });

    const sortBy = (column: InvoiceSortColumn) => {
        const direction =
            column === filters.orden
                ? filters.dir === 'asc'
                    ? 'desc'
                    : 'asc'
                : defaultDirection(column);

        visit({
            orden: column === 'fecha' ? null : column,
            dir: direction === defaultDirection(column) ? null : direction,
        });
    };

    const legacy: { key: 'estado' | 'tipo' | 'enlace'; value: string }[] = [];
    if (filters.estado) {
        legacy.push({
            key: 'estado',
            value: t(
                `billing.collection.${filters.estado as 'paid' | 'partial' | 'unpaid' | 'overdue' | 'cancelled' | 'draft'}`,
            ),
        });
    }
    if (filters.tipo) {
        legacy.push({
            key: 'tipo',
            value: t(
                `billing.document.${filters.tipo as 'invoice' | 'credit_note'}`,
            ),
        });
    }
    if (filters.enlace) {
        legacy.push({
            key: 'enlace',
            value: t(
                filters.enlace === 'con'
                    ? 'billing.filters.linked'
                    : 'billing.filters.unlinked',
            ),
        });
    }

    const filtered =
        filters.buscar !== '' ||
        filters.periodo !== null ||
        filters.cliente !== null ||
        filters.servicio.length > 0 ||
        filters.cobro !== null ||
        legacy.length > 0;
    const excluded = invoices.meta.total - totals.count;
    // En el móvil, los filtros van en una hoja con su número (I7).
    const activeFilters =
        (filters.periodo !== null ? 1 : 0) +
        (filters.cliente !== null ? 1 : 0) +
        (filters.servicio.length > 0 ? 1 : 0) +
        legacy.length;

    return (
        <>
            <Head title={t('billing.invoices.title')} />

            <div className="flex min-w-0 flex-1 flex-col gap-5 p-4 md:p-6">
                <BillingHeader
                    current="facturas"
                    title={t('billing.invoices.title')}
                    description={t('billing.invoices.description')}
                />

                <ViewTabs
                    label={t('billing.invoices.views_label')}
                    current={filters.vista}
                    dataTest="invoice-views"
                    tabs={VIEWS.map((view) => ({
                        id: view,
                        label: t(`billing.invoices.view.${view}`),
                        href: viewHref(view),
                        count: views[view],
                    }))}
                />

                <CollectionBar
                    data={bar}
                    selected={filters.cobro}
                    onSelect={(key) => visit({ cobro: key })}
                />

                <div
                    role="search"
                    aria-label={t('billing.filters.label')}
                    className="flex flex-wrap items-center gap-2"
                >
                    <div className="relative w-full sm:w-72">
                        <Label htmlFor={`${id}-search`} className="sr-only">
                            {t('billing.filters.search')}
                        </Label>
                        <Search
                            aria-hidden="true"
                            className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                        />
                        <Input
                            id={`${id}-search`}
                            type="search"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder={t(
                                'billing.filters.search_placeholder',
                            )}
                            className="h-8 pl-8"
                            autoComplete="off"
                        />
                    </div>
                    <FilterSheet count={activeFilters}>
                        <PeriodChip
                            period={period}
                            explicit={filters.periodo !== null}
                            today={today}
                            onChange={(patch) => visit(patch)}
                            onClear={() =>
                                visit({
                                    periodo: null,
                                    desde: null,
                                    hasta: null,
                                })
                            }
                        />
                        <ChipSelect
                            label={t('billing.filters.client')}
                            allLabel={t('billing.filters.all_clients')}
                            searchPlaceholder={t(
                                'billing.filters.search_client',
                            )}
                            options={clients.map((client) => ({
                                value: String(client.id),
                                label: client.name,
                            }))}
                            value={
                                filters.cliente === null
                                    ? []
                                    : [String(filters.cliente)]
                            }
                            onChange={(value) =>
                                visit({ cliente: value[0] ?? null })
                            }
                            dataTest="invoice-client"
                        />
                        <ChipSelect
                            label={t('billing.filters.service')}
                            allLabel={t('billing.filters.all_services')}
                            searchPlaceholder={t(
                                'billing.filters.search_service',
                            )}
                            multiple
                            options={services.map((service) => ({
                                value: service,
                                label: t(
                                    `billing.invoicing.services.${service}` as 'billing.invoicing.services.fees',
                                ),
                            }))}
                            value={filters.servicio}
                            onChange={(value) => visit({ servicio: value })}
                            dataTest="invoice-service"
                        />
                        {legacy.map((chip) => (
                            <RemovableChip
                                key={chip.key}
                                label={t(`billing.filters.legacy.${chip.key}`)}
                                value={chip.value}
                                onRemove={() => visit({ [chip.key]: null })}
                            />
                        ))}
                    </FilterSheet>
                    {filtered ? (
                        <Button variant="ghost" size="sm" asChild>
                            <Link
                                href={
                                    URL +
                                    toQueryString(
                                        filters.vista === 'todas'
                                            ? {}
                                            : { vista: filters.vista },
                                    )
                                }
                                preserveScroll
                                onClick={() => setSearch('')}
                            >
                                <X aria-hidden="true" />
                                {t('billing.filters.clear')}
                            </Link>
                        </Button>
                    ) : null}
                </div>

                <p className="sr-only" aria-live="polite">
                    {tCount('billing.invoices.results', invoices.meta.total)}
                </p>

                {invoices.data.length === 0 ? (
                    <EmptyState
                        icon={FileText}
                        title={t(
                            filtered || filters.vista !== 'todas'
                                ? 'billing.invoices.none_filtered'
                                : 'billing.invoices.none',
                        )}
                        description={t(
                            filtered || filters.vista !== 'todas'
                                ? 'billing.invoices.none_filtered_description'
                                : 'billing.invoices.none_description',
                        )}
                    >
                        {/* El paso siguiente (R7): quitar los filtros o ver todas. */}
                        {filtered || filters.vista !== 'todas' ? (
                            <Button variant="outline" size="sm" asChild>
                                <Link href={URL} onClick={() => setSearch('')}>
                                    {t('billing.invoices.see_all')}
                                </Link>
                            </Button>
                        ) : null}
                    </EmptyState>
                ) : (
                    <div className="grid gap-3">
                        <InvoiceTable
                            invoices={invoices.data}
                            caption={t('billing.invoices.caption', {
                                view: t(
                                    `billing.invoices.view.${filters.vista}`,
                                ),
                            })}
                            today={today}
                            linkQuery={{
                                ...listQuery,
                                ...(invoices.meta.current_page > 1
                                    ? { pagina: invoices.meta.current_page }
                                    : {}),
                            }}
                            sort={{
                                column: filters.orden,
                                direction: filters.dir,
                                onSort: sortBy,
                            }}
                            totals={totals}
                            footerNote={
                                excluded > 0
                                    ? tCount(
                                          'billing.invoices.footer_excluded',
                                          excluded,
                                      )
                                    : undefined
                            }
                        />
                        <ListPagination
                            page={{
                                meta: { ...invoices.meta, per_page: 50 },
                                links: invoices.links,
                            }}
                            label={t('billing.invoices.pagination')}
                        />
                    </div>
                )}
            </div>
        </>
    );
}

InvoicesIndex.layout = {
    breadcrumbs: [
        { title: t('billing.section'), href: '/facturacion' },
        { title: t('billing.invoices.title'), href: URL },
    ],
};
