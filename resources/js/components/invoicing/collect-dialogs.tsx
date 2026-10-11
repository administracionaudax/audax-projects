import { useForm } from '@inertiajs/react';
import { FileText, Paperclip, Plus, TriangleAlert, X } from 'lucide-react';
import type { FormEvent, KeyboardEvent, ReactNode } from 'react';
import { useEffect, useId, useState } from 'react';
import { DatePicker } from '@/components/domain/date-picker';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatCurrency, formatDate } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type {
    DocumentPayment,
    EmailCompose,
    PaymentMethodKey,
    SalesDocumentDetail,
} from '@/types';

/*
 * Diálogos de E2 · Cobrar y enviar (PLAN-EMISION §3.3 y §3.4; D-436 a D-441): registrar un cobro (o
 * la devolución de una rectificativa), anular un cobro y enviar la factura (o el recordatorio) por
 * email. El servidor comprueba todo otra vez (DocumentActions, PaymentRecorder e InvoiceMailer).
 */

const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

/** «1.234,5» o «1234.50» → «1234.50» (vacío si no es un número). */
export function parseAmount(value: string): string {
    const clean = value.replace(/\s|€/g, '');
    const normalized =
        clean.includes(',') && clean.includes('.')
            ? clean.replace(/\./g, '').replace(',', '.')
            : clean.replace(',', '.');
    const number = Number(normalized);

    return normalized !== '' && Number.isFinite(number)
        ? number.toFixed(2)
        : '';
}

/** En céntimos, para comparar sin decimales flotantes. */
function cents(value: string): number {
    return Math.round(Number(value || '0') * 100);
}

/**
 * Registrar un cobro (D-436): por defecto lo pendiente, la fecha de hoy y transferencia. Dice cómo
 * quedará la factura antes de guardar. En una rectificativa, la devolución al cliente.
 */
