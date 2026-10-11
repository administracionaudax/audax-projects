import { Head } from '@inertiajs/react';
import {
    Download,
    FileSearch,
    Paperclip,
    ReceiptText,
    Undo2,
} from 'lucide-react';
import { useId } from 'react';
import { EmptyState } from '@/components/empty-state';
import { ClientInvoiceStatus } from '@/components/invoicing/client-invoice';
import { formatBytes } from '@/components/tasks/task-attachments';
import { Button } from '@/components/ui/button';
import { useIsMobile } from '@/hooks/use-mobile';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatCurrency, formatDate } from '@/lib/format';
import { t } from '@/lib/i18n';
import { tCount } from '@/lib/people';
import { cn } from '@/lib/utils';
import type { ClientInvoice } from '@/types';

type PortalInvoice = ClientInvoice & {
    attachments: { id: number; name: string; size: number }[];
};

type Props = {
    client: { name: string };
    summary: { pending: string; overdue: string; count: number };
    invoices: PortalInvoice[];
    today: string;
};

const pdfUrl = (invoice: PortalInvoice, download = false) =>
    `/portal/facturas/${invoice.id}/pdf${download ? '?descargar=1' : ''}`;

/** «Vence el 03/03/2027», «Venció hace 8 días»… lo justo para el cliente. */
function statusLabel(invoice: PortalInvoice): string {
    if (invoice.collection === 'partial') {
        return t('portal_invoices.status.partial', {
            pending: formatCurrency(invoice.pending),
        });
    }

    return t(
        `portal_invoices.status.${invoice.collection}` as 'portal_invoices.status.paid',
    );
}

function Actions({ invoice }: { invoice: PortalInvoice }) {
    return (
        <div className="flex flex-wrap items-center gap-1">
            <Button variant="outline" size="sm" asChild>
                <a
                    href={pdfUrl(invoice)}
                    target="_blank"
                    rel="noopener"
                    data-test="portal-invoice-pdf"
                >
                    <FileSearch aria-hidden="true" />
                    {t('portal_invoices.view_pdf')}
                </a>
            </Button>
            <Button
                variant="ghost"
                size="icon"
                asChild
                aria-label={t('portal_invoices.download', {
                    number: invoice.number ?? '',
                })}
            >
                <a href={pdfUrl(invoice, true)}>
                    <Download aria-hidden="true" />
                </a>
            </Button>
        </div>
    );
}

function Attachments({ invoice }: { invoice: PortalInvoice }) {
    if (invoice.attachments.length === 0) {
        return null;
    }

    return (
        <ul className="flex flex-wrap gap-x-3 gap-y-1 text-xs">
            {invoice.attachments.map((attachment) => (
                <li key={attachment.id}>
                    <a
                        href={`/portal/facturas/${invoice.id}/adjuntos/${attachment.id}`}
                        className={cn(
                            'inline-flex items-center gap-1 rounded-md text-muted-foreground hover:text-foreground hover:underline',
                            FOCUS_RING,
                        )}
                    >
                        <Paperclip aria-hidden="true" className="size-3" />
                        {attachment.name}
                        <span className="sr-only">
                            {' '}
                            ({formatBytes(attachment.size)})
                        </span>
                    </a>
                </li>
            ))}
        </ul>
    );
}

function Number_({ invoice }: { invoice: PortalInvoice }) {
    return (
        <span className="inline-flex items-center gap-1.5">
            {invoice.type === 'credit_note' ? (
                <Undo2
                    aria-label={t('portal_invoices.credit_note')}
                    className="size-3.5 text-muted-foreground"
                />
            ) : null}
            {invoice.number}
        </span>
    );
}

/**
 * Portal · Facturas (PLAN-EMISION §3.5; E2, D-443): las facturas de Audax del cliente con lo que
 * queda por pagar, su estado, la forma de pago y el PDF. Nada interno.
 */
