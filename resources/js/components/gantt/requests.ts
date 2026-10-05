import { router } from '@inertiajs/react';
import type { ActiveVisit, VisitOptions } from '@inertiajs/core';
import { toast } from 'sonner';
import type { GanttDates } from '@/components/gantt/types';
import { t } from '@/lib/i18n';
import { xsrfToken } from '@/lib/xsrf';
import { store as storeTask } from '@/routes/tasks';
import {
    destroy as destroyDependency,
    store as storeDependency,
} from '@/routes/schedule/dependencies';
import {
    preview as previewReschedule,
    store as storeReschedule,
} from '@/routes/schedule/reschedule';
import type { ShiftProposal } from '@/types/schedule';

/**
 * Peticiones del Gantt (contrato de la Fase 4):
 * - la propuesta de reprogramar es JSON (fetch con el token CSRF de la cookie XSRF-TOKEN, como
 *   Laravel espera en las peticiones de la app); no cambia nada,
 * - guardar fechas, enlazar, quitar dependencias y crear tareas van con el router de Inertia
 *   (responden con back()), conservan el scroll y el estado y recargan solo las props del Gantt,
 * - si otra visita las interrumpe, se vuelven a pedir las props del Gantt (refreshAfterInterruption).
 */

export class GanttRequestError extends Error {}

export type GanttVisitCallbacks = {
    onSuccess?: () => void;
    /** Cualquier fallo, con un mensaje comprensible (el del servidor si es de validación). */
    onFailure?: (message: string, errors?: Record<string, string>) => void;
    /**
     * Otra visita síncrona de Inertia la ha interrumpido (otro guardado, los filtros, la escala, el
     * temporizador…). La petición ya había salido, así que no se sabe si el servidor la aplicó:
     * se vuelven a pedir las props del Gantt y, cuando llegan, se llama a onRefreshed. Después de
     * onCancel se llama a onFinish.
     */
    onCancel?: () => void;
    /** Tras una interrupción, cuando ya han llegado otra vez las props del Gantt. */
    onRefreshed?: () => void;
    /** Siempre al terminar: bien, mal o interrumpida. */
    onFinish?: () => void;
};

type Callbacks = GanttVisitCallbacks;

type RefreshWaiter = {
    props: ReadonlyArray<string>;
    done: () => void;
    /** Ruta de la página en la que se interrumpió el cambio (la del Gantt). */
    path: string;
};

/** Cambios interrumpidos que esperan a que vuelvan a llegar las props del Gantt. */
let waiters: RefreshWaiter[] = [];
/** Quita las escuchas de router.on: solo se escucha mientras hay algo pendiente. */
let stopListening: VoidFunction | null = null;
/**
 * Visitas que han traído página: Inertia emite `success` (o `error`, con errores de validación)
 * después de poner sus props. Inertia también da por terminada (`completed`) una visita que falla
 * por un error HTTP, de red o una respuesta que no es de Inertia, pero esa no trae ninguna prop.
 */
const answered = new Set<string>();

/** Un solo aviso aunque falle más de una recarga. */
const REFRESH_TOAST = 'gantt-refresh';

function currentPath(): string {
    return typeof window === 'undefined' ? '' : window.location.pathname;
}

/** Si una visita que ha traído página ha vuelto a traer la prop (las completas traen todas). */
function reloaded(visit: ActiveVisit, prop: string): boolean {
    return (
        (visit.only.length === 0 || visit.only.includes(prop)) &&
        !visit.except.includes(prop)
    );
}

/**
 * Inertia cancela la visita síncrona en curso cuando empieza otra. Si era un cambio del Gantt, no
 * se sabe si llegó al servidor, así que hay que volver a pedir sus props: cuando termina la
 * siguiente visita síncrona (normalmente la que la interrumpió), si no las ha traído (p. ej.
 * cambiar la escala solo recarga `preferences`) o ha fallado, se lanza una recarga parcial. `done`
 * se llama solo cuando han llegado de verdad. Una sola escucha y una sola recarga aunque se
 * interrumpan varias visitas.
 */
