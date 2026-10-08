import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    Download,
    ExternalLink,
    Link2,
    Link2Off,
    Plus,
    Trash2,
    Undo2,
} from 'lucide-react';
import type { FormEvent } from 'react';
import { useId } from 'react';
import { CollectionStatusBadge } from '@/components/billing/billing-badges';
import { invoiceUrl } from '@/components/billing/invoice-table';
import { ConfirmDialog } from '@/components/confirm-dialog';
import InputError from '@/components/input-error';
import { PageSection } from '@/components/projects-list/page-section';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { FOCUS_RING } from '@/lib/focus-ring';
import {
    formatCurrency,
    formatDate,
    formatDateTime,
    formatNumber,
} from '@/lib/format';
import { t } from '@/lib/i18n';
import { urls } from '@/lib/urls';
import { cn } from '@/lib/utils';
import type { HoldedInvoiceDetail, InvoiceLinkSuggestion } from '@/types';

type ProjectOption = {
    id: number;
    code: string;
    name: string;
    client: string | null;
    uses_banks: boolean;
    banks: { id: number; name: string; start_date: string }[];
};

type Props = {
    invoice: HoldedInvoiceDetail;
    projects: ProjectOption[];
    suggestions: InvoiceLinkSuggestion[];
    holded: { driver: string; configured: boolean };
};

const NONE = '__none__';

/**
 * Ficha de una factura de Holded (Fase 12, F1; D-385 y D-388): cifras, líneas, cobros,
 * rectificativas, su PDF original y con qué proyecto o bolsa está enlazada (automático por el
 * código F o el proyecto de Holded, o a mano desde aquí). Solo lectura de Holded.
 */
