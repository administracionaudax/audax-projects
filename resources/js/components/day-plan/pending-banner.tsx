import { router } from '@inertiajs/react';
import { toastVisitErrors } from '@/components/admin/visit-errors';
import { CalendarArrowUp, TriangleAlert } from 'lucide-react';
import { useId, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import { carryLabel, dayLabel, pendingLabel } from '@/lib/day-plan';
import { t } from '@/lib/i18n';
import { carry, notDone } from '@/routes/day-plan/pending';
import type { DayPlanPendingLine } from '@/types/day-plan';

const VISIT = {
    preserveScroll: true,
    preserveState: true,
    errorBag: 'dayPlan',
    // Que ningún error del servidor se pierda en silencio (D-310).
    onError: toastVisitErrors,
} as const;

/**
 * «Tienes 3 pendientes del lunes» (docs/PLAN-CARGAS.md §4.2 y P3): pasar todas a hoy de un clic,
 * elegirlas una a una o marcarlas como no hechas. Nunca se pasan solas.
 */
export function PendingBanner({
    pending,
    today,
}: {
    pending: DayPlanPendingLine[];
    today: string;
}) {
    const [processing, setProcessing] = useState<'carry' | 'not_done' | null>(
        null,
    );
    const [choosing, setChoosing] = useState(false);
    const ids = pending.map((line) => line.id);

    const send = (
        action: 'carry' | 'not_done',
        selected: number[],
        onSuccess?: () => void,
    ) =>
        router.post(
            action === 'carry' ? carry.url() : notDone.url(),
            action === 'carry'
                ? { ids: selected, date: today }
                : { ids: selected },
            {
                ...VISIT,
                onStart: () => setProcessing(action),
                onFinish: () => setProcessing(null),
                onSuccess,
            },
        );

    if (pending.length === 0) {
        return null;
    }

    return (
        <section
            aria-label={t('day_plan.pending.label')}
            className="flex flex-col gap-3 border border-warning bg-warning-soft p-3 sm:flex-row sm:items-center"
            data-test="day-plan-pending"
        >
            <p className="flex flex-1 items-start gap-2 text-sm">
                <TriangleAlert
                    aria-hidden="true"
                    className="mt-0.5 size-4 shrink-0 text-warning"
                />
                {pendingLabel(pending, today)}
            </p>
            <div className="flex flex-wrap gap-2">
                <Button
                    type="button"
                    size="sm"
                    disabled={processing !== null}
                    onClick={() => send('carry', ids)}
                    data-test="day-plan-pending-carry-all"
                >
                    {processing === 'carry' ? (
                        <Spinner />
                    ) : (
                        <CalendarArrowUp aria-hidden="true" />
                    )}
                    {t('day_plan.pending.carry_all')}
                </Button>
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    disabled={processing !== null}
                    onClick={() => setChoosing(true)}
                    data-test="day-plan-pending-choose"
                >
                    {t('day_plan.pending.choose')}
                </Button>
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    disabled={processing !== null}
                    onClick={() => send('not_done', ids)}
                    data-test="day-plan-pending-not-done"
                >
                    {processing === 'not_done' ? <Spinner /> : null}
                    {t('day_plan.pending.not_done_all')}
                </Button>
            </div>
            {choosing ? (
                <ChoosePendingDialog
                    pending={pending}
                    today={today}
                    processing={processing !== null}
                    onOpenChange={setChoosing}
                    onSubmit={(action, selected) =>
                        send(action, selected, () => setChoosing(false))
                    }
                />
            ) : null}
        </section>
    );
}

function ChoosePendingDialog({
    pending,
    today,
    processing,
    onOpenChange,
    onSubmit,
}: {
    pending: DayPlanPendingLine[];
    today: string;
    processing: boolean;
    onOpenChange: (open: boolean) => void;
    onSubmit: (action: 'carry' | 'not_done', ids: number[]) => void;
}) {
    const id = useId();
    const [selected, setSelected] = useState<number[]>(
        pending.map((line) => line.id),
    );

    const toggle = (lineId: number, checked: boolean) =>
        setSelected((current) =>
            checked
                ? [...current, lineId]
                : current.filter((value) => value !== lineId),
        );

    return (
        <Dialog open onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <DialogTitle>{t('day_plan.pending.choose_title')}</DialogTitle>
                <DialogDescription>
                    {t('day_plan.pending.choose_description')}
                </DialogDescription>
                <ul className="grid max-h-80 gap-2 overflow-y-auto">
                    {pending.map((line) => (
                        <li key={line.id} className="flex items-start gap-2">
                            <Checkbox
                                id={`${id}-${line.id}`}
                                checked={selected.includes(line.id)}
                                onCheckedChange={(checked) =>
                                    toggle(line.id, checked === true)
                                }
                                className="mt-0.5"
                            />
                            <Label
                                htmlFor={`${id}-${line.id}`}
                                className="grid gap-0.5 font-normal"
                            >
                                <span>{line.text}</span>
                                <span className="text-xs text-muted-foreground">
                                    <span className="capitalize">
                                        {dayLabel(line.date, today)}
                                    </span>
                                    {(line.project ?? line.client)
                                        ? ` · ${line.project ?? line.client}`
                                        : ''}
                                    {line.carry_count > 0
                                        ? ` · ${carryLabel(line.carry_count)}`
                                        : ''}
                                </span>
                            </Label>
                        </li>
                    ))}
                </ul>
                <DialogFooter className="gap-2">
                    <DialogClose asChild>
                        <Button
                            type="button"
                            variant="secondary"
                            disabled={processing}
                        >
                            {t('common.cancel')}
                        </Button>
                    </DialogClose>
                    <Button
                        type="button"
                        variant="outline"
                        disabled={processing || selected.length === 0}
                        onClick={() => onSubmit('not_done', selected)}
                    >
                        {t('day_plan.pending.not_done_selected', {
                            count: selected.length,
                        })}
                    </Button>
                    <Button
                        type="button"
                        disabled={processing || selected.length === 0}
                        onClick={() => onSubmit('carry', selected)}
                        data-test="day-plan-pending-carry-selected"
                    >
                        {processing ? <Spinner /> : null}
                        {t('day_plan.pending.carry_selected', {
                            count: selected.length,
                        })}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
