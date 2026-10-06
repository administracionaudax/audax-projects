import { router } from '@inertiajs/react';
import { toastVisitErrors } from '@/components/admin/visit-errors';
import { useId, useState } from 'react';
import type { FormEvent } from 'react';
import { DurationInput } from '@/components/domain/duration-input';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { carryOptions, dayLabel } from '@/lib/day-plan';
import { t } from '@/lib/i18n';
import { carry, status, update } from '@/routes/day-plan/items';
import { TargetPicker } from './target-picker';
import type { TargetValue } from './target-picker';
import type { DayPlanLine, DayPlanTargets } from '@/types/day-plan';

const VISIT = {
    preserveScroll: true,
    preserveState: true,
    errorBag: 'dayPlan',
    // Que ningún error del servidor se pierda en silencio (D-310).
    onError: toastVisitErrors,
} as const;

function firstError(errors: Record<string, string>): string {
    return Object.values(errors).filter(Boolean).join(' ');
}

/** Editar el texto, el cliente o proyecto y las horas previstas de una línea (hoy o días que vienen). */
export function EditLineDialog({
    line,
    targets,
    open,
    onOpenChange,
}: {
    line: DayPlanLine;
    targets: DayPlanTargets | undefined;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                {open ? (
                    <EditLineForm
                        line={line}
                        targets={targets}
                        onDone={() => onOpenChange(false)}
                    />
                ) : null}
            </DialogContent>
        </Dialog>
    );
}

function EditLineForm({
    line,
    targets,
    onDone,
}: {
    line: DayPlanLine;
    targets: DayPlanTargets | undefined;
    onDone: () => void;
}) {
    const id = useId();
    const [text, setText] = useState(line.text);
    const [target, setTarget] = useState<TargetValue>({
        client_id: line.client?.id ?? null,
        project_id: line.project?.id ?? null,
    });
    // Horas con el campo de duración de toda la app (vista previa «= 1:30» y no válido marcado).
    const [planned, setPlanned] = useState<number | null>(
        line.planned_minutes ?? null,
    );
    const [plannedText, setPlannedText] = useState(false);
    // Cada error junto a su campo (antes, todos juntos al pie). D-310.
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);
    const current = line.project
        ? `${line.project.code} · ${line.project.name}`
        : (line.client?.name ?? null);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const minutes = planned;

        if (text.trim() === '') {
            setErrors({ text: t('day_plan.composer.errors.text') });

            return;
        }

        if (plannedText && minutes === null) {
            setErrors({
                planned_minutes: t('day_plan.composer.errors.duration'),
            });

            return;
        }

        const sameTarget =
            target.project_id === (line.project?.id ?? null) &&
            target.client_id === (line.client?.id ?? null);

        router.patch(
            update.url(line.id),
            {
                text: text.trim(),
                planned_minutes: minutes,
                ...(sameTarget
                    ? {}
                    : {
                          client_id: target.project_id
                              ? null
                              : target.client_id,
                          project_id: target.project_id,
                          task_id: null,
                      }),
            },
            {
                ...VISIT,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onSuccess: onDone,
                onError: (next) => setErrors(next),
            },
        );
    };
    const targetError = errors.project_id ?? errors.client_id ?? errors.task_id;
    const otherError = Object.entries(errors).find(
        ([key]) =>
            ![
                'text',
                'planned_minutes',
                'project_id',
                'client_id',
                'task_id',
            ].includes(key),
    )?.[1];

    return (
        <form onSubmit={submit} className="grid gap-4" noValidate>
            <DialogTitle>{t('day_plan.edit.title')}</DialogTitle>
            <DialogDescription>
                {t('day_plan.edit.description')}
            </DialogDescription>
            <div className="grid gap-1.5">
                <Label htmlFor={`${id}-text`}>{t('day_plan.edit.text')}</Label>
                <Input
                    id={`${id}-text`}
                    value={text}
                    maxLength={200}
                    onChange={(event) => setText(event.target.value)}
                    aria-invalid={errors.text ? true : undefined}
                    data-test="day-plan-edit-text"
                />
                <InputError message={errors.text} />
            </div>
            <div className="grid gap-1.5">
                <Label htmlFor={`${id}-target`}>
                    {t('day_plan.edit.target')}
                </Label>
                <TargetPicker
                    id={`${id}-target`}
                    value={target}
                    targets={targets}
                    current={current}
                    onChange={setTarget}
                />
                <InputError message={targetError} />
                {line.task ? (
                    <p className="text-xs text-muted-foreground">
                        {t('day_plan.edit.task_hint', {
                            task: line.task.title,
                        })}
                    </p>
                ) : null}
            </div>
            <div className="grid gap-1.5">
                <Label htmlFor={`${id}-planned`}>
                    {t('day_plan.edit.planned')}
                </Label>
                <DurationInput
                    id={`${id}-planned`}
                    value={planned}
                    max={1440}
                    placeholder="1:30"
                    className="w-40"
                    invalid={Boolean(errors.planned_minutes)}
                    onChange={(minutes) => setPlanned(minutes)}
                    onTextChange={(value) =>
                        setPlannedText(value.trim() !== '')
                    }
                />
                <InputError message={errors.planned_minutes} />
            </div>
            <InputError message={otherError} />
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
                    type="submit"
                    disabled={processing}
                    data-test="day-plan-edit-save"
                >
                    {processing ? <Spinner /> : null}
                    {t('common.save')}
                </Button>
            </DialogFooter>
        </form>
    );
}

