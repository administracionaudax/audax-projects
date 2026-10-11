import { Link, router } from '@inertiajs/react';
import {
    ArrowDown,
    Ban,
    Copy,
    Download,
    Eye,
    FileText,
    Paperclip,
    Trash2,
    Undo2,
    Upload,
    Wallet,
} from 'lucide-react';
import type { ChangeEvent, ReactNode } from 'react';
import { useId, useRef, useState } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { PageSection } from '@/components/projects-list/page-section';
import { formatBytes } from '@/components/tasks/task-attachments';
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatCurrency, formatDate, formatDateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import { tCount } from '@/lib/people';
import { cn } from '@/lib/utils';
import type {
    DocumentAttachment,
    DocumentPayment,
    SalesDocumentDetail,
} from '@/types';
import { VoidPaymentDialog } from './collect-dialogs';

/*
 * Bloques de la ficha de E2 (D-436, D-444 y D-445): los cobros, los adjuntos y la cadena de
 * documentos (de qué se duplicó → la factura → sus rectificativas → sus cobros).
 */

/** Los cobros de la factura, también los anulados (tachados, con su motivo). */
export function PaymentsSection({
    document,
    canVoid,
}: {
    document: SalesDocumentDetail;
    canVoid: boolean;
}) {
    const [voiding, setVoiding] = useState<DocumentPayment | null>(null);
    const credit = document.type === 'credit_note';
    const live = document.payments.filter((payment) => !payment.voided);
    const paid = live.reduce((sum, payment) => sum + Number(payment.amount), 0);

    if (document.status === 'draft') {
        return null;
    }

    return (
        <PageSection
            title={t(
                credit
                    ? 'invoicing.collect.payments.title_refunds'
                    : 'invoicing.collect.payments.title',
            )}
            description={
                document.payments.length > 0
                    ? tCount(
                          'invoicing.collect.payments.summary',
                          live.length,
                          {
                              paid: formatCurrency(Math.abs(paid)),
                          },
                      )
                    : undefined
            }
        >
            {document.payments.length === 0 ? (
                <p
                    className="rounded-md border border-dashed px-4 py-3 text-sm text-muted-foreground"
                    data-test="payments-empty"
                >
                    {t(
                        credit
                            ? 'invoicing.collect.payments.empty_refunds'
                            : 'invoicing.collect.payments.empty',
                    )}
                </p>
            ) : (
                <ul
                    className="divide-y rounded-md border bg-card"
                    data-test="payments-list"
                >
                    {document.payments.map((payment) => (
                        <li
                            key={payment.id}
                            className="grid grid-cols-[auto_minmax(0,1fr)_auto] items-start gap-x-3 gap-y-1 px-4 py-3 text-sm sm:grid-cols-[auto_6rem_7rem_minmax(0,1fr)_auto] sm:items-center"
                            data-test="payment-row"
                        >
                            <Wallet
                                aria-hidden="true"
                                className="mt-0.5 size-4 shrink-0 text-muted-foreground sm:mt-0"
                            />
                            <span className="tabular">
                                {formatDate(payment.paid_on)}
                            </span>
                            <span
                                className={cn(
                                    'tabular text-right',
                                    payment.voided &&
                                        'text-muted-foreground line-through',
                                )}
                            >
                                {formatCurrency(payment.amount)}
                            </span>
                            <span className="col-span-2 col-start-2 min-w-0 sm:col-span-1 sm:col-start-auto">
                                {t(
                                    `invoicing.collect.method.${payment.method}`,
                                )}
                                {payment.bank_reference ? (
                                    <span className="text-muted-foreground">
                                        {' '}
                                        · {payment.bank_reference}
                                    </span>
                                ) : null}
                                {payment.note ? (
                                    <span className="block text-xs text-muted-foreground">
                                        {payment.note}
                                    </span>
                                ) : null}
                                {payment.voided ? (
                                    <span className="block text-xs text-danger">
                                        {t(
                                            'invoicing.collect.payments.voided',
                                            {
                                                by: payment.voided.by ?? '—',
                                                reason:
                                                    payment.voided.reason ?? '',
                                            },
                                        )}
                                    </span>
                                ) : null}
                            </span>
                            {canVoid && !payment.voided ? (
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    className="col-start-2 justify-self-start text-muted-foreground sm:col-start-auto sm:justify-self-end"
                                    onClick={() => setVoiding(payment)}
                                    aria-label={t(
                                        'invoicing.collect.payments.void_label',
                                        {
                                            amount: formatCurrency(
                                                payment.amount,
                                            ),
                                            date: formatDate(payment.paid_on),
                                        },
                                    )}
                                    data-test="payment-void"
                                >
                                    <Ban aria-hidden="true" />
                                    {t('invoicing.collect.payments.void')}
                                </Button>
                            ) : (
                                <span className="hidden sm:block" />
                            )}
                        </li>
                    ))}
                </ul>
            )}
            {canVoid ? (
                <VoidPaymentDialog
                    document={document}
                    payment={voiding}
                    onOpenChange={(open) => {
                        if (!open) {
                            setVoiding(null);
                        }
                    }}
                />
            ) : null}
        </PageSection>
    );
}

