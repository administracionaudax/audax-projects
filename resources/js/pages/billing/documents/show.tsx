import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    Ban,
    BellRing,
    CalendarClock,
    CalendarX2,
    ChevronLeft,
    ChevronRight,
    Clock,
    Copy,
    Download,
    FileCheck2,
    FileSearch,
    FileText,
    FileX2,
    Link2,
    MoreHorizontal,
    PencilLine,
    Plus,
    MailWarning,
    Repeat,
    Send,
    ShieldCheck,
    Trash2,
    Undo2,
    Wallet,
} from 'lucide-react';
import type { FormEvent, ReactNode } from 'react';
import { useId, useState } from 'react';
import { BillingHeader } from '@/components/billing/billing-header';
import { RelativeStatus } from '@/components/billing/invoice-table';
import { InvoiceTimeline } from '@/components/billing/invoice-timeline';
import {
    MarkNoProjectButton,
    NoProjectNeededPanel,
} from '@/components/billing/no-project-needed';
import type { TimelineEvent } from '@/components/billing/invoice-timeline';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { SearchableSelect } from '@/components/domain/searchable-select';
import InputError from '@/components/input-error';
import {
    CancelDialog,
    IssueDialog,
    RectifyDialog,
    VoidDialog,
} from '@/components/invoicing/document-dialogs';
import {
    RecordPaymentDialog,
    SendDocumentDialog,
} from '@/components/invoicing/collect-dialogs';
import {
    AttachmentsSection,
    ClientViews,
    DocumentChain,
    PaymentsSection,
} from '@/components/invoicing/collect-panels';
import { InvoiceTotalsPanel } from '@/components/invoicing/invoice-totals-panel';
import { ScheduleDialog } from '@/components/invoicing/schedule-dialog';
import {
    formatQuantity,
    taxLabel,
    unitLabel,
} from '@/components/invoicing/invoicing-format';
import { PageSection } from '@/components/projects-list/page-section';
import { StatusBadge } from '@/components/styleguide/status-badges';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatCurrency, formatDate, formatDateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import { tCount } from '@/lib/people';
import { cn } from '@/lib/utils';
import type { SalesDocumentDetail, SalesDocumentShowProps } from '@/types';

const NONE = '__none__';

/**
 * Ficha de una factura propia (PLAN-EMISION §6.2 y D-408; D-428): la cabecera con el número, el
 * estado y las acciones de la matriz (DocumentActions), el total y lo pendiente, las líneas, el
 * cuadro de impuestos, la historia, el registro de facturación plegado (orden, huella y anterior) y,
 * al lado, el proyecto y la bolsa y la nota interna (lo no fiscal, que se cambia también emitida) y
 * los datos del cliente tal como quedaron en la factura. Una emitida no se edita: se anula o se
 * rectifica.
 */
