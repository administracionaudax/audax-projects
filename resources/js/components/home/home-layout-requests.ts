import { xsrfToken } from '@/lib/xsrf';
import {
    destroy as destroyLayout,
    update as updateLayout,
} from '@/routes/home/layout';

/**
 * Guardado silencioso del orden de Inicio (D-138): una petición JSON sin recargar la página (el
 * servidor responde 204). Con Inertia, la respuesta volvería a calcular todas las props de Inicio
 * (tareas, horas, indicadores…) solo para guardar una lista. Lanza un error si no se guarda.
 */
async function send(method: 'PUT' | 'DELETE', url: string, body?: unknown) {
    const token = xsrfToken();
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(body === undefined
                ? {}
                : { 'Content-Type': 'application/json' }),
            ...(token ? { 'X-XSRF-TOKEN': token } : {}),
        },
        body: body === undefined ? undefined : JSON.stringify(body),
    });

    if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
    }
}

/** PUT /inicio/orden: guarda el orden de las tarjetas que se ven. */
export function saveHomeLayout(cards: readonly string[]): Promise<void> {
    return send('PUT', updateLayout.url(), { cards });
}

/** DELETE /inicio/orden: vuelve al orden por defecto. */
export function resetHomeLayout(): Promise<void> {
    return send('DELETE', destroyLayout.url());
}