/**
 * Adjuntos de la factura (contratos, albaranes; D-444), internos o visibles para el cliente (los
 * visibles van en el email y en el portal).
 */
export function AttachmentsSection({
    document,
    editable,
}: {
    document: SalesDocumentDetail;
    editable: boolean;
}) {
    const id = useId();
    const input = useRef<HTMLInputElement>(null);
    const [uploading, setUploading] = useState(false);
    const [deleting, setDeleting] = useState<DocumentAttachment | null>(null);
    const [processing, setProcessing] = useState(false);
    const base = `/facturacion/documentos/${document.id}/adjuntos`;

    const upload = (event: ChangeEvent<HTMLInputElement>) => {
        const files = Array.from(event.target.files ?? []);
        event.target.value = '';
        if (files.length === 0) {
            return;
        }
        router.post(
            base,
            { files },
            {
                forceFormData: true,
                preserveScroll: true,
                onStart: () => setUploading(true),
                onFinish: () => setUploading(false),
            },
        );
    };

    if (!editable && document.attachments.length === 0) {
        return null;
    }

    return (
        <PageSection
            title={t('invoicing.collect.attachments.title')}
            description={t('invoicing.collect.attachments.description')}
            action={
                editable ? (
                    <>
                        <input
                            ref={input}
                            id={`${id}-files`}
                            type="file"
                            multiple
                            className="sr-only"
                            tabIndex={-1}
                            aria-label={t('invoicing.collect.attachments.add')}
                            onChange={upload}
                            data-test="attachments-input"
                        />
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={uploading}
                            onClick={() => input.current?.click()}
                            data-test="attachments-add"
                        >
                            <Upload aria-hidden="true" />
                            {t('invoicing.collect.attachments.add')}
                        </Button>
                    </>
                ) : null
            }
        >
            {document.attachments.length === 0 ? (
                <p className="rounded-md border border-dashed px-4 py-3 text-sm text-muted-foreground">
                    {t('invoicing.collect.attachments.empty')}
                </p>
            ) : (
                <ul
                    className="divide-y rounded-md border bg-card"
                    data-test="attachments-list"
                >
                    {document.attachments.map((attachment) => (
                        <li
                            key={attachment.id}
                            className="flex flex-wrap items-center gap-x-3 gap-y-2 px-4 py-3 text-sm"
                        >
                            <Paperclip
                                aria-hidden="true"
                                className="size-4 shrink-0 text-muted-foreground"
                            />
                            <a
                                href={attachment.url}
                                className={cn(
                                    'min-w-0 flex-1 truncate rounded-md hover:underline',
                                    FOCUS_RING,
                                )}
                            >
                                {attachment.name}
                                <span className="ml-2 text-xs text-muted-foreground">
                                    {formatBytes(attachment.size)}
                                </span>
                            </a>
                            {editable ? (
                                <label className="flex items-center gap-2 text-xs text-muted-foreground">
                                    <Switch
                                        checked={attachment.client_visible}
                                        aria-label={t(
                                            'invoicing.collect.attachments.visible_for',
                                            { name: attachment.name },
                                        )}
                                        onCheckedChange={(checked) =>
                                            router.put(
                                                `${base}/${attachment.id}`,
                                                { client_visible: checked },
                                                { preserveScroll: true },
                                            )
                                        }
                                        data-test="attachment-visible"
                                    />
                                    {t(
                                        attachment.client_visible
                                            ? 'invoicing.collect.attachments.visible'
                                            : 'invoicing.collect.attachments.internal',
                                    )}
                                </label>
                            ) : (
                                <span className="text-xs text-muted-foreground">
                                    {t(
                                        attachment.client_visible
                                            ? 'invoicing.collect.attachments.visible'
                                            : 'invoicing.collect.attachments.internal',
                                    )}
                                </span>
                            )}
                            <Button
                                variant="ghost"
                                size="icon"
                                asChild
                                aria-label={t(
                                    'invoicing.collect.attachments.download',
                                    { name: attachment.name },
                                )}
                            >
                                <a href={attachment.url}>
                                    <Download aria-hidden="true" />
                                </a>
                            </Button>
                            {editable ? (
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    aria-label={t(
                                        'invoicing.collect.attachments.delete',
                                        { name: attachment.name },
                                    )}
                                    onClick={() => setDeleting(attachment)}
                                >
                                    <Trash2 aria-hidden="true" />
                                </Button>
                            ) : null}
                        </li>
                    ))}
                </ul>
            )}
            <ConfirmDialog
                trigger={<span hidden />}
                open={deleting !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setDeleting(null);
                    }
                }}
                title={t('invoicing.collect.attachments.delete_title')}
                description={t(
                    'invoicing.collect.attachments.delete_description',
                    { name: deleting?.name ?? '' },
                )}
                confirmLabel={t('invoicing.collect.attachments.delete_confirm')}
                processing={processing}
                onConfirm={() =>
                    deleting &&
                    router.delete(`${base}/${deleting.id}`, {
                        preserveScroll: true,
                        onStart: () => setProcessing(true),
                        onFinish: () => {
                            setProcessing(false);
                            setDeleting(null);
                        },
                    })
                }
            />
        </PageSection>
    );
}

