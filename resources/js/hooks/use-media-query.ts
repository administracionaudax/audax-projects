import { useSyncExternalStore } from 'react';

/** Por debajo de `sm` (640 px): el móvil, donde las tablas anchas pasan a tarjetas (D-327). */
export const NARROW_QUERY = '(max-width: 639px)';

function media(query: string): MediaQueryList | null {
    return typeof window === 'undefined' || !window.matchMedia
        ? null
        : window.matchMedia(query);
}

/** ¿Cumple la pantalla la consulta? Se actualiza al cambiar el tamaño (sin servidor: no). */
export function useMediaQuery(query: string): boolean {
    return useSyncExternalStore(
        (callback) => {
            const list = media(query);

            if (!list) {
                return () => {};
            }

            list.addEventListener('change', callback);

            return () => list.removeEventListener('change', callback);
        },
        () => media(query)?.matches ?? false,
        () => false,
    );
}

/** ¿Pantalla estrecha (menos de 640 px)? */
export function useIsNarrow(): boolean {
    return useMediaQuery(NARROW_QUERY);
}
