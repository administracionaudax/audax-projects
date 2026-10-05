import { xsrfToken } from '@/lib/xsrf';
import {
    show as showDictation,
    store as storeDictation,
} from '@/routes/dictations';
import { draft as draftRoute } from '@/routes/my-weekly';
import type {
    Dictation,
    WeeklyDraftInput,
    WeeklySubmission,
} from '@/types/weeklies';

/**
 * Peticiones JSON de «Mi weekly» que no recargan la página (D-157 y D-158):
 * - el autoguardado del borrador (PUT my-weekly.draft),
 * - subir un dictado (POST dictations.store, multipart) y consultar su estado (GET dictations.show).
 * Misma sesión que la página: cookie más cabecera X-XSRF-TOKEN.
 */

export class WeeklyRequestError extends Error {
    constructor(
        public readonly status: number,
        /** Primer mensaje de validación (422), si lo hay. */
        public readonly userMessage: string | null = null,
    ) {
        super(userMessage ?? `HTTP ${status}`);
        this.name = 'WeeklyRequestError';
    }
}

function headers(json: boolean): Record<string, string> {
    const token = xsrfToken();

    return {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...(json ? { 'Content-Type': 'application/json' } : {}),
        ...(token ? { 'X-XSRF-TOKEN': token } : {}),
    };
}

/** Primer mensaje de error de una respuesta 422 de Laravel. */
async function firstError(response: Response): Promise<string | null> {
    try {
        const body = (await response.json()) as {
            message?: string;
            errors?: Record<string, string[]>;
        };
        const first = Object.values(body.errors ?? {})[0]?.[0];

        return first ?? body.message ?? null;
    } catch {
        return null;
    }
}

async function parse<T>(response: Response): Promise<T> {
    if (!response.ok) {
        throw new WeeklyRequestError(
            response.status,
            response.status === 422 ? await firstError(response) : null,
        );
    }

    return (await response.json()) as T;
}

/** Guarda el borrador (o los cambios de una weekly ya enviada) sin recargar. */
export async function saveWeeklyDraft(
    cycleId: number,
    draft: WeeklyDraftInput,
    options: { keepalive?: boolean } = {},
): Promise<WeeklySubmission> {
    const response = await fetch(draftRoute.url(cycleId), {
        method: 'PUT',
        credentials: 'same-origin',
        headers: headers(true),
        body: JSON.stringify(draft),
        keepalive: options.keepalive,
    });

    return (await parse<{ submission: WeeklySubmission }>(response)).submission;
}

/** Sube un dictado grabado en el navegador; responde con el dictado (pendiente o ya hecho). */
export async function uploadDictation({
    cycleId,
    clientId,
    file,
    durationMs,
}: {
    cycleId: number;
    clientId: number | null;
    file: File;
    durationMs: number;
}): Promise<Dictation> {
    const body = new FormData();
    body.append('context', 'weekly_entry');
    body.append('weekly_cycle_id', String(cycleId));

    if (clientId !== null) {
        body.append('client_id', String(clientId));
    }

    body.append('audio', file, file.name);
    body.append('duration_ms', String(Math.round(durationMs)));

    const response = await fetch(storeDictation.url(), {
        method: 'POST',
        credentials: 'same-origin',
        headers: headers(false),
        body,
    });

    return (await parse<{ dictation: Dictation }>(response)).dictation;
}

export async function fetchDictation(id: number): Promise<Dictation> {
    const response = await fetch(showDictation.url(id), {
        credentials: 'same-origin',
        headers: headers(false),
    });

    return (await parse<{ dictation: Dictation }>(response)).dictation;
}
