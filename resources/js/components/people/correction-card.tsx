import { router } from '@inertiajs/react';
import { ArrowRight, Check, Undo2, X } from 'lucide-react';
import { useId, useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { formatDate, formatDateTime, formatTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { kindLabel, madridDate, modeLabel } from '@/lib/people';
import { cn } from '@/lib/utils';
import { accept, reject, withdraw } from '@/routes/people/corrections';
import type { Correction } from '@/types/people';

const STATUS_TONE: Record<Correction['status'], string> = {
    pending: 'bg-info-soft',
    accepted: 'bg-success-soft',
    disputed: 'bg-warning-soft',
    withdrawn: 'bg-neutral-soft',
};

function timeLabel(at: string, date: string): string {
    const time = formatTime(at);

    return madridDate(at) === date
        ? time
        : `${time} ${t('people.day.next_day')}`;
}

/**
 * Una corrección del registro (D-335): quién la propone y por qué, lo que anula (tachado) y lo que
 * añade, su estado y, a quien le toca, «Aceptar» o «No estoy de acuerdo» (con su motivo: queda en
 * discrepancia). Quien la propuso puede retirarla mientras está pendiente.
 */
export function CorrectionCard({
    correction,
    showPerson = false,
    selectable,
}: {
    correction: Correction;
    /** En la bandeja: el nombre de la persona y el día. */
    showPerson?: boolean;
    /** Casilla para aceptar en bloque. */
    selectable?: React.ReactNode;
}) {
    const [processing, setProcessing] = useState(false);
    const [rejecting, setRejecting] = useState(false);
    const [withdrawing, setWithdrawing] = useState(false);

    const decided = (() => {
        if (correction.status === 'accepted') {
            return t('people.correction_card.decided', {
                name: correction.decided_by ?? '',
                date: formatDateTime(correction.decided_at),
            });
        }

        if (correction.status === 'disputed') {
            return correction.dispute_reason === 'no_answer'
                ? t('people.correction_card.no_answer', {
                      date: formatDate(correction.decided_at),
                  })
                : t('people.correction_card.rejected', {
                      name: correction.decided_by ?? '',
                      date: formatDateTime(correction.decided_at),
                  });
        }

        if (correction.status === 'withdrawn') {
            return t('people.correction_card.withdrawn', {
                date: formatDateTime(correction.decided_at),
            });
        }

        return correction.expires_at
            ? t('people.correction_card.expires', {
                  date: formatDate(correction.expires_at),
              })
            : null;
    })();

    return (
        <article
            className="grid gap-3 rounded-md border p-3"
            data-test="correction"
            data-status={correction.status}
            aria-labelledby={`correction-${correction.id}`}
        >
            <header className="flex flex-wrap items-start gap-2">
                {selectable}
                <div className="min-w-0 flex-1">
                    <h3
                        id={`correction-${correction.id}`}
                        className="text-sm font-medium"
                    >
                        {showPerson
                            ? `${correction.user.name} · ${formatDate(correction.date)}`
                            : t('people.correction_card.number', {
                                  id: correction.id,
                              })}
                    </h3>
                    <p className="text-xs text-muted-foreground">
                        {t('people.correction_card.proposed', {
                            name: correction.proposed_by.name,
                            date: formatDateTime(correction.proposed_at),
                        })}
                    </p>
                </div>
                <span
                    className={cn(
                        'rounded-md px-1.5 py-0.5 text-xs',
                        STATUS_TONE[correction.status],
                    )}
                    data-test="correction-status"
                >
                    {t(
                        `people.correction_status.${correction.status}` as TranslationKey,
                    )}
                </span>
            </header>

            <dl className="grid gap-2 text-sm">
                <div>
                    <dt className="text-xs text-muted-foreground">
                        {t('people.correction_card.reason')}
                    </dt>
                    <dd className="break-words">{correction.reason}</dd>
                </div>
                <div className="grid gap-1 sm:grid-cols-[1fr_auto_1fr] sm:items-start">
                    <div>
                        <dt className="text-xs text-muted-foreground">
                            {t('people.correction_card.voids')}
                        </dt>
                        <dd>
                            {correction.voids.length === 0 ? (
                                <span className="text-muted-foreground">—</span>
                            ) : (
                                <ul className="grid">
                                    {correction.voids.map((event) => (
                                        <li
                                            key={event.id}
                                            className="tabular text-muted-foreground line-through"
                                        >
                                            {timeLabel(
                                                event.at,
                                                correction.date,
                                            )}{' '}
                                            {kindLabel(event.kind)}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </dd>
                    </div>
                    <ArrowRight
                        aria-hidden="true"
                        className="hidden size-4 text-muted-foreground sm:mt-5 sm:block"
                    />
                    <div>
                        <dt className="text-xs text-muted-foreground">
                            {t('people.correction_card.adds')}
                        </dt>
                        <dd>
                            {correction.adds.length === 0 ? (
                                <span className="text-muted-foreground">—</span>
                            ) : (
                                <ul className="grid">
                                    {correction.adds.map((add, index) => (
                                        <li
                                            key={`${add.kind}-${add.at}-${index}`}
                                            className="tabular"
                                        >
                                            {timeLabel(add.at, correction.date)}{' '}
                                            {kindLabel(add.kind)}
                                            {add.work_mode
                                                ? ` · ${modeLabel(add.work_mode)}`
                                                : ''}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </dd>
                    </div>
                </div>
                {correction.decision_note ? (
                    <div>
                        <dt className="text-xs text-muted-foreground">
                            {t('people.correction_card.note')}
                        </dt>
                        <dd className="break-words">
                            {correction.decision_note}
                        </dd>
                    </div>
                ) : null}
            </dl>

            {decided ? (
                <p className="text-xs text-muted-foreground">{decided}</p>
            ) : null}

            {correction.can.decide || correction.can.withdraw ? (
                <div className="flex flex-wrap gap-2">
                    {correction.can.decide ? (
                        <>
                            <Button
                                type="button"
                                size="sm"
                                disabled={processing}
                                onClick={() =>
                                    router.post(
                                        accept.url(correction.id),
                                        {},
                                        {
                                            preserveScroll: true,
                                            onStart: () => setProcessing(true),
                                            onFinish: () =>
                                                setProcessing(false),
                                        },
                                    )
                                }
                                data-test="correction-accept"
                            >
                                {processing ? (
                                    <Spinner />
                                ) : (
                                    <Check aria-hidden="true" />
                                )}
                                {t('people.correction_card.accept')}
                            </Button>
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                disabled={processing}
                                onClick={() => setRejecting(true)}
                                data-test="correction-reject"
                            >
                                <X aria-hidden="true" />
                                {t('people.correction_card.reject')}
                            </Button>
                        </>
                    ) : null}
                    {correction.can.withdraw ? (
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            disabled={processing}
                            onClick={() => setWithdrawing(true)}
                            data-test="correction-withdraw"
                        >
                            <Undo2 aria-hidden="true" />
                            {t('people.correction_card.withdraw')}
                        </Button>
                    ) : null}
                </div>
            ) : null}

            <RejectDialog
                correction={correction}
                open={rejecting}
                onOpenChange={setRejecting}
            />
            <WithdrawDialog
                correction={correction}
                open={withdrawing}
                onOpenChange={setWithdrawing}
            />
        </article>
    );
}

function RejectDialog({
    correction,
    open,
    onOpenChange,
}: {
    correction: Correction;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const id = useId();
    const [note, setNote] = useState('');
    const [error, setError] = useState<string | undefined>();
    const [processing, setProcessing] = useState(false);

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                onOpenChange(next);
                if (next) {
                    setNote('');
                    setError(undefined);
                }
            }}
        >
            <DialogContent>
                <form
                    className="grid gap-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        router.post(
                            reject.url(correction.id),
                            { note },
                            {
                                preserveScroll: true,
                                onStart: () => setProcessing(true),
                                onFinish: () => setProcessing(false),
                                onError: (errors) => setError(errors.note),
                                onSuccess: () => onOpenChange(false),
                            },
                        );
                    }}
                >
                    <DialogTitle>
                        {t('people.correction_card.reject_title')}
                    </DialogTitle>
                    <DialogDescription>
                        {t('people.correction_card.reject_description')}
                    </DialogDescription>
                    <div className="grid gap-2">
                        <Label htmlFor={`${id}-note`}>
                            {t('people.correction_card.reject_note')}
                        </Label>
                        <Textarea
                            id={`${id}-note`}
                            value={note}
                            onChange={(event) => setNote(event.target.value)}
                            maxLength={500}
                            required
                            aria-invalid={error ? true : undefined}
                            data-test="correction-reject-note"
                        />
                        <InputError message={error} />
                    </div>
                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button type="button" variant="secondary">
                                {t('common.cancel')}
                            </Button>
                        </DialogClose>
                        <Button
                            type="submit"
                            disabled={processing || note.trim().length < 3}
                            data-test="correction-reject-submit"
                        >
                            {processing ? <Spinner /> : null}
                            {t('people.correction_card.reject_submit')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function WithdrawDialog({
    correction,
    open,
    onOpenChange,
}: {
    correction: Correction;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const [processing, setProcessing] = useState(false);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogTitle>
                    {t('people.correction_card.withdraw_title')}
                </DialogTitle>
                <DialogDescription>
                    {t('people.correction_card.withdraw_description')}
                </DialogDescription>
                <DialogFooter className="gap-2">
                    <DialogClose asChild>
                        <Button type="button" variant="secondary">
                            {t('common.cancel')}
                        </Button>
                    </DialogClose>
                    <Button
                        type="button"
                        variant="destructive"
                        disabled={processing}
                        onClick={() =>
                            router.post(
                                withdraw.url(correction.id),
                                {},
                                {
                                    preserveScroll: true,
                                    onStart: () => setProcessing(true),
                                    onFinish: () => setProcessing(false),
                                    onSuccess: () => onOpenChange(false),
                                },
                            )
                        }
                        data-test="correction-withdraw-confirm"
                    >
                        {t('people.correction_card.withdraw')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
