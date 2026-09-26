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
import { MAX_MINUTES } from '@/lib/duration';
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
    const timer = usePage().props.timer ?? null;
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
                    <StopForm timer={timer} errors={errors} />
                ) : null}
            </DialogContent>
        </Dialog>
    );
}

function StopForm({
    timer,
    errors,
}: {
    timer: ActiveTimer;
    errors: Record<string, string>;
}) {
    const id = useId();
    const [minutes, setMinutes] = useState<number | null>(() =>
        Math.min(
            Math.max(Math.round(elapsedSeconds(timer.started_at) / 60), 1),
            MAX_MINUTES,
        ),
    );
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

        stopTimer({ ...callbacks, minutes, taskId: task?.id ?? null });
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
                    autoFocus
                />
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
