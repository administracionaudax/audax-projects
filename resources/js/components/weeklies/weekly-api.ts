import { xsrfToken } from '@/lib/xsrf';
import { ask as askRoute } from '@/routes/assistant';
import { show as showQuestion } from '@/routes/assistant/questions';
import {
    show as showDictation,
    store as storeDictation,
} from '@/routes/dictations';
import { notes as notesRoute } from '@/routes/my-space/tasks';
import { draft as draftRoute } from '@/routes/my-weekly';
import type {
    AssistantQuestion,
    Dictation,
    WeeklyDraftInput,
    WeeklySubmission,
} from '@/types/weeklies';

/**
 * Peticiones JSON de «Mi weekly» y de «Mi espacio» que no recargan la página (D-157, D-158 y 10.6):
 * - el autoguardado del borrador (PUT my-weekly.draft),
 * - subir un dictado (POST dictations.store, multipart) y consultar su estado (GET dictations.show),
 * - el autoguardado de las notas de una tarea (PUT my-space.tasks.notes),
 * - preguntar al asistente (POST assistant.ask) y consultar la respuesta (GET assistant.questions.show).
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

/**
 * Para qué es un dictado (D-152): el apunte de un cliente en «Mi weekly» o las notas de una tarea de
 * «Mi espacio» (10.6, F-060).
 */
export type DictationTarget =
    | { cycleId: number; clientId: number | null; taskId?: undefined }
    | { taskId: number; cycleId?: undefined; clientId?: undefined };

/** Sube un dictado grabado en el navegador; responde con el dictado (pendiente o ya hecho). */
export async function uploadDictation({
    file,
    durationMs,
    ...target
}: DictationTarget & {
    file: File;
    durationMs: number;
}): Promise<Dictation> {
    const body = new FormData();

    if (target.taskId !== undefined) {
        body.append('context', 'task_note');
        body.append('task_id', String(target.taskId));
    } else {
        body.append('context', 'weekly_entry');
        body.append('weekly_cycle_id', String(target.cycleId));

        if (target.clientId !== null) {
            body.append('client_id', String(target.clientId));
        }
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

/** Guarda las notas de una tarea (su descripción en texto plano, F-060) sin recargar. */
export async function saveTaskNotes(
    taskId: number,
    notes: string,
    options: { keepalive?: boolean } = {},
): Promise<string> {
    const response = await fetch(notesRoute.url(taskId), {
        method: 'PUT',
        credentials: 'same-origin',
        headers: headers(true),
        body: JSON.stringify({ notes }),
        keepalive: options.keepalive,
    });

    return (await parse<{ task: { id: number; notes: string } }>(response)).task
        .notes;
}

/** Una pregunta al asistente con la conversación anterior; responde con su id (en cola). */
export async function askAssistant(
    question: string,
    history: { role: 'user' | 'assistant'; content: string }[],
): Promise<AssistantQuestion> {
    const response = await fetch(askRoute.url(), {
        method: 'POST',
        credentials: 'same-origin',
        headers: headers(true),
        body: JSON.stringify({ question, history }),
    });

    return (await parse<{ question: AssistantQuestion }>(response)).question;
}

export async function fetchAssistantQuestion(
    id: string,
): Promise<AssistantQuestion> {
    const response = await fetch(showQuestion.url(id), {
        credentials: 'same-origin',
        headers: headers(false),
    });

    return (await parse<{ question: AssistantQuestion }>(response)).question;
}