export default function PortalInvoices({ client, summary, invoices }: Props) {
    const titleId = useId();
    const mobile = useIsMobile();
    // La forma de pago de la factura pendiente más reciente (IBAN incluido, como en el PDF).
    const howToPay =
        invoices.find(
            (invoice) => Number(invoice.pending) > 0 && invoice.payment_text,
        )?.payment_text ?? null;

    return (
        <>
            <Head title={t('portal_invoices.title')} />

            <div className="grid gap-8">
                <header className="space-y-1">
                    <p className="text-sm text-muted-foreground">
                        {client.name}
                    </p>
                    <h1
                        id={titleId}
                        className="text-3xl font-normal tracking-tight"
                    >
                        {t('portal_invoices.title')}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {t('portal_invoices.subtitle')}
                    </p>
                </header>

                {invoices.length === 0 ? (
                    <EmptyState
                        icon={ReceiptText}
                        title={t('portal_invoices.empty')}
                        description={t('portal_invoices.empty_description')}
                    />
                ) : (
                    <>
                        <dl
                            className="grid grid-cols-3 gap-2 sm:gap-3"
                            data-test="portal-invoices-summary"
                        >
                            {[
                                {
                                    key: 'pending',
                                    label: t('portal_invoices.summary.pending'),
                                    value: formatCurrency(summary.pending),
                                },
                                {
                                    key: 'overdue',
                                    label: t('portal_invoices.summary.overdue'),
                                    value: formatCurrency(summary.overdue),
                                },
                                {
                                    key: 'count',
                                    label: t('portal_invoices.summary.count'),
                                    value: String(summary.count),
                                },
                            ].map((item) => (
                                <div
                                    key={item.key}
                                    className="grid min-w-0 content-start gap-1 rounded-md border bg-card p-3 sm:p-4"
                                >
                                    <dt className="text-xs text-muted-foreground sm:text-sm">
                                        {item.label}
                                    </dt>
                                    <dd className="tabular text-base sm:text-2xl">
                                        {item.value}
                                    </dd>
                                </div>
                            ))}
                        </dl>

                        {mobile ? (
                            <ul
                                className="grid gap-3"
                                aria-labelledby={titleId}
                                data-test="portal-invoices"
                            >
                                {invoices.map((invoice) => (
                                    <li
                                        key={invoice.id}
                                        className="grid gap-3 rounded-md border bg-card p-4"
                                    >
                                        <div className="flex items-start justify-between gap-3">
                                            <div className="grid gap-0.5">
                                                <Number_ invoice={invoice} />
                                                <span className="text-xs text-muted-foreground">
                                                    {formatDate(
                                                        invoice.issue_date,
                                                    )}
                                                    {invoice.due_date
                                                        ? ` · ${t('portal_invoices.due', { date: formatDate(invoice.due_date) })}`
                                                        : ''}
                                                </span>
                                            </div>
                                            <span className="tabular text-right">
                                                {formatCurrency(invoice.total)}
                                            </span>
                                        </div>
                                        <ClientInvoiceStatus
                                            collection={invoice.collection}
                                            label={statusLabel(invoice)}
                                        />
                                        <Attachments invoice={invoice} />
                                        <Actions invoice={invoice} />
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <div className="overflow-x-auto rounded-md border">
                                <table
                                    className="tabular w-full min-w-[46rem] text-sm"
                                    data-test="portal-invoices"
                                >
                                    <caption className="sr-only">
                                        {tCount(
                                            'portal_invoices.caption',
                                            invoices.length,
                                        )}
                                    </caption>
                                    <thead>
                                        <tr className="border-b">
                                            <th
                                                scope="col"
                                                className="px-3 py-2 text-left font-medium"
                                            >
                                                {t('portal_invoices.number')}
                                            </th>
                                            <th
                                                scope="col"
                                                className="px-3 py-2 text-left font-medium"
                                            >
                                                {t('portal_invoices.date')}
                                            </th>
                                            <th
                                                scope="col"
                                                className="px-3 py-2 text-left font-medium"
                                            >
                                                {t('portal_invoices.due_date')}
                                            </th>
                                            <th
                                                scope="col"
                                                className="px-3 py-2 text-right font-medium"
                                            >
                                                {t('portal_invoices.total')}
                                            </th>
                                            <th
                                                scope="col"
                                                className="px-3 py-2 text-right font-medium"
                                            >
                                                {t('portal_invoices.pending')}
                                            </th>
                                            <th
                                                scope="col"
                                                className="px-3 py-2 text-left font-medium"
                                            >
                                                {t(
                                                    'portal_invoices.status_title',
                                                )}
                                            </th>
                                            <th
                                                scope="col"
                                                className="px-3 py-2 text-left font-medium"
                                            >
                                                <span className="sr-only">
                                                    {t(
                                                        'portal_invoices.actions',
                                                    )}
                                                </span>
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {invoices.map((invoice) => (
                                            <tr
                                                key={invoice.id}
                                                className="border-b align-top last:border-b-0"
                                            >
                                                <td className="px-3 py-2.5">
                                                    <div className="grid gap-1">
                                                        <Number_
                                                            invoice={invoice}
                                                        />
                                                        <Attachments
                                                            invoice={invoice}
                                                        />
                                                    </div>
                                                </td>
                                                <td className="px-3 py-2.5">
                                                    {formatDate(
                                                        invoice.issue_date,
                                                    )}
                                                </td>
                                                <td className="px-3 py-2.5">
                                                    {invoice.due_date
                                                        ? formatDate(
                                                              invoice.due_date,
                                                          )
                                                        : '—'}
                                                </td>
                                                <td className="px-3 py-2.5 text-right">
                                                    {formatCurrency(
                                                        invoice.total,
                                                    )}
                                                </td>
                                                <td className="px-3 py-2.5 text-right">
                                                    {formatCurrency(
                                                        invoice.pending,
                                                    )}
                                                </td>
                                                <td className="px-3 py-2.5">
                                                    <ClientInvoiceStatus
                                                        collection={
                                                            invoice.collection
                                                        }
                                                        label={statusLabel(
                                                            invoice,
                                                        )}
                                                    />
                                                </td>
                                                <td className="px-3 py-2">
                                                    <Actions
                                                        invoice={invoice}
                                                    />
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}

                        {howToPay ? (
                            <section className="grid max-w-3xl gap-1 text-sm">
                                <h2 className="text-base font-normal">
                                    {t('portal_invoices.how_to_pay')}
                                </h2>
                                <p className="whitespace-pre-line text-muted-foreground">
                                    {howToPay}
                                </p>
                            </section>
                        ) : null}
                    </>
                )}
            </div>
        </>
    );
}

PortalInvoices.layout = {
    breadcrumbs: [],
};
