import { Head, Link, router } from '@inertiajs/react';
import {
    Ban,
    Check,
    CheckCheck,
    FileCheck2,
    Link2,
    Search,
    UserRoundCheck,
    UsersRound,
} from 'lucide-react';
import { useId, useMemo, useState } from 'react';
import { BillingHeader } from '@/components/billing/billing-header';
import { HelpTip } from '@/components/billing/help-tip';
import {
    ConfidenceBadge,
    contactReason,
    parseTarget,
    targetGroups,
    UndoBar,
} from '@/components/billing/review-parts';
import { ViewTabs } from '@/components/billing/view-tabs';
import { SearchableSelect } from '@/components/domain/searchable-select';
import { EmptyState } from '@/components/empty-state';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatCurrency, formatDate, formatNumber } from '@/lib/format';
import { t } from '@/lib/i18n';
import { tCount } from '@/lib/people';
import { cn } from '@/lib/utils';
import type {
    BillingClientOption,
    ReviewContactRow,
    ReviewInvoiceRow,
    ReviewLinkTarget,
} from '@/types';

type Tab = 'contactos' | 'facturas';

type Props = {
    tab: Tab;
    counts: Record<Tab, number>;
    coverage: {
        contacts_matched: number;
        contacts_total: number;
        invoices_linked: number;
        invoices_total: number;
    };
    contacts: ReviewContactRow[];
    invoices: ReviewInvoiceRow[];
    clients: BillingClientOption[];
    targets: ReviewLinkTarget[];
    undo: { message: string; count: number } | null;
};

const URL = '/facturacion/por-revisar';

/** «estudio nébula» encuentra «Estudio Nébula, S.L.» (sin tildes ni mayúsculas). */
const fold = (value: string | null | undefined) =>
    (value ?? '')
        .normalize('NFD')
        .replace(/\p{Diacritic}/gu, '')
        .toLowerCase();

/**
 * Por revisar (I5, D-413 y D-414): la bandeja única para emparejar Holded con Audax, con dos
 * pestañas: los contactos sin cliente o por confirmar y las facturas sin proyecto ni bolsa. Cada
 * fila trae la propuesta con su motivo y su confianza; se acepta, se descarta o se elige otra en la
 * misma fila. «Aceptar las de confianza alta» y «Deshacer» la última acción. Primero lo que más
 * importe tiene. Nunca se crea un cliente desde Holded (D-387).
 */