export function refreshAfterInterruption(
    reload: ReadonlyArray<string>,
    done: () => void = () => {},
): void {
    waiters.push({ props: reload, done, path: currentPath() });

    if (stopListening) {
        return;
    }

    const record = (visitId?: string) => {
        if (visitId !== undefined) {
            answered.add(visitId);
        }
    };
    const stops = [
        router.on('success', (event) => record(event.detail.visitId)),
        router.on('error', (event) => record(event.detail.visitId)),
        router.on('finish', (event) => settle(event.detail.visit)),
    ];

    stopListening = () => stops.forEach((stop) => stop());
}

/** Atiende los cambios pendientes cuando termina una visita síncrona. */
function settle(visit: ActiveVisit): void {
    // La propia visita cancelada, las que se cancelen después, las asíncronas y las precargas no
    // cuentan.
    if (!visit.completed || visit.async || visit.prefetch) {
        return;
    }

    const arrived = answered.has(visit.id);
    const path = currentPath();
    // Los de otra página se olvidan: ese Gantt ya no está (p. ej. se salió con un enlace precargado,
    // que no emite «finish») y no tiene sentido pedir sus props aquí.
    const current = waiters.filter((waiter) => waiter.path === path);

    waiters = [];
    answered.clear();
    stopListening?.();
    stopListening = null;

    // Si ha fallado, no ha llegado ninguna prop: hay que pedirlas todas.
    const pending = arrived
        ? current.filter((waiter) =>
              waiter.props.some((prop) => !reloaded(visit, prop)),
          )
        : current;

    current
        .filter((waiter) => !pending.includes(waiter))
        .forEach((waiter) => waiter.done());

    if (pending.length > 0) {
        reloadProps(pending);
    }
}

/**
 * Recarga parcial de las props del Gantt. Como el resto de acciones dentro de la página, trata sus
 * propios errores sin salir de ella (App\Http\Responses\ErrorPage): si falla, avisa y las fechas
 * optimistas se quedan hasta que otra visita traiga las props (se vuelve a esperar, sin repetirla
 * en bucle), en lugar de volver a unas fechas que quizá ya no son las guardadas. Si otra visita la
 * interrumpe, también se vuelve a esperar.
 */
function reloadProps(pending: ReadonlyArray<RefreshWaiter>): void {
    let outcome: 'waiting' | 'loaded' | 'failed' | 'interrupted' = 'waiting';
    const waitAgain = () =>
        pending.forEach((waiter) =>
            refreshAfterInterruption(waiter.props, waiter.done),
        );
    const fail = () => {
        outcome = 'failed';

        return false;
    };

    router.visit(window.location.href, {
        only: [...new Set(pending.flatMap((waiter) => waiter.props))],
        preserveScroll: true,
        preserveState: true,
        replace: true,
        onSuccess: () => {
            outcome = 'loaded';
        },
        onError: () => {
            outcome = 'loaded';
        },
        onHttpException: fail,
        onNetworkError: fail,
        onCancel: () => {
            outcome = 'interrupted';
            waitAgain();
        },
        onFinish: () => {
            // Interrumpida: onCancel ya ha vuelto a esperar.
            if (outcome === 'interrupted') {
                return;
            }

            if (outcome === 'loaded') {
                pending.forEach((waiter) => waiter.done());

                return;
            }

            // Ha fallado (se avisa) o ha acabado sin página ni error, porque Inertia se va a otra
            // ubicación y recarga la página entera (p. ej. con assets nuevos). Se espera a la
            // siguiente visita.
            if (outcome === 'failed') {
                toast.error(t('gantt.errors.refresh'), { id: REFRESH_TOAST });
            }

            waitAgain();
        },
    });
}

/** Primer mensaje de error de un objeto de errores de validación. */
function firstError(errors: Record<string, unknown>): string | null {
    for (const value of Object.values(errors)) {
        if (typeof value === 'string' && value !== '') {
            return value;
        }

        if (Array.isArray(value) && typeof value[0] === 'string') {
            return value[0];
        }
    }

    return null;
}

/**
 * POST /tareas/{task}/reprogramar/propuesta: sucesoras que entrarían en conflicto y sus fechas
 * propuestas (D-057). Lanza GanttRequestError con un mensaje para el usuario si falla.
 */
