import { useSyncExternalStore } from 'react';

const QUERY = '(prefers-reduced-motion: reduce)';

function media(): MediaQueryList | null {
    return typeof window === 'undefined' || !window.matchMedia
        ? null
        : window.matchMedia(QUERY);
}

function subscribe(callback: () => void): () => void {
    const list = media();

    if (!list) {
        return () => {};
    }

    list.addEventListener('change', callback);

    return () => list.removeEventListener('change', callback);
}

/** ¿Ha pedido la persona menos movimiento (`prefers-reduced-motion: reduce`)? */
export function usePrefersReducedMotion(): boolean {
    return useSyncExternalStore(
        subscribe,
        () => media()?.matches ?? false,
        () => false,
    );
}