export default function Review({
    tab,
    counts,
    coverage,
    contacts,
    invoices,
    clients,
    targets,
    undo,
}: Props) {
    const id = useId();
    const [search, setSearch] = useState('');
    const [processing, setProcessing] = useState(false);
    const query = fold(search.trim());

    const shownContacts = useMemo(
        () =>
            query === ''
                ? contacts
                : contacts.filter((row) =>
                      [row.name, row.trade_name, row.tax_id, row.city]
                          .map(fold)
                          .some((value) => value.includes(query)),
                  ),
        [contacts, query],
    );
    const shownInvoices = useMemo(
        () =>
            query === ''
                ? invoices
                : invoices.filter((row) =>
                      [row.number, row.client?.name, row.contact_name]
                          .map(fold)
                          .some((value) => value.includes(query)),
                  ),
        [invoices, query],
    );

    const high =
        tab === 'contactos'
            ? contacts.filter(
                  (row) =>
                      row.client === null &&
                      row.proposal?.confidence === 'alta',
              ).length
            : invoices.filter((row) => row.proposal?.confidence === 'alta')
                  .length;

    const acceptHigh = () =>
        router.post(
            `${URL}/aceptar`,
            { tipo: tab },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
            },
        );

    const rows = tab === 'contactos' ? shownContacts : shownInvoices;
    const total = tab === 'contactos' ? contacts.length : invoices.length;

    return (
        <>
            <Head title={t('billing.nav.review')} />

            <div className="flex min-w-0 flex-1 flex-col gap-5 p-4 md:p-6">
                <BillingHeader
                    current="por-revisar"
                    title={t('billing.nav.review')}
                    description={t('billing.review.description')}
                />

                <p
                    className="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-muted-foreground"
                    data-test="review-coverage"
                >
                    <span className="inline-flex items-center gap-1.5">
                        <UsersRound aria-hidden="true" className="size-4" />
                        {t('billing.review.coverage_contacts', {
                            matched: formatNumber(coverage.contacts_matched, 0),
                            total: formatNumber(coverage.contacts_total, 0),
                        })}
                    </span>
                    <span aria-hidden="true">·</span>
                    <span className="inline-flex items-center gap-1.5">
                        <Link2 aria-hidden="true" className="size-4" />
                        {t('billing.review.coverage_invoices', {
                            linked: formatNumber(coverage.invoices_linked, 0),
                            total: formatNumber(coverage.invoices_total, 0),
                        })}
                    </span>
                    <HelpTip topic={t('billing.review.coverage')}>
                        <p>{t('billing.review.coverage_help')}</p>
                    </HelpTip>
                </p>

                <UndoBar undo={undo} />

                <ViewTabs
                    label={t('billing.review.tabs')}
                    current={tab}
                    dataTest="review-tabs"
                    tabs={[
                        {
                            id: 'contactos',
                            label: t('billing.review.tab.contactos'),
                            href: `${URL}?tipo=contactos`,
                            count: counts.contactos,
                        },
                        {
                            id: 'facturas',
                            label: t('billing.review.tab.facturas'),
                            href: `${URL}?tipo=facturas`,
                            count: counts.facturas,
                        },
                    ]}
                />

                {total === 0 ? (
                    <ReviewEmpty tab={tab} counts={counts} />
                ) : (
                    <>
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <div className="relative w-full sm:w-72">
                                <Label
                                    htmlFor={`${id}-search`}
                                    className="sr-only"
                                >
                                    {t('billing.review.search')}
                                </Label>
                                <Search
                                    aria-hidden="true"
                                    className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                                />
                                <Input
                                    id={`${id}-search`}
                                    type="search"
                                    value={search}
                                    onChange={(event) =>
                                        setSearch(event.target.value)
                                    }
                                    placeholder={t(
                                        tab === 'contactos'
                                            ? 'billing.review.search_contacts'
                                            : 'billing.review.search_invoices',
                                    )}
                                    className="h-8 pl-8"
                                    autoComplete="off"
                                />
                            </div>
                            <div className="flex items-center gap-1">
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    disabled={high === 0 || processing}
                                    onClick={acceptHigh}
                                    data-test="review-accept-high"
                                >
                                    <CheckCheck aria-hidden="true" />
                                    {t('billing.review.accept_high', {
                                        count: high,
                                    })}
                                </Button>
                                <HelpTip
                                    topic={t('billing.review.confidence_topic')}
                                >
                                    <p>
                                        {t(
                                            tab === 'contactos'
                                                ? 'billing.review.confidence_help_contacts'
                                                : 'billing.review.confidence_help_invoices',
                                        )}
                                    </p>
                                </HelpTip>
                            </div>
                        </div>

                        <p className="sr-only" aria-live="polite">
                            {tCount('billing.review.results', rows.length)}
                        </p>

                        {rows.length === 0 ? (
                            <EmptyState
                                icon={Search}
                                title={t('billing.review.no_results')}
                                description={t(
                                    'billing.review.no_results_description',
                                )}
                            />
                        ) : tab === 'contactos' ? (
                            <ContactList
                                rows={shownContacts}
                                clients={clients}
                            />
                        ) : (
                            <InvoiceList
                                rows={shownInvoices}
                                targets={targets}
                            />
                        )}
                    </>
                )}
            </div>
        </>
    );
}

/** Estado vacío de cada pestaña con el paso siguiente (R7): la otra pestaña o el Resumen. */
function ReviewEmpty({
    tab,
    counts,
}: {
    tab: Tab;
    counts: Record<Tab, number>;
}) {
    const other: Tab = tab === 'contactos' ? 'facturas' : 'contactos';

    return (
        <EmptyState
            icon={tab === 'contactos' ? UserRoundCheck : FileCheck2}
            title={t(`billing.review.empty.${tab}`)}
            description={t(`billing.review.empty.${tab}_description`)}
        >
            {counts[other] > 0 ? (
                <Button size="sm" asChild>
                    <Link href={`${URL}?tipo=${other}`}>
                        {tCount(`billing.review.next.${other}`, counts[other])}
                    </Link>
                </Button>
            ) : (
                <Button size="sm" variant="outline" asChild>
                    <Link href="/facturacion">
                        {t('billing.review.next.home')}
                    </Link>
                </Button>
            )}
        </EmptyState>
    );
}

