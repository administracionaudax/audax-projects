import { http, router } from '@inertiajs/react';
import { HttpResponseError } from '@inertiajs/core';
import { toast } from 'sonner';
import { TASK_RELOAD, toastErrors } from '@/components/tasks/task-requests';
import { t } from '@/lib/i18n';
import {
    preview as previewRoute,
    store as storeRoute,
} from '@/routes/schedule/reschedule';
import type {
    ReschedulePreview,
    RescheduleRequest,
    ShiftProposal,
} from '@/types/schedule';

/**
 * Reprogramar una tarea (D-057) en dos pasos: la propuesta (JSON, no cambia nada) y, después,
 * guardar las fechas nuevas desplazando o no las sucesoras. La propuesta se recalcula en el
 * servidor al guardar: nunca se envían fechas para las sucesoras.
 */

/** Error comprensible de la propuesta (validación o permisos), para enseñarlo tal cual. */
export class RescheduleError extends Error {}

function firstError(body: string): string | null {
    try {
        const data = JSON.parse(body) as {
            message?: unknown;
            errors?: Record<string, unknown>;
        };
        const first = Object.values(data.errors ?? {})[0];

        if (Array.isArray(first) && typeof first[0] === 'string') {
            return first[0];
        }

        return typeof data.message === 'string' && data.message !== ''
            ? data.message
            : null;
    } catch {
        return null;
    }
}

/** POST /tareas/{task}/reprogramar/propuesta: sucesoras en conflicto y a qué fechas irían. */
export async function fetchReschedulePreview(
    taskId: number,
    dates: Pick<RescheduleRequest, 'start_date' | 'due_date'>,
): Promise<ShiftProposal[]> {
    try {
        const response = await http.getClient().request({
            method: 'post',
            url: previewRoute.url(taskId),
            data: { start_date: dates.start_date, due_date: dates.due_date },
            headers: { Accept: 'application/json' },
        });
        const data = JSON.parse(response.data) as Partial<ReschedulePreview>;

        return Array.isArray(data.proposals) ? data.proposals : [];
    } catch (error) {
        if (error instanceof HttpResponseError) {
            const status = error.response.status;
            const message =
                status === 422 ? firstError(String(error.response.data)) : null;

            throw new RescheduleError(
                message ??
                    (status === 403
                        ? t('planning.reschedule.forbidden')
                        : t('task_errors.server')),
            );
        }

        throw new RescheduleError(t('task_errors.network'));
    }
}

/**
 * POST /tareas/{task}/reprogramar: guarda las fechas y, si `shift_successors`, desplaza las
 * sucesoras en conflicto. Recarga la lista, el calendario y el panel sin perder el scroll.
 */
export function saveReschedule(
    taskId: number,
    body: RescheduleRequest,
    options: { onFinish?: () => void; onSuccess?: () => void } = {},
): void {
    router.post(
        storeRoute.url(taskId),
        { ...body },
        {
            preserveScroll: true,
            preserveState: true,
            only: TASK_RELOAD,
            onSuccess: () => options.onSuccess?.(),
            onError: (errors) => toastErrors(errors as Record<string, string>),
            onHttpException: () => {
                toast.error(t('task_errors.server'));

                return false;
            },
            onNetworkError: () => {
                toast.error(t('task_errors.network'));

                return false;
            },
            onFinish: () => options.onFinish?.(),
        },
    );
}
