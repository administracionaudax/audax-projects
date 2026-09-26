import { router } from '@inertiajs/react';
import { Undo2 } from 'lucide-react';
import { useId, useState } from 'react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { t } from '@/lib/i18n';
import { sendBack } from '@/routes/time/approvals';

/**
 * Devolver una semana con un comentario obligatorio (D-020): la persona la recibe como
 * notificación y la semana vuelve a ser editable.
 */
export function ReturnWeekDialog({
    periodId,
    personName,
    weekLabel,
    size = 'default',
}: {
    periodId: number;
    personName: string;
    weekLabel: string;
    size?: 'default' | 'sm';
}) {
    const id = useId();
    const [open, setOpen] = useState(false);
    const [comment, setComment] = useState('');
    const [error, setError] = useState<string | undefined>();
    const [processing, setProcessing] = useState(false);

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (comment.trim() === '') {
            setError(t('hours.review.comment_required'));

            return;
        }

        router.post(
            sendBack.url(periodId),
            { comment: comment.trim() },
            {
                preserveScroll: true,
                errorBag: 'review',
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onError: (errors) =>
                    setError(errors.comment ?? Object.values(errors)[0]),
                onSuccess: () => {
                    setOpen(false);
                    setComment('');
                },
            },
        );
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                setOpen(next);
                setError(undefined);
            }}
        >
            <DialogTrigger asChild>
                <Button type="button" variant="outline" size={size}>
                    <Undo2 aria-hidden="true" />
                    {t('hours.review.return')}
                </Button>
            </DialogTrigger>
            <DialogContent>
                <form onSubmit={submit} className="grid gap-4" noValidate>
                    <DialogHeader>
                        <DialogTitle>
                            {t('hours.review.return_title')}
                        </DialogTitle>
                        <DialogDescription>
                            {t('hours.review.return_description', {
                                name: personName,
                                week: weekLabel,
                            })}
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-2">
                        <Label htmlFor={`${id}-comment`}>
                            {t('hours.review.comment')}
                        </Label>
                        <Textarea
                            id={`${id}-comment`}
                            value={comment}
                            onChange={(event) => setComment(event.target.value)}
                            rows={4}
                            maxLength={2000}
                            placeholder={t('hours.review.comment_placeholder')}
                            aria-invalid={error ? true : undefined}
                            aria-describedby={error ? `${id}-error` : undefined}
                            autoFocus
                        />
                        <InputError id={`${id}-error`} message={error} />
                    </div>
                    <DialogFooter className="gap-2">
                        <Button
                            type="button"
                            variant="secondary"
                            disabled={processing}
                            onClick={() => setOpen(false)}
                        >
                            {t('common.cancel')}
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing ? <Spinner /> : null}
                            {t('hours.review.return_submit')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