/** «Pasar a otro día»: hoy, mañana o cualquier día hasta el domingo de la semana que viene. */
export function CarryDialog({
    line,
    today,
    horizonEnd,
    open,
    onOpenChange,
}: {
    line: DayPlanLine;
    today: string;
    horizonEnd: string;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const [processing, setProcessing] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const options = carryOptions(line.date, today, horizonEnd);

    const move = (date: string) => {
        router.post(
            carry.url(line.id),
            { date },
            {
                ...VISIT,
                onStart: () => setProcessing(date),
                onFinish: () => setProcessing(null),
                onSuccess: () => onOpenChange(false),
                onError: (errors) => setError(firstError(errors)),
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogTitle>{t('day_plan.carry_dialog.title')}</DialogTitle>
                <DialogDescription>
                    {t('day_plan.carry_dialog.description', {
                        text: line.text,
                    })}
                </DialogDescription>
                <ul
                    className="grid max-h-80 gap-1 overflow-y-auto"
                    data-test="day-plan-carry-options"
                >
                    {options.map((date) => (
                        <li key={date}>
                            <Button
                                type="button"
                                variant="outline"
                                className="w-full justify-start font-normal capitalize"
                                disabled={processing !== null}
                                onClick={() => move(date)}
                            >
                                {processing === date ? <Spinner /> : null}
                                {dayLabel(date, today)}
                            </Button>
                        </li>
                    ))}
                </ul>
                <InputError message={error ?? undefined} />
            </DialogContent>
        </Dialog>
    );
}

/** «Marcar como no hecha», con un motivo opcional. */
export function NotDoneDialog({
    line,
    open,
    onOpenChange,
}: {
    line: DayPlanLine;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const id = useId();
    const [reason, setReason] = useState(line.not_done_reason ?? '');
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        router.post(
            status.url(line.id),
            { status: 'not_done', reason: reason.trim() || null },
            {
                ...VISIT,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onSuccess: () => onOpenChange(false),
                onError: (errors) => setError(firstError(errors)),
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <form onSubmit={submit} className="grid gap-4">
                    <DialogTitle>{t('day_plan.not_done.title')}</DialogTitle>
                    <DialogDescription>{line.text}</DialogDescription>
                    <div className="grid gap-1.5">
                        <Label htmlFor={`${id}-reason`}>
                            {t('day_plan.not_done.reason')}
                        </Label>
                        <Textarea
                            id={`${id}-reason`}
                            value={reason}
                            maxLength={200}
                            rows={2}
                            placeholder={t('day_plan.not_done.placeholder')}
                            onChange={(event) => setReason(event.target.value)}
                        />
                    </div>
                    <InputError message={error ?? undefined} />
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
                            type="submit"
                            disabled={processing}
                            data-test="day-plan-not-done-save"
                        >
                            {processing ? <Spinner /> : null}
                            {t('day_plan.not_done.confirm')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/**
 * Al marcar hecha una línea con tarea abierta: «¿Marcar también la tarea como hecha?» (§4.4). Nunca
 * en silencio: «Solo la línea» o «También la tarea».
 */
export function CompleteTaskDialog({
    line,
    open,
    onOpenChange,
}: {
    line: DayPlanLine;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const [processing, setProcessing] = useState(false);

    const done = (completeTask: boolean) =>
        router.post(
            status.url(line.id),
            { status: 'done', complete_task: completeTask },
            {
                ...VISIT,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onSuccess: () => onOpenChange(false),
            },
        );

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogTitle>{t('day_plan.complete_task.title')}</DialogTitle>
                <DialogDescription>
                    {t('day_plan.complete_task.description', {
                        task: line.task?.title ?? '',
                    })}
                </DialogDescription>
                <DialogFooter className="gap-2">
                    <Button
                        type="button"
                        variant="secondary"
                        disabled={processing}
                        onClick={() => done(false)}
                        data-test="day-plan-complete-line-only"
                    >
                        {t('day_plan.complete_task.line_only')}
                    </Button>
                    <Button
                        type="button"
                        disabled={processing}
                        onClick={() => done(true)}
                        data-test="day-plan-complete-task"
                    >
                        {processing ? <Spinner /> : null}
                        {t('day_plan.complete_task.both')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
