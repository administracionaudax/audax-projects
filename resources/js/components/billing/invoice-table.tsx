import { Link, router } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowUp,
    Ban,
    CircleCheck,
    FileCheck2,
    Link2,
    Link2Off,
    Undo2,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { StatusBadge } from '@/components/styleguide/status-badges';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatCurrency, formatDate } from '@/lib/format';
import { t } from '@/lib/i18n';
import { tCount } from '@/lib/people';
import { ROW_CLICK_CLASS, rowClickProps } from '@/lib/row-click';
import { cn } from '@/lib/utils';
import { useIsMobile } from '@/hooks/use-mobile';
import { todayInMadrid } from '@/lib/week';
import type { HoldedInvoiceSummary } from '@/types';
import { COLLECTION_META } from './billing-badges';
import { relativeCollection } from './billing-time';

export type InvoiceQuery = Record<
    string,
    string | number | string[] | null | undefined
>;

/** «?a=1&servicio[]=fees» (vacío sin parámetros): el formato que entiende Laravel. */
export function toQueryString(query: InvoiceQuery): string {
    const params = new URLSearchParams();

    for (const [key, value] of Object.entries(query)) {
        if (value === null || value === undefined || value === '') {
            continue;
        }

        if (Array.isArray(value)) {
            value.forEach((item) => params.append(`${key}[]`, item));
        } else {
            params.append(key, String(value));
        }
    }

    const string = params.toString();

    return string === '' ? '' : `?${string}`;
}

/**
 * Ficha de una factura; con la query del listado, conserva sus filtros (D-408). Un id negativo es
 * una factura propia de la vista billing_documents (D-427), con su ficha en /facturacion/documentos.
 */
export const invoiceUrl = (id: number, query: InvoiceQuery = {}) =>
    id < 0
        ? `/facturacion/documentos/${-id}${toQueryString(query)}`
        : `/facturacion/facturas/${id}${toQueryString(query)}`;

/** El número de una factura del listado con su origen: un icono si es propia y «Prueba» si es de prueba. */
function NumberLabel({ invoice }: { invoice: HoldedInvoiceSummary }) {
    return (
        <>
            {invoice.kind === 'credit_note' ? (
                <Undo2
                    aria-label={t('billing.invoice.credit_note')}
                    className="size-3.5 text-muted-foreground"
                />
            ) : null}
            {invoice.is_draft
                ? t('billing.invoice.draft_number')
                : (invoice.number ?? '—')}
            {invoice.source === 'audax' ? (
                <span
                    title={t('invoicing.origin.audax_hint')}
                    data-test="origin-audax"
                >
                    <FileCheck2
                        aria-label={t('invoicing.origin.audax_hint')}
                        className="size-3.5 text-muted-foreground"
                    />
                </span>
            ) : null}
            {invoice.is_test ? (
                <span className="rounded-md bg-info-soft px-1 text-xs text-foreground">
                    {t('invoicing.origin.test')}
                </span>
            ) : null}
        </>
    );
}

export type InvoiceSortColumn =
    | 'fecha'
    | 'numero'
    | 'cliente'
    | 'base'
    | 'total'
    | 'pendiente'
    | 'vencimiento';

export type InvoiceSort = {
    column: InvoiceSortColumn;
    direction: 'asc' | 'desc';
    onSort: (column: InvoiceSortColumn) => void;
};

export type InvoiceTotals = {
    count: number;
    subtotal: string;
    total: string;
    pending: string;
};

function HeaderCell({
    label,
    column,
    sort,
    sortLabel,
    align = 'left',
    className,
}: {
    label: string;
    /** Nombre del orden si no es el de la columna (contiene su texto, WCAG 2.5.3). */
    sortLabel?: string;
    column?: InvoiceSortColumn;
    sort?: InvoiceSort;
    align?: 'left' | 'right';
    className?: string;
}) {
    const active = sort !== undefined && column === sort.column;
    const Icon = sort?.direction === 'asc' ? ArrowUp : ArrowDown;

    return (
        <th
            scope="col"
            className={cn(
                'px-3 py-2 font-medium whitespace-nowrap',
                align === 'right' ? 'text-right' : 'text-left',
                className,
            )}
            aria-sort={
                active
                    ? sort.direction === 'asc'
                        ? 'ascending'
                        : 'descending'
                    : undefined
            }
        >
            {sort && column ? (
                <button
                    type="button"
                    onClick={() => sort.onSort(column)}
                    className={cn(
                        'inline-flex items-center gap-1 uppercase hover:text-foreground',
                        align === 'right' && 'flex-row-reverse',
                        active && 'text-foreground',
                        FOCUS_RING,
                    )}
                    aria-label={
                        sortLabel ??
                        t('billing.invoices.sort_by', {
                            column: label,
                        })
                    }
                    data-test={`invoice-sort-${column}`}
                >
                    {label}
                    {active ? (
                        <Icon aria-hidden="true" className="size-3.5" />
                    ) : (
                        <span aria-hidden="true" className="size-3.5" />
                    )}
                </button>
            ) : (
                label
            )}
        </th>
    );
}

