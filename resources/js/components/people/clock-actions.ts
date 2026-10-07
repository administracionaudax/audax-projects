import { router } from '@inertiajs/react';
import { toast } from 'sonner';
import { t } from '@/lib/i18n';
import { clock } from '@/routes/people';
import type { ClockKind, WorkMode } from '@/types/people';

/**
 * Fichar (POST /fichar, D-333). El navegador solo dice QUÉ se ficha y el modo: la hora la pone el
 * servidor. Los errores (fichar dos veces la entrada, por ejemplo) van en su propia bolsa y salen
 * como avisos. Sin conexión no se ficha: nunca se guarda la hora del dispositivo.
 */

type Callbacks = {
    onStart?: () => void;
    onFinish?: () => void;
    onSuccess?: () => void;
};

/** ¿Es la app instalada (PWA)? Solo para saber desde dónde se ficha. */
export function clockSource(): 'web' | 'pwa' {
    if (typeof window === 'undefined' || !window.matchMedia) {
        return 'web';
    }

    return window.matchMedia('(display-mode: standalone)').matches
        ? 'pwa'
        : 'web';
}

export function punch(
    kind: ClockKind,
    workMode: WorkMode | null = null,
    callbacks: Callbacks = {},
): void {
    if (typeof navigator !== 'undefined' && navigator.onLine === false) {
        toast.error(t('people.clock.offline'));

        return;
    }

    router.post(
        clock.url(),
        { kind, work_mode: workMode, source: clockSource() },
        {
            preserveScroll: true,
            preserveState: true,
            errorBag: 'clock',
            onStart: () => callbacks.onStart?.(),
            onFinish: () => callbacks.onFinish?.(),
            onSuccess: () => callbacks.onSuccess?.(),
            onError: (errors) => {
                Object.values(errors)
                    .filter((message) => message !== '')
                    .forEach((message) => toast.error(message));
            },
        },
    );
}

/** Tarea del temporizador parado al empezar la comida, para ofrecer reanudarlo al volver. */
const RESUME_KEY = 'audax.clock.resume_timer';

export type ResumableTimer = { task_id: number; task_title: string };

export function rememberTimer(timer: ResumableTimer | null): void {
    try {
        if (timer === null) {
            window.localStorage.removeItem(RESUME_KEY);
        } else {
            window.localStorage.setItem(RESUME_KEY, JSON.stringify(timer));
        }
    } catch {
        // Sin almacenamiento (navegación privada): simplemente no se ofrece reanudar.
    }
}

export function takeRememberedTimer(): ResumableTimer | null {
    try {
        const raw = window.localStorage.getItem(RESUME_KEY);
        window.localStorage.removeItem(RESUME_KEY);

        if (!raw) {
            return null;
        }

        const parsed = JSON.parse(raw) as Partial<ResumableTimer>;

        return typeof parsed.task_id === 'number' &&
            typeof parsed.task_title === 'string'
            ? { task_id: parsed.task_id, task_title: parsed.task_title }
            : null;
    } catch {
        return null;
    }
}
