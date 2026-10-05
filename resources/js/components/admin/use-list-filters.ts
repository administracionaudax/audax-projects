import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

type FilterValues = Record<string, string | null>;

/** Quita los filtros vacíos o con su valor por defecto, para URL limpias (?q=ana&rol=employee). */
export function cleanFilters(
    filters: FilterValues,
    defaults: FilterValues = {},
): Record<string, string> {
    const query: Record<string, string> = {};

    for (const [key, value] of Object.entries(filters)) {
        if (value !== null && value !== '' && value !== defaults[key]) {
            query[key] = value;
        }
    }

    return query;
}

/**
 * Filtros de un listado en la URL (se pueden compartir y sobreviven a recargar). Los cambios de
 * selector se aplican al momento; el texto de búsqueda, 300 ms después de dejar de escribir.
 * Conserva el estado y el scroll de la página y no llena el historial (replace).
 */
export function useListFilters<T extends FilterValues>(
    url: string,
    initial: T,
    defaults: Partial<T> = {},
    only: string[] = [],
) {
    const [filters, setFilters] = useState<T>(initial);
    const timer = useRef<number | undefined>(undefined);

    useEffect(() => () => window.clearTimeout(timer.current), []);

    const visit = (next: T) => {
        router.get(url, cleanFilters(next, defaults as FilterValues), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            ...(only.length > 0 ? { only } : {}),
        });
    };

    const update = (key: keyof T, value: string | null, debounce = false) => {
        const next = { ...filters, [key]: value } as T;
        setFilters(next);
        window.clearTimeout(timer.current);

        if (debounce) {
            timer.current = window.setTimeout(() => visit(next), 300);
        } else {
            visit(next);
        }
    };

    /** Varios filtros a la vez en una sola visita (p. ej. el orden y su sentido). */
    const updateMany = (values: Partial<T>) => {
        const next = { ...filters, ...values } as T;
        setFilters(next);
        window.clearTimeout(timer.current);
        visit(next);
    };

    const reset = () => {
        const next = { ...initial };
        for (const key of Object.keys(next)) {
            (next as FilterValues)[key] =
                (defaults as FilterValues)[key] ?? (key === 'q' ? '' : null);
        }
        setFilters(next);
        window.clearTimeout(timer.current);
        visit(next);
    };

    return { filters, update, updateMany, reset };
}