/** Estado de cobro con días (D-407). «Cobrada» y «Anulada» en gris: destaca lo que no está cobrado. */
export function RelativeStatus({
    invoice,
    today,
}: {
    invoice: Pick<
        HoldedInvoiceSummary,
        'collection_status' | 'due_on' | 'total' | 'pending_total' | 'is_draft'
    >;
    today: string;
}) {
    const relative = relativeCollection(invoice, today);
    const due = invoice.due_on
        ? t('billing.invoice.due', { date: formatDate(invoice.due_on) })
        : undefined;

    if (relative.status === 'paid' || relative.status === 'cancelled') {
        const Icon = relative.status === 'paid' ? CircleCheck : Ban;

        return (
            <span
                className="inline-flex items-center gap-1 text-muted-foreground"
                title={due}
            >
                <Icon aria-hidden="true" className="size-3.5" />
                {relative.label}
            </span>
        );
    }

    const meta = COLLECTION_META[relative.status];

    return (
        <span title={due}>
            <StatusBadge tone={meta.tone} icon={meta.icon}>
                {relative.label}
            </StatusBadge>
        </span>
    );
}

function ProjectCell({ invoice }: { invoice: HoldedInvoiceSummary }) {
    if (invoice.links.length > 0) {
        const names = invoice.links.map(
            (link) =>
                `${link.project?.code ?? ''} · ${link.bank ? link.bank.name : (link.project?.name ?? '')}`,
        );

        return (
            <span className="block truncate" title={names.join('\n')}>
                <span className="text-muted-foreground">
                    {invoice.links[0].project?.code}
                </span>{' '}
                {invoice.links[0].bank
                    ? invoice.links[0].bank.name
                    : invoice.links[0].project?.name}
                {invoice.links.length > 1 ? (
                    <span className="text-muted-foreground">
                        {' '}
                        {t('billing.invoices.more_links', {
                            count: invoice.links.length - 1,
                        })}
                    </span>
                ) : null}
            </span>
        );
    }

    if (invoice.suggestion) {
        const suggestion = invoice.suggestion;
        const target = `${suggestion.project.code} · ${suggestion.bank ? suggestion.bank.name : suggestion.project.name}`;

        return (
            <Tooltip>
                <TooltipTrigger asChild>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        className="-ml-2 h-7 max-w-full px-2 text-primary-text"
                        data-test="accept-suggestion"
                        onClick={() =>
                            router.post(
                                `${invoiceUrl(invoice.id)}/enlaces`,
                                {
                                    project_id: suggestion.project.id,
                                    hour_bank_id: suggestion.bank?.id ?? null,
                                },
                                { preserveScroll: true },
                            )
                        }
                    >
                        <Link2 aria-hidden="true" />
                        <span className="truncate">
                            {t('billing.invoices.link_to', {
                                project: suggestion.project.code,
                            })}
                        </span>
                    </Button>
                </TooltipTrigger>
                <TooltipContent className="max-w-72">
                    {t('billing.invoices.suggestion_reason', {
                        target,
                        reason: t(
                            `billing.suggestions.reason.${suggestion.reason}`,
                        ),
                    })}
                </TooltipContent>
            </Tooltip>
        );
    }

    return (
        <span className="inline-flex items-center gap-1 text-muted-foreground">
            <Link2Off aria-hidden="true" className="size-3.5" />
            {t(
                invoice.no_project_needed
                    ? 'billing.no_project.marked'
                    : 'billing.invoice.unlinked',
            )}
        </span>
    );
}

/**
 * Facturas leídas de Holded (D-385, D-406 y D-407): una línea por factura (número, fecha, cliente,
 * proyecto o bolsa, base, total, pendiente, vencimiento y estado de cobro con días). La fila entera
 * abre la ficha (con los filtros del listado, D-408); las rectificativas, en negativo y con su icono;
 * las anuladas, atenuadas. Con `sort`, las columnas se ordenan; con `totals`, los totales al pie.
 */
