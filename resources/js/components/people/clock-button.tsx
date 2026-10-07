import { Link, usePage } from '@inertiajs/react';
import {
    CalendarClock,
    ChevronDown,
    LogIn,
    LogOut,
    TriangleAlert,
    Utensils,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';
import { startTimer, stopTimer } from '@/components/time/timer-actions';
import { Button } from '@/components/ui/button';
import {
    Dialog,
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
import { formatDate, formatMinutes, formatTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import { liveWorkedSeconds, modeLabel } from '@/lib/people';
import { cn } from '@/lib/utils';
import { index as workdayIndex } from '@/routes/people/workday';
import type { ActiveTimer } from '@/types';
import type { ClockKind, ClockShared, WorkMode } from '@/types/people';
import { punch, rememberTimer, takeRememberedTimer } from './clock-actions';

const MODES: WorkMode[] = ['on_site', 'remote'];

/** Reloj que avanza cada 15 s (la cabecera muestra h:mm, no los segundos). */
function useNow(active: boolean): number {
    const [now, setNow] = useState(() => Date.now());

    useEffect(() => {
        if (!active) {
            return;
        }

        const id = window.setInterval(() => setNow(Date.now()), 15_000);

        return () => window.clearInterval(id);
    }, [active]);

    return now;
}

/**
 * Botón de fichar de la cabecera, junto al temporizador (PLAN-FASE-11 §3.2 y §6.1; D-333 y D-340),
 * como el «Entrar / Salir» de Woffu:
 * - sin jornada: «Fichar entrada» (con el modo, presencial o a distancia, en el desplegable),
 * - trabajando: el tiempo de hoy, «Comida» y «Salir»,
 * - en la comida: desde cuándo, «Volver» y «Salir»,
 * - jornada cerrada: el total de hoy y, en el menú, volver a entrar (jornada partida).
 * Al empezar la comida o salir con el temporizador en marcha, pregunta si se para también (sí por
 * defecto) y, al volver de la comida, ofrece reanudarlo. La hora la pone siempre el servidor.
 */
export function ClockButton({ clock }: { clock: ClockShared }) {
    const { timer } = usePage().props;
    const [processing, setProcessing] = useState(false);
    const [confirm, setConfirm] = useState<ClockKind | null>(null);
    const now = useNow(clock.status !== 'off');
    // Diferencia entre el reloj del servidor y el del dispositivo al pintar la página.
    const offset = useMemo(
        () => new Date(clock.server_now).getTime() - Date.now(),
        [clock.server_now],
    );
    const worked = Math.floor(liveWorkedSeconds(clock, now, offset) / 60);
    const lastMode: WorkMode = clock.work_mode ?? 'on_site';

    const callbacks = {
        onStart: () => setProcessing(true),
        onFinish: () => setProcessing(false),
    };

    const doPunch = (
        kind: ClockKind,
        mode: WorkMode | null = null,
        stop: boolean = false,
    ) => {
        punch(kind, mode, {
            ...callbacks,
            onSuccess: () => {
                if (stop && timer) {
                    if (kind === 'pause_start') {
                        rememberTimer({
                            task_id: timer.task_id,
                            task_title: timer.task_title,
                        });
                    }

                    stopTimer();
                }

                if (kind === 'pause_end') {
                    offerResume();
                }
            },
        });
    };

    const request = (kind: ClockKind, mode: WorkMode | null = null) => {
        if ((kind === 'pause_start' || kind === 'clock_out') && timer) {
            setConfirm(kind);

            return;
        }

        doPunch(kind, mode);
    };

    const label = (() => {
        switch (clock.status) {
            case 'working':
                return t('people.clock.announce_working', {
                    duration: formatMinutes(worked),
                });
            case 'paused':
                return t('people.clock.announce_paused', {
                    time: formatTime(clock.since),
                });
            case 'closed':
                return t('people.clock.announce_closed', {
                    duration: formatMinutes(worked),
                });
            default:
                return t('people.clock.in');
        }
    })();

    return (
        <div
            role="group"
            aria-label={t('people.clock.label')}
            className="flex shrink-0 items-center"
            data-test="clock-button"
            data-status={clock.status}
        >
            <span className="sr-only" aria-live="polite" aria-atomic="true">
                {label}
            </span>

            {clock.status === 'off' ? (
                <div className="flex items-center">
                    <Button
                        type="button"
                        className="rounded-r-none px-2.5 sm:px-3"
                        disabled={processing}
                        onClick={() => request('clock_in', lastMode)}
                        aria-label={t('people.clock.in_mode', {
                            mode: modeLabel(lastMode),
                        })}
                        title={t('people.clock.server_time')}
                        data-test="clock-in"
                    >
                        {processing ? (
                            <Spinner
                                aria-hidden="true"
                                role={undefined}
                                aria-label={undefined}
                            />
                        ) : (
                            <LogIn aria-hidden="true" />
                        )}
                        <span className="hidden md:inline">
                            {t('people.clock.in_short')}
                        </span>
                    </Button>
                    <ClockMenu
                        clock={clock}
                        trigger="primary"
                        title={t('people.clock.mode_title')}
                        onMode={(mode) => request('clock_in', mode)}
                    />
                </div>
            ) : (
                <div
                    className={cn(
                        'flex h-9 min-w-0 items-center gap-1 rounded-md border pr-0.5 pl-2 text-sm',
                        clock.status === 'paused' && 'bg-warning-soft',
                        clock.status !== 'paused' && 'bg-background',
                    )}
                    data-test="clock-chip"
                >
                    {clock.status === 'working' ? (
                        <span
                            aria-hidden="true"
                            className="size-2 shrink-0 rounded-full bg-success motion-safe:animate-pulse"
                        />
                    ) : clock.status === 'paused' ? (
                        <Utensils
                            aria-hidden="true"
                            className="size-3.5 shrink-0"
                        />
                    ) : (
                        <CalendarClock
                            aria-hidden="true"
                            className="size-3.5 shrink-0 text-muted-foreground"
                        />
                    )}
                    <span
                        className="hidden text-muted-foreground lg:inline"
                        aria-hidden="true"
                    >
                        {clock.status === 'working'
                            ? t('people.clock.working')
                            : clock.status === 'paused'
                              ? t('people.clock.paused')
                              : t('people.clock.closed')}
                    </span>
                    <time
                        className="tabular px-1 font-medium"
                        aria-hidden="true"
                        data-test="clock-worked"
                    >
                        {clock.status === 'paused'
                            ? formatTime(clock.since)
                            : formatMinutes(worked)}
                    </time>

                    {clock.status === 'working' ? (
                        <>
                            <Button
                                type="button"
                                size="sm"
                                variant="ghost"
                                className="h-8 px-2"
                                disabled={processing}
                                onClick={() => request('pause_start')}
                                aria-label={t('people.clock.pause_label')}
                                title={t('people.clock.pause_label')}
                                data-test="clock-pause"
                            >
                                <Utensils aria-hidden="true" />
                                <span className="hidden sm:inline">
                                    {t('people.clock.pause')}
                                </span>
                            </Button>
                            <Button
                                type="button"
                                size="sm"
                                variant="ghost"
                                className="h-8 px-2"
                                disabled={processing}
                                onClick={() => request('clock_out')}
                                aria-label={t('people.clock.out_label')}
                                title={t('people.clock.out_label')}
                                data-test="clock-out"
                            >
                                <LogOut aria-hidden="true" />
                                <span className="hidden sm:inline">
                                    {t('people.clock.out')}
                                </span>
                            </Button>
                        </>
                    ) : null}

                    {clock.status === 'paused' ? (
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            className="h-8 px-2"
                            disabled={processing}
                            onClick={() => request('pause_end', lastMode)}
                            aria-label={t('people.clock.back_label')}
                            title={t('people.clock.back_label')}
                            data-test="clock-back"
                        >
                            <LogIn aria-hidden="true" />
                            <span className="hidden sm:inline">
                                {t('people.clock.back')}
                            </span>
                        </Button>
                    ) : null}

                    <ClockMenu
                        clock={clock}
                        trigger="ghost"
                        title={
                            clock.status === 'paused'
                                ? t('people.clock.back_mode_title')
                                : t('people.clock.mode_title')
                        }
                        onMode={
                            clock.status === 'working'
                                ? null
                                : (mode) =>
                                      request(
                                          clock.status === 'paused'
                                              ? 'pause_end'
                                              : 'clock_in',
                                          mode,
                                      )
                        }
                        onClockOut={
                            clock.status === 'paused'
                                ? () => request('clock_out')
                                : null
                        }
                    />
                </div>
            )}

            <StopTimerDialog
                kind={confirm}
                timer={timer ?? null}
                onCancel={() => setConfirm(null)}
                onChoose={(stop) => {
                    const kind = confirm;
                    setConfirm(null);

                    if (kind) {
                        doPunch(kind, null, stop);
                    }
                }}
            />
        </div>
    );
}

/**
 * Menú del registro: el modo con el que fichar (o volver de la comida), salir desde la comida,
 * el día que se quedó sin salida y el enlace a «Mi jornada».
 */
function ClockMenu({
    clock,
    trigger,
    title,
    onMode,
    onClockOut = null,
}: {
    clock: ClockShared;
    trigger: 'primary' | 'ghost';
    title: string;
    onMode: ((mode: WorkMode) => void) | null;
    onClockOut?: (() => void) | null;
}) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    type="button"
                    size="icon"
                    variant={trigger === 'primary' ? 'default' : 'ghost'}
                    className={cn(
                        trigger === 'primary'
                            ? 'w-7 rounded-l-none border-l border-primary-foreground/30'
                            : 'size-8',
                        'relative',
                    )}
                    aria-label={t('people.clock.menu')}
                    data-test="clock-menu"
                >
                    <ChevronDown aria-hidden="true" />
                    {clock.unclosed_date ? (
                        <span
                            aria-hidden="true"
                            className="absolute top-1 right-1 size-1.5 rounded-full bg-warning"
                        />
                    ) : null}
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-64">
                {onMode ? (
                    <>
                        <DropdownMenuLabel className="text-xs font-normal text-muted-foreground">
                            {title}
                        </DropdownMenuLabel>
                        {MODES.map((mode) => (
                            <DropdownMenuItem
                                key={mode}
                                onSelect={() => onMode(mode)}
                                data-test={`clock-mode-${mode}`}
                            >
                                <LogIn aria-hidden="true" />
                                {modeLabel(mode)}
                                {clock.work_mode === mode ? (
                                    <span className="ml-auto text-xs text-muted-foreground">
                                        ✓
                                    </span>
                                ) : null}
                            </DropdownMenuItem>
                        ))}
                        <DropdownMenuSeparator />
                    </>
                ) : null}
                {onClockOut ? (
                    <>
                        <DropdownMenuItem
                            onSelect={onClockOut}
                            data-test="clock-out-from-pause"
                        >
                            <LogOut aria-hidden="true" />
                            {t('people.clock.out_label')}
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                    </>
                ) : null}
                {clock.unclosed_date ? (
                    <DropdownMenuItem asChild>
                        <Link
                            href={workdayIndex.url({
                                query: { dia: clock.unclosed_date },
                            })}
                        >
                            <TriangleAlert
                                aria-hidden="true"
                                className="text-warning"
                            />
                            <span className="grid">
                                <span>
                                    {t('people.clock.unclosed', {
                                        date: formatDate(clock.unclosed_date),
                                    })}
                                </span>
                                <span className="text-xs text-muted-foreground">
                                    {t('people.clock.unclosed_action')}
                                </span>
                            </span>
                        </Link>
                    </DropdownMenuItem>
                ) : null}
                <DropdownMenuItem asChild>
                    <Link href={workdayIndex.url()}>
                        <CalendarClock aria-hidden="true" />
                        {t('people.clock.my_workday')}
                    </Link>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/** «¿Paras también el temporizador?» al empezar la comida o salir (sí por defecto). */
function StopTimerDialog({
    kind,
    timer,
    onCancel,
    onChoose,
}: {
    kind: ClockKind | null;
    timer: ActiveTimer | null;
    onCancel: () => void;
    onChoose: (stop: boolean) => void;
}) {
    return (
        <Dialog
            open={kind !== null && timer !== null}
            onOpenChange={(open) => (open ? null : onCancel())}
        >
            <DialogContent>
                <DialogTitle>{t('people.timer.stop_title')}</DialogTitle>
                <DialogDescription>
                    {kind === 'pause_start'
                        ? t('people.timer.stop_pause', {
                              task: timer?.task_title ?? '',
                          })
                        : t('people.timer.stop_out', {
                              task: timer?.task_title ?? '',
                          })}
                </DialogDescription>
                <DialogFooter className="gap-2">
                    <Button
                        type="button"
                        variant="secondary"
                        onClick={() => onChoose(false)}
                        data-test="clock-keep-timer"
                    >
                        {t('people.timer.stop_no')}
                    </Button>
                    <Button
                        type="button"
                        autoFocus
                        onClick={() => onChoose(true)}
                        data-test="clock-stop-timer"
                    >
                        {t('people.timer.stop_yes')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/** Al volver de la comida: «¿Reanudas el temporizador de…?» si se paró al empezarla. */
function offerResume(): void {
    const remembered = takeRememberedTimer();

    if (!remembered) {
        return;
    }

    toast(t('people.timer.resume', { task: remembered.task_title }), {
        duration: 10_000,
        action: {
            label: t('people.timer.resume_action'),
            onClick: () => startTimer(remembered.task_id),
        },
    });
}
