import { router } from '@inertiajs/react';
import { useSyncExternalStore } from 'react';
import { toast } from 'sonner';
import { punch } from '@/components/people/clock-actions';
import { formatTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import { discard, start, stop } from '@/routes/timer';
import type { ClockShared } from '@/types/people';

/**
 * Acciones del temporizador (SPEC §7, D-035) compartidas por la cabecera, el botón de cada tarea
 * e Inicio. Los errores van en su propia bolsa (`timer`) para no mezclarse con los formularios de
 * la página.
 *
 * Si al PARAR la imputación no es válida (bolsa `block` sin saldo, semana enviada, descripción
 * obligatoria…), el servidor deja el temporizador en marcha y devuelve los errores: se abre el
 * diálogo de la cabecera (TimerStopDialog) para ajustar la duración, elegir otra tarea, escribir
 * la descripción o descartarlo. Lo mismo si al INICIAR otro no se puede imputar el que estaba en
 * marcha (el servidor lo marca con la clave `running_timer`).
 */

export type TimerErrors = Record<string, string>;

type StopDialogState = {
    open: boolean;
    errors: TimerErrors;
};

let state: StopDialogState = { open: false, errors: {} };
const listeners = new Set<() => void>();

function emit(next: StopDialogState): void {
    state = next;
    listeners.forEach((listener) => listener());
}

function subscribe(listener: () => void): () => void {
    listeners.add(listener);

    return () => {
        listeners.delete(listener);
    };
}

function getSnapshot(): StopDialogState {
    return state;
}

/** Estado global del diálogo «no se ha podido imputar» (uno por página, en la cabecera). */
export const timerStopDialog = {
    open: (errors: TimerErrors): void => emit({ open: true, errors }),
    close: (): void => emit({ open: false, errors: {} }),
    subscribe,
    getSnapshot,
};

export function useTimerStopDialog(): StopDialogState {
    return useSyncExternalStore(subscribe, getSnapshot, getSnapshot);
}

type VisitCallbacks = {
    onStart?: () => void;
    onFinish?: () => void;
    onSuccess?: () => void;
};

const BASE = {
    preserveScroll: true,
    preserveState: true,
    errorBag: 'timer',
} as const;

function firstMessages(errors: TimerErrors): string[] {
    return Object.values(errors).filter((message) => message !== '');
}

/**
 * Registro de jornada (PLAN-FASE-11 §3.2.2; D-340): si se empieza el temporizador sin haber fichado
 * la entrada, se ofrece fichar con un clic. Fichar es siempre un gesto de la persona: nunca se
 * ficha solo por usar el temporizador.
 */
export function offerClockIn(clock: ClockShared | null): void {
    if (
        clock === null ||
        (clock.status !== 'off' && clock.status !== 'closed')
    ) {
        return;
    }

    toast(
        t('people.timer.clock_in_prompt', {
            time: formatTime(new Date().toISOString()),
        }),
        {
            duration: 12_000,
            action: {
                label: t('people.timer.clock_in_action'),
                onClick: () => punch('clock_in', clock.work_mode ?? 'on_site'),
            },
        },
    );
}

/** Clave con la que el servidor avisa de que no ha podido imputar el temporizador en marcha. */
export const RUNNING_TIMER_ERROR = 'running_timer';

/**
 * Inicia el temporizador en una tarea. Los errores de la tarea nueva (no ser miembro, bolsa sin
 * saldo…) van como avisos; si lo que falla es imputar el que estaba en marcha, se abre su diálogo.
 */
export function startTimer(
    taskId: number,
    callbacks: VisitCallbacks = {},
): void {
    router.post(
        start.url(),
        { task_id: taskId },
        {
            ...BASE,
            onStart: () => callbacks.onStart?.(),
            onFinish: () => callbacks.onFinish?.(),
            onSuccess: (page) => {
                offerClockIn(page.props.people?.clock ?? null);
                callbacks.onSuccess?.();
            },
            onError: (errors) => {
                if (errors[RUNNING_TIMER_ERROR]) {
                    timerStopDialog.open(errors);

                    return;
                }

                firstMessages(errors).forEach((message) =>
                    toast.error(message),
                );
            },
        },
    );
}

/**
 * Para el temporizador e imputa. Con `minutes`, `taskId` o `description`, imputa esa duración, en
 * esa tarea o con esa descripción (diálogo al parar). Si falla, abre el diálogo con los errores.
 */
export function stopTimer(
    options: VisitCallbacks & {
        minutes?: number | null;
        taskId?: number | null;
        description?: string | null;
    } = {},
): void {
    router.post(
        stop.url(),
        {
            minutes: options.minutes ?? null,
            task_id: options.taskId ?? null,
            description: options.description?.trim() || null,
        },
        {
            ...BASE,
            onStart: () => options.onStart?.(),
            onFinish: () => options.onFinish?.(),
            onSuccess: () => {
                timerStopDialog.close();
                options.onSuccess?.();
            },
            onError: (errors) => timerStopDialog.open(errors),
        },
    );
}

/** Descarta el temporizador sin imputar nada. */
export function discardTimer(callbacks: VisitCallbacks = {}): void {
    router.delete(discard.url(), {
        ...BASE,
        onStart: () => callbacks.onStart?.(),
        onFinish: () => callbacks.onFinish?.(),
        onSuccess: () => {
            timerStopDialog.close();
            callbacks.onSuccess?.();
        },
        onError: (errors) => {
            firstMessages(errors).forEach((message) => toast.error(message));
        },
    });
}
