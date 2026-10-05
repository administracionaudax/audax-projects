/**
 * Peticiones JSON de los hooks de tiempo real (contadores, «leído por», presencia, conversación
 * abierta y avisos del navegador). Misma sesión que la página: cookie + cabecera X-XSRF-TOKEN
 * (Laravel también acepta Sec-Fetch-Site: same-origin).
 */

import { xsrfToken } from '@/lib/xsrf';

export class RealtimeHttpError extends Error {
    constructor(public readonly status: number) {
        super(`HTTP ${status}`);
        this.name = 'RealtimeHttpError';
    }
}

type Method = 'GET' | 'POST' | 'DELETE';

/**
 * Devuelve el JSON de la respuesta, o null si no tiene cuerpo (204). Lanza RealtimeHttpError si
 * el servidor responde con un error.
 */
export async function realtimeRequest<T>(
    url: string,
    options: {
        method?: Method;
        body?: unknown;
        /** Para las peticiones al cerrar u ocultar la pestaña (sobreviven a la descarga). */
        keepalive?: boolean;
        signal?: AbortSignal;
    } = {},
): Promise<T | null> {
    const method = options.method ?? 'GET';
    const headers: Record<string, string> = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    };
    const token = method === 'GET' ? null : xsrfToken();

    if (token) {
        headers['X-XSRF-TOKEN'] = token;
    }

    if (options.body !== undefined) {
        headers['Content-Type'] = 'application/json';
    }

    const response = await fetch(url, {
        method,
        headers,
        credentials: 'same-origin',
        body:
            options.body === undefined
                ? undefined
                : JSON.stringify(options.body),
        keepalive: options.keepalive,
        signal: options.signal,
    });

    if (!response.ok) {
        throw new RealtimeHttpError(response.status);
    }

    if (response.status === 204) {
        return null;
    }

    const text = await response.text();

    return text === '' ? null : (JSON.parse(text) as T);
}