function ChainLink({ href, children }: { href: string; children: ReactNode }) {
    return (
        <Link
            href={href}
            className={cn('rounded-md text-primary-text underline', FOCUS_RING)}
        >
            {children}
        </Link>
    );
}

/**
 * La cadena de documentos (L5, D-445): de qué se duplicó, la factura, sus rectificativas y sus
 * cobros. En una rectificativa, la factura que rectifica arriba.
 */
export function DocumentChain({ document }: { document: SalesDocumentDetail }) {
    const live = document.payments.filter((payment) => !payment.voided);
    const paid = live.reduce((sum, payment) => sum + Number(payment.amount), 0);
    const original = document.rectification?.original ?? null;
    const steps: {
        key: string;
        icon: typeof FileText;
        content: ReactNode;
        current?: boolean;
    }[] = [];

    if (document.source) {
        steps.push({
            key: 'source',
            icon: Copy,
            content: (
                <>
                    {t('invoicing.collect.chain.duplicated_from')}{' '}
                    <ChainLink
                        href={
                            document.source.own
                                ? `/facturacion/documentos/${document.source.id}`
                                : `/facturacion/facturas/${document.source.id}`
                        }
                    >
                        {document.source.number ??
                            t('invoicing.show.draft_number')}
                    </ChainLink>
                </>
            ),
        });
    }

    if (original) {
        steps.push({
            key: 'original',
            icon: FileText,
            content: (
                <>
                    {t('invoicing.collect.chain.rectifies')}{' '}
                    <ChainLink
                        href={
                            original.view_id < 0
                                ? `/facturacion/documentos/${original.id}`
                                : `/facturacion/facturas/${original.id}`
                        }
                    >
                        {original.number ?? ''}
                    </ChainLink>
                </>
            ),
        });
    }

    steps.push({
        key: 'self',
        icon: document.type === 'credit_note' ? Undo2 : FileText,
        current: true,
        content: (
            <>
                <span>
                    {document.number ?? t('invoicing.show.draft_number')}
                </span>{' '}
                <span className="tabular text-muted-foreground">
                    {formatCurrency(document.total)}
                </span>
            </>
        ),
    });

    for (const credit of document.rectifications) {
        steps.push({
            key: `credit-${credit.id}`,
            icon: Undo2,
            content: (
                <>
                    <ChainLink href={`/facturacion/documentos/${credit.id}`}>
                        {credit.number ?? ''}
                    </ChainLink>{' '}
                    <span className="tabular text-muted-foreground">
                        {formatCurrency(credit.total)}
                    </span>
                    <span className="block text-xs text-muted-foreground">
                        {t(
                            credit.kind === 'cancellation'
                                ? 'invoicing.collect.chain.cancellation'
                                : 'invoicing.collect.chain.differences',
                        )}
                    </span>
                </>
            ),
        });
    }

    if (live.length > 0) {
        steps.push({
            key: 'payments',
            icon: Wallet,
            content: (
                <>
                    {tCount('invoicing.collect.chain.payments', live.length, {
                        amount: formatCurrency(Math.abs(paid)),
                    })}
                </>
            ),
        });
    }

    if (steps.length < 2) {
        return null;
    }

    return (
        <PageSection title={t('invoicing.collect.chain.title')}>
            <ol className="grid gap-1 text-sm" data-test="document-chain">
                {steps.map((step, index) => (
                    <li key={step.key} className="grid gap-1">
                        <div
                            className={cn(
                                'flex items-start gap-2 rounded-md px-3 py-2',
                                step.current ? 'border bg-card' : '',
                            )}
                            aria-current={step.current ? 'true' : undefined}
                        >
                            <step.icon
                                aria-hidden="true"
                                className="mt-0.5 size-4 shrink-0 text-muted-foreground"
                            />
                            <span className="min-w-0">{step.content}</span>
                        </div>
                        {index < steps.length - 1 ? (
                            <ArrowDown
                                aria-hidden="true"
                                className="ml-4 size-3.5 text-muted-foreground"
                            />
                        ) : null}
                    </li>
                ))}
            </ol>
        </PageSection>
    );
}

/** «Vista por el cliente el 14/11 a las 10:32 · 3 veces», o «Enviada, aún sin abrir». */
export function ClientViews({ document }: { document: SalesDocumentDetail }) {
    if (document.status === 'draft') {
        return null;
    }

    if (document.first_viewed_at) {
        return (
            <span
                className="inline-flex items-center gap-1 text-xs text-muted-foreground"
                data-test="document-viewed"
            >
                <Eye aria-hidden="true" className="size-3.5" />
                {tCount('invoicing.collect.views.viewed', document.view_count, {
                    when: formatDateTime(document.first_viewed_at),
                })}
            </span>
        );
    }

    if (document.sent_at) {
        return (
            <span className="text-xs text-muted-foreground">
                {t('invoicing.collect.views.sent_not_viewed')}
            </span>
        );
    }

    return null;
}
