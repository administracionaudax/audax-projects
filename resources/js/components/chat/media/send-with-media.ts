import { audioFileName } from '@/components/chat/media/media-utils';
import type { SentMediaMessage } from '@/components/chat/media/types';
import { t } from '@/lib/i18n';
import { store as storeMediaRoute } from '@/routes/chat/media';

/**
 * Publicar en el chat con adjuntos y/o un audio: POST /chat/{conversación}/multimedia (C3).
 * Con XMLHttpRequest para informar del progreso de la subida y poder cancelarla (fetch no da el
 * progreso de subida). Devuelve el mensaje publicado (MediaPayload::message) o rechaza con un
 * MediaUploadError con el texto que hay que enseñar.
 */

export type MediaAudioPayload = {
    file: Blob;
    /** Duración medida al grabar; el servidor la comprueba (y después el transcriptor). */
    durationMs: number;
    /** Por defecto, «audio-AAAAMMDD-HHMMSS.webm» según el tipo del archivo. */
    name?: string;
};

export type MediaMessagePayload = {
    body?: string | null;
    files?: File[];
    audio?: MediaAudioPayload | null;
    /** Respuesta en hilo al mensaje con este id. */
    parentId?: number | null;
};

export type SendMediaOptions = {
    /** Fracción subida, de 0 a 1. */
    onProgress?: (fraction: number) => void;
    signal?: AbortSignal;
};

export type MediaUploadErrorKind =
    | 'validation'
    | 'forbidden'
    | 'too_large'
    | 'session'
    | 'throttled'
    | 'network'
    | 'server'
    | 'aborted';

export class MediaUploadError extends Error {
    readonly kind: MediaUploadErrorKind;

    /** Errores de validación por campo (body, files.0, audio, duration_ms…). */
    readonly errors: Record<string, string>;

    constructor(
        kind: MediaUploadErrorKind,
        message: string,
        errors: Record<string, string> = {},
    ) {
        super(message);
        this.name = 'MediaUploadError';
        this.kind = kind;
        this.errors = errors;
    }
}

/** Token CSRF de la cookie XSRF-TOKEN (lo mismo que hace Inertia). */
export function xsrfToken(): string | null {
    if (typeof document === 'undefined') {
        return null;
    }

    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : null;
}

function json(text: string): unknown {
    try {
        return JSON.parse(text) as unknown;
    } catch {
        return null;
    }
}

function validationErrors(body: unknown): Record<string, string> {
    const errors =
        body !== null && typeof body === 'object' && 'errors' in body
            ? (body as { errors: unknown }).errors
            : null;

    if (errors === null || typeof errors !== 'object') {
        return {};
    }

    return Object.fromEntries(
        Object.entries(errors as Record<string, unknown>).map(
            ([field, messages]) => [
                field,
                Array.isArray(messages)
                    ? String(messages[0] ?? '')
                    : String(messages),
            ],
        ),
    );
}

function failure(status: number, body: unknown): MediaUploadError {
    switch (status) {
        case 422: {
            const errors = validationErrors(body);

            return new MediaUploadError(
                'validation',
                Object.values(errors)[0] ?? t('chat_media.send.invalid'),
                errors,
            );
        }
        case 401:
        case 419:
            return new MediaUploadError(
                'session',
                t('chat_media.send.session'),
            );
        case 403:
            return new MediaUploadError(
                'forbidden',
                t('chat_media.send.forbidden'),
            );
        case 413:
            return new MediaUploadError(
                'too_large',
                t('chat_media.send.too_large'),
            );
        case 429:
            return new MediaUploadError(
                'throttled',
                t('chat_media.send.throttled'),
            );
        default:
            return new MediaUploadError('server', t('chat_media.send.server'));
    }
}

/** FormData de la petición: body, parent_id, files[], audio y duration_ms. */
export function mediaFormData(payload: MediaMessagePayload): FormData {
    const data = new FormData();
    const body = payload.body?.trim();

    if (body) {
        data.append('body', body);
    }

    if (payload.parentId) {
        data.append('parent_id', String(payload.parentId));
    }

    for (const file of payload.files ?? []) {
        data.append('files[]', file, file.name);
    }

    if (payload.audio) {
        data.append(
            'audio',
            payload.audio.file,
            payload.audio.name ??
                (payload.audio.file instanceof File
                    ? payload.audio.file.name
                    : audioFileName(payload.audio.file.type)),
        );
        data.append(
            'duration_ms',
            String(Math.max(0, Math.round(payload.audio.durationMs))),
        );
    }

    return data;
}

/**
 * Publica el mensaje. Ejemplo:
 *   await sendWithMedia(42, { body: 'Te paso el plano', files }, { onProgress, signal })
 *   await sendWithMedia(42, { audio: { file, durationMs } })
 */
export function sendWithMedia(
    conversationId: number,
    payload: MediaMessagePayload,
    options: SendMediaOptions = {},
): Promise<SentMediaMessage> {
    return new Promise((resolve, reject) => {
        const { signal, onProgress } = options;

        if (signal?.aborted) {
            reject(
                new MediaUploadError('aborted', t('chat_media.send.aborted')),
            );

            return;
        }

        const request = new XMLHttpRequest();
        const onAbort = () => request.abort();

        request.open('POST', storeMediaRoute.url(conversationId));
        request.setRequestHeader('Accept', 'application/json');
        request.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

        const token = xsrfToken();

        if (token) {
            request.setRequestHeader('X-XSRF-TOKEN', token);
        }

        request.upload.addEventListener('progress', (event) => {
            if (event.lengthComputable && event.total > 0) {
                onProgress?.(Math.min(1, event.loaded / event.total));
            }
        });

        request.addEventListener('load', () => {
            signal?.removeEventListener('abort', onAbort);
            const body = json(request.responseText);

            if (request.status >= 200 && request.status < 300) {
                const message =
                    body !== null &&
                    typeof body === 'object' &&
                    'message' in body
                        ? (body as { message: SentMediaMessage }).message
                        : null;

                if (message) {
                    onProgress?.(1);
                    resolve(message);

                    return;
                }
            }

            reject(failure(request.status, body));
        });

        request.addEventListener('error', () => {
            signal?.removeEventListener('abort', onAbort);
            reject(
                new MediaUploadError('network', t('chat_media.send.network')),
            );
        });

        request.addEventListener('abort', () => {
            signal?.removeEventListener('abort', onAbort);
            reject(
                new MediaUploadError('aborted', t('chat_media.send.aborted')),
            );
        });

        signal?.addEventListener('abort', onAbort, { once: true });
        request.send(mediaFormData(payload));
    });
}
