import { router, useForm } from '@inertiajs/react';
import { CircleAlert, TriangleAlert } from 'lucide-react';
import type { FormEvent, ReactNode } from 'react';
import { useId, useState } from 'react';
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
import { formatCurrency, formatDate } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { IssuePreview, SalesDocumentDetail } from '@/types';
import { formatQuantity, unitLabel } from './invoicing-format';
import { computeTotals, scaled } from './totals';

const CODES = ['R1', 'R4'] as const;

function DialogShell({
    trigger,
    title,
    description,
    open,
    onOpenChange,
    children,
    wide,
}: {
    trigger: ReactNode;
    title: string;
    description: string;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    children: ReactNode;
    wide?: boolean;
}) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent className={wide ? 'sm:max-w-3xl' : undefined}>
                <DialogTitle>{title}</DialogTitle>
                <DialogDescription>{description}</DialogDescription>
                {children}
            </DialogContent>
        </Dialog>
    );
}

function Problems({ problems }: { problems: string[] }) {
    if (problems.length === 0) {
        return null;
    }

    return (
        <div
            className="grid gap-1 rounded-md bg-danger-soft px-3 py-2 text-sm"
            role="alert"
            data-test="issue-problems"
        >
            <p className="flex items-center gap-1.5">
                <CircleAlert
                    aria-hidden="true"
                    className="size-4 text-danger"
                />
                {t('invoicing.issue.cannot')}
            </p>
            <ul className="list-disc pl-6">
                {problems.map((problem) => (
                    <li key={problem}>{problem}</li>
                ))}
            </ul>
        </div>
    );
}

/**
 * Emitir (PLAN-EMISION §6.2, paso 5): confirma el número que va a recibir y la fecha, avisa si la
 * fecha queda antes de la última de la serie y lista lo que impide emitir.
 */
export function IssueDialog({
    document,
    preview,
    trigger,
    defaultOpen = false,
}: {
    document: SalesDocumentDetail;
    preview: IssuePreview;
    trigger: ReactNode;
    defaultOpen?: boolean;
}) {
    const [open, setOpen] = useState(defaultOpen);
    const [processing, setProcessing] = useState(false);
    const blocked = preview.problems.length > 0;

    return (
        <DialogShell
            trigger={trigger}
            title={t('invoicing.issue.title')}
            description={
                blocked
                    ? t('invoicing.issue.description_blocked')
                    : t('invoicing.issue.description', {
                          number: preview.number ?? '—',
                          date: formatDate(preview.issue_date),
                      })
            }
            open={open}
            onOpenChange={setOpen}
        >
            {preview.last_date && !blocked ? (
                <p className="text-sm text-muted-foreground">
                    {t('invoicing.issue.last_date', {
                        series: preview.series ?? '',
                        date: formatDate(preview.last_date),
                    })}
                </p>
            ) : null}
            <Problems problems={preview.problems} />
            {!blocked ? (
                <p className="flex items-start gap-2 text-sm">
                    <TriangleAlert
                        aria-hidden="true"
                        className="mt-0.5 size-4 shrink-0 text-warning"
                    />
                    {document.is_test
                        ? t('invoicing.issue.test_warning')
                        : t('invoicing.issue.warning')}
                </p>
            ) : null}
            <DialogFooter className="gap-2">
                <Button
                    type="button"
                    variant="secondary"
                    onClick={() => setOpen(false)}
                    disabled={processing}
                >
                    {t('common.cancel')}
                </Button>
                <Button
                    type="button"
                    disabled={blocked || processing}
                    data-test="issue-confirm"
                    onClick={() =>
                        router.post(
                            `/facturacion/documentos/${document.id}/emitir`,
                            {},
                            {
                                preserveScroll: true,
                                onStart: () => setProcessing(true),
                                onFinish: () => setProcessing(false),
                                onSuccess: () => setOpen(false),
                            },
                        )
                    }
                >
                    {processing ? <Spinner /> : null}
                    {t('invoicing.issue.confirm', {
                        number: preview.number ?? '',
                    })}
                </Button>
            </DialogFooter>
        </DialogShell>
    );
}