export function InvoiceTable({
    invoices,
    caption,
    showClient = true,
    today = todayInMadrid(),
    linkQuery = {},
    sort,
    totals,
    footerNote,
}: {
    invoices: ReadonlyArray<HoldedInvoiceSummary>;
    caption: string;
    showClient?: boolean;
    /** Hoy en Madrid (AAAA-MM-DD), para los días de retraso. */
    today?: string;
    linkQuery?: InvoiceQuery;
    sort?: InvoiceSort;
    totals?: InvoiceTotals;
    footerNote?: ReactNode;
}) {
    const leading = showClient ? 4 : 3;
    const mobile = useIsMobile();

    if (mobile) {
        return (
            <InvoiceCards
                invoices={invoices}
                caption={caption}
                today={today}
                linkQuery={linkQuery}
                totals={totals}
                footerNote={footerNote}
            />
        );
    }

    return (
        <div
            className={cn('overflow-x-auto rounded-md border', FOCUS_RING)}
            role="region"
            aria-label={caption}
            tabIndex={0}
        >
            <table
                className={cn(
                    'tabular w-full text-sm',
                    showClient ? 'min-w-[62rem]' : 'min-w-[52rem]',
                )}
                data-test="invoice-table"
            >
                <caption className="sr-only">{caption}</caption>
                <thead>
                    <tr className="border-b">
                        <HeaderCell
                            label={t('billing.invoice.number')}
                            column="numero"
                            sort={sort}
                        />
                        <HeaderCell
                            label={t('billing.invoice.date')}
                            column="fecha"
                            sort={sort}
                        />
                        {showClient ? (
                            <HeaderCell
                                label={t('billing.invoice.client')}
                                column="cliente"
                                sort={sort}
                                className="w-[22%]"
                            />
                        ) : null}
                        <HeaderCell
                            label={t('billing.invoice.links')}
                            className="w-[20%]"
                        />
                        <HeaderCell
                            label={t('billing.invoice.subtotal_short')}
                            column="base"
                            sort={sort}
                            align="right"
                        />
                        <HeaderCell
                            label={t('billing.invoice.total_short')}
                            column="total"
                            sort={sort}
                            align="right"
                        />
                        <HeaderCell
                            label={t('billing.invoice.pending')}
                            column="pendiente"
                            sort={sort}
                            align="right"
                        />
                        {/* El estado dice cuándo vence o venció: se ordena por el vencimiento. */}
                        <HeaderCell
                            label={t('billing.invoice.status')}
                            column="vencimiento"
                            sort={sort}
                            sortLabel={t('billing.invoices.sort_due')}
                        />
                    </tr>
                </thead>
                <tbody>
                    {invoices.map((invoice) => {
                        const cancelled =
                            invoice.collection_status === 'cancelled';

                        return (
                            <tr
                                key={invoice.id}
                                className={cn(
                                    'h-10 border-b last:border-0 even:bg-muted',
                                    ROW_CLICK_CLASS,
                                    cancelled && 'text-muted-foreground',
                                )}
                                {...rowClickProps}
                            >
                                <th
                                    scope="row"
                                    className="px-3 py-1.5 text-left font-normal whitespace-nowrap"
                                >
                                    <Link
                                        href={invoiceUrl(invoice.id, linkQuery)}
                                        data-row-primary
                                        className={cn(
                                            'inline-flex items-center gap-1 rounded-md text-primary-text hover:underline',
                                            cancelled && 'line-through',
                                            FOCUS_RING,
                                        )}
                                    >
                                        <NumberLabel invoice={invoice} />
                                    </Link>
                                </th>
                                <td className="px-3 py-1.5 whitespace-nowrap">
                                    {formatDate(invoice.issued_on)}
                                </td>
                                {showClient ? (
                                    <td className="max-w-0 px-3 py-1.5">
                                        <span
                                            className="block truncate"
                                            title={
                                                invoice.client?.name ??
                                                invoice.contact_name ??
                                                undefined
                                            }
                                        >
                                            {invoice.client?.name ??
                                                invoice.contact_name ??
                                                '—'}
                                            {!invoice.client ? (
                                                <span className="text-warning">
                                                    {' '}
                                                    ·{' '}
                                                    {t(
                                                        'billing.invoice.no_client',
                                                    )}
                                                </span>
                                            ) : null}
                                        </span>
                                    </td>
                                ) : null}
                                <td className="max-w-0 px-3 py-1.5">
                                    <ProjectCell invoice={invoice} />
                                </td>
                                <td className="px-3 py-1.5 text-right whitespace-nowrap">
                                    {formatCurrency(invoice.subtotal)}
                                </td>
                                <td className="px-3 py-1.5 text-right whitespace-nowrap">
                                    {formatCurrency(invoice.total)}
                                </td>
                                <td
                                    className={cn(
                                        'px-3 py-1.5 text-right whitespace-nowrap',
                                        Number(invoice.pending_total) <= 0 &&
                                            'text-muted-foreground',
                                    )}
                                >
                                    {formatCurrency(invoice.pending_total)}
                                </td>
                                <td className="px-3 py-1.5 whitespace-nowrap">
                                    <RelativeStatus
                                        invoice={invoice}
                                        today={today}
                                    />
                                </td>
                            </tr>
                        );
                    })}
                </tbody>
                {totals ? (
                    <tfoot>
                        <tr className="border-t bg-muted">
                            <th
                                scope="row"
                                colSpan={leading}
                                className="px-3 py-2 text-left font-medium"
                            >
                                {tCount(
                                    'billing.invoices.footer',
                                    totals.count,
                                )}
                                {footerNote ? (
                                    <span className="ml-1 text-xs font-normal text-muted-foreground">
                                        {footerNote}
                                    </span>
                                ) : null}
                            </th>
                            <td className="px-3 py-2 text-right font-medium whitespace-nowrap">
                                {formatCurrency(totals.subtotal)}
                            </td>
                            <td className="px-3 py-2 text-right font-medium whitespace-nowrap">
                                {formatCurrency(totals.total)}
                            </td>
                            <td className="px-3 py-2 text-right font-medium whitespace-nowrap">
                                {formatCurrency(totals.pending)}
                            </td>
                            <td />
                        </tr>
                    </tfoot>
                ) : null}
            </table>
        </div>
    );
}