export default function SalesDocumentShow({
    document,
    actions,
    issue,
    void_problems: voidProblems,
    projects,
    open_issue: openIssue,
    open_schedule: openSchedule = false,
    auto_active: autoActive = false,
    today,
    payment_methods: paymentMethods,
    list,
}: SalesDocumentShowProps) {
    const can = (action: SalesDocumentShowProps['actions'][number]) =>
        actions.includes(action);
    const draft = document.status === 'draft';
    const credit = document.type === 'credit_note';
    const number = document.number ?? t('invoicing.show.draft_number');
    const pdf = `/facturacion/documentos/${document.id}/pdf`;

    return (
        <>
            <Head title={t('invoicing.show.title', { number })} />

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
                                    ? 'invoicing.show.kicker_credit'
                                    : draft
                                      ? 'invoicing.show.kicker_draft'
                                      : 'invoicing.show.kicker_invoice',
                            )}
                            <span aria-hidden="true">·</span>
                            <Link
                                href={`/clientes/${document.client.id}/facturacion`}
                                className={cn(
                                    'rounded-md hover:underline',
                                    FOCUS_RING,
                                )}
                            >
                                {document.client.name}
                            </Link>
                        </>
                    }
                    title={number}
                    description={
                        <div className="flex flex-wrap items-center gap-2">
                            <DocumentStatus document={document} today={today} />
                            {document.is_test ? (
                                <StatusBadge tone="info" icon={FileText}>
                                    {t('invoicing.show.test')}
                                </StatusBadge>
                            ) : null}
                            <span className="text-xs text-muted-foreground">
                                {t('invoicing.show.origin')}
                            </span>
                            <ClientViews document={document} />
                        </div>
                    }
                    actions={
                        <>
                            {list.previous || list.next ? (
                                <nav
                                    aria-label={t('billing.invoice.list_nav')}
                                    className="flex items-center gap-1"
                                >
                                    <Neighbour
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
                                    <Neighbour
                                        href={list.next}
                                        label={t('billing.invoice.next')}
                                        icon={
                                            <ChevronRight aria-hidden="true" />
                                        }
                                    />
                                </nav>
                            ) : null}
                            {can('edit') ? (
                                <Button variant="outline" asChild>
                                    <Link
                                        href={`/facturacion/documentos/${document.id}/editar`}
                                        data-test="document-edit"
                                    >
                                        <PencilLine aria-hidden="true" />
                                        {t('invoicing.show.edit')}
                                    </Link>
                                </Button>
                            ) : null}
                            {can('download_pdf') ? (
                                <Button variant="outline" asChild>
                                    <a
                                        href={pdf}
                                        target="_blank"
                                        rel="noopener"
                                        data-test="document-pdf"
                                    >
                                        <FileSearch aria-hidden="true" />
                                        {t(
                                            draft
                                                ? 'invoicing.show.preview'
                                                : 'invoicing.show.open_pdf',
                                        )}
                                    </a>
                                </Button>
                            ) : null}
                            {can('download_pdf') && !draft ? (
                                <Button
                                    variant="outline"
                                    size="icon"
                                    asChild
                                    aria-label={t(
                                        'invoicing.show.download_pdf',
                                    )}
                                >
                                    <a
                                        href={`${pdf}?descargar=1`}
                                        title={t('invoicing.show.download_pdf')}
                                        data-test="document-download"
                                    >
                                        <Download aria-hidden="true" />
                                    </a>
                                </Button>
                            ) : null}
                            {can('send') ? (
                                <SendDocumentDialog
                                    document={document}
                                    trigger={
                                        <Button
                                            variant={
                                                document.sent_at
                                                    ? 'outline'
                                                    : 'default'
                                            }
                                            data-test="document-send"
                                        >
                                            <Send aria-hidden="true" />
                                            {t(
                                                document.sent_at
                                                    ? 'invoicing.collect.email.resend'
                                                    : 'invoicing.collect.email.send',
                                            )}
                                        </Button>
                                    }
                                />
                            ) : null}
                            {can('record_payment') &&
                            Number(document.remaining) > 0 ? (
                                <RecordPaymentDialog
                                    document={document}
                                    methods={paymentMethods}
                                    today={today}
                                    trigger={
                                        <Button
                                            variant={
                                                document.sent_at
                                                    ? 'default'
                                                    : 'outline'
                                            }
                                            data-test="document-record-payment"
                                        >
                                            <Wallet aria-hidden="true" />
                                            {t(
                                                credit
                                                    ? 'invoicing.collect.payment.open_refund'
                                                    : 'invoicing.collect.payment.open',
                                            )}
                                        </Button>
                                    }
                                />
                            ) : null}
                            {can('schedule') ? (
                                <ScheduleDialog
                                    document={document}
                                    today={today}
                                    autoActive={autoActive}
                                    defaultOpen={openSchedule}
                                    trigger={
                                        <Button
                                            variant="outline"
                                            data-test="document-schedule"
                                        >
                                            <CalendarClock aria-hidden="true" />
                                            {t(
                                                document.scheduled
                                                    ? 'invoicing.schedule.change'
                                                    : 'invoicing.schedule.open',
                                            )}
                                        </Button>
                                    }
                                />
                            ) : null}
                            {can('issue') && issue ? (
                                <IssueDialog
                                    document={document}
                                    preview={issue}
                                    defaultOpen={openIssue}
                                    trigger={
                                        <Button data-test="document-issue">
                                            <FileCheck2 aria-hidden="true" />
                                            {t(
                                                document.scheduled
                                                    ? 'invoicing.schedule.issue_now'
                                                    : 'invoicing.show.issue',
                                            )}
                                        </Button>
                                    }
                                />
                            ) : null}
                            {can('rectify') ? (
                                <RectifyDialog
                                    document={document}
                                    trigger={
                                        <Button
                                            variant="outline"
                                            data-test="document-rectify"
                                        >
                                            <Undo2 aria-hidden="true" />
                                            {t('invoicing.show.rectify')}
                                        </Button>
                                    }
                                />
                            ) : null}
                            {can('cancel') ? (
                                <CancelDialog
                                    document={document}
                                    trigger={
                                        <Button
                                            variant="outline"
                                            data-test="document-cancel"
                                        >
                                            <Ban aria-hidden="true" />
                                            {t('invoicing.show.cancel')}
                                        </Button>
                                    }
                                />
                            ) : null}
                            <MoreActions
                                document={document}
                                actions={actions}
                                voidProblems={voidProblems}
                            />
                        </>
                    }
                />

                <Notice document={document} />

                <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
                    <div className="grid min-w-0 content-start gap-6">
                        <AmountCard document={document} />

                        <PaymentsSection
                            document={document}
                            canVoid={can('void_payment')}
                        />

                        <PageSection title={t('invoicing.show.lines')}>
                            <LinesTable document={document} />
                        </PageSection>

                        {document.rectification ? (
                            <RectificationBlock document={document} />
                        ) : null}

                        <PageSection title={t('billing.timeline.title')}>
                            <InvoiceTimeline events={timeline(document)} />
                        </PageSection>

                        <AttachmentsSection
                            document={document}
                            editable={can('attach')}
                        />

                        {document.body ? (
                            <PageSection title={t('invoicing.show.body')}>
                                <p className="text-sm whitespace-pre-line">
                                    {document.body}
                                </p>
                            </PageSection>
                        ) : null}

                        {can('view_record') && document.records.length > 0 ? (
                            <RecordBlock document={document} />
                        ) : null}
                    </div>

                    <aside className="grid content-start gap-6">
                        {document.no_project ? (
                            <NoProjectNeededPanel
                                invoice={{
                                    id: document.view_id,
                                    number: document.number,
                                    no_project: document.no_project,
                                }}
                            />
                        ) : null}
                        <NonFiscalPanel
                            document={document}
                            projects={projects}
                            editable={can('edit_non_fiscal')}
                        />
                        <DocumentChain document={document} />
                        <ClientPanel document={document} />
                    </aside>
                </div>
            </div>
        </>
    );
}

