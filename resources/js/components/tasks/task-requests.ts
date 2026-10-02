import { router } from '@inertiajs/react';
import type { VisitOptions } from '@inertiajs/core';
import { toast } from 'sonner';
import { t } from '@/lib/i18n';
import { update as updateTaskRoute } from '@/routes/tasks';
import type { TaskFilters, TaskView } from '@/types';

/**
 * Peticiones de la pestaña Tareas. Todas conservan el scroll y el estado de la página y solo
 * recargan las props que cambian (recarga parcial de Inertia).
 */

/** Props que se recargan tras editar una tarea desde la lista, el kanban, el calendario o el panel. */
export const TASK_RELOAD = [
    'tasks',
    'panel',
    'hiddenCompletedCount',
    'calendar',
];

type Errors = Record<string, string>;

/** Primer mensaje de error de una respuesta de validación, como aviso emergente. */
export function toastErrors(errors: Errors): void {
    const message = Object.values(errors)[0];

    toast.error(message ?? t('task_errors.generic'));
}

/**
 * Opciones comunes: recarga parcial, sin perder scroll ni estado, y errores comprensibles
 * (validación, error del servidor o sin conexión). `onFailure` se llama en cualquier fallo.
 */
export function taskVisitOptions(
    options: VisitOptions & { onFailure?: () => void } = {},
): VisitOptions {
    const { onFailure, ...rest } = options;

    return {
        preserveScroll: true,
        preserveState: true,
        only: TASK_RELOAD,
        onError: (errors) => {
            onFailure?.();
            toastErrors(errors as Errors);
        },
        onHttpException: () => {
            onFailure?.();
            toast.error(t('task_errors.server'));

            return false;
        },
        onNetworkError: () => {
            onFailure?.();
            toast.error(t('task_errors.network'));

            return false;
        },
        ...rest,
    };
}

export type TaskChanges = Partial<{
    title: string;
    description: string | null;
    status_id: number;
    priority: string;
    assignee_user_id: number | null;
    hour_bank_id: number | null;
    task_type_id: number | null;
    start_date: string | null;
    due_date: string | null;
    estimated_minutes: number | null;
    is_billable: boolean;
    is_milestone: boolean;
}>;

export function updateTask(
    taskId: number,
    changes: TaskChanges,
    options: VisitOptions & { onFailure?: () => void } = {},
): void {
    router.patch(
        updateTaskRoute.url(taskId),
        changes,
        taskVisitOptions(options),
    );
}

/** Parámetros de la URL (en español) a partir de la vista y los filtros. */
export function filtersToQuery(
    view: TaskView,
    filters: TaskFilters,
): Record<string, string> {
    const query: Record<string, string> = {};

    if (view === 'kanban') {
        query.vista = 'kanban';
    } else if (view === 'calendar') {
        query.vista = 'calendario';
    }

    if (filters.assignee !== null) {
        query.responsable =
            filters.assignee === 'none' ? 'ninguno' : String(filters.assignee);
    }

    if (filters.bank !== null) {
        query.bolsa = String(filters.bank);
    }

    if (filters.type !== null) {
        query.tipo = String(filters.type);
    }

    if (filters.priority !== null) {
        query.prioridad = filters.priority;
    }

    if (filters.status !== null) {
        query.estado = String(filters.status);
    }

    if (filters.mine) {
        query.mias = '1';
    }

    if (filters.completed) {
        query.completadas = '1';
    }

    if (filters.group !== 'status') {
        query.agrupar = filters.group;
    }

    return query;
}

/** URL actual con el parámetro ?tarea= puesto o quitado (abre o cierra el panel). */
export function withTaskParam(
    currentUrl: string,
    taskId: number | null,
): string {
    const url = new URL(currentUrl, 'http://localhost');

    if (taskId === null) {
        url.searchParams.delete('tarea');
    } else {
        url.searchParams.set('tarea', String(taskId));
    }

    return url.pathname + url.search;
}

/** Id de la tarea abierta en el panel según la URL. */
export function taskParam(currentUrl: string): number | null {
    const value = new URL(currentUrl, 'http://localhost').searchParams.get(
        'tarea',
    );

    return value && /^\d+$/.test(value) ? Number(value) : null;
}

/** Abre (o cambia) el panel lateral: recarga solo la prop `panel`. */
export function openTaskPanel(currentUrl: string, taskId: number): void {
    router.visit(withTaskParam(currentUrl, taskId), {
        only: ['panel'],
        preserveState: true,
        preserveScroll: true,
    });
}

/** Cierra el panel: quita ?tarea= de la URL. */
export function closeTaskPanel(currentUrl: string): void {
    router.visit(withTaskParam(currentUrl, null), {
        only: ['panel'],
        preserveState: true,
        preserveScroll: true,
    });
}
