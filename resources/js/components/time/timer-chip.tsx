import { Link } from '@inertiajs/react';
import {
    EllipsisVertical,
    ExternalLink,
    Square,
    Trash2,
    TriangleAlert,
} from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Spinner } from '@/components/ui/spinner';
import { FOCUS_RING } from '@/lib/focus-ring';
import { t } from '@/lib/i18n';
import { urls } from '@/lib/urls';
import { cn } from '@/lib/utils';
import type { ActiveTimer } from '@/types';
import { discardTimer, stopTimer } from './timer-actions';
import { formatElapsed, useElapsedSeconds } from './use-elapsed';

/** "1 h 5 min" para lectores de pantalla. */
export function spokenDuration(minutes: number): string {
    const hours = Math.floor(minutes / 60);
    const rest = minutes % 60;

    if (hours === 0) {
        return t('hours.timer.spoken_minutes', { minutes: rest });
    }

    return t('hours.timer.spoken_hours', { hours, minutes: rest });
}

/**
 * Temporizador activo en la cabecera (SPEC §7): tarea y proyecto, tiempo transcurrido en vivo
 * (h:mm:ss, cada segundo; los lectores de pantalla solo oyen el cambio de minuto), parar y un
 * menú para descartarlo o ir a la tarea. Aviso visual si supera timer_warning_hours.
 * En móvil muestra solo el tiempo, parar y el menú (que incluye la tarea).
 */
export function TimerChip({
    timer,
    warningHours,
}: {
    timer: ActiveTimer;
    warningHours: number;
}) {
    const seconds = useElapsedSeconds(timer.started_at);
    const minutes = Math.floor(seconds / 60);
    const long = warningHours > 0 && seconds >= warningHours * 3600;
    const [processing, setProcessing] = useState(false);
    const [confirmDiscard, setConfirmDiscard] = useState(false);

    const callbacks = {
        onStart: () => setProcessing(true),
        onFinish: () => setProcessing(false),
    };

    return (
        <div
            role="group"
            aria-label={t('hours.timer.label')}
            data-test="timer-chip"
            data-warning={long || undefined}
            className={cn(
                'flex h-9 min-w-0 items-center gap-1 rounded-md border pr-0.5 pl-2 text-sm',
                long ? 'border-warning bg-warning-soft' : 'bg-background',
            )}
        >
            {long ? (
                <TriangleAlert
                    aria-hidden="true"
                    className="size-4 shrink-0 text-warning"
                />
            ) : (
                <span
                    aria-hidden="true"
                    className="size-2 shrink-0 rounded-full bg-success motion-safe:animate-pulse"
                />
            )}
            <Link
                href={urls.task(timer.project_id, timer.task_id)}
                className={cn(
                    'hidden max-w-[14rem] min-w-0 truncate rounded-sm hover:underline lg:block',
                    FOCUS_RING,
                )}
                title={`${timer.project_code} · ${timer.task_title}`}
            >
                <span className="text-muted-foreground">
                    {timer.project_code}
                </span>{' '}
                {timer.task_title}
            </Link>
            <time
                className="tabular px-1 font-medium"
                dateTime={`PT${seconds}S`}
                aria-hidden="true"
                data-test="timer-elapsed"
            >
                {formatElapsed(seconds)}
            </time>
            <span className="sr-only" aria-live="polite" aria-atomic="true">
                {t('hours.timer.announce', {
                    duration: spokenDuration(minutes),
                    task: timer.task_title,
                })}
                {long
                    ? ` ${t('hours.timer.long', { hours: warningHours })}`
                    : ''}
            </span>
            {long ? (
                <span className="hidden text-xs text-foreground xl:inline">
                    {t('hours.timer.long_short', { hours: warningHours })}
                </span>
            ) : null}
            <Button
                type="button"
                size="icon"
                variant="ghost"
                className="size-8"
                disabled={processing}
                onClick={() => stopTimer(callbacks)}
                aria-label={t('timer.stop', { task: timer.task_title })}
                title={t('timer.stop', { task: timer.task_title })}
            >
                {processing ? (
                    <Spinner
                        aria-hidden="true"
                        role={undefined}
                        aria-label={undefined}
                    />
                ) : (
                    <Square aria-hidden="true" className="fill-current" />
                )}
            </Button>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button
                        type="button"
                        size="icon"
                        variant="ghost"
                        className="size-8"
                        aria-label={t('hours.timer.menu')}
                    >
                        <EllipsisVertical aria-hidden="true" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-64">
                    <DropdownMenuLabel className="font-normal">
                        <span className="block text-xs text-muted-foreground">
                            {timer.project_code} · {timer.project_name}
                        </span>
                        <span className="block truncate">
                            {timer.task_title}
                        </span>
                    </DropdownMenuLabel>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem asChild>
                        <Link href={urls.task(timer.project_id, timer.task_id)}>
                            <ExternalLink aria-hidden="true" />
                            {t('hours.timer.go_to_task')}
                        </Link>
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        onSelect={() => setConfirmDiscard(true)}
                        className="text-destructive-foreground"
                    >
                        <Trash2 aria-hidden="true" />
                        {t('hours.timer.discard')}
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>

            <Dialog open={confirmDiscard} onOpenChange={setConfirmDiscard}>
                <DialogContent>
                    <DialogTitle>{t('hours.timer.discard_title')}</DialogTitle>
                    <DialogDescription>
                        {t('hours.timer.discard_description', {
                            duration: formatElapsed(seconds),
                            task: timer.task_title,
                        })}
                    </DialogDescription>
                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button variant="secondary">
                                {t('common.cancel')}
                            </Button>
                        </DialogClose>
                        <Button
                            variant="destructive"
                            disabled={processing}
                            onClick={() =>
                                discardTimer({
                                    ...callbacks,
                                    onSuccess: () => setConfirmDiscard(false),
                                })
                            }
                        >
                            {t('hours.timer.discard')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}
