import { usePage } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { xsrfToken } from '@/lib/xsrf';
import { update as updateNavSections } from '@/routes/nav/sections';

/**
 * Secciones plegables de la barra lateral (D-260), en su orden. Contrato con
 * App\Domain\Navigation\NavSections::SECTIONS (tests/fixtures/nav-sections.json).
 * «Personas» (RR. HH., nombre provisional) y «Facturación» están preparadas: sin entradas
 * visibles no se pintan.
 */
export const NAV_SECTION_IDS = [
    'projects',
    'weekly',
    'people',
    'billing',
    'admin',
] as const;

export type NavSectionId = (typeof NAV_SECTION_IDS)[number];

/** Plegadas mientras la persona no toque nada (D-261): todas menos Proyectos. */
export const DEFAULT_COLLAPSED: NavSectionId[] = [
    'weekly',
    'people',
    'billing',
    'admin',
];

/** Clave del navegador para las secciones plegadas de esta persona. */
export function navSectionsStorageKey(userId: number | null | undefined) {
    return `audax.nav.collapsed.${userId ?? 'guest'}`;
}

/** Solo ids conocidos, sin repetir y en el orden de la barra. */
export function normalizeCollapsed(value: unknown): NavSectionId[] {
    if (!Array.isArray(value)) {
        return [];
    }

    return NAV_SECTION_IDS.filter((id) => value.includes(id));
}

function readStored(key: string): NavSectionId[] | null {
    try {
        const raw = window.localStorage.getItem(key);

        return raw === null ? null : normalizeCollapsed(JSON.parse(raw));
    } catch {
        // Sin acceso al almacenamiento (modo privado, bloqueado) o un valor roto: por defecto.
        return null;
    }
}

function writeStored(key: string, collapsed: NavSectionId[]) {
    try {
        window.localStorage.setItem(key, JSON.stringify(collapsed));
    } catch {
        // Sin almacenamiento, el estado vale para esta visita (y el servidor lo guarda).
    }
}

/**
 * Guardado silencioso en el servidor (PUT /menu/secciones, 204): una petición JSON sin recargar la
 * página, como el orden de Inicio (D-138). Si falla, queda el navegador.
 */
async function saveCollapsed(collapsed: NavSectionId[]) {
    const token = xsrfToken();

    await fetch(updateNavSections.url(), {
        method: 'PUT',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(token ? { 'X-XSRF-TOKEN': token } : {}),
        },
        body: JSON.stringify({ collapsed }),
    });
}

type State = { collapsed: NavSectionId[]; active: string | null };

/**
 * Lo último de esta pestaña: si la barra se vuelve a montar antes de que el servidor tenga el
 * cambio (una visita que sale justo después de plegar), manda lo que la persona acaba de hacer.
 */
let memory: (State & { key: string }) | null = null;

/** Solo para los tests: olvida el estado de la pestaña. */
export function resetNavSectionsMemory() {
    memory = null;
}

/**
 * Estado plegado o desplegado de las secciones de la barra lateral, por persona y persistente
 * (D-260): por defecto solo Proyectos desplegada (D-261); se guarda en el servidor (`navCollapsed`, prop
 * compartida) y en el navegador (localStorage, por si no hay servidor o falla). Al entrar en una
 * página de una sección plegada, la sección se despliega sola; plegar la sección de la página
 * actual se respeta hasta que se entra en otra.
 */
export function useNavSections(activeSection: string | null) {
    const { props } = usePage();
    const key = navSectionsStorageKey(props.auth?.user?.id);
    const fromServer = Array.isArray(props.navCollapsed);

    const [initial] = useState<State>(() => {
        if (memory?.key === key) {
            return { collapsed: memory.collapsed, active: memory.active };
        }

        const collapsed = fromServer
            ? normalizeCollapsed(props.navCollapsed)
            : (readStored(key) ?? DEFAULT_COLLAPSED);

        return { collapsed, active: null };
    });
    const [state, setState] = useState<State>(initial);
    const saved = useRef(JSON.stringify(initial.collapsed));

    // Entrar en otra sección: si está plegada, se despliega (en el render, sin parpadeo).
    let current = state;

    if (activeSection !== state.active) {
        current = {
            active: activeSection,
            collapsed: state.collapsed.filter((id) => id !== activeSection),
        };
        setState(current);
    }

    useEffect(() => {
        memory = { key, ...state };
        const json = JSON.stringify(state.collapsed);

        if (json === saved.current) {
            return;
        }

        saved.current = json;
        writeStored(key, state.collapsed);

        if (fromServer) {
            saveCollapsed(state.collapsed).catch(() => undefined);
        }
    }, [key, state, fromServer]);

    const setOpen = useCallback((id: NavSectionId, open: boolean) => {
        setState((previous) => {
            const without = previous.collapsed.filter(
                (section) => section !== id,
            );

            return {
                ...previous,
                collapsed: open
                    ? without
                    : normalizeCollapsed([...without, id]),
            };
        });
    }, []);

    const isOpen = useCallback(
        (id: string) => !current.collapsed.includes(id as NavSectionId),
        [current.collapsed],
    );

    return { isOpen, setOpen };
}