/**
 * Las facturas en el móvil (I7, D-415): una tarjeta de tres líneas por factura (número y cliente con
 * lo pendiente; el estado con días y la fecha; el proyecto o la sugerencia para enlazar), sin
 * desplazamiento lateral. Toda la tarjeta abre la ficha; los totales, al pie.
 */
function InvoiceCards({
    invoices,
    caption,
    today,
    linkQuery,
    totals,
    footerNote,
}: {
    invoices: ReadonlyArray<HoldedInvoiceSummary>;
    caption: string;
    today: string;
    linkQuery: InvoiceQuery;
    totals?: InvoiceTotals;
    footerNote?: ReactNode;
}) {
    return (
        <div className="grid gap-2">
            <ul
                className="grid gap-2"
                aria-label={caption}
                data-test="invoice-cards"
            >
                {invoices.map((invoice) => {
                    const cancelled = invoice.collection_status === 'cancelled';
                    const pending = Number(invoice.pending_total) > 0;

                    return (
                        <li
                            key={invoice.id}
                            className={cn(
                                'grid gap-1.5 rounded-md border bg-card p-3 text-sm',
                                ROW_CLICK_CLASS,
                                cancelled && 'text-muted-foreground',
                            )}
                            {...rowClickProps}
                        >
                            <div className="flex items-baseline justify-between gap-3">
                                <span className="flex min-w-0 items-baseline gap-2">
                                    <Link
                                        href={invoiceUrl(invoice.id, linkQuery)}
                                        data-row-primary
                                        className={cn(
                                            'inline-flex shrink-0 items-center gap-1 rounded-md text-primary-text hover:underline',
                                            cancelled && 'line-through',
                                            FOCUS_RING,
                                        )}
                                    >
                                        <NumberLabel invoice={invoice} />
                                    </Link>
                                    <span className="truncate">
                                        {invoice.client?.name ??
                                            invoice.contact_name ??
                                            '—'}
                                    </span>
                                </span>
                                <span
                                    className={cn(
                                        'tabular shrink-0',
                                        !pending && 'text-muted-foreground',
                                    )}
                                >
                                    {formatCurrency(
                                        pending
                                            ? invoice.pending_total
                                            : invoice.total,
                                    )}
                                </span>
                            </div>
                            <div className="flex items-center justify-between gap-3 text-xs">
                                <RelativeStatus
                                    invoice={invoice}
                                    today={today}
                                />
                                <span className="tabular text-muted-foreground">
                                    {formatDate(invoice.issued_on)}
                                </span>
                            </div>
                            <div className="min-w-0 text-xs">
                                <ProjectCell invoice={invoice} />
                            </div>
                        </li>
                    );
                })}
            </ul>
            {totals ? (
                <p className="tabular rounded-md border bg-muted px-3 py-2 text-sm">
                    <span className="font-medium">
                        {tCount('billing.invoices.footer', totals.count)}
                    </span>
                    {footerNote ? (
                        <span className="ml-1 text-xs text-muted-foreground">
                            {footerNote}
                        </span>
                    ) : null}
                    <span className="mt-1 grid grid-cols-3 gap-2 text-xs text-muted-foreground">
                        <span>
                            {t('billing.invoice.subtotal_short')}
                            <span className="block text-sm text-foreground">
                                {formatCurrency(totals.subtotal)}
                            </span>
                        </span>
                        <span>
                            {t('billing.invoice.total_short')}
                            <span className="block text-sm text-foreground">
                                {formatCurrency(totals.total)}
                            </span>
                        </span>
                        <span>
                            {t('billing.invoice.pending')}
                            <span className="block text-sm text-foreground">
                                {formatCurrency(totals.pending)}
                            </span>
                        </span>
                    </span>
                </p>
            ) : null}
        </div>
    );
}
