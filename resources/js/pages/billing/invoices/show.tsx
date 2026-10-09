import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    ChevronLeft,
    ChevronRight,
    Download,
    ExternalLink,
    FileSearch,
    Link2,
    Link2Off,
    Plus,
    Trash2,
    Undo2,
    UserRoundX,
} from 'lucide-react';
import type { FormEvent, ReactNode } from 'react';
import { useId, useState } from 'react';
import { toast } from 'sonner';
import { BillingHeader } from '@/components/billing/billing-header';
import { invoiceUrl, RelativeStatus } from '@/components/billing/invoice-table';
import {
    InvoiceTimeline,
    invoiceTimeline,
} from '@/components/billing/invoice-timeline';
import { Meter } from '@/components/billing/sold-vs-actual-kpis';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { SearchableSelect } from '@/components/domain/searchable-select';
import InputError from '@/components/input-error';
import {
    MarkNoProjectButton,
    NoProjectNeededPanel,
} from '@/components/billing/no-project-needed';
import { PageSection } from '@/components/projects-list/page-section';
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
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useClipboard } from '@/hooks/use-clipboard';
import { FOCUS_RING } from '@/lib/focus-ring';
import {
    formatCurrency,
    formatDate,
    formatNumber,
    formatPercent,
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
    own_client: boolean;
    uses_banks: boolean;
    banks: { id: number; name: string; start_date: string }[];
};

type Props = {
    invoice: HoldedInvoiceDetail;
    projects: ProjectOption[];
    suggestions: InvoiceLinkSuggestion[];
    holded: { driver: string; configured: boolean };
    holded_url: string;
    today: string;
    list: {
        query: Record<string, string | number | string[]>;
        back: string;
        previous: string | null;
        next: string | null;
        position: number | null;
        total: number;
    };
};

const NONE = '__none__';

const numberOf = (invoice: HoldedInvoiceDetail) =>
    invoice.is_draft
        ? t('billing.invoice.draft_number')
        : (invoice.number ?? '—');

/**
 * Ficha de una factura de Holded (Fase 12, F1; D-385, D-388 y D-408): la cabecera dice lo importante
 * (estado con días, lo pendiente y la barra de cobro), el enlace con proyecto o bolsa va en un solo
 * bloque con sus sugerencias, y la línea de tiempo cuenta lo que ha pasado. «Anterior» y «Siguiente»
 * recorren el listado del que vienes y la miga «Facturas» vuelve a él con sus filtros. Solo lectura
 * de Holded.
 */