function Neighbour({
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

function DocumentStatus({
    document,
    today,
}: {
    document: SalesDocumentDetail;
    today: string;
}) {
    if (document.scheduled) {
        return (
            <StatusBadge tone="info" icon={CalendarClock}>
                {t('invoicing.schedule.badge', {
                    date: formatDate(document.scheduled.for ?? ''),
                })}
            </StatusBadge>
        );
    }

    if (document.status === 'draft') {
        return (
            <StatusBadge tone="neutral" icon={PencilLine}>
                {t('invoicing.status.draft')}
            </StatusBadge>
        );
    }

    if (document.status === 'cancelled' || document.status === 'voided') {
        return (
            <span className="inline-flex items-center gap-1 text-sm text-muted-foreground">
                <Ban aria-hidden="true" className="size-3.5" />
                {t(`invoicing.status.${document.status}`)}
            </span>
        );
    }

    if (document.type === 'credit_note') {
        return (
            <span className="inline-flex items-center gap-1 text-sm text-muted-foreground">
                <ShieldCheck aria-hidden="true" className="size-3.5" />
                {t('invoicing.status.issued')}
            </span>
        );
    }

    // El estado de cobro calculado como en el listado (E2, D-436): pendiente, en parte, cobrada o vencida.
    return (
        <RelativeStatus
            invoice={{
                collection_status:
                    document.collection === 'paid'
                        ? 'paid'
                        : Number(document.paid_total) > 0
                          ? 'partial'
                          : 'unpaid',
                due_on: document.due_date,
                total: document.total,
                pending_total: document.pending,
                is_draft: false,
            }}
            today={today}
        />
    );
}

/** Lo que conviene saber antes de nada: borrador, prueba, anulada, PDF en preparación. */
function Notice({ document }: { document: SalesDocumentDetail }) {
    let text: string | null = null;
    let tone = 'bg-info-soft';

    if (document.scheduled) {
        text = t(
            document.scheduled.send
                ? 'invoicing.schedule.notice_send'
                : 'invoicing.schedule.notice',
            {
                date: formatDate(document.scheduled.for ?? ''),
                person: document.scheduled.by ?? '—',
            },
        );
    } else if (document.status === 'draft') {
        text = t('invoicing.show.notice_draft');
    } else if (document.status === 'cancelled' && document.cancelled_by) {
        text = t('invoicing.show.notice_cancelled', {
            number: document.cancelled_by.number ?? '',
            date: formatDate(document.cancelled_by.issue_date),
        });
        tone = 'bg-muted';
    } else if (document.status === 'voided' && document.voided) {
        text = t('invoicing.show.notice_voided', {
            reason: document.voided.reason ?? '',
        });
        tone = 'bg-muted';
    } else if (!document.pdf_ready) {
        text = t('invoicing.show.notice_pdf');
    }

    if (text === null && !document.is_test && !document.auto_issue_error) {
        return null;
    }

    return (
        <div className="grid gap-1" data-test="document-notice">
            {document.auto_issue_error ? (
                <p
                    className="rounded-md bg-danger-soft px-3 py-2 text-sm"
                    role="alert"
                    data-test="document-auto-error"
                >
                    {document.auto_issue_error}
                </p>
            ) : null}
            {text ? (
                <p
                    className={cn(
                        'rounded-md px-3 py-2 text-sm text-foreground',
                        tone,
                    )}
                >
                    {text}
                </p>
            ) : null}
            {document.is_test ? (
                <p className="rounded-md bg-info-soft px-3 py-2 text-sm">
                    {t('invoicing.show.notice_test')}
                </p>
            ) : null}
        </div>
    );
}

function MoreActions({
    document,
    actions,
    voidProblems,
}: {
    document: SalesDocumentDetail;
    actions: string[];
    voidProblems: string[];
}) {
    const [confirming, setConfirming] = useState<
        'delete' | 'void' | 'remind' | null
    >(null);
    const [processing, setProcessing] = useState(false);
    // Recordatorio a mano (E2, D-442): solo con algo pendiente.
    const canRemind =
        actions.includes('send_reminder') && Number(document.pending) > 0;
    const hasMenu =
        actions.includes('duplicate') ||
        actions.includes('delete') ||
        actions.includes('void') ||
        actions.includes('make_recurring') ||
        actions.includes('unschedule') ||
        canRemind;

    if (!hasMenu) {
        return null;
    }

    return (
        <>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button
                        variant="outline"
                        size="icon"
                        aria-label={t('invoicing.show.more')}
                        data-test="document-more"
                    >
                        <MoreHorizontal aria-hidden="true" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="min-w-56">
                    {canRemind ? (
                        <DropdownMenuItem
                            onSelect={() => setConfirming('remind')}
                            data-test="document-remind"
                        >
                            <BellRing aria-hidden="true" />
                            {t('invoicing.collect.email.remind')}
                        </DropdownMenuItem>
                    ) : null}
                    {actions.includes('duplicate') ? (
                        <DropdownMenuItem
                            onSelect={() =>
                                router.post(
                                    `/facturacion/documentos/${document.id}/duplicar`,
                                )
                            }
                            data-test="document-duplicate"
                        >
                            <Copy aria-hidden="true" />
                            {t('invoicing.show.duplicate')}
                        </DropdownMenuItem>
                    ) : null}
                    {actions.includes('make_recurring') ? (
                        <DropdownMenuItem asChild>
                            <Link
                                href={`/facturacion/recurrentes/nueva?desde=${document.id}`}
                                data-test="document-make-recurring"
                            >
                                <Repeat aria-hidden="true" />
                                {t('invoicing.recurring.make')}
                            </Link>
                        </DropdownMenuItem>
                    ) : null}
                    {actions.includes('unschedule') ? (
                        <DropdownMenuItem
                            onSelect={() =>
                                router.delete(
                                    `/facturacion/documentos/${document.id}/programar`,
                                    { preserveScroll: true },
                                )
                            }
                            data-test="document-unschedule"
                        >
                            <CalendarX2 aria-hidden="true" />
                            {t('invoicing.schedule.unschedule')}
                        </DropdownMenuItem>
                    ) : null}
                    {actions.includes('void') ? (
                        <>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                                onSelect={() => setConfirming('void')}
                                className="text-danger"
                                data-test="document-void"
                            >
                                <FileX2 aria-hidden="true" />
                                {t('invoicing.show.void')}
                            </DropdownMenuItem>
                        </>
                    ) : null}
                    {actions.includes('delete') ? (
                        <DropdownMenuItem
                            onSelect={() => setConfirming('delete')}
                            className="text-danger"
                            data-test="document-delete"
                        >
                            <Trash2 aria-hidden="true" />
                            {t('invoicing.show.delete')}
                        </DropdownMenuItem>
                    ) : null}
                </DropdownMenuContent>
            </DropdownMenu>
            {canRemind ? (
                <SendDocumentDialog
                    document={document}
                    reminder
                    open={confirming === 'remind'}
                    onOpenChange={(open) =>
                        setConfirming(open ? 'remind' : null)
                    }
                />
            ) : null}
            {actions.includes('void') ? (
                <VoidDialog
                    document={document}
                    problems={voidProblems}
                    open={confirming === 'void'}
                    onOpenChange={(open) => setConfirming(open ? 'void' : null)}
                />
            ) : null}
            <ConfirmDialog
                trigger={<span hidden />}
                open={confirming === 'delete'}
                onOpenChange={(open) => setConfirming(open ? 'delete' : null)}
                title={t('invoicing.show.delete_title')}
                description={t('invoicing.show.delete_description')}
                confirmLabel={t('invoicing.show.delete_confirm')}
                processing={processing}
                onConfirm={() =>
                    router.delete(`/facturacion/documentos/${document.id}`, {
                        onStart: () => setProcessing(true),
                        onFinish: () => setProcessing(false),
                    })
                }
            />
        </>
    );
}

/** Lo importante en grande: lo pendiente del total (emitida) o el total (borrador). */
function AmountCard({ document }: { document: SalesDocumentDetail }) {
    const pending = Math.max(
        Number(document.total) - Number(document.paid_total),
        0,
    );
    const invoice = document.status === 'issued' && document.type === 'invoice';
    const showPending = invoice && pending > 0;
    // Lo cobrado de la factura, en la barra (E2, D-436).
    const paidPct = invoice
        ? Math.min(
              100,
              Math.round(
                  (Number(document.paid_total) / Number(document.total)) * 100,
              ),
          )
        : 0;

    return (
        <section
            aria-label={t('invoicing.show.amounts')}
            className="grid gap-4 rounded-md border bg-card p-4 md:grid-cols-[minmax(0,1fr)_18rem]"
            data-test="document-amounts"
        >
            <div className="grid content-start gap-4">
                <div className="grid gap-0.5">
                    <p className="text-sm text-muted-foreground">
                        {t(
                            showPending
                                ? 'invoicing.show.pending'
                                : invoice
                                  ? 'invoicing.collect.amount.paid'
                                  : 'invoicing.show.total',
                        )}
                    </p>
                    <p className="tabular text-3xl">
                        {formatCurrency(showPending ? pending : document.total)}
                        {showPending ? (
                            <span className="ml-2 text-base text-muted-foreground">
                                {t('billing.invoice.of_total', {
                                    total: formatCurrency(document.total),
                                })}
                            </span>
                        ) : null}
                    </p>
                    {invoice && paidPct > 0 ? (
                        <div className="mt-2 grid max-w-80 gap-1">
                            <div
                                aria-hidden="true"
                                className="h-1.5 overflow-hidden rounded-full bg-muted"
                            >
                                <div
                                    className="h-full bg-success"
                                    style={{ width: `${paidPct}%` }}
                                />
                            </div>
                            <p className="tabular text-xs text-muted-foreground">
                                {t('invoicing.collect.amount.bar', {
                                    paid: formatCurrency(document.paid_total),
                                    pct: paidPct,
                                })}
                            </p>
                        </div>
                    ) : null}
                </div>
                <dl className="grid gap-1.5 text-sm sm:grid-cols-2 sm:gap-x-6">
                    <Fact
                        label={t('invoicing.show.issue_date')}
                        value={formatDate(document.issue_date)}
                    />
                    {document.operation_date ? (
                        <Fact
                            label={t('invoicing.show.operation_date')}
                            value={formatDate(document.operation_date)}
                        />
                    ) : null}
                    <Fact
                        label={t('invoicing.show.due_date')}
                        value={
                            document.due_date
                                ? formatDate(document.due_date)
                                : '—'
                        }
                    />
                    <Fact
                        label={t('invoicing.show.series')}
                        value={
                            document.series
                                ? `${document.series.code} · ${document.series.name}`
                                : '—'
                        }
                    />
                    {document.payment_method ? (
                        <Fact
                            label={t('invoicing.show.payment_method')}
                            value={document.payment_method}
                        />
                    ) : null}
                    {document.customer_reference ? (
                        <Fact
                            label={t('invoicing.show.reference')}
                            value={document.customer_reference}
                        />
                    ) : null}
                </dl>
            </div>
            <InvoiceTotalsPanel
                totals={document}
                withholdingLabel={
                    document.withholding_rate
                        ? t('invoicing.totals.withholding_rate', {
                              rate: formatQuantity(document.withholding_rate),
                          })
                        : null
                }
            />
        </section>
    );
}

function Fact({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex items-baseline justify-between gap-3">
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="tabular text-right">{value}</dd>
        </div>
    );
}

/** E3: una línea de horas hecha desde el trabajo dice cuántas entradas lleva y si ya están bloqueadas. */
function WorkOrigin({
    line,
    draft,
}: {
    line: SalesDocumentDetail['lines'][number];
    draft: boolean;
}) {
    if (
        (line.origin !== 'hours' && line.origin !== 'overage') ||
        !line.entries
    ) {
        return null;
    }

    return (
        <span
            className="mt-0.5 flex items-center gap-1 text-xs text-muted-foreground"
            data-test="line-work-origin"
        >
            <Clock aria-hidden="true" className="size-3" />
            {tCount(
                draft
                    ? 'invoicing.work.line_entries_draft'
                    : 'invoicing.work.line_entries_locked',
                line.entries,
            )}
        </span>
    );
}

function LinesTable({ document }: { document: SalesDocumentDetail }) {
    return (
        <>
            <LinesList document={document} />
            <LinesGrid document={document} />
        </>
    );
}

/** En el móvil (por debajo de 768 px, D-415), cada línea en una fila sin desplazamiento lateral. */
function LinesList({ document }: { document: SalesDocumentDetail }) {
    return (
        <ul
            className="divide-y rounded-md border text-sm md:hidden"
            aria-label={t('invoicing.show.lines')}
            data-test="document-lines-list"
        >
            {document.lines.map((line) =>
                line.kind === 'text' ? (
                    <li
                        key={line.id}
                        className="px-3 py-2 whitespace-pre-line text-muted-foreground"
                    >
                        {line.description}
                    </li>
                ) : (
                    <li key={line.id} className="grid gap-1 px-3 py-2.5">
                        <div className="flex items-start justify-between gap-3">
                            <span className="min-w-0">
                                {line.name ?? line.description}
                                {line.service_code ? (
                                    <span className="text-xs text-muted-foreground">
                                        {' '}
                                        · {line.service_code}
                                    </span>
                                ) : null}
                            </span>
                            <span className="tabular shrink-0">
                                {formatCurrency(line.line_base)}
                            </span>
                        </div>
                        {line.name && line.description ? (
                            <span className="text-xs whitespace-pre-line text-muted-foreground">
                                {line.description}
                            </span>
                        ) : null}
                        <WorkOrigin
                            line={line}
                            draft={document.status === 'draft'}
                        />
                        <span className="tabular text-xs text-muted-foreground">
                            {[
                                `${formatQuantity(line.quantity)} ${unitLabel(line.unit, line.quantity)} × ${formatCurrency(line.unit_price)}`,
                                Number(line.discount_pct) === 0
                                    ? null
                                    : `${t('invoicing.show.discount')} ${formatQuantity(line.discount_pct)} %`,
                                line.tax_rate === null
                                    ? null
                                    : taxLabel(
                                          line.operation_type,
                                          line.tax_rate,
                                      ),
                            ]
                                .filter(Boolean)
                                .join(' · ')}
                        </span>
                    </li>
                ),
            )}
        </ul>
    );
}

function LinesGrid({ document }: { document: SalesDocumentDetail }) {
    return (
        <div
            className={cn(
                'overflow-x-auto rounded-md border max-md:hidden',
                FOCUS_RING,
            )}
            role="region"
            aria-label={t('invoicing.show.lines')}
            tabIndex={0}
        >
            <table
                className="tabular w-full min-w-[40rem] text-sm"
                data-test="document-lines"
            >
                <caption className="sr-only">
                    {t('invoicing.show.lines')}
                </caption>
                <thead>
                    <tr className="border-b text-left text-xs tracking-[0.12em] text-muted-foreground uppercase">
                        <th scope="col" className="px-3 py-2 font-medium">
                            {t('invoicing.show.concept')}
                        </th>
                        <th
                            scope="col"
                            className="px-3 py-2 text-right font-medium"
                        >
                            {t('invoicing.show.quantity')}
                        </th>
                        <th
                            scope="col"
                            className="px-3 py-2 text-right font-medium"
                        >
                            {t('invoicing.show.price')}
                        </th>
                        <th
                            scope="col"
                            className="px-3 py-2 text-right font-medium"
                        >
                            {t('invoicing.show.discount')}
                        </th>
                        <th
                            scope="col"
                            className="px-3 py-2 text-right font-medium"
                        >
                            {t('invoicing.show.tax')}
                        </th>
                        <th
                            scope="col"
                            className="px-3 py-2 text-right font-medium"
                        >
                            {t('invoicing.show.base')}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    {document.lines.map((line) =>
                        line.kind === 'text' ? (
                            <tr
                                key={line.id}
                                className="border-b last:border-0"
                            >
                                <td
                                    colSpan={6}
                                    className="px-3 py-2 whitespace-pre-line text-muted-foreground"
                                >
                                    {line.description}
                                </td>
                            </tr>
                        ) : (
                            <tr
                                key={line.id}
                                className="border-b last:border-0 even:bg-muted"
                            >
                                <th
                                    scope="row"
                                    className="px-3 py-2 text-left font-normal"
                                >
                                    {line.name ?? line.description}
                                    {line.service_code ? (
                                        <span className="text-xs text-muted-foreground">
                                            {' '}
                                            · {line.service_code}
                                        </span>
                                    ) : null}
                                    {line.name && line.description ? (
                                        <span className="block text-xs whitespace-pre-line text-muted-foreground">
                                            {line.description}
                                        </span>
                                    ) : null}
                                    <WorkOrigin
                                        line={line}
                                        draft={document.status === 'draft'}
                                    />
                                </th>
                                <td className="px-3 py-2 text-right whitespace-nowrap">
                                    {formatQuantity(line.quantity)}{' '}
                                    {unitLabel(line.unit, line.quantity)}
                                </td>
                                <td className="px-3 py-2 text-right whitespace-nowrap">
                                    {formatCurrency(line.unit_price)}
                                </td>
                                <td className="px-3 py-2 text-right whitespace-nowrap">
                                    {Number(line.discount_pct) === 0
                                        ? '—'
                                        : `${formatQuantity(line.discount_pct)} %`}
                                </td>
                                <td className="px-3 py-2 text-right whitespace-nowrap">
                                    {line.tax_rate === null
                                        ? '—'
                                        : taxLabel(
                                              line.operation_type,
                                              line.tax_rate,
                                          )}
                                </td>
                                <td className="px-3 py-2 text-right whitespace-nowrap">
                                    {formatCurrency(line.line_base)}
                                </td>
                            </tr>
                        ),
                    )}
                </tbody>
            </table>
        </div>
    );
}

function RectificationBlock({ document }: { document: SalesDocumentDetail }) {
    const rectification = document.rectification;

    if (!rectification) {
        return null;
    }

    const original = rectification.original;

    return (
        <PageSection title={t('invoicing.show.rectification')}>
            <dl
                className="grid gap-1.5 rounded-md border bg-card p-4 text-sm"
                data-test="document-rectification"
            >
                <Fact
                    label={t('invoicing.show.rectifies')}
                    value={
                        original
                            ? `${original.number ?? ''} · ${formatDate(original.issue_date)}`
                            : '—'
                    }
                />
                <Fact
                    label={t('invoicing.show.rectification_kind')}
                    value={
                        rectification.kind
                            ? t(`invoicing.rectification.${rectification.kind}`)
                            : '—'
                    }
                />
                <Fact
                    label={t('invoicing.show.rectification_code')}
                    value={rectification.code ?? '—'}
                />
                <div className="grid gap-0.5">
                    <dt className="text-muted-foreground">
                        {t('invoicing.show.reason')}
                    </dt>
                    <dd>{rectification.reason}</dd>
                </div>
            </dl>
            {original ? (
                <div>
                    <Button variant="ghost" size="sm" asChild>
                        <Link
                            href={
                                original.view_id < 0
                                    ? `/facturacion/documentos/${original.id}`
                                    : `/facturacion/facturas/${original.id}`
                            }
                        >
                            {t('invoicing.show.open_original', {
                                number: original.number ?? '',
                            })}
                        </Link>
                    </Button>
                </div>
            ) : null}
        </PageSection>
    );
}

/** La historia de la factura: creada, emitida, rectificativas, anulada (D-408). */
function timeline(document: SalesDocumentDetail): TimelineEvent[] {
    const events: TimelineEvent[] = [];
    const day = (iso: string) => iso.slice(0, 10);

    if (document.created_at) {
        events.push({
            key: 'created',
            date: day(document.created_at),
            when: formatDateTime(document.created_at),
            icon: PencilLine,
            tone: 'muted',
            content: t('invoicing.timeline.created', {
                by: document.created_by ?? '—',
            }),
        });
    }

    if (document.issued_at) {
        events.push({
            key: 'issued',
            date: day(document.issued_at),
            when: formatDateTime(document.issued_at),
            icon: FileCheck2,
            tone: 'neutral',
            content: t('invoicing.timeline.issued', {
                number: document.number ?? '',
                total: formatCurrency(document.total),
                by: document.issued_by ?? '—',
            }),
        });
    }

    for (const credit of document.rectifications) {
        events.push({
            key: `credit-${credit.id}`,
            date: credit.issue_date,
            when: formatDate(credit.issue_date),
            icon: Undo2,
            tone: credit.kind === 'cancellation' ? 'danger' : 'neutral',
            content: (
                <>
                    {t(
                        credit.kind === 'cancellation'
                            ? 'invoicing.timeline.cancelled_with'
                            : 'invoicing.timeline.rectified_with',
                        { amount: formatCurrency(credit.total) },
                    )}{' '}
                    <Link
                        href={`/facturacion/documentos/${credit.id}`}
                        className={cn(
                            'rounded-md text-primary-text underline',
                            FOCUS_RING,
                        )}
                    >
                        {credit.number ?? ''}
                    </Link>
                </>
            ),
        });
    }

    // E2 (D-436 a D-442): envíos, vistas del cliente, cobros y recordatorios que no salieron.
    for (const email of document.emails) {
        const when = email.sent_at ?? email.created_at;
        const recipients = [...email.to, ...email.cc].join(', ');
        events.push({
            key: `email-${email.id}`,
            date: when ? day(when) : null,
            when: when ? formatDateTime(when) : '',
            icon:
                email.status === 'failed'
                    ? MailWarning
                    : email.kind === 'reminder'
                      ? BellRing
                      : Send,
            tone: email.status === 'failed' ? 'danger' : 'neutral',
            content: (
                <>
                    {t(
                        `invoicing.collect.timeline.${email.kind === 'reminder' ? 'reminder' : 'email'}_${email.status}`,
                        {
                            to: recipients,
                            by:
                                email.sent_by ??
                                t('invoicing.collect.timeline.automatic'),
                            days:
                                email.rule_days === null
                                    ? ''
                                    : t(
                                          email.rule_days < 0
                                              ? 'invoicing.collect.timeline.days_before'
                                              : email.rule_days === 0
                                                ? 'invoicing.collect.timeline.days_due'
                                                : 'invoicing.collect.timeline.days_after',
                                          { days: Math.abs(email.rule_days) },
                                      ),
                        },
                    )}
                    {email.test_mode ? (
                        <span className="block text-xs text-muted-foreground">
                            {t('invoicing.collect.timeline.test_mode')}
                        </span>
                    ) : null}
                    {email.status === 'failed' && email.error ? (
                        <span className="block text-xs text-danger">
                            {email.error}
                        </span>
                    ) : null}
                    {email.first_viewed_at ? (
                        <span className="block text-xs text-muted-foreground">
                            {tCount(
                                'invoicing.collect.timeline.viewed',
                                email.view_count,
                                {
                                    when: formatDateTime(email.first_viewed_at),
                                },
                            )}
                        </span>
                    ) : null}
                </>
            ),
        });
    }

    for (const problem of document.reminder_problems) {
        events.push({
            key: `reminder-problem-${problem.id}`,
            date: problem.created_at ? day(problem.created_at) : null,
            when: problem.created_at ? formatDateTime(problem.created_at) : '',
            icon: MailWarning,
            tone: 'danger',
            content: t('invoicing.collect.timeline.reminder_no_recipients'),
        });
    }

    for (const payment of document.payments) {
        events.push({
            key: `payment-${payment.id}`,
            date: payment.paid_on,
            when: formatDate(payment.paid_on),
            icon: Wallet,
            tone: payment.voided ? 'muted' : 'success',
            content: t(
                Number(payment.amount) < 0
                    ? 'invoicing.collect.timeline.refund'
                    : 'invoicing.collect.timeline.payment',
                {
                    amount: formatCurrency(Math.abs(Number(payment.amount))),
                    method: t(`invoicing.collect.method.${payment.method}`),
                    by: payment.created_by ?? '—',
                },
            ),
        });
        if (payment.voided) {
            events.push({
                key: `payment-void-${payment.id}`,
                date: day(payment.voided.at),
                when: formatDateTime(payment.voided.at),
                icon: Ban,
                tone: 'danger',
                content: t('invoicing.collect.timeline.payment_voided', {
                    amount: formatCurrency(Math.abs(Number(payment.amount))),
                    by: payment.voided.by ?? '—',
                    reason: payment.voided.reason ?? '',
                }),
            });
        }
    }

    if (document.voided) {
        events.push({
            key: 'voided',
            date: day(document.voided.at),
            when: formatDateTime(document.voided.at),
            icon: FileX2,
            tone: 'danger',
            content: t('invoicing.timeline.voided', {
                by: document.voided.by ?? '—',
                reason: document.voided.reason ?? '',
            }),
        });
    }

    return events.sort((a, b) => (a.date ?? '').localeCompare(b.date ?? ''));
}

/** El registro de facturación (D-420), plegado: para la gestoría o una inspección. */
function RecordBlock({ document }: { document: SalesDocumentDetail }) {
    return (
        <details
            className="group rounded-md border bg-card p-4 text-sm"
            data-test="document-record"
        >
            <summary
                className={cn(
                    'cursor-pointer rounded-md text-base',
                    FOCUS_RING,
                )}
            >
                {t('invoicing.record.title')}
            </summary>
            <p className="mt-2 text-muted-foreground">
                {t('invoicing.record.description')}
            </p>
            <ol className="mt-3 grid gap-3">
                {document.records.map((record) => (
                    <li key={record.id} className="grid gap-1 border-t pt-3">
                        <p>
                            {t(
                                record.kind === 'alta'
                                    ? 'invoicing.record.alta'
                                    : 'invoicing.record.anulacion',
                                { seq: record.seq },
                            )}
                            {record.is_first ? (
                                <span className="text-muted-foreground">
                                    {' '}
                                    · {t('invoicing.record.first')}
                                </span>
                            ) : null}
                            <span className="text-muted-foreground">
                                {' '}
                                · {record.generated_at}
                            </span>
                        </p>
                        <dl className="grid gap-1 text-xs">
                            <div className="grid gap-0.5 sm:grid-cols-[9rem_minmax(0,1fr)]">
                                <dt className="text-muted-foreground">
                                    {t('invoicing.record.hash')}
                                </dt>
                                <dd className="font-mono break-all">
                                    {record.hash}
                                </dd>
                            </div>
                            <div className="grid gap-0.5 sm:grid-cols-[9rem_minmax(0,1fr)]">
                                <dt className="text-muted-foreground">
                                    {t('invoicing.record.previous')}
                                </dt>
                                <dd className="font-mono break-all">
                                    {record.previous_hash ||
                                        t('invoicing.record.none')}
                                </dd>
                            </div>
                        </dl>
                    </li>
                ))}
            </ol>
            {document.pdf_sha256 ? (
                <p className="mt-3 border-t pt-3 text-xs">
                    <span className="text-muted-foreground">
                        {t('invoicing.record.pdf')}
                    </span>{' '}
                    <span className="font-mono break-all">
                        {document.pdf_sha256}
                    </span>
                </p>
            ) : null}
        </details>
    );
}

/** Proyecto, bolsa y nota interna: lo no fiscal, que se cambia también en una emitida (D-421). */
function NonFiscalPanel({
    document,
    projects,
    editable,
}: {
    document: SalesDocumentDetail;
    projects: SalesDocumentShowProps['projects'];
    editable: boolean;
}) {
    const id = useId();
    const [editing, setEditing] = useState(false);
    const form = useForm({
        internal_note: document.internal_note ?? '',
        links: document.links.map((link) => ({
            project_id: link.project.id,
            hour_bank_id: link.bank?.id ?? null,
        })),
    });
    const own = projects.filter(
        (project) => project.client_id === document.client.id,
    );
    const others = projects.filter(
        (project) => project.client_id !== document.client.id,
    );
    const current = form.data.links[0] ?? null;
    const project =
        projects.find((one) => one.id === current?.project_id) ?? null;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.put(`/facturacion/documentos/${document.id}/no-fiscal`, {
            preserveScroll: true,
            onSuccess: () => setEditing(false),
        });
    };

    return (
        <PageSection
            title={t('invoicing.show.links_title')}
            action={
                editable && !editing ? (
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => setEditing(true)}
                        data-test="non-fiscal-edit"
                    >
                        <PencilLine aria-hidden="true" />
                        {t('invoicing.show.change')}
                    </Button>
                ) : null
            }
        >
            {editing ? (
                <form
                    onSubmit={submit}
                    noValidate
                    className="grid gap-3"
                    data-test="non-fiscal-form"
                >
                    <div className="grid gap-1">
                        <Label htmlFor={`${id}-project`}>
                            {t('invoicing.editor.project')}
                        </Label>
                        <SearchableSelect
                            id={`${id}-project`}
                            value={
                                current === null
                                    ? NONE
                                    : String(current.project_id)
                            }
                            placeholder={t('invoicing.editor.choose_project')}
                            groups={[
                                {
                                    label: null,
                                    options: [
                                        {
                                            value: NONE,
                                            label: t(
                                                'invoicing.editor.no_project',
                                            ),
                                        },
                                    ],
                                },
                                {
                                    label: t('invoicing.show.client_projects'),
                                    options: own.map((one) => ({
                                        value: String(one.id),
                                        label: `${one.code} · ${one.name}`,
                                        keywords: one.code,
                                    })),
                                },
                                {
                                    label: t('invoicing.show.other_projects'),
                                    options: others.map((one) => ({
                                        value: String(one.id),
                                        label: `${one.code} · ${one.name}`,
                                        keywords: one.code,
                                    })),
                                },
                            ]}
                            onChange={(value) =>
                                form.setData(
                                    'links',
                                    value === NONE
                                        ? []
                                        : [
                                              {
                                                  project_id: Number(value),
                                                  hour_bank_id: null,
                                              },
                                          ],
                                )
                            }
                            search={t('invoicing.editor.search_project')}
                            empty={t('invoicing.editor.no_projects')}
                        />
                    </div>
                    {project && project.banks.length > 0 && current ? (
                        <div className="grid gap-1">
                            <Label htmlFor={`${id}-bank`}>
                                {t('invoicing.editor.bank')}
                            </Label>
                            <SearchableSelect
                                id={`${id}-bank`}
                                value={
                                    current.hour_bank_id === null
                                        ? NONE
                                        : String(current.hour_bank_id)
                                }
                                placeholder={t('invoicing.editor.no_bank')}
                                groups={[
                                    {
                                        label: null,
                                        options: [
                                            {
                                                value: NONE,
                                                label: t(
                                                    'invoicing.editor.no_bank',
                                                ),
                                            },
                                            ...project.banks.map((bank) => ({
                                                value: String(bank.id),
                                                label: bank.name,
                                            })),
                                        ],
                                    },
                                ]}
                                onChange={(value) =>
                                    form.setData('links', [
                                        {
                                            project_id: current.project_id,
                                            hour_bank_id:
                                                value === NONE
                                                    ? null
                                                    : Number(value),
                                        },
                                    ])
                                }
                                search={t('invoicing.editor.search_bank')}
                                empty={t('invoicing.editor.no_banks')}
                            />
                        </div>
                    ) : null}
                    <div className="grid gap-1">
                        <Label htmlFor={`${id}-note`}>
                            {t('invoicing.editor.internal_note')}
                        </Label>
                        <Textarea
                            id={`${id}-note`}
                            value={form.data.internal_note}
                            maxLength={5000}
                            onChange={(event) =>
                                form.setData(
                                    'internal_note',
                                    event.target.value,
                                )
                            }
                        />
                        <InputError message={form.errors.internal_note} />
                    </div>
                    <div className="flex gap-2">
                        <Button
                            type="submit"
                            size="sm"
                            disabled={form.processing}
                        >
                            {t('invoicing.show.save')}
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            onClick={() => {
                                form.reset();
                                setEditing(false);
                            }}
                        >
                            {t('common.cancel')}
                        </Button>
                    </div>
                </form>
            ) : (
                <div className="grid gap-3 text-sm">
                    {document.links.length > 0 ? (
                        <ul className="grid gap-2" data-test="document-links">
                            {document.links.map((link) => (
                                <li
                                    key={link.id}
                                    className="rounded-md border bg-card px-3 py-2"
                                >
                                    <Link2
                                        aria-hidden="true"
                                        className="mr-1 inline size-3.5 text-muted-foreground"
                                    />
                                    <Link
                                        href={`/proyectos/${link.project.id}/facturacion`}
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
                                    {link.method === 'rectified' ? (
                                        <span className="block text-xs text-muted-foreground">
                                            {t('invoicing.show.link_rectified')}
                                        </span>
                                    ) : null}
                                </li>
                            ))}
                        </ul>
                    ) : (
                        <p className="text-muted-foreground">
                            {t('invoicing.show.no_links')}
                        </p>
                    )}
                    <div className="grid gap-0.5">
                        <p className="text-xs font-medium tracking-[0.12em] text-muted-foreground uppercase">
                            {t('invoicing.editor.internal_note')}
                        </p>
                        <p className="whitespace-pre-line">
                            {document.internal_note ?? (
                                <span className="text-muted-foreground">
                                    {t('invoicing.show.no_note')}
                                </span>
                            )}
                        </p>
                    </div>
                    {editable && document.links.length === 0 ? (
                        <div className="flex flex-wrap gap-2">
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => setEditing(true)}
                            >
                                <Plus aria-hidden="true" />
                                {t('invoicing.show.add_project')}
                            </Button>
                            {/* «No necesita proyecto» (D-431), como en las de Holded. */}
                            {document.no_project === null &&
                            document.status !== 'cancelled' &&
                            document.status !== 'voided' ? (
                                <MarkNoProjectButton
                                    invoice={{
                                        id: document.view_id,
                                        number: document.number,
                                    }}
                                />
                            ) : null}
                        </div>
                    ) : null}
                </div>
            )}
        </PageSection>
    );
}