function useSend() {
    const [processing, setProcessing] = useState(false);

    const send = (
        method: 'put' | 'post',
        url: string,
        data: Record<string, string | number | null>,
    ) =>
        router[method](url, data, {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
        });

    return { processing, send };
}

/** La línea de un contacto: nombre, NIF y ciudad, y lo que ha facturado. */
function ContactIdentity({ row }: { row: ReviewContactRow }) {
    return (
        <>
            <span className="block truncate" title={row.name}>
                {row.name}
            </span>
            <span className="block truncate text-xs text-muted-foreground">
                {[
                    row.trade_name && row.trade_name !== row.name
                        ? row.trade_name
                        : null,
                    row.tax_id
                        ? t('billing.contacts.tax_id', { tax_id: row.tax_id })
                        : t('billing.contacts.no_tax_id'),
                    row.city,
                ]
                    .filter(Boolean)
                    .join(' · ')}
            </span>
        </>
    );
}

function clientGroups(clients: BillingClientOption[]) {
    return [
        {
            label: null,
            options: clients.map((client) => ({
                value: String(client.id),
                label: client.is_active
                    ? client.name
                    : t('billing.contacts.inactive_client', {
                          name: client.name,
                      }),
                hint: client.tax_id,
            })),
        },
    ];
}

/**
 * Las acciones de un contacto: Aceptar la propuesta (casarlo con ese cliente o confirmar el
 * parecido), Descartar (no es cliente de la agencia) y Elegir otro, con el buscador de clientes.
 */
function ContactActions({
    row,
    clients,
}: {
    row: ReviewContactRow;
    clients: BillingClientOption[];
}) {
    const { processing, send } = useSend();
    const [choosing, setChoosing] = useState(row.proposal === null);
    const url = `/facturacion/contactos/${row.id}`;

    const accept = () =>
        row.client !== null
            ? send('put', url, { action: 'confirm', client_id: null })
            : send('put', url, {
                  action: 'assign',
                  client_id: row.proposal?.client.id ?? null,
              });

    return (
        <div className="flex flex-wrap items-center justify-end gap-1 lg:flex-nowrap">
            {choosing ? (
                <SearchableSelect
                    value={null}
                    placeholder={t('billing.contacts.choose_client')}
                    search={t('billing.contacts.search_client')}
                    empty={t('billing.contacts.no_client_found')}
                    aria-label={t('billing.review.choose_client_for', {
                        contact: row.name,
                    })}
                    className="h-8 w-full min-w-0 sm:w-56"
                    dataTest="review-choose-client"
                    groups={clientGroups(clients)}
                    disabled={processing}
                    onChange={(value) =>
                        send('put', url, {
                            action: 'assign',
                            client_id: Number(value),
                        })
                    }
                />
            ) : null}
            {row.proposal !== null ? (
                <Button
                    type="button"
                    size="sm"
                    disabled={processing}
                    onClick={accept}
                    aria-label={t('billing.review.accept_contact', {
                        contact: row.name,
                        client: row.proposal.client.name,
                    })}
                    data-test="review-accept"
                >
                    <Check aria-hidden="true" />
                    {t('billing.review.accept')}
                </Button>
            ) : null}
            {row.proposal !== null && !choosing ? (
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    disabled={processing}
                    onClick={() => setChoosing(true)}
                    data-test="review-other"
                >
                    {t('billing.review.other')}
                </Button>
            ) : null}
            <Button
                type="button"
                size="sm"
                variant="ghost"
                disabled={processing}
                onClick={() =>
                    send('put', url, { action: 'ignore', client_id: null })
                }
                aria-label={t('billing.review.ignore_contact', {
                    contact: row.name,
                })}
                title={t('billing.review.ignore_hint')}
                data-test="review-ignore"
            >
                <Ban aria-hidden="true" />
                <span className="sm:sr-only lg:not-sr-only">
                    {t('billing.contacts.ignore')}
                </span>
            </Button>
        </div>
    );
}