export default function InvoiceShow({
    invoice,
    projects,
    suggestions,
    holded_url: holdedUrl,
    today,
    list,
}: Props) {
    const credit = invoice.kind === 'credit_note';
    const pdf = `${invoiceUrl(invoice.id)}/pdf`;
    const [, copy] = useClipboard();

    const openHolded = () => {
        if (!invoice.number) {
            return;
        }

        void copy(invoice.number).then((copied) => {
            if (copied) {
                toast.success(
                    t('billing.invoice.holded_copied', {
                        number: invoice.number ?? '',
                    }),
                );
            }
        });
    };

    return (
        <>
            <Head
                title={t('billing.invoice.title', {
                    number: numberOf(invoice),
                })}
            />

            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
                <BillingHeader
                    current="facturas"
                    kicker={
                        <>
                            {credit ? (
                                <Undo2 aria-hidden="true" className="size-4" />
                            ) : null}
                            {t(
                                credit
                                    ? 'billing.document.credit_note'
                                    : 'billing.document.invoice',
                            )}
                            <span aria-hidden="true">·</span>
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
                        </>
                    }
                    title={numberOf(invoice)}
                    description={
                        <div className="flex flex-wrap items-center gap-2">
                            <RelativeStatus invoice={invoice} today={today} />
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
                        </div>
                    }
                    actions={
                        <>
                            {list.previous || list.next ? (
                                <nav
                                    aria-label={t('billing.invoice.list_nav')}
                                    className="flex items-center gap-1"
                                    data-test="invoice-neighbours"
                                >
                                    <NeighbourLink
                                        href={list.previous}
                                        label={t('billing.invoice.previous')}
                                        icon={
                                            <ChevronLeft aria-hidden="true" />
                                        }
                                    />
                                    {list.position !== null ? (
                                        <span className="tabular px-1 text-xs text-muted-foreground">
                                            {t('billing.invoice.position', {
                                                position: list.position,
                                                total: list.total,
                                            })}
                                        </span>
                                    ) : null}
                                    <NeighbourLink
                                        href={list.next}
                                        label={t('billing.invoice.next')}
                                        icon={
                                            <ChevronRight aria-hidden="true" />
                                        }
                                    />
                                </nav>
                            ) : null}
                            <Button variant="outline" asChild>
                                <a
                                    href={pdf}
                                    target="_blank"
                                    rel="noopener"
                                    data-test="invoice-pdf"
                                >
                                    <FileSearch aria-hidden="true" />
                                    {t('billing.invoice.open_pdf')}
                                </a>
                            </Button>
                            <Button
                                variant="outline"
                                size="icon"
                                asChild
                                aria-label={t('billing.invoice.download_pdf')}
                            >
                                <a
                                    href={`${pdf}?descargar=1`}
                                    title={t('billing.invoice.download_pdf')}
                                >
                                    <Download aria-hidden="true" />
                                </a>
                            </Button>
                            {invoice.number ? (
                                <Tooltip>
                                    <TooltipTrigger asChild>
                                        <Button variant="outline" asChild>
                                            <a
                                                href={holdedUrl}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                onClick={openHolded}
                                                data-test="invoice-holded"
                                            >
                                                <ExternalLink aria-hidden="true" />
                                                {t(
                                                    'billing.invoice.open_holded',
                                                )}
                                            </a>
                                        </Button>
                                    </TooltipTrigger>
                                    <TooltipContent className="max-w-72">
                                        {t('billing.invoice.open_holded_hint', {
                                            number: invoice.number,
                                        })}
                                    </TooltipContent>
                                </Tooltip>
                            ) : null}
                        </>
                    }
                />

                {invoice.is_draft ? (
                    <p className="rounded-md bg-info-soft px-3 py-2 text-sm text-foreground">
                        {t('billing.invoice.draft_notice')}
                    </p>
                ) : null}

                <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
                    <div className="grid min-w-0 content-start gap-6">
                        <CollectionCard invoice={invoice} />

                        <PageSection title={t('billing.invoice.lines')}>
                            <LinesTable invoice={invoice} />
                        </PageSection>

                        <PageSection title={t('billing.timeline.title')}>
                            <InvoiceTimeline
                                events={invoiceTimeline(invoice, today)}
                            />
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
                        {invoice.no_project ? (
                            <NoProjectNeededPanel
                                invoice={{
                                    ...invoice,
                                    no_project: invoice.no_project,
                                }}
                            />
                        ) : (
                            <div className="grid content-start gap-2">
                                <LinksPanel
                                    invoice={invoice}
                                    projects={projects}
                                    suggestions={suggestions}
                                />
                                {/* D-431: gastos repercutidos o una factura suelta. */}
                                {invoice.links.length === 0 &&
                                invoice.collection_status !== 'cancelled' ? (
                                    <div>
                                        <MarkNoProjectButton
                                            invoice={invoice}
                                        />
                                    </div>
                                ) : null}
                            </div>
                        )}
                        <ClientPanel invoice={invoice} />
                    </aside>
                </div>
            </div>
        </>
    );
}

function NeighbourLink({
    href,
    label,
    icon,
}: {
    href: string | null;
    label: string;
    icon: ReactNode;
}) {
    if (!href) {
        return (
            <Button variant="ghost" size="icon" disabled aria-label={label}>
                {icon}
            </Button>
        );
    }

    return (
        <Button variant="ghost" size="icon" asChild>
            <Link href={href} aria-label={label} title={label}>
                {icon}
            </Link>
        </Button>
    );
}

/**
 * Lo importante del cobro (D-408): lo pendiente en grande, de cuánto, la barra de lo cobrado y las
 * cifras agrupadas por su base (sin IVA y con IVA, rotuladas una vez, D-410).
 */