/** El cliente tal como quedó en la factura (la copia congelada) o, en un borrador, su ficha. */
function ClientPanel({ document }: { document: SalesDocumentDetail }) {
    const snapshot = document.client_snapshot;

    return (
        <PageSection title={t('invoicing.show.client_title')}>
            <dl className="grid gap-1.5 text-sm">
                <Fact
                    label={t('invoicing.show.client')}
                    value={snapshot?.legal_name ?? document.client_name}
                />
                {snapshot?.tax_id ? (
                    <Fact
                        label={t('invoicing.fiscal.tax_id')}
                        value={snapshot.tax_id}
                    />
                ) : null}
                {snapshot?.eu_vat_number ? (
                    <Fact
                        label={t('invoicing.fiscal.eu_vat_number')}
                        value={snapshot.eu_vat_number}
                    />
                ) : null}
                {snapshot?.address ? (
                    <Fact
                        label={t('invoicing.fiscal.address')}
                        value={[
                            snapshot.address,
                            [snapshot.postal_code, snapshot.city]
                                .filter(Boolean)
                                .join(' '),
                        ]
                            .filter(Boolean)
                            .join(', ')}
                    />
                ) : null}
            </dl>
            <p className="text-xs text-muted-foreground">
                {t(
                    snapshot
                        ? 'invoicing.show.client_frozen'
                        : 'invoicing.show.client_live',
                )}
            </p>
            <div>
                <Button variant="ghost" size="sm" asChild>
                    <Link href={`/clientes/${document.client.id}/facturacion`}>
                        {t('invoicing.show.client_billing')}
                    </Link>
                </Button>
            </div>
        </PageSection>
    );
}

SalesDocumentShow.layout = (props: SalesDocumentShowProps) => ({
    breadcrumbs: [
        { title: t('billing.section'), href: '/facturacion' },
        { title: t('billing.invoices.title'), href: props.list.back },
        {
            title: props.document.number ?? t('invoicing.show.draft_number'),
            href: `/facturacion/documentos/${props.document.id}`,
        },
    ],
});