export async function fetchReschedulePreview(
    taskId: number,
    dates: GanttDates,
): Promise<ShiftProposal[]> {
    const token = xsrfToken();
    let response: Response;

    try {
        response = await fetch(previewReschedule.url(taskId), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...(token ? { 'X-XSRF-TOKEN': token } : {}),
            },
            body: JSON.stringify({
                start_date: dates.start_date,
                due_date: dates.due_date,
            }),
        });
    } catch {
        throw new GanttRequestError(t('gantt.errors.network'));
    }

    if (response.status === 422) {
        const body = (await response.json().catch(() => ({}))) as {
            errors?: Record<string, unknown>;
            message?: string;
        };

        throw new GanttRequestError(
            firstError(body.errors ?? {}) ??
                body.message ??
                t('gantt.errors.generic'),
        );
    }

    if (response.status === 403) {
        throw new GanttRequestError(t('gantt.errors.forbidden'));
    }

    if (!response.ok) {
        throw new GanttRequestError(t('gantt.errors.server'));
    }

    const body = (await response.json()) as { proposals?: ShiftProposal[] };

    return body.proposals ?? [];
}

function visitOptions(reload: string[], callbacks: Callbacks): VisitOptions {
    return {
        preserveScroll: true,
        preserveState: true,
        only: reload,
        onSuccess: () => callbacks.onSuccess?.(),
        onError: (errors) => {
            const list = errors as Record<string, string>;
            callbacks.onFailure?.(
                firstError(list) ?? t('gantt.errors.generic'),
                list,
            );
        },
        onHttpException: (response) => {
            callbacks.onFailure?.(
                response.status === 403
                    ? t('gantt.errors.forbidden')
                    : t('gantt.errors.server'),
            );

            return false;
        },
        onNetworkError: () => {
            callbacks.onFailure?.(t('gantt.errors.network'));

            return false;
        },
        onCancel: () => {
            refreshAfterInterruption(reload, () => callbacks.onRefreshed?.());
            callbacks.onCancel?.();
        },
        onFinish: () => callbacks.onFinish?.(),
    };
}

/** POST /tareas/{task}/reprogramar: guarda las fechas y, si se confirma, desplaza las sucesoras. */
export function saveReschedule(
    taskId: number,
    dates: GanttDates,
    shiftSuccessors: boolean,
    reload: string[],
    callbacks: Callbacks = {},
): void {
    router.post(
        storeReschedule.url(taskId),
        {
            start_date: dates.start_date,
            due_date: dates.due_date,
            shift_successors: shiftSuccessors,
        },
        visitOptions(reload, callbacks),
    );
}

/** POST /proyectos/{project}/dependencias (fin-inicio, D-056). */
export function linkTasks(
    projectId: number,
    predecessorId: number,
    successorId: number,
    reload: string[],
    callbacks: Callbacks = {},
): void {
    router.post(
        storeDependency.url(projectId),
        {
            predecessor_task_id: predecessorId,
            successor_task_id: successorId,
        },
        visitOptions(reload, callbacks),
    );
}

/** DELETE /dependencias/{dependency}. */
export function unlinkTasks(
    dependencyId: number,
    reload: string[],
    callbacks: Callbacks = {},
): void {
    router.delete(
        destroyDependency.url(dependencyId),
        visitOptions(reload, callbacks),
    );
}

export type NewGanttTask = {
    title: string;
    start_date: string | null;
    due_date: string | null;
    is_milestone: boolean;
    hour_bank_id?: number | null;
};

/** POST /proyectos/{project}/tareas (TaskWriter, la misma ruta que el alta rápida). */
export function createTask(
    projectId: number,
    data: NewGanttTask,
    reload: string[],
    callbacks: Callbacks = {},
): void {
    router.post(
        storeTask.url(projectId),
        {
            title: data.title,
            start_date: data.is_milestone ? null : data.start_date,
            due_date: data.due_date,
            is_milestone: data.is_milestone,
            ...(data.hour_bank_id !== undefined
                ? { hour_bank_id: data.hour_bank_id }
                : {}),
        },
        visitOptions(reload, callbacks),
    );
}
