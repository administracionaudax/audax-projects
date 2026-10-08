import { useCallback, useState } from 'react';
import type { ChatSectionKey } from '@/components/chat/conversation-tree';

/**
 * Cómo deja cada persona la lista del chat en este navegador (D-273, D-246): qué tipo ve en la barra
 * de iconos (todo, directos, proyectos y clientes o canales), «Solo los míos» y «Ocultar
 * archivados». En localStorage, con try/catch: sin almacenamiento (modo privado o bloqueado) vale lo
 * de por defecto y lo que cambie dura lo que la visita.
 */

export type ChatListView = 'all' | ChatSectionKey;

export const CHAT_LIST_VIEWS: ChatListView[] = [
    'all',
    'direct',
    'clients',
    'channels',
];

export type ChatListPrefs = {
    view: ChatListView;
    mineOnly: boolean;
    hideArchived: boolean;
};

export const DEFAULT_CHAT_LIST_PREFS: ChatListPrefs = {
    view: 'all',
    mineOnly: false,
    hideArchived: false,
};

function view(value: unknown): ChatListView {
    return CHAT_LIST_VIEWS.includes(value as ChatListView)
        ? (value as ChatListView)
        : 'all';
}

const PREFIX = 'audax.chat.list';

export function chatListPrefsKey(personId: number | null): string {
    return `${PREFIX}.${personId ?? 0}`;
}

function bool(value: unknown, fallback: boolean): boolean {
    return typeof value === 'boolean' ? value : fallback;
}

export function readChatListPrefs(key: string): ChatListPrefs {
    try {
        const raw = window.localStorage.getItem(key);
        const parsed: unknown = raw ? JSON.parse(raw) : null;

        if (typeof parsed !== 'object' || parsed === null) {
            return DEFAULT_CHAT_LIST_PREFS;
        }

        const data = parsed as Partial<Record<keyof ChatListPrefs, unknown>>;

        return {
            view: view(data.view),
            mineOnly: bool(data.mineOnly, false),
            hideArchived: bool(data.hideArchived, false),
        };
    } catch {
        return DEFAULT_CHAT_LIST_PREFS;
    }
}

function write(key: string, prefs: ChatListPrefs): void {
    try {
        window.localStorage.setItem(key, JSON.stringify(prefs));
    } catch {
        // Sin almacenamiento: se queda en memoria.
    }
}

export function useChatListPrefs(personId: number | null) {
    const key = chatListPrefsKey(personId);
    const [state, setState] = useState(() => ({
        key,
        prefs: readChatListPrefs(key),
    }));

    // Otra persona en la misma pestaña (cambio de sesión): se leen las suyas.
    const current =
        state.key === key ? state : { key, prefs: readChatListPrefs(key) };
    if (current !== state) {
        setState(current);
    }

    const update = useCallback(
        (change: (prefs: ChatListPrefs) => ChatListPrefs) => {
            setState((previous) => {
                const prefs = change(previous.prefs);
                write(previous.key, prefs);

                return { key: previous.key, prefs };
            });
        },
        [],
    );

    const setView = useCallback(
        (next: ChatListView) => update((prefs) => ({ ...prefs, view: next })),
        [update],
    );

    const setMineOnly = useCallback(
        (mineOnly: boolean) => update((prefs) => ({ ...prefs, mineOnly })),
        [update],
    );

    const setHideArchived = useCallback(
        (hideArchived: boolean) =>
            update((prefs) => ({ ...prefs, hideArchived })),
        [update],
    );

    return {
        prefs: current.prefs,
        setView,
        setMineOnly,
        setHideArchived,
    };
}
