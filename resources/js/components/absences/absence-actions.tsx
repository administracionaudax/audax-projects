import { router, useForm } from '@inertiajs/react';
import { CircleCheck, CircleX, Undo2 } from 'lucide-react';
import { useId, useState } from 'react';
import { absencePeriodLabel } from '@/components/absences/absence-meta';
import type { AbsenceRow } from '@/components/absences/types';
import { describedBy, Field } from '@/components/admin/field';
import { toastVisitErrors } from '@/components/admin/visit-errors';
import { ConfirmDialog } from '@/components/confirm-dialog';
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
import { approve, cancel, reject } from '@/routes/absences';

type Target = Pick<
    AbsenceRow,
    'id' | 'status' | 'start_date' | 'end_date' | 'partial_minutes'
>;

/**
 * Cancelar una ausencia propia (solicitada, o aprobada que aún no ha empezado) o, si es de otra
 * persona, anularla como quien la aprueba (D-049). Siempre con confirmación.
 */
export function CancelAbsenceButton({
    absence,
    personName,
}: {
    absence: Target;
    /** Si es de otra persona: se «anula» y el texto la nombra. */
    personName?: string;
}) {
    const [open, setOpen] = useState(false);
    const [processing, setProcessing] = useState(false);
    const period = absencePeriodLabel(absence);
    const annul = personName !== undefined;

    return (
        <ConfirmDialog
            open={open}
            onOpenChange={setOpen}
            trigger={
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    aria-label={
                        annul
                            ? t('absences.annul.label', {
                                  name: personName,
                                  period,
                              })
                            : t('absences.cancel.label', { period })
                    }
                >
                    <Undo2 aria-hidden="true" />
                    {annul
                        ? t('absences.annul.button')
                        : t('absences.cancel.button')}
                </Button>
            }
            title={
                annul
                    ? t('absences.annul.title', { name: personName })
                    : t('absences.cancel.title')
            }
            description={
                annul
                    ? t('absences.annul.description', {
                          name: personName,
                          period,
                      })
                    : absence.status === 'approved'
                      ? t('absences.cancel.description_approved', { period })
                      : t('absences.cancel.description_requested', {
                            period,
                        })
            }
            confirmLabel={
                annul
                    ? t('absences.annul.confirm')
                    : t('absences.cancel.confirm')
            }
            processing={processing}
            onConfirm={() =>
                router.post(
                    cancel.url(absence.id),
                    {},
                    {
                        preserveScroll: true,
                        onError: toastVisitErrors,
                        onStart: () => setProcessing(true),
                        onFinish: () => {
                            setProcessing(false);
                            setOpen(false);
                        },
                    },
                )
            }
        />
    );
}

/** Aprobar una solicitud pendiente: un clic (se puede anular después). */
export function ApproveAbsenceButton({
    absence,
    personName,
}: {
    absence: Target;
    personName: string;
}) {
    const [processing, setProcessing] = useState(false);

    return (
        <Button
            type="button"
            size="sm"
            disabled={processing}
            aria-label={t('absences.team.approve_label', {
                name: personName,
                period: absencePeriodLabel(absence),
            })}
            onClick={() =>
                router.post(
                    approve.url(absence.id),
                    {},
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
            {t('absences.team.approve')}
        </Button>
    );
}

/** Rechazar una solicitud con un comentario obligatorio, que recibe la persona (D-049). */
export function RejectAbsenceDialog({
    absence,
    personName,
}: {
    absence: Target;
    personName: string;
}) {
    const id = useId();
    const [open, setOpen] = useState(false);
    const form = useForm({ comment: '' });
    const period = absencePeriodLabel(absence);
    const error =
        form.errors.comment ??
        (form.errors as Record<string, string | undefined>).absence;

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.post(reject.url(absence.id), {
            preserveScroll: true,
            onSuccess: () => setOpen(false),
        });
    };

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
                    aria-label={t('absences.team.reject_label', {
                        name: personName,
                        period,
                    })}
                >
                    <CircleX aria-hidden="true" />
                    {t('absences.team.reject')}
                </Button>
            </DialogTrigger>
            <DialogContent className="sm:max-w-lg">
                <form onSubmit={submit} className="grid gap-5" noValidate>
                    <DialogHeader>
                        <DialogTitle>
                            {t('absences.team.reject_title', {
                                name: personName,
                            })}
                        </DialogTitle>
                        <DialogDescription>
                            {t('absences.team.reject_description', {
                                name: personName,
                                period,
                            })}
                        </DialogDescription>
                    </DialogHeader>

                    <Field
                        id={`${id}-comment`}
                        label={t('absences.team.reject_comment')}
                        error={error}
                    >
                        <Textarea
                            id={`${id}-comment`}
                            value={form.data.comment}
                            onChange={(event) =>
                                form.setData('comment', event.target.value)
                            }
                            required
                            maxLength={2000}
                            rows={4}
                            placeholder={t('absences.team.reject_placeholder')}
                            aria-invalid={error ? true : undefined}
                            aria-describedby={describedBy(`${id}-comment`, {
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
                            variant="destructive"
                            disabled={
                                form.processing ||
                                form.data.comment.trim() === ''
                            }
                        >
                            {form.processing && <Spinner />}
                            {t('absences.team.reject_confirm')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
