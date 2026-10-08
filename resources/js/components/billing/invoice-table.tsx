import { Link, router } from '@inertiajs/react';
import { Link2, Link2Off, Undo2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatCurrency, formatDate } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { HoldedInvoiceSummary } from '@/types';
import { CollectionStatusBadge } from './billing-badges';

export const invoiceUrl = (id: number) => `/facturacion/facturas/${id}`;

/**
 * Facturas leídas de Holded (D-385): número (a su ficha), fecha y vencimiento, cliente, con qué
 * proyecto o bolsa está enlazada, base, total, cobrado, pendiente y estado de cobro. Las
 * rectificativas van en negativo y con su icono.
 */
export function InvoiceTable({
    invoices,
    caption,
    showClient = true,
}: {
    invoices: ReadonlyArray<HoldedInvoiceSummary>;
    caption: string;
    showClient?: boolean;
}) {
    return (
        <div
            className={cn('overflow-x-auto rounded-md border', FOCUS_RING)}
            role="region"
            aria-label={caption}
            tabIndex={0}
        >
            <table
                className="tabular w-full min-w-[64rem] text-sm"
                data-test="invoice-table"
            >
                <caption className="sr-only">{caption}</caption>
                <thead>
                    <tr className="border-b text-left">
                        <th scope="col" className="px-3 py-2 font-medium">
                            {t('billing.invoice.number')}
                        </th>
                        <th scope="col" className="px-3 py-2 font-medium">
                            {t('billing.invoice.date')}
                        </th>
                        {showClient ? (
                            <th scope="col" className="px-3 py-2 font-medium">
                                {t('billing.invoice.client')}
                            </th>
                        ) : null}
                        <th scope="col" className="px-3 py-2 font-medium">
                            {t('billing.invoice.links')}
                        </th>
                        <th
                            scope="col"
                            className="px-3 py-2 text-right font-medium"
                        >
                            {t('billing.invoice.subtotal')}
                        </th>
                        <th
                            scope="col"
                            className="px-3 py-2 text-right font-medium"
                        >
                            {t('billing.invoice.total')}
                        </th>
                        <th
                            scope="col"
                            className="px-3 py-2 text-right font-medium"
                        >
                            {t('billing.invoice.pending')}
                        </th>
                        <th scope="col" className="px-3 py-2 font-medium">
                            {t('billing.invoice.status')}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    {invoices.map((invoice) => (
                        <tr
                            key={invoice.id}
                            className="border-b last:border-0 even:bg-muted"
                        >
                            <th
                                scope="row"
                                className="px-3 py-2 text-left font-normal whitespace-nowrap"
                            >
                                <Link
                                    href={invoiceUrl(invoice.id)}
                                    className={cn(
                                        'inline-flex items-center gap-1 rounded-md hover:underline',
                                        FOCUS_RING,
                                    )}
                                >
                                    {invoice.kind === 'credit_note' ? (
                                        <Undo2
                                            aria-label={t(
                                                'billing.invoice.credit_note',
                                            )}
                                            className="size-3.5 text-muted-foreground"
                                        />
                                    ) : null}
                                    {invoice.is_draft
                                        ? t('billing.invoice.draft_number')
                                        : (invoice.number ?? '—')}
                                </Link>
                            </th>
                            <td className="px-3 py-2 whitespace-nowrap">
                                {formatDate(invoice.issued_on)}
                                {invoice.due_on ? (
                                    <span className="block text-xs text-muted-foreground">
                                        {t('billing.invoice.due', {
                                            date: formatDate(invoice.due_on),
                                        })}
                                    </span>
                                ) : null}
                            </td>
                            {showClient ? (
                                <td className="px-3 py-2">
                                    {invoice.client?.name ??
                                        invoice.contact_name ?? (
                                            <span className="text-muted-foreground">
                                                —
                                            </span>
                                        )}
                                    {!invoice.client ? (
                                        <span className="block text-xs text-warning">
                                            {t('billing.invoice.no_client')}
                                        </span>
                                    ) : null}
                                </td>
                            ) : null}
                            <td className="px-3 py-2">
                                {invoice.links.length === 0 ? (
                                    <span className="grid justify-items-start gap-1">
                                        <span className="inline-flex items-center gap-1 text-muted-foreground">
                                            <Link2Off
                                                aria-hidden="true"
                                                className="size-3.5"
                                            />
                                            {t('billing.invoice.unlinked')}
                                        </span>
                                        {invoice.suggestion ? (
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                className="h-auto py-0.5"
                                                data-test="accept-suggestion"
                                                onClick={() =>
                                                    router.post(
                                                        `${invoiceUrl(invoice.id)}/enlaces`,
                                                        {
                                                            project_id:
                                                                invoice
                                                                    .suggestion
                                                                    ?.project
                                                                    .id,
                                                            hour_bank_id:
                                                                invoice
                                                                    .suggestion
                                                                    ?.bank
                                                                    ?.id ??
                                                                null,
                                                        },
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                <Link2 aria-hidden="true" />
                                                {t(
                                                    'billing.invoice.accept_suggestion',
                                                    {
                                                        project: `${invoice.suggestion.project.code}${invoice.suggestion.bank ? ` · ${invoice.suggestion.bank.name}` : ''}`,
                                                    },
                                                )}
                                            </Button>
                                        ) : null}
                                    </span>
                                ) : (
                                    <ul className="grid gap-0.5">
                                        {invoice.links.map((link) => (
                                            <li key={link.id}>
                                                <span className="text-muted-foreground">
                                                    {link.project?.code}
                                                </span>
                                                {link.bank
                                                    ? ` · ${link.bank.name}`
                                                    : ` · ${link.project?.name ?? ''}`}
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </td>
                            <td className="px-3 py-2 text-right">
                                {formatCurrency(invoice.subtotal)}
                            </td>
                            <td className="px-3 py-2 text-right">
                                {formatCurrency(invoice.total)}
                            </td>
                            <td className="px-3 py-2 text-right">
                                {formatCurrency(invoice.pending_total)}
                            </td>
                            <td className="px-3 py-2">
                                <CollectionStatusBadge
                                    status={invoice.collection_status}
                                />
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