function CollectionCard({ invoice }: { invoice: HoldedInvoiceDetail }) {
    const total = Number(invoice.total);
    const paid = Number(invoice.paid_total);
    const cancelled = invoice.collection_status === 'cancelled';
    const ratio = total > 0 ? Math.min(paid / total, 1) : null;

    return (
        <section
            aria-label={t('billing.invoice.collection')}
            className="grid gap-4 rounded-md border bg-card p-4"
            data-test="invoice-collection"
        >
            <div className="flex flex-wrap items-end justify-between gap-x-6 gap-y-2">
                <div className="grid gap-0.5">
                    <p className="text-sm text-muted-foreground">
                        {t('billing.invoice.pending')}
                    </p>
                    <p className="tabular text-3xl">
                        {formatCurrency(
                            cancelled ? '0' : invoice.pending_total,
                        )}
                        <span className="ml-2 text-base text-muted-foreground">
                            {t('billing.invoice.of_total', {
                                total: formatCurrency(invoice.total),
                            })}
                        </span>
                    </p>
                </div>
                {ratio !== null && !cancelled ? (
                    <p className="text-sm text-muted-foreground">
                        {t('billing.invoice.paid_ratio', {
                            paid: formatCurrency(invoice.paid_total),
                            pct: formatPercent(ratio, 0),
                        })}
                    </p>
                ) : null}
            </div>
            {ratio !== null && !cancelled ? (
                <Meter
                    value={paid}
                    max={total}
                    label={t('billing.invoice.paid_meter', {
                        pct: formatPercent(ratio, 0),
                    })}
                />
            ) : null}
            <div className="grid gap-4 border-t pt-4 sm:grid-cols-3">
                <FactGroup title={t('billing.invoice.dates')}>
                    <Fact
                        label={t('billing.invoice.date')}
                        value={formatDate(invoice.issued_on)}
                    />
                    <Fact
                        label={t('billing.invoice.due_on')}
                        value={
                            invoice.due_on ? formatDate(invoice.due_on) : '—'
                        }
                    />
                </FactGroup>
                <FactGroup title={t('billing.invoice.without_vat')}>
                    <Fact
                        label={t('billing.invoice.subtotal')}
                        value={formatCurrency(invoice.subtotal)}
                    />
                </FactGroup>
                <FactGroup title={t('billing.invoice.with_vat')}>
                    <Fact
                        label={t('billing.invoice.tax')}
                        value={formatCurrency(invoice.tax_total)}
                    />
                    <Fact
                        label={t('billing.invoice.total')}
                        value={formatCurrency(invoice.total)}
                    />
                    <Fact
                        label={t('billing.invoice.paid')}
                        value={formatCurrency(invoice.paid_total)}
                    />
                </FactGroup>
            </div>
            {invoice.currency !== 'EUR' ? (
                <p className="text-xs text-muted-foreground">
                    {t('billing.invoice.currency_note', {
                        currency: invoice.currency,
                    })}
                </p>
            ) : null}
        </section>
    );
}

function FactGroup({
    title,
    children,
}: {
    title: string;
    children: ReactNode;
}) {
    return (
        <div className="grid content-start gap-2">
            <h3 className="text-xs font-medium tracking-[0.12em] text-muted-foreground uppercase">
                {title}
            </h3>
            <dl className="grid gap-1.5">{children}</dl>
        </div>
    );
}

function Fact({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex items-baseline justify-between gap-3 text-sm">
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="tabular">{value}</dd>
        </div>
    );
}

