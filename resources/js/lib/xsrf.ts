/**
 * Valor de la cookie XSRF-TOKEN que pone Laravel, para mandarlo en la cabecera X-XSRF-TOKEN de
 * las peticiones con fetch o XMLHttpRequest (lo mismo que hace Inertia con axios). Es el único
 * lector de la cookie: chat, Gantt, adjuntos, tiempo real, Inicio y Google Sheets lo importan.
 * Devuelve null fuera del navegador o si la cookie no está.
 */
export function xsrfToken(): string | null {
    if (typeof document === 'undefined') {
        return null;
    }

    const match = /(?:^|;\s*)XSRF-TOKEN=([^;]*)/.exec(document.cookie);

    if (!match) {
        return null;
    }

    try {
        return decodeURIComponent(match[1]);
    } catch {
        // Una cookie mal codificada se manda tal cual: el servidor decidirá si vale.
        return match[1];
    }
}
