import { router, useForm } from '@inertiajs/react';
import { CalendarX, CircleCheck, CircleX } from 'lucide-react';
import { useId, useState } from 'react';
import { absencePeriodLabel } from '@/components/absences/absence-meta';
import type { AbsenceRow } from '@/components/absences/types';
import { describedBy, Field } from '@/components/admin/field';
import { toastVisitErrors } from '@/components/admin/visit-errors';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { t } from '@/lib/i18n';
import {
    decide as decideCancellation,
    request as requestCancellation,
} from '@/routes/absences/cancellation';

type Target = Pick<
    AbsenceRow,
    'id' | 'start_date' | 'end_date' | 'partial_minutes'
>;

/** Un diálogo con un texto obligatorio que se manda por POST (pedir cancelación o rechazarla). */
function TextDialog({
    triggerLabel,
    triggerAria,
    triggerIcon: Icon,
    title,
    description,
    label,
    placeholder,
    confirm,
    field,
    url,
    extra = {},
    destructive = false,
    testId,
}: {
    triggerLabel: string;
    triggerAria: string;
    triggerIcon: typeof CalendarX;
    title: string;
    description: string;
    label: string;
    placeholder: string;
    confirm: string;
    field: 'reason' | 'comment';
    url: string;
    extra?: Record<string, string>;
    destructive?: boolean;
    testId: string;
}) {
    const id = useId();
    const [open, setOpen] = useState(false);
    const form = useForm<Record<string, string>>({ [field]: '' });
    const error =
        form.errors[field] ??
        (form.errors as Record<string, string | undefined>).absence;

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                setOpen(next);
                if (next) {
                    form.reset();
                    form.clearErrors();
                }
            }}
        >
            <DialogTrigger asChild>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    aria-label={triggerAria}
                    data-test={testId}
                >
                    <Icon aria-hidden="true" />
                    {triggerLabel}
                </Button>
            </DialogTrigger>
            <DialogContent className="sm:max-w-lg">
                <form
                    noValidate
                    className="grid gap-5"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.transform((data) => ({ ...data, ...extra }));
                        form.post(url, {
                            preserveScroll: true,
                            onSuccess: () => setOpen(false),
                        });
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>{title}</DialogTitle>
                        <DialogDescription>{description}</DialogDescription>
                    </DialogHeader>
                    <Field id={`${id}-text`} label={label} error={error}>
                        <Textarea
                            id={`${id}-text`}
                            value={form.data[field]}
                            onChange={(event) =>
                                form.setData(field, event.target.value)
                            }
                            required
                            maxLength={2000}
                            rows={3}
                            placeholder={placeholder}
                            aria-invalid={error ? true : undefined}
                            aria-describedby={describedBy(`${id}-text`, {
                                error,
                            })}
                        />
                    </Field>
                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button
                                type="button"
                                variant="secondary"
                                disabled={form.processing}
                            >
                                {t('common.cancel')}
                            </Button>
                        </DialogClose>
                        <Button
                            type="submit"
                            variant={destructive ? 'destructive' : 'default'}
                            disabled={
                                form.processing ||
                                form.data[field].trim() === ''
                            }
                        >
                            {form.processing && <Spinner />}
                            {confirm}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/** «Pedir cancelación» de una ausencia aprobada que ya ha empezado (W-069). */
export function RequestCancellationDialog({ absence }: { absence: Target }) {
    const period = absencePeriodLabel(absence);

    return (
        <TextDialog
            triggerLabel={t('leave.cancellation.request')}
            triggerAria={t('leave.cancellation.request_label', { period })}
            triggerIcon={CalendarX}
            title={t('leave.cancellation.request_title')}
            description={t('leave.cancellation.request_description', {
                period,
            })}
            label={t('leave.cancellation.reason')}
            placeholder={t('leave.cancellation.reason_placeholder')}
            confirm={t('leave.cancellation.request_confirm')}
            field="reason"
            url={requestCancellation.url(absence.id)}
            testId="request-cancellation"
        />
    );
}

/** Aceptar (un clic) o rechazar con comentario la cancelación que pide la persona. */
export function DecideCancellationButtons({
    absence,
    personName,
}: {
    absence: Target;
    personName: string;
}) {
    const [processing, setProcessing] = useState(false);
    const period = absencePeriodLabel(absence);

    return (
        <>
            <Button
                type="button"
                size="sm"
                disabled={processing}
                aria-label={t('leave.cancellation.accept_label', {
                    name: personName,
                    period,
                })}
                data-test="accept-cancellation"
                onClick={() =>
                    router.post(
                        decideCancellation.url(absence.id),
                        { decision: 'accept' },
                        {
                            preserveScroll: true,
                            onError: toastVisitErrors,
                            onStart: () => setProcessing(true),
                            onFinish: () => setProcessing(false),
                        },
                    )
                }
            >
                {processing ? <Spinner /> : <CircleCheck aria-hidden="true" />}
                {t('leave.cancellation.accept')}
            </Button>
            <TextDialog
                triggerLabel={t('leave.cancellation.reject')}
                triggerAria={t('leave.cancellation.reject_label', {
                    name: personName,
                    period,
                })}
                triggerIcon={CircleX}
                title={t('leave.cancellation.reject_title', {
                    name: personName,
                })}
                description={t('leave.cancellation.reject_description', {
                    name: personName,
                    period,
                })}
                label={t('leave.cancellation.comment')}
                placeholder={t('leave.cancellation.comment_placeholder')}
                confirm={t('leave.cancellation.reject_confirm')}
                field="comment"
                url={decideCancellation.url(absence.id)}
                extra={{ decision: 'reject' }}
                destructive
                testId="reject-cancellation"
            />
        </>
    );
}
