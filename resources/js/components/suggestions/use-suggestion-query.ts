import { router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { suggestionParams } from '@/lib/suggestions';
import type { SuggestionQuery } from '@/lib/suggestions';
import { index as helpIndex } from '@/routes/help';

/** Espera tras dejar de escribir en el buscador antes de pedir los resultados. */
export const SEARCH_DEBOUNCE_MS = 300;

/**
 * Cambia los filtros de la pestaña de sugerencias en la URL (se pueden compartir y la página se
 * recarga solo con sus datos). El buscador espera a que se deje de escribir.
 */
export function useSuggestionQuery(current: SuggestionQuery) {
    const [search, setSearch] = useState(current.q ?? '');
    const [synced, setSynced] = useState(current.q ?? '');
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);

    // Lo que llega del servidor manda (p. ej., al volver atrás).
    if ((current.q ?? '') !== synced) {
        setSynced(current.q ?? '');
        setSearch(current.q ?? '');
    }

    const go = useCallback(
        (
            patch: Partial<SuggestionQuery>,
            options: { replace?: boolean } = {},
        ) => {
            router.get(
                helpIndex.url({
                    query: suggestionParams({
                        ...current,
                        limit: null,
                        ...patch,
                    }),
                }),
                {},
                {
                    preserveState: true,
                    preserveScroll: true,
                    replace: options.replace ?? false,
                    only: ['suggestions', 'tab'],
                },
            );
        },
        [current],
    );

    useEffect(
        () => () => {
            if (timer.current !== null) {
                clearTimeout(timer.current);
            }
        },
        [],
    );

    const onSearch = (value: string) => {
        setSearch(value);

        if (timer.current !== null) {
            clearTimeout(timer.current);
        }

        timer.current = setTimeout(
            () => go({ q: value }, { replace: true }),
            SEARCH_DEBOUNCE_MS,
        );
    };

    return { go, search, onSearch };
}