function ContactProposal({ row }: { row: ReviewContactRow }) {
    if (row.proposal === null) {
        return (
            <span className="text-muted-foreground">
                {t('billing.review.no_proposal')}
            </span>
        );
    }

    return (
        <span className="grid gap-0.5">
            <span className="truncate" title={row.proposal.client.name}>
                {row.proposal.client.name}
            </span>
            <span className="flex flex-wrap items-center gap-1.5 text-xs text-muted-foreground">
                {contactReason(row.proposal.reason)}
            </span>
        </span>
    );
}

function ContactList({
    rows,
    clients,
}: {
    rows: ReviewContactRow[];
    clients: BillingClientOption[];
}) {
    const caption = t('billing.review.contacts_caption');

    return (
        <>
            <div
                className={cn(
                    'hidden overflow-x-auto rounded-md border md:block',
                    FOCUS_RING,
                )}
                role="region"
                aria-label={caption}
                tabIndex={0}
            >
                <table
                    className="tabular w-full text-sm"
                    data-test="review-contacts"
                >
                    <caption className="sr-only">{caption}</caption>
                    <thead>
                        <tr className="border-b">
                            <th
                                scope="col"
                                className="w-[28%] px-3 py-2 text-left"
                            >
                                {t('billing.review.col.contact')}
                            </th>
                            <th
                                scope="col"
                                className="px-3 py-2 text-right whitespace-nowrap"
                            >
                                {t('billing.review.col.invoiced')}
                            </th>
                            <th
                                scope="col"
                                className="w-[22%] px-3 py-2 text-left"
                            >
                                {t('billing.review.col.proposal')}
                            </th>
                            <th scope="col" className="px-3 py-2 text-left">
                                {t('billing.review.col.confidence')}
                            </th>
                            <th scope="col" className="px-3 py-2 text-right">
                                {t('billing.review.col.actions')}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((row) => (
                            <tr
                                key={row.id}
                                className="border-b last:border-0 even:bg-muted"
                                data-test="review-contact-row"
                            >
                                <th
                                    scope="row"
                                    className="max-w-0 px-3 py-2 text-left font-normal"
                                >
                                    <ContactIdentity row={row} />
                                </th>
                                <td className="px-3 py-2 text-right whitespace-nowrap">
                                    {formatCurrency(row.invoiced)}
                                    <span className="block text-xs text-muted-foreground">
                                        {tCount(
                                            'billing.invoices',
                                            row.invoices,
                                        )}
                                    </span>
                                </td>
                                <td className="max-w-0 px-3 py-2">
                                    <ContactProposal row={row} />
                                </td>
                                <td className="px-3 py-2">
                                    {row.proposal ? (
                                        <ConfidenceBadge
                                            confidence={row.proposal.confidence}
                                        />
                                    ) : null}
                                </td>
                                <td className="px-3 py-2">
                                    <ContactActions
                                        row={row}
                                        clients={clients}
                                    />
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <ul
                className="grid gap-2 md:hidden"
                data-test="review-contact-cards"
            >
                {rows.map((row) => (
                    <li
                        key={row.id}
                        className="grid gap-2 rounded-md border bg-card p-3 text-sm"
                    >
                        <div className="flex items-start justify-between gap-3">
                            <div className="min-w-0">
                                <ContactIdentity row={row} />
                            </div>
                            <span className="tabular shrink-0">
                                {formatCurrency(row.invoiced)}
                            </span>
                        </div>
                        <div className="flex items-start justify-between gap-3 border-t pt-2">
                            <div className="min-w-0">
                                <ContactProposal row={row} />
                            </div>
                            {row.proposal ? (
                                <ConfidenceBadge
                                    confidence={row.proposal.confidence}
                                />
                            ) : null}
                        </div>
                        <ContactActions row={row} clients={clients} />
                    </li>
                ))}
            </ul>
        </>
    );
}

/** El motivo de la propuesta de una factura: cliente, servicio y, si cuadra, la fecha. */
function invoiceReason(row: ReviewInvoiceRow): string {
    if (row.proposal === null) {
        return '';
    }

    return t(
        row.proposal.dated
            ? 'billing.review.reason.invoice_dated'
            : 'billing.review.reason.invoice',
        {
            service: t(`billing.suggestions.reason.${row.proposal.reason}`),
        },
    );
}

function InvoiceIdentity({ row }: { row: ReviewInvoiceRow }) {
    return (
        <>
            <Link
                href={`/facturacion/facturas/${row.id}?vista=sin-proyecto`}
                className={cn(
                    'rounded-md text-primary-text hover:underline',
                    FOCUS_RING,
                )}
            >
                {row.is_draft
                    ? t('billing.invoice.draft_number')
                    : (row.number ?? '—')}
            </Link>
            <span className="block truncate text-xs text-muted-foreground">
                {[
                    formatDate(row.issued_on),
                    row.client?.name ?? row.contact_name,
                ]
                    .filter(Boolean)
                    .join(' · ')}
            </span>
        </>
    );
}

function InvoiceProposal({ row }: { row: ReviewInvoiceRow }) {
    if (row.client === null) {
        return (
            <span className="text-xs text-muted-foreground">
                {t('billing.review.no_client')}{' '}
                <Link
                    href={`${URL}?tipo=contactos`}
                    className={cn(
                        'rounded-md text-primary-text underline underline-offset-2',
                        FOCUS_RING,
                    )}
                >
                    {t('billing.review.match_contact_first')}
                </Link>
            </span>
        );
    }

    if (row.proposal === null) {
        return (
            <span className="text-muted-foreground">
                {t('billing.review.no_proposal')}
            </span>
        );
    }

    const target = row.proposal.bank
        ? row.proposal.bank.name
        : row.proposal.project.name;

    return (
        <span className="grid gap-0.5">
            <span
                className="truncate"
                title={`${row.proposal.project.code} · ${target}`}
            >
                <span className="text-muted-foreground">
                    {row.proposal.project.code}
                </span>{' '}
                {target}
            </span>
            <span className="text-xs text-muted-foreground">
                {invoiceReason(row)}
                {row.alternatives.length > 0
                    ? ` · ${tCount('billing.review.alternatives', row.alternatives.length)}`
                    : ''}
            </span>
        </span>
    );
}

/**
 * Las acciones de una factura: Enlazar la propuesta, Rechazarla (no se guarda: deja elegir otro
 * proyecto) y Elegir otro proyecto o bolsa con el buscador (todos los proyectos con cliente, los
 * suyos primero).
 */
function InvoiceActions({
    row,
    targets,
}: {
    row: ReviewInvoiceRow;
    targets: ReviewLinkTarget[];
}) {
    const { processing, send } = useSend();
    const [choosing, setChoosing] = useState(row.proposal === null);
    const groups = useMemo(
        () => (choosing ? targetGroups(targets, row.client?.id ?? null) : []),
        [choosing, targets, row.client],
    );
    const url = `/facturacion/facturas/${row.id}/enlaces`;
    const label = row.number ?? t('billing.invoice.draft_number');

    return (
        <div className="flex flex-wrap items-center justify-end gap-1 lg:flex-nowrap">
            {choosing ? (
                <SearchableSelect
                    value={null}
                    placeholder={t('billing.review.choose_project')}
                    search={t('billing.review.search_project')}
                    empty={t('billing.review.no_project_found')}
                    aria-label={t('billing.review.choose_project_for', {
                        invoice: label,
                    })}
                    className="h-8 w-full min-w-0 sm:w-56"
                    dataTest="review-choose-project"
                    groups={groups}
                    disabled={processing}
                    onChange={(value) => send('post', url, parseTarget(value))}
                />
            ) : null}
            {row.proposal !== null && !choosing ? (
                <>
                    <Button
                        type="button"
                        size="sm"
                        disabled={processing}
                        onClick={() =>
                            send('post', url, {
                                project_id: row.proposal?.project.id ?? null,
                                hour_bank_id: row.proposal?.bank?.id ?? null,
                            })
                        }
                        aria-label={t('billing.review.accept_invoice', {
                            invoice: label,
                            project: row.proposal.project.code,
                        })}
                        data-test="review-accept"
                    >
                        <Check aria-hidden="true" />
                        {t('billing.review.link')}
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        disabled={processing}
                        onClick={() => setChoosing(true)}
                        aria-label={t('billing.review.reject_invoice', {
                            invoice: label,
                        })}
                        title={t('billing.review.reject_hint')}
                        data-test="review-reject"
                    >
                        <Ban aria-hidden="true" />
                        {t('billing.review.reject')}
                    </Button>
                </>
            ) : null}
        </div>
    );
}

function InvoiceList({
    rows,
    targets,
}: {
    rows: ReviewInvoiceRow[];
    targets: ReviewLinkTarget[];
}) {
    const caption = t('billing.review.invoices_caption');

    return (
        <>
            <div
                className={cn(
                    'hidden overflow-x-auto rounded-md border md:block',
                    FOCUS_RING,
                )}
                role="region"
                aria-label={caption}
                tabIndex={0}
            >
                <table
                    className="tabular w-full text-sm"
                    data-test="review-invoices"
                >
                    <caption className="sr-only">{caption}</caption>
                    <thead>
                        <tr className="border-b">
                            <th
                                scope="col"
                                className="w-[24%] px-3 py-2 text-left"
                            >
                                {t('billing.review.col.invoice')}
                            </th>
                            <th
                                scope="col"
                                className="px-3 py-2 text-right whitespace-nowrap"
                            >
                                {t('billing.invoice.subtotal_short')}
                            </th>
                            <th
                                scope="col"
                                className="w-[28%] px-3 py-2 text-left"
                            >
                                {t('billing.review.col.proposal')}
                            </th>
                            <th scope="col" className="px-3 py-2 text-left">
                                {t('billing.review.col.confidence')}
                            </th>
                            <th scope="col" className="px-3 py-2 text-right">
                                {t('billing.review.col.actions')}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((row) => (
                            <tr
                                key={row.id}
                                className="border-b last:border-0 even:bg-muted"
                                data-test="review-invoice-row"
                            >
                                <th
                                    scope="row"
                                    className="max-w-0 px-3 py-2 text-left font-normal"
                                >
                                    <InvoiceIdentity row={row} />
                                </th>
                                <td className="px-3 py-2 text-right whitespace-nowrap">
                                    {formatCurrency(row.subtotal)}
                                </td>
                                <td className="max-w-0 px-3 py-2">
                                    <InvoiceProposal row={row} />
                                </td>
                                <td className="px-3 py-2">
                                    {row.proposal ? (
                                        <ConfidenceBadge
                                            confidence={row.proposal.confidence}
                                        />
                                    ) : null}
                                </td>
                                <td className="px-3 py-2">
                                    <InvoiceActions
                                        row={row}
                                        targets={targets}
                                    />
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <ul
                className="grid gap-2 md:hidden"
                data-test="review-invoice-cards"
            >
                {rows.map((row) => (
                    <li
                        key={row.id}
                        className="grid gap-2 rounded-md border bg-card p-3 text-sm"
                    >
                        <div className="flex items-start justify-between gap-3">
                            <div className="min-w-0">
                                <InvoiceIdentity row={row} />
                            </div>
                            <span className="tabular shrink-0">
                                {formatCurrency(row.subtotal)}
                            </span>
                        </div>
                        <div className="flex items-start justify-between gap-3 border-t pt-2">
                            <div className="min-w-0">
                                <InvoiceProposal row={row} />
                            </div>
                            {row.proposal ? (
                                <ConfidenceBadge
                                    confidence={row.proposal.confidence}
                                />
                            ) : null}
                        </div>
                        <InvoiceActions row={row} targets={targets} />
                    </li>
                ))}
            </ul>
        </>
    );
}

Review.layout = {
    breadcrumbs: [
        { title: t('billing.section'), href: '/facturacion' },
        { title: t('billing.nav.review'), href: URL },
    ],
};
