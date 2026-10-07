import { useState } from 'react';

/** Grupos que se recuerdan como mucho (los más recientes), para no llenar el navegador. */
const MAX_KEYS = 300;

type Stored = Record<string, boolean>;

function read(storageKey: string | null): Stored {
    if (storageKey === null || typeof window === 'undefined') {
        return {};
    }

    try {
        const raw = window.localStorage.getItem(storageKey);
        const value: unknown = raw === null ? null : JSON.parse(raw);

        if (
            value === null ||
            typeof value !== 'object' ||
            Array.isArray(value)
        ) {
            return {};
        }

        return Object.fromEntries(
            Object.entries(value).filter(
                (entry): entry is [string, boolean] =>
                    typeof entry[1] === 'boolean',
            ),
        );
    } catch {
        // Sin acceso al almacenamiento (modo privado, bloqueado) o un valor roto: por defecto.
        return {};
    }
}

function write(storageKey: string | null, value: Stored): void {
    if (storageKey === null) {
        return;
    }

    try {
        const entries = Object.entries(value);
        window.localStorage.setItem(
            storageKey,
            JSON.stringify(Object.fromEntries(entries.slice(-MAX_KEYS))),
        );
    } catch {
        // Sin almacenamiento, el estado vale para esta visita.
    }
}

export type CollapsedGroups = {
    /** ¿Está plegado? Si la persona no lo ha tocado, `fallback` (el valor por defecto del grupo). */
    isCollapsed: (groupKey: string, fallback?: boolean) => boolean;
    setCollapsed: (groupKey: string, collapsed: boolean) => void;
    /** Pliega o despliega varios a la vez («Plegar todo», «Desplegar todo»). */
    setMany: (groupKeys: string[], collapsed: boolean) => void;
};

/**
 * Grupos plegables que se recuerdan en este navegador (D-320): lo que la persona pliega o
 * despliega a mano, por clave de grupo, en `localStorage[storageKey]` (con try/catch). Lo que no ha
 * tocado sigue el valor por defecto de cada grupo. Con `storageKey` null, solo en memoria.
 */
export function useCollapsedGroups(storageKey: string | null): CollapsedGroups {
    const [state, setState] = useState(() => ({
        key: storageKey,
        value: read(storageKey),
    }));

    // Otra clave (otro proyecto o persona): se lee la suya (estado derivado, sin efectos).
    if (state.key !== storageKey) {
        setState({ key: storageKey, value: read(storageKey) });
    }

    const update = (changes: Stored) => {
        setState((current) => {
            const next = { ...current.value };

            for (const [key, collapsed] of Object.entries(changes)) {
                // Se mueve al final: los últimos tocados son los que se conservan.
                delete next[key];
                next[key] = collapsed;
            }

            write(current.key, next);

            return { key: current.key, value: next };
        });
    };

    return {
        isCollapsed: (groupKey, fallback = false) =>
            state.value[groupKey] ?? fallback,
        setCollapsed: (groupKey, collapsed) =>
            update({ [groupKey]: collapsed }),
        setMany: (groupKeys, collapsed) =>
            update(
                Object.fromEntries(groupKeys.map((key) => [key, collapsed])),
            ),
    };
}
