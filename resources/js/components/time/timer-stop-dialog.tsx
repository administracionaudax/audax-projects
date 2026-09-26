import { usePage } from '@inertiajs/react';
import { CircleAlert } from 'lucide-react';
import { useId, useState } from 'react';
import type { FormEvent } from 'react';
import { DurationInput } from '@/components/domain/duration-input';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { MAX_MINUTES, roundToNearest } from '@/lib/duration';
import { formatMinutes } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { ActiveTimer } from '@/types';
import { TaskPicker } from './task-picker';
import type { PickedTask } from './task-picker';
import {
    discardTimer,
    stopTimer,
    timerStopDialog,
    useTimerStopDialog,
} from './timer-actions';
import { elapsedSeconds } from './use-elapsed';

/**
 * Diálogo que se abre si al parar el temporizador la imputación no es válida (D-035): el
 * temporizador sigue en marcha y se puede ajustar la duración, imputar en otra tarea o
 * descartarlo, sin perder lo medido. Vive en la cabecera: una sola instancia por página.
 */
export function TimerStopDialog() {
    const { open, errors } = useTimerStopDialog();
    const { timer = null, config } = usePage().props;
    const visible = open && timer !== null;

    return (
        <Dialog
            open={visible}
            onOpenChange={(next) => {
                if (!next) {
                    timerStopDialog.close();
                }
            }}
        >
            <DialogContent className="max-h-[calc(100dvh-2rem)] overflow-y-auto">
                {visible && timer ? (
                    <StopForm
                        timer={timer}
                        errors={errors}
                        rounding={config?.timer_rounding_minutes ?? 1}
                    />
                ) : null}
            </DialogContent>
        </Dialog>
    );
}

/**
 * Minutos medidos por el temporizador, redondeados como en el servidor (timer_rounding_minutes).
 */
export function measuredMinutes(
    startedAt: string,
    rounding: number,
    now: number = Date.now(),
): number {
    return roundToNearest(
        Math.round(elapsedSeconds(startedAt, now) / 60),
        Math.max(rounding, 1),
    );
}

function StopForm({
    timer,
    errors,
    rounding,
}: {
    timer: ActiveTimer;
    errors: Record<string, string>;
    rounding: number;
}) {
    const id = useId();
    const [measured] = useState(() =>
        measuredMinutes(timer.started_at, rounding),
    );
    // Lo que muestra el campo al abrir: lo medido, sin pasar del máximo de una entrada.
    const [initial] = useState(() => Math.min(measured, MAX_MINUTES));
    const [minutes, setMinutes] = useState<number | null>(initial);
    const [task, setTask] = useState<PickedTask | null>({
        id: timer.task_id,
        title: timer.task_title,
        project_id: timer.project_id,
        project: {
            id: timer.project_id,
            code: timer.project_code,
            name: timer.project_name,
            color: '',
            is_internal: false,
        },
    });
    const [processing, setProcessing] = useState(false);
    const messages = [...new Set(Object.values(errors))];

    const callbacks = {
        onStart: () => setProcessing(true),
        onFinish: () => setProcessing(false),
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (minutes === null) {
            return;
        }

        // Si no se toca la duración, el servidor imputa lo medido hasta ahora, repartido por días y
        // redondeado (D-036); solo una duración cambiada va como una única entrada.
        stopTimer({
            ...callbacks,
            minutes: minutes === initial ? null : minutes,
            taskId: task?.id ?? null,
        });
    };

    return (
        <form onSubmit={submit} className="grid gap-5" noValidate>
            <DialogHeader>
                <DialogTitle>{t('hours.timer.stop_failed_title')}</DialogTitle>
                <DialogDescription>
                    {t('hours.timer.stop_failed_description')}
                </DialogDescription>
            </DialogHeader>

            {messages.length > 0 ? (
                <Alert variant="destructive">
                    <CircleAlert aria-hidden="true" />
                    <AlertTitle>
                        {t('hours.timer.stop_failed_reason')}
                    </AlertTitle>
                    <AlertDescription>
                        <ul className="list-disc space-y-1 pl-4">
                            {messages.map((message) => (
                                <li key={message}>{message}</li>
                            ))}
                        </ul>
                    </AlertDescription>
                </Alert>
            ) : null}

            <div className="grid gap-2">
                <Label htmlFor={`${id}-minutes`}>
                    {t('hours.dialog.duration')}
                </Label>
                <DurationInput
                    id={`${id}-minutes`}
                    value={minutes}
                    onChange={setMinutes}
                    aria-describedby={`${id}-minutes-hint`}
                    autoFocus
                />
                <p
                    id={`${id}-minutes-hint`}
                    className="text-sm text-muted-foreground"
                >
                    {t('hours.timer.stop_measured_hint', {
                        duration: formatMinutes(measured),
                    })}
                </p>
            </div>

            <div className="grid gap-2">
                <Label htmlFor={`${id}-task`}>{t('hours.dialog.task')}</Label>
                <TaskPicker id={`${id}-task`} value={task} onChange={setTask} />
            </div>

            <DialogFooter className="gap-2 sm:justify-between">
                <Button
                    type="button"
                    variant="ghost"
                    disabled={processing}
                    onClick={() => discardTimer(callbacks)}
                >
                    {t('hours.timer.discard')}
                </Button>
                <div className="flex flex-col-reverse gap-2 sm:flex-row">
                    <Button
                        type="button"
                        variant="secondary"
                        disabled={processing}
                        onClick={() => timerStopDialog.close()}
                    >
                        {t('hours.timer.keep_running')}
                    </Button>
                    <Button
                        type="submit"
                        disabled={processing || minutes === null}
                    >
                        {processing ? <Spinner /> : null}
                        {t('hours.timer.log_and_stop')}
                    </Button>
                </div>
            </DialogFooter>
        </form>
    );
}
