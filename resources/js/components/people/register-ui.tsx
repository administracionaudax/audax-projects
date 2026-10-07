import { router } from '@inertiajs/react';
import {
    Check,
    CircleCheck,
    FileDown,
    Fingerprint,
    Hourglass,
    MessageSquareWarning,
    Minus,
    RotateCcw,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
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
import { formatDate, formatDateTime, formatMinutes } from '@/lib/format';
import { t } from '@/lib/i18n';
import { formatDifference, monthLabel } from '@/lib/people';
import {
    balanceKindLabel,
    CAP_TONE,
    capPercent,
    CLOSE_TONE,
    closeStatusLabel,
    shortHash,
    signedMinutes,
} from '@/lib/people-register';
import { cn } from '@/lib/utils';
import { confirm, disagree, pdf } from '@/routes/people/closes';
import type {
    BalanceMovement,
    CloseState,
    CloseStatus,
    MonthClose,
    PendingCompensation,
    YearSummary,
} from '@/types/people-register';
import { Figure } from './people-ui';

/*
 * Piezas comunes de las pantallas de R2 (D-346 a D-359): el estado de un cierre (con texto e icono,
 * nunca solo color), la huella, la barra del tope de horas extra, la respuesta al resumen del mes y
 * los movimientos del saldo de horas.
 */

const CLOSE_ICON: Record<CloseStatus | CloseState, LucideIcon> = {
    pending: Hourglass,
    confirmed: CircleCheck,
    disagreed: MessageSquareWarning,
    reopened: RotateCcw,
    superseded: Minus,
    missing: Minus,
};

export function CloseStatusBadge({
    status,
    className,
}: {
    status: CloseStatus | CloseState;
    className?: string;
}) {
    const Icon = CLOSE_ICON[status];

    return (
        <span
            className={cn(
                'inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-xs whitespace-nowrap',
                CLOSE_TONE[status],
                className,
            )}
            data-test="close-status"
            data-status={status}
        >
            <Icon
                aria-hidden="true"
                className={cn(
                    'size-3.5 shrink-0',
                    status === 'confirmed' && 'text-success',
                    status === 'disagreed' && 'text-warning',
                    status === 'pending' && 'text-info',
                )}
            />
            {closeStatusLabel(status)}
        </span>
    );
}

/** Una huella SHA-256: corta en pantalla y entera al pasar el ratón o con el lector. */
export function HashText({
    hash,
    label,
    className,
}: {
    hash: string | null | undefined;
    label?: string;
    className?: string;
}) {
    if (!hash) {
        return null;
    }

    return (
        <span
            className={cn(
                'inline-flex max-w-full items-center gap-1 font-mono text-xs text-muted-foreground',
                className,
            )}
            title={hash}
            data-test="hash"
        >
            <Fingerprint aria-hidden="true" className="size-3.5 shrink-0" />
            <span className="sr-only">
                {label ?? t('people.hash.label')}: {hash}
            </span>
            <span aria-hidden="true" className="truncate">
                {shortHash(hash)}
            </span>
        </span>
    );
}

/** Barra de las horas extra del año frente al tope de 80 h (art. 35.2 ET). */
export function CapBar({ summary }: { summary: YearSummary }) {
    const percent = capPercent(summary.overtime_minutes, summary.cap_minutes);

    return (
        <div
            className="grid gap-1.5"
            data-test="cap-bar"
            data-level={summary.level}
        >
            <div className="flex flex-wrap items-baseline justify-between gap-2 text-sm">
                <span>
                    {t('people.overtime.cap_label', {
                        used: formatMinutes(summary.overtime_minutes),
                        cap: formatMinutes(summary.cap_minutes),
                        year: summary.year,
                    })}
                </span>
                <span
                    className={cn(
                        'text-xs',
                        summary.level === 'over' && 'text-danger',
                        summary.level === 'near' && 'text-warning',
                        summary.level === 'ok' && 'text-muted-foreground',
                    )}
                >
                    {t(`people.overtime.cap_level.${summary.level}`)}
                </span>
            </div>
            <div
                className="h-2 overflow-hidden rounded-md bg-muted"
                role="progressbar"
                aria-label={t('people.overtime.cap_aria')}
                aria-valuemin={0}
                aria-valuemax={summary.cap_minutes}
                aria-valuenow={summary.overtime_minutes}
                aria-valuetext={t('people.overtime.cap_label', {
                    used: formatMinutes(summary.overtime_minutes),
                    cap: formatMinutes(summary.cap_minutes),
                    year: summary.year,
                })}
            >
                <div
                    className={cn('h-full', CAP_TONE[summary.level])}
                    style={{ width: `${percent}%` }}
                />
            </div>
        </div>
    );
}

/**
 * El resumen del mes que espera la respuesta de la persona (W-084): las cifras, el PDF con su
 * huella y «Confirmar el mes» o «No estoy de acuerdo» (con su motivo).
 */
export function CloseAnswerCard({ close }: { close: MonthClose }) {
    const [processing, setProcessing] = useState(false);
    const [disagreeing, setDisagreeing] = useState(false);
    const totals = close.totals;

    return (
        <section
            aria-labelledby={`close-${close.id}-title`}
            className="grid gap-4 rounded-md border border-info p-4"
            data-test="close-answer"
            data-month={close.month}
        >
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0 space-y-1">
                    <h2
                        id={`close-${close.id}-title`}
                        className="text-lg first-letter:uppercase"
                    >
                        {t('people.closes.answer_title', {
                            month: monthLabel(close.month),
                        })}
                    </h2>
                    <p className="max-w-2xl text-sm text-muted-foreground">
                        {close.status === 'disagreed'
                            ? t('people.closes.answer_disagreed', {
                                  date: formatDate(close.disagreed_at),
                              })
                            : t('people.closes.answer_hint')}
                    </p>
                </div>
                <CloseStatusBadge status={close.status} />
            </div>

            <dl className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                <Figure
                    label={t('people.workday.worked')}
                    value={formatMinutes(close.worked_minutes)}
                    hint={t('people.workday.of_expected', {
                        expected: formatMinutes(close.expected_minutes),
                    })}
                />
                <Figure
                    label={t('people.workday.difference')}
                    value={formatDifference(close.difference_minutes)}
                    tone={close.difference_minutes < 0 ? 'negative' : undefined}
                />
                <Figure
                    label={t('people.closes.overtime')}
                    value={formatMinutes(
                        totals.overtime_minutes + totals.complementary_minutes,
                    )}
                    hint={
                        totals.unclassified_minutes > 0
                            ? t('people.closes.unclassified_hint', {
                                  minutes: formatMinutes(
                                      totals.unclassified_minutes,
                                  ),
                              })
                            : undefined
                    }
                />
                <Figure
                    label={t('people.closes.incident_days')}
                    value={String(totals.incident_days)}
                />
            </dl>

            {close.disagreement_note ? (
                <p className="rounded-md bg-warning-soft p-3 text-sm">
                    <span className="text-muted-foreground">
                        {t('people.closes.your_note')}{' '}
                    </span>
                    {close.disagreement_note}
                </p>
            ) : null}

            <div className="flex flex-wrap items-center gap-2">
                {close.can.confirm ? (
                    <Button
                        type="button"
                        disabled={processing}
                        onClick={() =>
                            router.post(
                                confirm.url(close.id),
                                {},
                                {
                                    preserveScroll: true,
                                    onStart: () => setProcessing(true),
                                    onFinish: () => setProcessing(false),
                                },
                            )
                        }
                        data-test="close-confirm"
                    >
                        {processing ? (
                            <Spinner />
                        ) : (
                            <Check aria-hidden="true" />
                        )}
                        {t('people.closes.confirm')}
                    </Button>
                ) : null}
                {close.can.disagree ? (
                    <Button
                        type="button"
                        variant="outline"
                        disabled={processing}
                        onClick={() => setDisagreeing(true)}
                        data-test="close-disagree"
                    >
                        <MessageSquareWarning aria-hidden="true" />
                        {t('people.closes.disagree')}
                    </Button>
                ) : null}
                <Button asChild variant="ghost">
                    <a href={pdf.url(close.id)} data-test="close-pdf">
                        <FileDown aria-hidden="true" />
                        {t('people.closes.download_pdf')}
                    </a>
                </Button>
            </div>

            <p className="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
                <span>
                    {t('people.closes.version_generated', {
                        version: close.version,
                        date: formatDateTime(close.generated_at),
                    })}
                </span>
                <HashText
                    hash={close.pdf_sha256}
                    label={t('people.closes.pdf_hash')}
                />
            </p>

            <DisagreeDialog
                close={close}
                open={disagreeing}
                onOpenChange={setDisagreeing}
            />
        </section>
    );
}

function DisagreeDialog({
    close,
    open,
    onOpenChange,
}: {
    close: MonthClose;
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
                            disagree.url(close.id),
                            { note },
                            {
                                preserveScroll: true,
                                onStart: () => setProcessing(true),
                                onFinish: () => setProcessing(false),
                                onError: (errors) =>
                                    setError(errors.note ?? errors.close),
                                onSuccess: () => onOpenChange(false),
                            },
                        );
                    }}
                >
                    <DialogTitle>
                        {t('people.closes.disagree_title')}
                    </DialogTitle>
                    <DialogDescription>
                        {t('people.closes.disagree_description')}
                    </DialogDescription>
                    <div className="grid gap-2">
                        <Label htmlFor={`${id}-note`}>
                            {t('people.closes.disagree_note')}
                        </Label>
                        <Textarea
                            id={`${id}-note`}
                            value={note}
                            onChange={(event) => setNote(event.target.value)}
                            maxLength={1000}
                            required
                            aria-invalid={error ? true : undefined}
                            data-test="close-disagree-note"
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
                            disabled={processing || note.trim().length < 5}
                            data-test="close-disagree-submit"
                        >
                            {processing ? <Spinner /> : null}
                            {t('people.closes.disagree_submit')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/** Lo que queda por disfrutar del saldo, con su fecha límite (4 meses). */
export function PendingCompensationList({
    pending,
}: {
    pending: PendingCompensation[];
}) {
    if (pending.length === 0) {
        return (
            <p className="text-sm text-muted-foreground">
                {t('people.balance.nothing_pending')}
            </p>
        );
    }

    return (
        <ul className="grid gap-1.5 text-sm" data-test="balance-pending">
            {pending.map((credit) => (
                <li
                    key={`${credit.date}-${credit.kind}-${credit.minutes}`}
                    className={cn(
                        'flex flex-wrap items-center justify-between gap-2 rounded-md px-2 py-1',
                        credit.expired && 'bg-warning-soft',
                    )}
                >
                    <span className="tabular">
                        {t('people.balance.pending_item', {
                            minutes: formatMinutes(credit.remaining_minutes),
                            date: formatDate(credit.date),
                        })}
                    </span>
                    <span
                        className={cn(
                            'text-xs',
                            credit.expired
                                ? 'text-foreground'
                                : 'text-muted-foreground',
                        )}
                    >
                        {credit.expired
                            ? t('people.balance.expired', {
                                  date: formatDate(credit.deadline),
                              })
                            : t('people.balance.deadline', {
                                  date: formatDate(credit.deadline),
                              })}
                    </span>
                </li>
            ))}
        </ul>
    );
}

/** Movimientos del saldo de horas, los últimos primero. */
export function BalanceMovementList({
    movements,
}: {
    movements: BalanceMovement[];
}) {
    if (movements.length === 0) {
        return (
            <p className="text-sm text-muted-foreground">
                {t('people.balance.no_movements')}
            </p>
        );
    }

    return (
        <ul
            className="divide-y rounded-md border text-sm"
            data-test="balance-movements"
        >
            {movements.map((movement) => (
                <li
                    key={movement.id}
                    className="grid gap-0.5 px-3 py-2 sm:grid-cols-[7rem_1fr_auto] sm:items-baseline sm:gap-3"
                >
                    <span className="tabular text-xs text-muted-foreground">
                        {formatDate(movement.date)}
                    </span>
                    <span className="min-w-0">
                        <span className="block">
                            {balanceKindLabel(movement.kind)}
                        </span>
                        <span className="block text-xs break-words text-muted-foreground">
                            {movement.reason}
                            {movement.author ? ` · ${movement.author}` : ''}
                        </span>
                    </span>
                    <span
                        className={cn(
                            'tabular sm:text-right',
                            movement.minutes < 0 && 'text-danger',
                        )}
                    >
                        {signedMinutes(movement.minutes)}
                    </span>
                </li>
            ))}
        </ul>
    );
}