function LinesTable({ invoice }: { invoice: HoldedInvoiceDetail }) {
    return (
        <div
            className={cn('overflow-x-auto rounded-md border', FOCUS_RING)}
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
                        <th scope="col" className="px-3 py-2 font-medium">
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
                            {t('billing.invoice.subtotal_short')}
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
                                        t(`billing.line_kind.${line.kind}`),
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
                                {formatNumber(Number(line.units), 2)}
                            </td>
                            <td className="px-3 py-2 text-right">
                                {formatCurrency(line.unit_price)}
                            </td>
                            <td className="px-3 py-2 text-right">
                                {line.tax_rate === null
                                    ? '—'
                                    : `${formatNumber(Number(line.tax_rate), 0)} %`}
                            </td>
                            <td className="px-3 py-2 text-right">
                                {formatCurrency(line.subtotal)}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function acceptSuggestion(
    invoiceId: number,
    suggestion: InvoiceLinkSuggestion,
): void {
    router.post(
        `${invoiceUrl(invoiceId)}/enlaces`,
        {
            project_id: suggestion.project.id,
            hour_bank_id: suggestion.bank?.id ?? null,
        },
        { preserveScroll: true },
    );
}

/**
 * Proyecto y bolsa en un solo bloque (D-408): si está enlazada, sus enlaces (los manuales se
 * quitan) y «Añadir otro»; si no, la sugerencia principal con «Enlazar», las demás debajo y «Elegir
 * otro proyecto…», que abre el buscador con todos los proyectos (los del cliente primero).
 */
function LinksPanel({
    invoice,
    projects,
    suggestions,
}: {
    invoice: HoldedInvoiceDetail;
    projects: ProjectOption[];
    suggestions: InvoiceLinkSuggestion[];
}) {
    const linked = invoice.links.length > 0;
    const [choosing, setChoosing] = useState(
        !linked && suggestions.length === 0,
    );
    const [main, ...others] = suggestions;

    return (
        <PageSection
            title={t('billing.links.title')}
            description={linked ? t('billing.links.description') : undefined}
        >
            {linked ? (
                <ul className="grid gap-2" data-test="invoice-links">
                    {invoice.links.map((link) => (
                        <li
                            key={link.id}
                            className="flex items-start justify-between gap-2 rounded-md border bg-card px-3 py-2 text-sm"
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
                                            { preserveScroll: true },
                                        )
                                    }
                                />
                            ) : null}
                        </li>
                    ))}
                </ul>
            ) : (
                <p
                    className="flex items-center gap-1.5 text-sm text-muted-foreground"
                    data-test="invoice-unlinked"
                >
                    <Link2Off aria-hidden="true" className="size-4" />
                    {t('billing.links.none')}
                </p>
            )}

            {!linked && main ? (
                <div
                    className="grid gap-2 rounded-md border border-primary bg-card p-3 text-sm"
                    data-test="invoice-suggestions"
                >
                    <p className="text-xs text-muted-foreground">
                        {t('billing.links.suggested', {
                            reason: t(
                                `billing.suggestions.reason.${main.reason}`,
                            ),
                        })}
                    </p>
                    <p>
                        <span className="text-muted-foreground">
                            {main.project.code}
                        </span>{' '}
                        {main.bank ? main.bank.name : main.project.name}
                    </p>
                    <div>
                        <Button
                            type="button"
                            size="sm"
                            onClick={() => acceptSuggestion(invoice.id, main)}
                        >
                            <Link2 aria-hidden="true" />
                            {t('billing.suggestions.accept')}
                        </Button>
                    </div>
                </div>
            ) : null}

            {!linked && others.length > 0 ? (
                <ul
                    className="grid gap-1.5"
                    aria-label={t('billing.links.others')}
                >
                    {others.map((suggestion) => (
                        <li
                            key={`${suggestion.project.id}-${suggestion.bank?.id ?? 0}`}
                            className="flex items-center justify-between gap-2 text-sm"
                        >
                            <span className="min-w-0 truncate">
                                <span className="text-muted-foreground">
                                    {suggestion.project.code}
                                </span>{' '}
                                {suggestion.bank
                                    ? suggestion.bank.name
                                    : suggestion.project.name}
                            </span>
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                className="h-7"
                                onClick={() =>
                                    acceptSuggestion(invoice.id, suggestion)
                                }
                                aria-label={t('billing.links.accept_other', {
                                    project: suggestion.project.code,
                                })}
                            >
                                {t('billing.suggestions.accept')}
                            </Button>
                        </li>
                    ))}
                </ul>
            ) : null}

            {choosing ? (
                <LinkForm invoice={invoice} projects={projects} />
            ) : (
                <div>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={() => setChoosing(true)}
                        data-test="invoice-choose-project"
                    >
                        <Plus aria-hidden="true" />
                        {t(
                            linked
                                ? 'billing.links.add_another'
                                : 'billing.links.choose_other',
                        )}
                    </Button>
                </div>
            )}
        </PageSection>
    );
}