export default function InvoiceShow({ invoice, projects, suggestions }: Props) {
    const credit = invoice.kind === 'credit_note';
    const pdf = `${invoiceUrl(invoice.id)}/pdf`;

    return (
        <>
            <Head
                title={t('billing.invoice.title', {
                    number: invoice.is_draft
                        ? t('billing.invoice.draft_number')
                        : (invoice.number ?? '—'),
                })}
            />

            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div className="min-w-0 space-y-1">
                        <p className="flex items-center gap-1.5 text-sm text-muted-foreground">
                            {credit ? (
                                <Undo2 aria-hidden="true" className="size-4" />
                            ) : null}
                            {t(
                                credit
                                    ? 'billing.document.credit_note'
                                    : 'billing.document.invoice',
                            )}
                            {' · '}
                            {invoice.client ? (
                                <Link
                                    href={`/clientes/${invoice.client.id}/facturacion`}
                                    className={cn(
                                        'rounded-md hover:underline',
                                        FOCUS_RING,
                                    )}
                                >
                                    {invoice.client.name}
                                </Link>
                            ) : (
                                (invoice.contact_name ?? '—')
                            )}
                        </p>
                        <h1 className="text-2xl font-normal tracking-tight">
                            {invoice.is_draft
                                ? t('billing.invoice.draft_number')
                                : (invoice.number ?? '—')}
                        </h1>
                        <div className="flex flex-wrap items-center gap-2">
                            <CollectionStatusBadge
                                status={invoice.collection_status}
                            />
                            {invoice.synced_at ? (
                                <span className="text-xs text-muted-foreground">
                                    {t('billing.invoice.synced', {
                                        date: formatDateTime(invoice.synced_at),
                                    })}
                                </span>
                            ) : null}
                        </div>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button variant="outline" asChild>
                            <a
                                href={pdf}
                                target="_blank"
                                rel="noopener"
                                data-test="invoice-pdf"
                            >
                                <ExternalLink aria-hidden="true" />
                                {t('billing.invoice.open_pdf')}
                            </a>
                        </Button>
                        <Button variant="outline" asChild>
                            <a href={`${pdf}?descargar=1`}>
                                <Download aria-hidden="true" />
                                {t('billing.invoice.download_pdf')}
                            </a>
                        </Button>
                    </div>
                </header>

                {invoice.is_draft ? (
                    <p className="rounded-md bg-info-soft px-3 py-2 text-sm text-foreground">
                        {t('billing.invoice.draft_notice')}
                    </p>
                ) : null}

                {invoice.tags.length > 0 ? (
                    <ul
                        aria-label={t('billing.invoice.tags')}
                        className="flex flex-wrap gap-1.5"
                    >
                        {invoice.tags.map((tag) => (
                            <li
                                key={tag}
                                className="rounded-md bg-muted px-1.5 py-0.5 text-xs text-muted-foreground"
                            >
                                {tag}
                            </li>
                        ))}
                    </ul>
                ) : null}

                {!invoice.client ? (
                    <p className="rounded-md bg-warning-soft px-3 py-2 text-sm text-foreground">
                        {t('billing.invoice.no_client_notice')}{' '}
                        <Link
                            href="/facturacion/contactos"
                            className={cn('underline', FOCUS_RING)}
                        >
                            {t('billing.invoice.resolve_contact')}
                        </Link>
                    </p>
                ) : null}

                <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
                    <div className="grid content-start gap-6">
                        <Card className="py-4">
                            <CardContent className="grid gap-4 px-4">
                                <dl className="grid gap-x-6 gap-y-3 sm:grid-cols-3">
                                    <Fact
                                        label={t('billing.invoice.date')}
                                        value={formatDate(invoice.issued_on)}
                                    />
                                    <Fact
                                        label={t('billing.invoice.due_on')}
                                        value={
                                            invoice.due_on
                                                ? formatDate(invoice.due_on)
                                                : '—'
                                        }
                                    />
                                    <Fact
                                        label={t('billing.invoice.currency')}
                                        value={invoice.currency}
                                    />
                                    <Fact
                                        label={t('billing.invoice.subtotal')}
                                        value={formatCurrency(invoice.subtotal)}
                                    />
                                    <Fact
                                        label={t('billing.invoice.tax')}
                                        value={formatCurrency(
                                            invoice.tax_total,
                                        )}
                                    />
                                    <Fact
                                        label={t('billing.invoice.total')}
                                        value={formatCurrency(invoice.total)}
                                        strong
                                    />
                                    <Fact
                                        label={t('billing.invoice.paid')}
                                        value={formatCurrency(
                                            invoice.paid_total,
                                        )}
                                    />
                                    <Fact
                                        label={t('billing.invoice.pending')}
                                        value={formatCurrency(
                                            invoice.pending_total,
                                        )}
                                    />
                                </dl>
                            </CardContent>
                        </Card>

                        <PageSection title={t('billing.invoice.lines')}>
                            <div
                                className={cn(
                                    'overflow-x-auto rounded-md border',
                                    FOCUS_RING,
                                )}
                                role="region"
                                aria-label={t('billing.invoice.lines')}
                                tabIndex={0}
                            >
                                <table className="tabular w-full min-w-[36rem] text-sm">
                                    <caption className="sr-only">
                                        {t('billing.invoice.lines')}
                                    </caption>
                                    <thead>
                                        <tr className="border-b text-left">
                                            <th
                                                scope="col"
                                                className="px-3 py-2 font-medium"
                                            >
                                                {t('billing.invoice.concept')}
                                            </th>
                                            <th
                                                scope="col"
                                                className="px-3 py-2 text-right font-medium"
                                            >
                                                {t('billing.invoice.units')}
                                            </th>
                                            <th
                                                scope="col"
                                                className="px-3 py-2 text-right font-medium"
                                            >
                                                {t('billing.invoice.price')}
                                            </th>
                                            <th
                                                scope="col"
                                                className="px-3 py-2 text-right font-medium"
                                            >
                                                {t('billing.invoice.vat')}
                                            </th>
                                            <th
                                                scope="col"
                                                className="px-3 py-2 text-right font-medium"
                                            >
                                                {t('billing.invoice.subtotal')}
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {invoice.lines.map((line) => (
                                            <tr
                                                key={line.id}
                                                className="border-b last:border-0 even:bg-muted"
                                            >
                                                <th
                                                    scope="row"
                                                    className="px-3 py-2 text-left font-normal"
                                                >
                                                    {line.name ?? '—'}
                                                    <span className="block text-xs text-muted-foreground">
                                                        {[
                                                            line.service_code,
                                                            t(
                                                                `billing.line_kind.${line.kind}`,
                                                            ),
                                                        ]
                                                            .filter(Boolean)
                                                            .join(' · ')}
                                                    </span>
                                                    {line.description ? (
                                                        <span className="block text-xs text-muted-foreground">
                                                            {line.description}
                                                        </span>
                                                    ) : null}
                                                </th>
                                                <td className="px-3 py-2 text-right">
                                                    {formatNumber(
                                                        Number(line.units),
                                                        2,
                                                    )}
                                                </td>
                                                <td className="px-3 py-2 text-right">
                                                    {formatCurrency(
                                                        line.unit_price,
                                                    )}
                                                </td>
                                                <td className="px-3 py-2 text-right">
                                                    {line.tax_rate === null
                                                        ? '—'
                                                        : `${formatNumber(Number(line.tax_rate), 0)} %`}
                                                </td>
                                                <td className="px-3 py-2 text-right">
                                                    {formatCurrency(
                                                        line.subtotal,
                                                    )}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </PageSection>

                        <PageSection title={t('billing.invoice.payments')}>
                            {invoice.payments.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    {t('billing.invoice.no_payments')}
                                </p>
                            ) : (
                                <ul className="grid gap-2 text-sm">
                                    {invoice.payments.map((payment) => (
                                        <li
                                            key={payment.id}
                                            className="flex flex-wrap justify-between gap-2 rounded-md border px-3 py-2"
                                        >
                                            <span>
                                                {formatDate(payment.paid_on)}
                                                {payment.method ? (
                                                    <span className="text-muted-foreground">
                                                        {' '}
                                                        · {payment.method}
                                                    </span>
                                                ) : null}
                                            </span>
                                            <span className="tabular">
                                                {formatCurrency(payment.amount)}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </PageSection>

                        {invoice.notes ? (
                            <PageSection title={t('billing.invoice.notes')}>
                                <p className="text-sm whitespace-pre-line">
                                    {invoice.notes}
                                </p>
                            </PageSection>
                        ) : null}
                    </div>

                    <aside className="grid content-start gap-6">
                        {suggestions.length > 0 ? (
                            <PageSection
                                title={t('billing.suggestions.title')}
                                description={t(
                                    'billing.suggestions.description',
                                )}
                            >
                                <ul
                                    className="grid gap-2"
                                    data-test="invoice-suggestions"
                                >
                                    {suggestions.map((suggestion) => (
                                        <li
                                            key={`${suggestion.project.id}-${suggestion.bank?.id ?? 0}`}
                                            className="flex items-center justify-between gap-2 rounded-md border border-dashed px-3 py-2 text-sm"
                                        >
                                            <span className="min-w-0">
                                                <span className="text-muted-foreground">
                                                    {suggestion.project.code}
                                                </span>{' '}
                                                {suggestion.bank
                                                    ? suggestion.bank.name
                                                    : suggestion.project.name}
                                                <span className="block text-xs text-muted-foreground">
                                                    {t(
                                                        `billing.suggestions.reason.${suggestion.reason}`,
                                                    )}
                                                </span>
                                            </span>
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="outline"
                                                onClick={() =>
                                                    router.post(
                                                        `${invoiceUrl(invoice.id)}/enlaces`,
                                                        {
                                                            project_id:
                                                                suggestion
                                                                    .project.id,
                                                            hour_bank_id:
                                                                suggestion.bank
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
                                                    'billing.suggestions.accept',
                                                )}
                                            </Button>
                                        </li>
                                    ))}
                                </ul>
                            </PageSection>
                        ) : null}

                        <LinksPanel invoice={invoice} projects={projects} />

                        {invoice.rectified ||
                        invoice.rectifications.length > 0 ? (
                            <PageSection title={t('billing.invoice.relations')}>
                                <ul className="grid gap-2 text-sm">
                                    {invoice.rectified ? (
                                        <li>
                                            {t('billing.invoice.rectifies')}{' '}
                                            <Link
                                                href={invoiceUrl(
                                                    invoice.rectified.id,
                                                )}
                                                className={cn(
                                                    'rounded-md underline',
                                                    FOCUS_RING,
                                                )}
                                            >
                                                {invoice.rectified.number}
                                            </Link>
                                        </li>
                                    ) : null}
                                    {invoice.rectifications.map(
                                        (rectification) => (
                                            <li key={rectification.id}>
                                                {t(
                                                    'billing.invoice.rectified_by',
                                                )}{' '}
                                                <Link
                                                    href={invoiceUrl(
                                                        rectification.id,
                                                    )}
                                                    className={cn(
                                                        'rounded-md underline',
                                                        FOCUS_RING,
                                                    )}
                                                >
                                                    {rectification.number}
                                                </Link>{' '}
                                                <span className="tabular text-muted-foreground">
                                                    (
                                                    {formatCurrency(
                                                        rectification.subtotal,
                                                    )}
                                                    )
                                                </span>
                                            </li>
                                        ),
                                    )}
                                </ul>
                            </PageSection>
                        ) : null}
                    </aside>
                </div>
            </div>
        </>
    );
}

function Fact({
    label,
    value,
    strong = false,
}: {
    label: string;
    value: string;
    strong?: boolean;
}) {
    return (
        <div className="grid gap-0.5">
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className={cn('tabular', strong ? 'text-lg' : 'text-sm')}>
                {value}
            </dd>
        </div>
    );
}

/** Enlaces de la factura con proyectos y bolsas; los manuales se añaden y se quitan aquí. */
function LinksPanel({
    invoice,
    projects,
}: {
    invoice: HoldedInvoiceDetail;
    projects: ProjectOption[];
}) {
    const id = useId();
    const form = useForm<{
        project_id: number | null;
        hour_bank_id: number | null;
    }>({
        project_id: null,
        hour_bank_id: null,
    });
    const project =
        projects.find((option) => option.id === form.data.project_id) ?? null;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(`${invoiceUrl(invoice.id)}/enlaces`, {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    };

    return (
        <PageSection
            title={t('billing.links.title')}
            description={t('billing.links.description')}
        >
            {invoice.links.length === 0 ? (
                <p
                    className="flex items-center gap-1.5 text-sm text-muted-foreground"
                    data-test="invoice-unlinked"
                >
                    <Link2Off aria-hidden="true" className="size-4" />
                    {t('billing.links.none')}
                </p>
            ) : (
                <ul className="grid gap-2" data-test="invoice-links">
                    {invoice.links.map((link) => (
                        <li
                            key={link.id}
                            className="flex items-start justify-between gap-2 rounded-md border px-3 py-2 text-sm"
                        >
                            <span className="min-w-0">
                                <Link2
                                    aria-hidden="true"
                                    className="mr-1 inline size-3.5 text-muted-foreground"
                                />
                                {link.project ? (
                                    <Link
                                        href={
                                            link.bank
                                                ? urls.hourBank(
                                                      link.project.id,
                                                      link.bank.id,
                                                  )
                                                : `/proyectos/${link.project.id}/facturacion`
                                        }
                                        className={cn(
                                            'rounded-md hover:underline',
                                            FOCUS_RING,
                                        )}
                                    >
                                        <span className="text-muted-foreground">
                                            {link.project.code}
                                        </span>{' '}
                                        {link.bank
                                            ? link.bank.name
                                            : link.project.name}
                                    </Link>
                                ) : null}
                                <span className="block text-xs text-muted-foreground">
                                    {t(`billing.link_method.${link.method}`)}
                                    {link.created_by
                                        ? ` · ${link.created_by}`
                                        : ''}
                                </span>
                            </span>
                            {link.method === 'manual' ? (
                                <ConfirmDialog
                                    trigger={
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            aria-label={t(
                                                'billing.links.remove',
                                                {
                                                    project:
                                                        link.project?.code ??
                                                        '',
                                                },
                                            )}
                                        >
                                            <Trash2 aria-hidden="true" />
                                        </Button>
                                    }
                                    title={t('billing.links.remove_title')}
                                    description={t(
                                        'billing.links.remove_description',
                                    )}
                                    confirmLabel={t(
                                        'billing.links.remove_confirm',
                                    )}
                                    onConfirm={() =>
                                        router.delete(
                                            `${invoiceUrl(invoice.id)}/enlaces/${link.id}`,
                                            {
                                                preserveScroll: true,
                                            },
                                        )
                                    }
                                />
                            ) : null}
                        </li>
                    ))}
                </ul>
            )}

            <form
                onSubmit={submit}
                className="grid gap-3 rounded-md border border-dashed p-3"
                noValidate
            >
                <p className="text-sm font-medium">{t('billing.links.add')}</p>
                <div className="grid gap-1">
                    <Label htmlFor={`${id}-project`}>
                        {t('billing.links.project')}
                    </Label>
                    <Select
                        value={
                            form.data.project_id
                                ? String(form.data.project_id)
                                : NONE
                        }
                        onValueChange={(value) => {
                            const projectId =
                                value === NONE ? null : Number(value);
                            const chosen = projects.find(
                                (option) => option.id === projectId,
                            );
                            form.setData({
                                project_id: projectId,
                                hour_bank_id: chosen?.uses_banks
                                    ? (chosen.banks[0]?.id ?? null)
                                    : null,
                            });
                        }}
                    >
                        <SelectTrigger
                            id={`${id}-project`}
                            className="w-full"
                            aria-invalid={
                                form.errors.project_id ? true : undefined
                            }
                        >
                            <SelectValue
                                placeholder={t(
                                    'billing.links.project_placeholder',
                                )}
                            />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={NONE}>
                                {t('billing.links.project_placeholder')}
                            </SelectItem>
                            {projects.map((option) => (
                                <SelectItem
                                    key={option.id}
                                    value={String(option.id)}
                                >
                                    {option.code} · {option.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError message={form.errors.project_id} />
                </div>
                {project?.uses_banks ? (
                    <div className="grid gap-1">
                        <Label htmlFor={`${id}-bank`}>
                            {t('billing.links.bank')}
                        </Label>
                        <Select
                            value={
                                form.data.hour_bank_id
                                    ? String(form.data.hour_bank_id)
                                    : NONE
                            }
                            onValueChange={(value) =>
                                form.setData(
                                    'hour_bank_id',
                                    value === NONE ? null : Number(value),
                                )
                            }
                        >
                            <SelectTrigger id={`${id}-bank`} className="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={NONE}>
                                    {t('billing.links.no_bank')}
                                </SelectItem>
                                {project.banks.map((bank) => (
                                    <SelectItem
                                        key={bank.id}
                                        value={String(bank.id)}
                                    >
                                        {bank.name} ·{' '}
                                        {formatDate(bank.start_date)}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={form.errors.hour_bank_id} />
                    </div>
                ) : null}
                <div>
                    <Button
                        type="submit"
                        size="sm"
                        disabled={
                            form.processing || form.data.project_id === null
                        }
                    >
                        <Plus aria-hidden="true" />
                        {t('billing.links.submit')}
                    </Button>
                </div>
            </form>
        </PageSection>
    );
}

InvoiceShow.layout = {
    breadcrumbs: [
        { title: t('billing.section'), href: '/facturacion/facturas' },
        { title: t('billing.invoices.title'), href: '/facturacion/facturas' },
    ],
};
