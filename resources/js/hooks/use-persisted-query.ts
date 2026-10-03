import { useEffect, useRef } from 'react';

/**
 * Filtros guardados por persona en localStorage (Mis tareas y el calendario del equipo, D-143 y
 * D-144). Todo va en try/catch: sin almacenamiento (modo privado, bloqueado o lleno) la página
 * funciona igual, solo que no recuerda nada.
 */

export type StoredQuery = Record<string, string>;

export function readStoredQuery(key: string): StoredQuery | null {
    try {
        const raw = window.localStorage.getItem(key);

        if (raw === null) {
            return null;
        }

        const value: unknown = JSON.parse(raw);

        if (
            typeof value !== 'object' ||
            value === null ||
            Array.isArray(value)
        ) {
            return null;
        }

        const query: StoredQuery = {};

        for (const [name, item] of Object.entries(value)) {
            if (typeof item === 'string') {
                query[name] = item;
            }
        }

        return query;
    } catch {
        return null;
    }
}

export function writeStoredQuery(key: string, query: StoredQuery): void {
    try {
        window.localStorage.setItem(key, JSON.stringify(query));
    } catch {
        // Sin almacenamiento: no se recuerda.
    }
}

/** ¿La URL trae alguno de estos parámetros? */
export function urlHasAny(search: string, keys: readonly string[]): boolean {
    const params = new URLSearchParams(search);

    return keys.some((key) => params.has(key));
}

/**
 * Recuerda la última consulta (filtros y orden o vista) y, al entrar sin ninguno de esos
 * parámetros en la URL, vuelve a la guardada:
 * - `current`: la consulta de lo que se ve ahora (sin el cursor ni nada efímero),
 * - `restore(stored, done)`: hace la visita a la consulta guardada y llama a `done` al acabar.
 * Mientras se vuelve a la guardada no se escribe nada (si no, se guardaría la vacía de la primera
 * pintada). Si el servidor la corrige (un proyecto que ya no existe), se guarda la corregida.
 */
export function usePersistedQuery(
    key: string,
    current: StoredQuery,
    keys: readonly string[],
    restore: (stored: StoredQuery, done: () => void) => void,
): void {
    const serialized = JSON.stringify(current);
    const pending = useRef<string | null>(null);
    const started = useRef(false);
    const restoreRef = useRef(restore);

    useEffect(() => {
        restoreRef.current = restore;
    });

    useEffect(() => {
        if (started.current) {
            return;
        }

        started.current = true;
        const stored = readStoredQuery(key);

        if (
            stored !== null &&
            Object.keys(stored).length > 0 &&
            !urlHasAny(window.location.search, keys) &&
            JSON.stringify(stored) !== serialized
        ) {
            pending.current = JSON.stringify(stored);
            restoreRef.current(stored, () => {
                pending.current = null;
            });
        }
        // Solo al entrar en la página (con la consulta de la primera pintada).
    }, []);

    useEffect(() => {
        if (!started.current) {
            return;
        }

        if (pending.current !== null) {
            if (serialized !== pending.current) {
                return;
            }

            pending.current = null;
        }

        writeStoredQuery(key, JSON.parse(serialized) as StoredQuery);
    }, [key, serialized]);
}
