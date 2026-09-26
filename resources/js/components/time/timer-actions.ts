import { router } from '@inertiajs/react';
import { useSyncExternalStore } from 'react';
import { toast } from 'sonner';
import { discard, start, stop } from '@/routes/timer';

/**
 * Acciones del temporizador (SPEC §7, D-035) compartidas por la cabecera, el botón de cada tarea
 * e Inicio. Los errores van en su propia bolsa (`timer`) para no mezclarse con los formularios de
 * la página.
 *
 * Si al PARAR la imputación no es válida (bolsa `block` sin saldo, semana enviada…), el servidor
 * deja el temporizador en marcha y devuelve los errores: se abre el diálogo de la cabecera
 * (TimerStopDialog) para ajustar la duración, elegir otra tarea o descartarlo.
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

/** Inicia el temporizador en una tarea. Los errores (no ser miembro, bolsa sin saldo…) van como avisos. */
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
            onSuccess: () => callbacks.onSuccess?.(),
            onError: (errors) => {
                firstMessages(errors).forEach((message) =>
                    toast.error(message),
                );
            },
        },
    );
}

/**
 * Para el temporizador e imputa. Con `minutes` o `taskId`, imputa esa duración o en esa tarea
 * (diálogo al parar). Si falla, abre el diálogo con los errores.
 */
export function stopTimer(
    options: VisitCallbacks & {
        minutes?: number | null;
        taskId?: number | null;
    } = {},
): void {
    router.post(
        stop.url(),
        {
            minutes: options.minutes ?? null,
            task_id: options.taskId ?? null,
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