function ReasonFields({
    form,
    id,
}: {
    form: {
        data: { reason: string; code: string };
        setData: (key: 'reason' | 'code', value: string) => void;
        errors: Partial<Record<string, string>>;
    };
    id: string;
}) {
    return (
        <>
            <div className="grid gap-1">
                <Label htmlFor={`${id}-reason`}>
                    {t('invoicing.correct.reason')}
                </Label>
                <Textarea
                    id={`${id}-reason`}
                    value={form.data.reason}
                    maxLength={500}
                    required
                    aria-invalid={form.errors.reason ? true : undefined}
                    placeholder={t('invoicing.correct.reason_placeholder')}
                    onChange={(event) =>
                        form.setData('reason', event.target.value)
                    }
                    data-test="correct-reason"
                />
                <InputError message={form.errors.reason} />
            </div>
            <div className="grid gap-1">
                <Label htmlFor={`${id}-code`}>
                    {t('invoicing.correct.code')}
                </Label>
                <Select
                    value={form.data.code}
                    onValueChange={(value) => form.setData('code', value)}
                >
                    <SelectTrigger id={`${id}-code`} className="w-full">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {CODES.map((code) => (
                            <SelectItem key={code} value={code}>
                                {t(`invoicing.correct.code_${code}`)}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <p className="text-xs text-muted-foreground">
                    {t('invoicing.correct.code_hint')}
                </p>
            </div>
        </>
    );
}

/** Anular (D-244): una rectificativa por el total con motivo; la original queda «Anulada». */
export function CancelDialog({
    document,
    trigger,
}: {
    document: SalesDocumentDetail;
    trigger: ReactNode;
}) {
    const id = useId();
    const [open, setOpen] = useState(false);
    const form = useForm({ reason: '', code: 'R1' });
    const net = document.rectifications
        .filter((credit) => credit.status === 'issued')
        .reduce(
            (sum, credit) => sum + Number(credit.total),
            Number(document.total),
        );

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(`/facturacion/documentos/${document.id}/anular`, {
            preserveScroll: true,
            onSuccess: () => setOpen(false),
        });
    };

    return (
        <DialogShell
            trigger={trigger}
            title={t('invoicing.cancel.title', {
                number: document.number ?? '',
            })}
            description={t('invoicing.cancel.description')}
            open={open}
            onOpenChange={setOpen}
        >
            <form
                onSubmit={submit}
                noValidate
                className="grid gap-4"
                data-test="cancel-form"
            >
                <ReasonFields form={form} id={id} />
                <p
                    className="rounded-md bg-muted px-3 py-2 text-sm"
                    data-test="cancel-summary"
                >
                    {t('invoicing.cancel.summary', {
                        amount: formatCurrency(-net),
                    })}
                </p>
                {Number(document.paid_total) > 0 ? (
                    <p className="text-sm text-warning">
                        {t('invoicing.cancel.paid_warning')}
                    </p>
                ) : null}
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
                        variant="destructive"
                        disabled={
                            form.processing ||
                            form.data.reason.trim().length < 5
                        }
                        data-test="cancel-confirm"
                    >
                        {form.processing ? <Spinner /> : null}
                        {t('invoicing.cancel.confirm')}
                    </Button>
                </DialogFooter>
            </form>
        </DialogShell>
    );
}

/**
 * Rectificar por diferencias (D-244): se escribe cómo debería haber sido cada línea (unidades y
 * precio) y se emite una rectificativa solo por la diferencia, que se ve antes de confirmar.
 */
export function RectifyDialog({
    document,
    trigger,
}: {
    document: SalesDocumentDetail;
    trigger: ReactNode;
}) {
    const id = useId();
    const [open, setOpen] = useState(false);
    const items = document.lines.filter((line) => line.kind === 'item');
    const form = useForm({
        reason: '',
        code: 'R1',
        lines: items.map((line) => ({
            line_id: line.id,
            quantity: trimZeros(line.quantity),
            unit_price: trimZeros(line.unit_price),
        })),
    });

    // La diferencia, como la calcula el servidor (InvoiceCorrections::rectify).
    const differences = items.flatMap((line, index) => {
        const wanted = form.data.lines[index];
        const tax =
            line.tax_rate_id === null
                ? null
                : {
                      key: String(line.tax_rate_id),
                      rate: line.tax_rate ?? '0',
                      operation_type: line.operation_type ?? 'S1',
                  };
        const out = [];
        const deltaQuantity = Number(wanted.quantity) - Number(line.quantity);
        const deltaPrice = Number(wanted.unit_price) - Number(line.unit_price);

        if (
            Number.isFinite(deltaQuantity) &&
            Math.abs(deltaQuantity) > 0.00001
        ) {
            out.push({
                quantity: subtract(wanted.quantity, line.quantity),
                unit_price: line.unit_price,
                discount_pct: line.discount_pct,
                tax,
            });
        }

        if (Number.isFinite(deltaPrice) && Math.abs(deltaPrice) > 0.00001) {
            out.push({
                quantity: wanted.quantity,
                unit_price: subtract(wanted.unit_price, line.unit_price),
                discount_pct: line.discount_pct,
                tax,
            });
        }

        return out;
    });
    const totals = computeTotals(differences, document.withholding_rate);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(`/facturacion/documentos/${document.id}/rectificar`, {
            preserveScroll: true,
            onSuccess: () => setOpen(false),
        });
    };

    return (
        <DialogShell
            trigger={trigger}
            title={t('invoicing.rectify.title', {
                number: document.number ?? '',
            })}
            description={t('invoicing.rectify.description')}
            open={open}
            onOpenChange={setOpen}
            wide
        >
            <form
                onSubmit={submit}
                noValidate
                className="grid gap-4"
                data-test="rectify-form"
            >
                <div className="max-h-[45vh] overflow-y-auto rounded-md border">
                    <table className="tabular w-full text-sm">
                        <caption className="sr-only">
                            {t('invoicing.rectify.lines')}
                        </caption>
                        <thead>
                            <tr className="border-b text-left text-xs tracking-[0.12em] text-muted-foreground uppercase">
                                <th
                                    scope="col"
                                    className="px-3 py-2 font-medium"
                                >
                                    {t('invoicing.rectify.concept')}
                                </th>
                                <th
                                    scope="col"
                                    className="px-3 py-2 font-medium"
                                >
                                    {t('invoicing.rectify.quantity')}
                                </th>
                                <th
                                    scope="col"
                                    className="px-3 py-2 font-medium"
                                >
                                    {t('invoicing.rectify.price')}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {items.map((line, index) => (
                                <tr
                                    key={line.id}
                                    className="border-b last:border-0"
                                >
                                    <th
                                        scope="row"
                                        className="px-3 py-2 text-left font-normal"
                                    >
                                        {line.name ?? line.description}
                                        <span className="block text-xs text-muted-foreground">
                                            {t('invoicing.rectify.issued', {
                                                quantity: formatQuantity(
                                                    line.quantity,
                                                ),
                                                unit: unitLabel(
                                                    line.unit,
                                                    line.quantity,
                                                ),
                                                price: formatCurrency(
                                                    line.unit_price,
                                                ),
                                            })}
                                        </span>
                                    </th>
                                    <td className="px-3 py-2">
                                        <Label
                                            htmlFor={`${id}-q-${line.id}`}
                                            className="sr-only"
                                        >
                                            {t(
                                                'invoicing.rectify.quantity_of',
                                                { n: index + 1 },
                                            )}
                                        </Label>
                                        <Input
                                            id={`${id}-q-${line.id}`}
                                            inputMode="decimal"
                                            className="w-24 text-right"
                                            value={
                                                form.data.lines[index].quantity
                                            }
                                            onChange={(event) =>
                                                form.setData(
                                                    'lines',
                                                    form.data.lines.map(
                                                        (one, i) =>
                                                            i === index
                                                                ? {
                                                                      ...one,
                                                                      quantity:
                                                                          event
                                                                              .target
                                                                              .value,
                                                                  }
                                                                : one,
                                                    ),
                                                )
                                            }
                                            data-test="rectify-quantity"
                                        />
                                    </td>
                                    <td className="px-3 py-2">
                                        <Label
                                            htmlFor={`${id}-p-${line.id}`}
                                            className="sr-only"
                                        >
                                            {t('invoicing.rectify.price_of', {
                                                n: index + 1,
                                            })}
                                        </Label>
                                        <Input
                                            id={`${id}-p-${line.id}`}
                                            inputMode="decimal"
                                            className="w-28 text-right"
                                            value={
                                                form.data.lines[index]
                                                    .unit_price
                                            }
                                            onChange={(event) =>
                                                form.setData(
                                                    'lines',
                                                    form.data.lines.map(
                                                        (one, i) =>
                                                            i === index
                                                                ? {
                                                                      ...one,
                                                                      unit_price:
                                                                          event
                                                                              .target
                                                                              .value,
                                                                  }
                                                                : one,
                                                    ),
                                                )
                                            }
                                            data-test="rectify-price"
                                        />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <InputError
                    message={
                        (form.errors as Record<string, string | undefined>)
                            .lines
                    }
                />
                <ReasonFields form={form} id={id} />
                <p
                    className="rounded-md bg-muted px-3 py-2 text-sm"
                    data-test="rectify-summary"
                    aria-live="polite"
                >
                    {differences.length === 0
                        ? t('invoicing.rectify.no_difference')
                        : t('invoicing.rectify.summary', {
                              subtotal: formatCurrency(totals.subtotal),
                              total: formatCurrency(totals.total),
                          })}
                </p>
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
                            differences.length === 0 ||
                            form.data.reason.trim().length < 5
                        }
                        data-test="rectify-confirm"
                    >
                        {form.processing ? <Spinner /> : null}
                        {t('invoicing.rectify.confirm')}
                    </Button>
                </DialogFooter>
            </form>
        </DialogShell>
    );
}

/** Anular el registro (V-03, solo admin): para una factura que no debió existir. */
export function VoidDialog({
    document,
    problems,
    trigger,
}: {
    document: SalesDocumentDetail;
    problems: string[];
    trigger: ReactNode;
}) {
    const id = useId();
    const [open, setOpen] = useState(false);
    const form = useForm({ reason: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(`/facturacion/documentos/${document.id}/anular-registro`, {
            preserveScroll: true,
            onSuccess: () => setOpen(false),
        });
    };

    return (
        <DialogShell
            trigger={trigger}
            title={t('invoicing.void.title', { number: document.number ?? '' })}
            description={t('invoicing.void.description')}
            open={open}
            onOpenChange={setOpen}
        >
            <form
                onSubmit={submit}
                noValidate
                className="grid gap-4"
                data-test="void-form"
            >
                <Problems problems={problems} />
                <div className="grid gap-1">
                    <Label htmlFor={`${id}-reason`}>
                        {t('invoicing.correct.reason')}
                    </Label>
                    <Textarea
                        id={`${id}-reason`}
                        value={form.data.reason}
                        maxLength={500}
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
                        onClick={() => setOpen(false)}
                        disabled={form.processing}
                    >
                        {t('common.cancel')}
                    </Button>
                    <Button
                        type="submit"
                        variant="destructive"
                        disabled={
                            form.processing ||
                            problems.length > 0 ||
                            form.data.reason.trim().length < 5
                        }
                    >
                        {form.processing ? <Spinner /> : null}
                        {t('invoicing.void.confirm')}
                    </Button>
                </DialogFooter>
            </form>
        </DialogShell>
    );
}

function trimZeros(value: string): string {
    return value.includes('.') ? value.replace(/\.?0+$/, '') : value;
}

/** a − b con cuatro decimales, sin float (texto para la cuenta exacta de computeTotals). */
function subtract(a: string, b: string): string {
    const result = scaled(a, 4) - scaled(b, 4);
    const negative = result < BigInt(0);
    const digits = (negative ? -result : result).toString().padStart(5, '0');

    return `${negative ? '-' : ''}${digits.slice(0, -4)}.${digits.slice(-4)}`;
}
