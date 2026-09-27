import { router } from '@inertiajs/react';
import type { VisitOptions } from '@inertiajs/core';
import type { GanttDates } from '@/components/gantt/types';
import { t } from '@/lib/i18n';
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
 *   (responden con back()), conservan el scroll y el estado y recargan solo las props del Gantt.
 */

export class GanttRequestError extends Error {}

export type GanttVisitCallbacks = {
    onSuccess?: () => void;
    /** Cualquier fallo, con un mensaje comprensible (el del servidor si es de validación). */
    onFailure?: (message: string, errors?: Record<string, string>) => void;
    /**
     * Otra visita síncrona de Inertia la ha interrumpido (otro guardado, los filtros, la escala, el
     * temporizador…). La petición ya había salido, así que no se sabe si el servidor la aplicó: la
     * visita que la interrumpe trae los datos nuevos. Después se llama a onFinish.
     */
    onCancel?: () => void;
    /** Siempre al terminar: bien, mal o interrumpida. */
    onFinish?: () => void;
};

type Callbacks = GanttVisitCallbacks;

/** Valor de la cookie XSRF-TOKEN (Laravel la acepta en la cabecera X-XSRF-TOKEN). */
export function xsrfToken(): string | null {
    if (typeof document === 'undefined') {
        return null;
    }

    const cookie = document.cookie
        .split('; ')
        .find((item) => item.startsWith('XSRF-TOKEN='));

    return cookie
        ? decodeURIComponent(cookie.slice('XSRF-TOKEN='.length))
        : null;
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
        onCancel: () => callbacks.onCancel?.(),
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
