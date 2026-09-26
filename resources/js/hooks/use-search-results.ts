import { useEffect, useState } from 'react';
import { search } from '@/routes';
import type { SearchResponse, SearchResult } from '@/types';

export const SEARCH_MIN_LENGTH = 2;
export const SEARCH_DEBOUNCE_MS = 200;

export type SearchStatus = 'idle' | 'loading' | 'success' | 'error';

type Completed = {
    query: string;
    status: 'success' | 'error';
    results: SearchResult[];
};

export type UseSearchResultsReturn = {
    status: SearchStatus;
    results: SearchResult[];
    /** Consulta normalizada (sin espacios en los extremos). */
    query: string;
};

async function fetchResults(
    query: string,
    signal: AbortSignal,
): Promise<SearchResult[]> {
    const response = await fetch(search.url({ query: { q: query } }), {
        method: 'GET',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
        signal,
    });

    if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
    }

    const data = (await response.json()) as Partial<SearchResponse>;

    return Array.isArray(data.results) ? data.results : [];
}

/**
 * Búsqueda global: GET /buscar?q= con debounce de 200 ms, mínimo 2 caracteres
 * y cancelación de la petición anterior (AbortController) al seguir escribiendo.
 */
export function useSearchResults(rawQuery: string): UseSearchResultsReturn {
    const query = rawQuery.trim();
    const [completed, setCompleted] = useState<Completed | null>(null);
    const tooShort = query.length < SEARCH_MIN_LENGTH;

    useEffect(() => {
        if (tooShort) {
            return;
        }

        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            fetchResults(query, controller.signal)
                .then((results) => {
                    setCompleted({ query, status: 'success', results });
                })
                .catch(() => {
                    if (!controller.signal.aborted) {
                        setCompleted({ query, status: 'error', results: [] });
                    }
                });
        }, SEARCH_DEBOUNCE_MS);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [query, tooShort]);

    if (tooShort) {
        return { status: 'idle', results: [], query };
    }

    if (!completed || completed.query !== query) {
        return { status: 'loading', results: [], query };
    }

    return { status: completed.status, results: completed.results, query };
}
