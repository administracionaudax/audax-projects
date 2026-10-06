import { useCallback, useState } from 'react';
import type { ChatSectionKey } from '@/components/chat/conversation-tree';

/**
 * Cómo deja cada persona la lista del chat en este navegador (D-273): niveles plegados, «Solo los
 * míos» y «Ocultar archivados». En localStorage, con try/catch: sin almacenamiento (modo privado o
 * bloqueado) vale lo de por defecto y lo que cambie dura lo que la visita.
 */

export type ChatListPrefs = {
    collapsed: Record<ChatSectionKey, boolean>;
    mineOnly: boolean;
    hideArchived: boolean;
};

export const DEFAULT_CHAT_LIST_PREFS: ChatListPrefs = {
    collapsed: { channels: false, clients: false, direct: false },
    mineOnly: false,
    hideArchived: false,
};

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
        const collapsed =
            typeof data.collapsed === 'object' && data.collapsed !== null
                ? (data.collapsed as Partial<Record<ChatSectionKey, unknown>>)
                : {};

        return {
            collapsed: {
                channels: bool(collapsed.channels, false),
                clients: bool(collapsed.clients, false),
                direct: bool(collapsed.direct, false),
            },
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

    const toggleSection = useCallback(
        (section: ChatSectionKey) =>
            update((prefs) => ({
                ...prefs,
                collapsed: {
                    ...prefs.collapsed,
                    [section]: !prefs.collapsed[section],
                },
            })),
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
        toggleSection,
        setMineOnly,
        setHideArchived,
    };
}