export function RecordPaymentDialog({
    document,
    methods,
    today,
    trigger,
}: {
    document: SalesDocumentDetail;
    methods: { value: PaymentMethodKey; label: string }[];
    today: string;
    trigger: ReactNode;
}) {
    const id = useId();
    const [open, setOpen] = useState(false);
    const credit = document.type === 'credit_note';
    const form = useForm({
        amount: document.remaining,
        paid_on: today,
        method: 'transfer' as PaymentMethodKey,
        bank_reference: '',
        note: '',
    });
    const amount = parseAmount(form.data.amount);
    const left = cents(document.remaining) - cents(amount);
    const tooMuch = amount !== '' && left < 0;

    useEffect(() => {
        if (open) {
            form.setData((data) => ({
                ...data,
                amount: document.remaining,
                paid_on: today,
            }));
            form.clearErrors();
        }
        // Al abrir, lo pendiente de ahora (puede haber cambiado con otro cobro).
    }, [open, document.remaining]);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({ ...data, amount }));
        form.post(`/facturacion/documentos/${document.id}/cobros`, {
            preserveScroll: true,
            onSuccess: () => {
                setOpen(false);
                form.reset();
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent>
                <DialogTitle>
                    {t(
                        credit
                            ? 'invoicing.collect.payment.title_refund'
                            : 'invoicing.collect.payment.title',
                        { number: document.number ?? '' },
                    )}
                </DialogTitle>
                <DialogDescription>
                    {t(
                        credit
                            ? 'invoicing.collect.payment.description_refund'
                            : 'invoicing.collect.payment.description',
                        { amount: formatCurrency(document.remaining) },
                    )}
                </DialogDescription>
                <form
                    onSubmit={submit}
                    noValidate
                    className="grid gap-4"
                    data-test="payment-form"
                >
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid content-start gap-1">
                            <Label htmlFor={`${id}-amount`}>
                                {t('invoicing.collect.payment.amount')}
                            </Label>
                            <div className="relative">
                                <Input
                                    id={`${id}-amount`}
                                    inputMode="decimal"
                                    autoComplete="off"
                                    className="tabular pr-8 text-right"
                                    value={form.data.amount}
                                    aria-invalid={
                                        form.errors.amount || tooMuch
                                            ? true
                                            : undefined
                                    }
                                    aria-describedby={`${id}-amount-help`}
                                    onChange={(event) =>
                                        form.setData(
                                            'amount',
                                            event.target.value,
                                        )
                                    }
                                    data-test="payment-amount"
                                />
                                <span
                                    aria-hidden="true"
                                    className="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-sm text-muted-foreground"
                                >
                                    €
                                </span>
                            </div>
                            <InputError message={form.errors.amount} />
                        </div>
                        <div className="grid content-start gap-1">
                            <Label htmlFor={`${id}-date`}>
                                {t('invoicing.collect.payment.paid_on')}
                            </Label>
                            <DatePicker
                                id={`${id}-date`}
                                value={form.data.paid_on}
                                clearable={false}
                                min={document.issue_date}
                                max={today}
                                invalid={form.errors.paid_on !== undefined}
                                onChange={(value) =>
                                    form.setData('paid_on', value ?? today)
                                }
                            />
                            <InputError message={form.errors.paid_on} />
                        </div>
                    </div>
                    <p
                        id={`${id}-amount-help`}
                        className={cn(
                            'rounded-md px-3 py-2 text-sm',
                            tooMuch ? 'bg-danger-soft' : 'bg-muted',
                        )}
                        aria-live="polite"
                        data-test="payment-summary"
                    >
                        {amount === ''
                            ? t('invoicing.collect.payment.summary_empty')
                            : tooMuch
                              ? t(
                                    'invoicing.collect.payment.summary_too_much',
                                    {
                                        max: formatCurrency(document.remaining),
                                    },
                                )
                              : left === 0
                                ? t(
                                      credit
                                          ? 'invoicing.collect.payment.summary_refunded'
                                          : 'invoicing.collect.payment.summary_paid',
                                  )
                                : t('invoicing.collect.payment.summary_left', {
                                      amount: formatCurrency(left / 100),
                                  })}
                    </p>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid content-start gap-1">
                            <Label htmlFor={`${id}-method`}>
                                {t('invoicing.collect.payment.method')}
                            </Label>
                            <Select
                                value={form.data.method}
                                onValueChange={(value) =>
                                    form.setData(
                                        'method',
                                        value as PaymentMethodKey,
                                    )
                                }
                            >
                                <SelectTrigger
                                    id={`${id}-method`}
                                    className="w-full"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {methods.map((method) => (
                                        <SelectItem
                                            key={method.value}
                                            value={method.value}
                                        >
                                            {method.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={form.errors.method} />
                        </div>
                        <div className="grid content-start gap-1">
                            <Label htmlFor={`${id}-reference`}>
                                {t('invoicing.collect.payment.reference')}
                            </Label>
                            <Input
                                id={`${id}-reference`}
                                value={form.data.bank_reference}
                                maxLength={120}
                                placeholder={t(
                                    'invoicing.collect.payment.reference_placeholder',
                                )}
                                onChange={(event) =>
                                    form.setData(
                                        'bank_reference',
                                        event.target.value,
                                    )
                                }
                            />
                            <InputError message={form.errors.bank_reference} />
                        </div>
                    </div>
                    <div className="grid content-start gap-1">
                        <Label htmlFor={`${id}-note`}>
                            {t('invoicing.collect.payment.note')}
                        </Label>
                        <Textarea
                            id={`${id}-note`}
                            value={form.data.note}
                            maxLength={500}
                            rows={2}
                            onChange={(event) =>
                                form.setData('note', event.target.value)
                            }
                        />
                        <InputError message={form.errors.note} />
                    </div>
                    <DialogFooter className="gap-2">
                        <Button
                            type="button"
                            variant="secondary"
                            onClick={() => setOpen(false)}
                            disabled={form.processing}
                        >
                            {t('common.cancel')}
                        </Button>
                        <Button
                            type="submit"
                            disabled={
                                form.processing ||
                                amount === '' ||
                                tooMuch ||
                                cents(amount) <= 0
                            }
                            data-test="payment-confirm"
                        >
                            {form.processing ? <Spinner /> : null}
                            {t(
                                credit
                                    ? 'invoicing.collect.payment.confirm_refund'
                                    : 'invoicing.collect.payment.confirm',
                            )}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/** Anular un cobro (D-436): no se borra; queda anulado con su motivo. */
export function VoidPaymentDialog({
    document,
    payment,
    onOpenChange,
}: {
    document: SalesDocumentDetail;
    payment: DocumentPayment | null;
    onOpenChange: (open: boolean) => void;
}) {
    const id = useId();
    const form = useForm({ reason: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (!payment) {
            return;
        }
        form.post(
            `/facturacion/documentos/${document.id}/cobros/${payment.id}/anular`,
            {
                preserveScroll: true,
                onSuccess: () => {
                    form.reset();
                    onOpenChange(false);
                },
            },
        );
    };

    return (
        <Dialog open={payment !== null} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogTitle>
                    {t('invoicing.collect.void_payment.title')}
                </DialogTitle>
                <DialogDescription>
                    {payment
                        ? t('invoicing.collect.void_payment.description', {
                              amount: formatCurrency(payment.amount),
                              date: formatDate(payment.paid_on),
                          })
                        : ''}
                </DialogDescription>
                <form
                    onSubmit={submit}
                    noValidate
                    className="grid gap-4"
                    data-test="void-payment-form"
                >
                    <div className="grid content-start gap-1">
                        <Label htmlFor={`${id}-reason`}>
                            {t('invoicing.collect.void_payment.reason')}
                        </Label>
                        <Textarea
                            id={`${id}-reason`}
                            value={form.data.reason}
                            maxLength={500}
                            placeholder={t(
                                'invoicing.collect.void_payment.reason_placeholder',
                            )}
                            aria-invalid={form.errors.reason ? true : undefined}
                            onChange={(event) =>
                                form.setData('reason', event.target.value)
                            }
                        />
                        <InputError message={form.errors.reason} />
                    </div>
                    <DialogFooter className="gap-2">
                        <Button
                            type="button"
                            variant="secondary"
                            onClick={() => onOpenChange(false)}
                            disabled={form.processing}
                        >
                            {t('common.cancel')}
                        </Button>
                        <Button
                            type="submit"
                            variant="destructive"
                            disabled={
                                form.processing ||
                                form.data.reason.trim().length < 5
                            }
                            data-test="void-payment-confirm"
                        >
                            {form.processing ? <Spinner /> : null}
                            {t('invoicing.collect.void_payment.confirm')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/** Pide al servidor lo que pinta el diálogo de envío. Exportada para los tests. */
export async function fetchCompose(
    documentId: number,
    reminder: boolean,
    signal?: AbortSignal,
): Promise<EmailCompose> {
    const response = await fetch(
        `/facturacion/documentos/${documentId}/envio${reminder ? '?recordatorio=1' : ''}`,
        {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            signal,
        },
    );

    if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
    }

    return (await response.json()) as EmailCompose;
}

/**
 * Destinatarios como fichas que se quitan, las propuestas del cliente como botones para añadirlas
 * y un campo para escribir otro (Intro o coma).
 */
export function RecipientsField({
    id,
    label,
    value,
    onChange,
    suggestions,
    error,
    testId,
}: {
    id: string;
    label: string;
    value: string[];
    onChange: (value: string[]) => void;
    suggestions: EmailCompose['suggestions'];
    error?: string;
    testId: string;
}) {
    const [draft, setDraft] = useState('');
    const [invalid, setInvalid] = useState(false);
    const labelFor = (email: string) =>
        suggestions.find((one) => one.email === email)?.label ?? null;
    const available = suggestions.filter((one) => !value.includes(one.email));

    const add = (raw: string) => {
        const emails = raw
            .split(/[,;\s]+/)
            .map((one) => one.trim().toLowerCase())
            .filter(Boolean);
        if (emails.length === 0) {
            return true;
        }
        if (emails.some((email) => !EMAIL.test(email))) {
            setInvalid(true);

            return false;
        }
        onChange([...new Set([...value, ...emails])]);
        setDraft('');
        setInvalid(false);

        return true;
    };

    const onKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
        if (event.key === 'Enter' || event.key === ',') {
            event.preventDefault();
            add(draft);
        } else if (event.key === 'Backspace' && draft === '' && value.length) {
            onChange(value.slice(0, -1));
        }
    };

    return (
        <div className="grid gap-1.5" data-test={testId}>
            <Label htmlFor={id}>{label}</Label>
            <div
                className={cn(
                    'flex min-h-10 min-w-0 flex-wrap items-center gap-1.5 overflow-hidden rounded-md border bg-background px-2 py-1.5',
                    (error || invalid) && 'border-danger',
                )}
            >
                {value.map((email) => (
                    <span
                        key={email}
                        className="inline-flex max-w-full min-w-0 items-center gap-1 rounded-md bg-muted py-0.5 pr-1 pl-2 text-sm"
                    >
                        <span className="truncate">
                            {labelFor(email) ? (
                                <>
                                    {labelFor(email)}{' '}
                                    <span className="text-muted-foreground">
                                        {email}
                                    </span>
                                </>
                            ) : (
                                email
                            )}
                        </span>
                        <button
                            type="button"
                            className={cn(
                                'rounded-sm p-0.5 text-muted-foreground hover:text-foreground',
                                FOCUS_RING,
                            )}
                            aria-label={t('invoicing.collect.email.remove', {
                                email,
                            })}
                            onClick={() =>
                                onChange(value.filter((one) => one !== email))
                            }
                        >
                            <X aria-hidden="true" className="size-3.5" />
                        </button>
                    </span>
                ))}
                <input
                    id={id}
                    type="email"
                    value={draft}
                    autoComplete="off"
                    className="min-w-32 flex-1 bg-transparent py-0.5 text-sm outline-none placeholder:text-muted-foreground"
                    placeholder={
                        value.length === 0
                            ? t('invoicing.collect.email.add_placeholder')
                            : ''
                    }
                    aria-invalid={error || invalid ? true : undefined}
                    onChange={(event) => {
                        setDraft(event.target.value);
                        setInvalid(false);
                    }}
                    onKeyDown={onKeyDown}
                    onBlur={() => add(draft)}
                />
            </div>
            {invalid ? (
                <p className="text-sm text-danger">
                    {t('invoicing.collect.email.invalid')}
                </p>
            ) : null}
            <InputError message={error} />
            {available.length > 0 ? (
                <div className="flex flex-wrap gap-1.5">
                    {available.map((one) => (
                        <button
                            key={one.email}
                            type="button"
                            className={cn(
                                'inline-flex max-w-full items-center gap-1 rounded-md border border-dashed px-2 py-0.5 text-xs text-muted-foreground hover:border-solid hover:text-foreground',
                                FOCUS_RING,
                            )}
                            onClick={() => onChange([...value, one.email])}
                        >
                            <Plus aria-hidden="true" className="size-3" />
                            <span className="truncate">
                                {one.label} · {one.email}
                            </span>
                        </button>
                    ))}
                </div>
            ) : null}
        </div>
    );
}

type SendForm = {
    to: string[];
    cc: string[];
    subject: string;
    body: string;
    reminder: boolean;
};

/**
 * Enviar por email (D-438 a D-441): destinatarios desde los emails del cliente, con copia, la
 * plantilla con sus variables y lo que se adjunta. En modo de prueba (D-239), avisa de que solo te
 * llega a ti.
 */
export function SendDocumentDialog({
    document,
    reminder = false,
    trigger,
    open: controlledOpen,
    onOpenChange,
}: {
    document: SalesDocumentDetail;
    reminder?: boolean;
    trigger?: ReactNode;
    open?: boolean;
    onOpenChange?: (open: boolean) => void;
}) {
    const id = useId();
    const [ownOpen, setOwnOpen] = useState(false);
    const open = controlledOpen ?? ownOpen;
    const setOpen = onOpenChange ?? setOwnOpen;
    const [status, setStatus] = useState<'loading' | 'ready' | 'error'>(
        'loading',
    );
    const [compose, setCompose] = useState<EmailCompose | null>(null);
    const form = useForm<SendForm>({
        to: [],
        cc: [],
        subject: '',
        body: '',
        reminder,
    });
    const errors = form.errors as Record<string, string | undefined>;

    useEffect(() => {
        if (!open) {
            return;
        }
        setStatus('loading');
        const controller = new AbortController();
        fetchCompose(document.id, reminder, controller.signal)
            .then((data) => {
                setCompose(data);
                form.setData({
                    to: data.to,
                    cc: [],
                    subject: data.subject,
                    body: data.body,
                    reminder,
                });
                form.clearErrors();
                setStatus('ready');
            })
            .catch(() => {
                if (!controller.signal.aborted) {
                    setStatus('error');
                }
            });

        return () => controller.abort();
        // Solo al abrir: el formulario es del diálogo.
    }, [open, document.id, reminder]);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(`/facturacion/documentos/${document.id}/envios`, {
            preserveScroll: true,
            onSuccess: () => setOpen(false),
        });
    };

    const toError =
        errors.to ??
        Object.entries(errors).find(([key]) => key.startsWith('to.'))?.[1];
    const ccError = Object.entries(errors).find(([key]) =>
        key.startsWith('cc'),
    )?.[1];

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            {trigger ? <DialogTrigger asChild>{trigger}</DialogTrigger> : null}
            <DialogContent className="sm:max-w-2xl">
                <DialogTitle>
                    {t(
                        reminder
                            ? 'invoicing.collect.email.title_reminder'
                            : 'invoicing.collect.email.title',
                        { number: document.number ?? '' },
                    )}
                </DialogTitle>
                <DialogDescription>
                    {compose
                        ? t('invoicing.collect.email.description', {
                              name: compose.from.name,
                              email: compose.from.email,
                          })
                        : t('invoicing.collect.email.loading')}
                </DialogDescription>
                {status === 'loading' ? (
                    <p className="flex items-center gap-2 py-6 text-sm text-muted-foreground">
                        <Spinner /> {t('invoicing.collect.email.loading')}
                    </p>
                ) : status === 'error' || !compose ? (
                    <p className="rounded-md bg-danger-soft px-3 py-2 text-sm">
                        {t('invoicing.collect.email.error')}
                    </p>
                ) : (
                    <form
                        onSubmit={submit}
                        noValidate
                        className="grid max-h-[70dvh] grid-cols-[minmax(0,1fr)] gap-4 overflow-y-auto pr-1"
                        data-test="send-form"
                    >
                        {compose.test_mode ? (
                            <p
                                className="flex items-start gap-2 rounded-md bg-warning-soft px-3 py-2 text-sm"
                                data-test="send-test-mode"
                            >
                                <TriangleAlert
                                    aria-hidden="true"
                                    className="mt-0.5 size-4 shrink-0 text-warning"
                                />
                                {t('invoicing.collect.email.test_mode', {
                                    email: compose.self_email,
                                })}
                            </p>
                        ) : null}
                        {document.is_test && !compose.test_mode ? (
                            <p className="flex items-start gap-2 rounded-md bg-info-soft px-3 py-2 text-sm">
                                <TriangleAlert
                                    aria-hidden="true"
                                    className="mt-0.5 size-4 shrink-0"
                                />
                                {t('invoicing.collect.email.test_series')}
                            </p>
                        ) : null}
                        <RecipientsField
                            id={`${id}-to`}
                            label={t('invoicing.collect.email.to')}
                            value={form.data.to}
                            onChange={(value) => form.setData('to', value)}
                            suggestions={compose.suggestions}
                            error={toError}
                            testId="send-to"
                        />
                        <RecipientsField
                            id={`${id}-cc`}
                            label={t('invoicing.collect.email.cc')}
                            value={form.data.cc}
                            onChange={(value) => form.setData('cc', value)}
                            suggestions={compose.suggestions.filter(
                                (one) => !form.data.to.includes(one.email),
                            )}
                            error={ccError}
                            testId="send-cc"
                        />
                        {compose.bcc ? (
                            <p className="-mt-2 text-xs text-muted-foreground">
                                {t('invoicing.collect.email.bcc', {
                                    email: compose.bcc,
                                })}
                            </p>
                        ) : null}
                        <div className="grid content-start gap-1">
                            <Label htmlFor={`${id}-subject`}>
                                {t('invoicing.collect.email.subject')}
                            </Label>
                            <Input
                                id={`${id}-subject`}
                                value={form.data.subject}
                                maxLength={255}
                                onChange={(event) =>
                                    form.setData('subject', event.target.value)
                                }
                                data-test="send-subject"
                            />
                            <InputError message={errors.subject} />
                        </div>
                        <div className="grid content-start gap-1">
                            <Label htmlFor={`${id}-body`}>
                                {t('invoicing.collect.email.body')}
                            </Label>
                            <Textarea
                                id={`${id}-body`}
                                value={form.data.body}
                                rows={9}
                                maxLength={10000}
                                aria-describedby={`${id}-body-help`}
                                onChange={(event) =>
                                    form.setData('body', event.target.value)
                                }
                                data-test="send-body"
                            />
                            <p
                                id={`${id}-body-help`}
                                className="text-xs text-muted-foreground"
                            >
                                {t('invoicing.collect.email.body_help', {
                                    days: compose.link_days,
                                })}
                            </p>
                            <InputError message={errors.body} />
                        </div>
                        <div className="grid gap-1.5">
                            <p className="text-sm">
                                {t('invoicing.collect.email.attachments')}
                            </p>
                            <ul
                                className="flex flex-wrap gap-1.5"
                                data-test="send-attachments"
                            >
                                {compose.attachments.map((name, index) => (
                                    <li
                                        key={name}
                                        className="inline-flex items-center gap-1.5 rounded-md border px-2 py-1 text-xs"
                                    >
                                        {index === 0 ? (
                                            <FileText
                                                aria-hidden="true"
                                                className="size-3.5 text-muted-foreground"
                                            />
                                        ) : (
                                            <Paperclip
                                                aria-hidden="true"
                                                className="size-3.5 text-muted-foreground"
                                            />
                                        )}
                                        {name}
                                    </li>
                                ))}
                            </ul>
                        </div>
                        <DialogFooter className="gap-2">
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() => setOpen(false)}
                                disabled={form.processing}
                            >
                                {t('common.cancel')}
                            </Button>
                            <Button
                                type="submit"
                                disabled={
                                    form.processing ||
                                    form.data.to.length === 0 ||
                                    form.data.subject.trim() === '' ||
                                    form.data.body.trim() === ''
                                }
                                data-test="send-confirm"
                            >
                                {form.processing ? <Spinner /> : null}
                                {t(
                                    compose.test_mode
                                        ? 'invoicing.collect.email.confirm_test'
                                        : reminder
                                          ? 'invoicing.collect.email.confirm_reminder'
                                          : 'invoicing.collect.email.confirm',
                                )}
                            </Button>
                        </DialogFooter>
                    </form>
                )}
            </DialogContent>
        </Dialog>
    );
}