function LinkForm({
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
    const own = projects.filter((option) => option.own_client);
    const rest = projects.filter((option) => !option.own_client);
    const option = (item: ProjectOption) => ({
        value: String(item.id),
        label: `${item.code} · ${item.name}`,
        hint: item.client,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (form.data.project_id === null) {
            form.setError('project_id', t('billing.links.project_required'));

            return;
        }

        form.post(`${invoiceUrl(invoice.id)}/enlaces`, {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    };

    return (
        <form
            onSubmit={submit}
            className="grid gap-3 rounded-md border border-dashed p-3"
            noValidate
        >
            <div className="grid gap-1">
                <Label htmlFor={`${id}-project`}>
                    {t('billing.links.project')}
                </Label>
                <SearchableSelect
                    id={`${id}-project`}
                    value={
                        form.data.project_id
                            ? String(form.data.project_id)
                            : null
                    }
                    placeholder={t('billing.links.project_placeholder')}
                    search={t('billing.links.search_project')}
                    empty={t('billing.links.no_project_found')}
                    invalid={Boolean(form.errors.project_id)}
                    dataTest="invoice-link-project"
                    groups={[
                        ...(own.length > 0
                            ? [
                                  {
                                      label: t('billing.links.own_client'),
                                      options: own.map(option),
                                  },
                              ]
                            : []),
                        {
                            label:
                                own.length > 0
                                    ? t('billing.links.other_projects')
                                    : null,
                            options: rest.map(option),
                        },
                    ]}
                    onChange={(value) => {
                        const projectId = Number(value);
                        const chosen = projects.find(
                            (item) => item.id === projectId,
                        );
                        form.clearErrors('project_id');
                        form.setData({
                            project_id: projectId,
                            hour_bank_id: chosen?.uses_banks
                                ? (chosen.banks[0]?.id ?? null)
                                : null,
                        });
                    }}
                />
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
                                    {bank.name} · {formatDate(bank.start_date)}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError message={form.errors.hour_bank_id} />
                </div>
            ) : null}
            <div>
                <Button type="submit" size="sm" disabled={form.processing}>
                    <Link2 aria-hidden="true" />
                    {t('billing.links.submit')}
                </Button>
            </div>
        </form>
    );
}

/** El cliente de Audax y el contacto de Holded; sin cliente, cómo resolverlo. */
function ClientPanel({ invoice }: { invoice: HoldedInvoiceDetail }) {
    return (
        <PageSection title={t('billing.invoice.client')}>
            {invoice.client ? (
                <dl className="grid gap-1.5 text-sm">
                    <div className="flex justify-between gap-3">
                        <dt className="text-muted-foreground">
                            {t('billing.invoice.audax_client')}
                        </dt>
                        <dd className="min-w-0 truncate text-right">
                            <Link
                                href={`/clientes/${invoice.client.id}/facturacion`}
                                className={cn(
                                    'rounded-md text-primary-text hover:underline',
                                    FOCUS_RING,
                                )}
                            >
                                {invoice.client.name}
                            </Link>
                        </dd>
                    </div>
                    {invoice.contact_name ? (
                        <div className="flex justify-between gap-3">
                            <dt className="text-muted-foreground">
                                {t('billing.invoice.holded_contact')}
                            </dt>
                            <dd className="min-w-0 truncate text-right">
                                {invoice.contact_name}
                            </dd>
                        </div>
                    ) : null}
                </dl>
            ) : (
                <div className="grid gap-2 rounded-md bg-warning-soft px-3 py-2 text-sm text-foreground">
                    <p className="flex items-start gap-1.5">
                        <UserRoundX
                            aria-hidden="true"
                            className="mt-0.5 size-4 shrink-0"
                        />
                        {t('billing.invoice.no_client_notice')}
                    </p>
                    <Link
                        href="/facturacion/por-revisar"
                        className={cn('underline', FOCUS_RING)}
                    >
                        {t('billing.invoice.resolve_contact')}
                    </Link>
                </div>
            )}
        </PageSection>
    );
}

InvoiceShow.layout = (props: Props) => ({
    breadcrumbs: [
        { title: t('billing.section'), href: '/facturacion' },
        { title: t('billing.invoices.title'), href: props.list.back },
        {
            title: numberOf(props.invoice),
            href: invoiceUrl(props.invoice.id, props.list.query),
        },
    ],
});
