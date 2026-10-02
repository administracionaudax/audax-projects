import { usePage } from '@inertiajs/react';
import { useEffect, useMemo, useSyncExternalStore } from 'react';
import {
    acquireChannel,
    onRealtimeReconnect,
    realtimeStatus,
    releaseChannel,
} from '@/hooks/use-realtime-connection';
import type { RealtimeChannel } from '@/hooks/use-realtime-connection';
import { realtimeRequest } from '@/hooks/use-realtime-http';
import { unread as unreadRoute } from '@/routes/realtime';

/**
 * Contadores de mensajes sin leer (SPEC §12), compartidos por toda la interfaz: el total de la
 * navegación y el de cada conversación de la lista usan el mismo almacén y la misma consulta.
 *
 * - Al empezar: GET /tiempo-real/no-leidos.
 * - Con tiempo real: +1 en vivo con cada mensaje ajeno (evento chat.activity del canal personal),
 *   salvo en las conversaciones abiertas en pantalla (useConversationChannel); tras leer en otra
 *   pestaña o dispositivo (conversation.read), al volver a la pestaña o al reconectar, se vuelve a
 *   pedir; y cada 5 minutos, por si algo se ha quedado atrás (mensajes borrados…).
 * - Sin tiempo real: se pide cada 30 s mientras la pestaña está visible.
 * - Las silenciadas tienen su número, pero no suman al total.
 * - Un recuento que se pidió ANTES de un +1 en vivo no lo pisa: el servidor dice hasta qué mensaje
 *   ha contado (`latest_message_id`) y los avisos en vivo de mensajes posteriores se vuelven a
 *   sumar sobre él (D-121).
 */

export const UNREAD_POLL_MS = 30_000;
export const UNREAD_RESYNC_MS = 5 * 60_000;
/** Como mucho una consulta cada 10 s al volver a la pestaña. */
const FOCUS_REFRESH_MS = 10_000;
const READ_REFRESH_DELAY_MS = 400;

export type UnreadResponse = {
    total: number;
    conversations: Record<string, number>;
    muted: number[];
    /** Último mensaje que entra en el recuento (null si aún no hay ninguno). */
    latest_message_id?: number | null;
};

/** Cuántos avisos en vivo recientes se guardan para volver a sumarlos sobre un recuento. */
const RECENT_ACTIVITY = 200;

export type UnreadState = {
    /** Total para la navegación (sin las conversaciones silenciadas). */
    total: number;
    /** Sin leer por conversación (solo las que tienen alguno). */
    conversations: Record<number, number>;
    /** Conversaciones silenciadas. */
    muted: number[];
    /** Ya se ha recibido el primer recuento del servidor. */
    ready: boolean;
};

type ActivityEvent = {
    conversation_id: number;
    message_id: number;
    user_id: number | null;
};

type ReadEvent = {
    conversation_id: number;
    user_id: number;
    last_read_message_id: number;
};

const EMPTY: UnreadState = {
    total: 0,
    conversations: {},
    muted: [],
    ready: false,
};

let state: UnreadState = EMPTY;
let userId: number | null = null;
let consumers = 0;
let inflight: Promise<void> | null = null;
let lastRefresh = 0;
let readTimer: ReturnType<typeof setTimeout> | null = null;
let teardown: (() => void) | null = null;

const listeners = new Set<() => void>();
const openConversations = new Map<number, number>();
/** Avisos en vivo ya sumados que quizá no estén en el próximo recuento del servidor. */
let recentActivity: ActivityEvent[] = [];
/** Mensajes cuyo aviso en vivo ya se ha sumado (el mismo aviso dos veces no suma dos veces). */
let seenActivity: number[] = [];

function emit(next: UnreadState): void {
    state = next;
    listeners.forEach((listener) => listener());
}

function fromResponse(data: UnreadResponse): UnreadState {
    const conversations: Record<number, number> = {};

    for (const [id, count] of Object.entries(data.conversations)) {
        if (count > 0) {
            conversations[Number(id)] = count;
        }
    }

    let total = data.total;
    const upTo = data.latest_message_id;

    // Los avisos en vivo de mensajes que el recuento aún no incluía se vuelven a sumar.
    if (upTo !== undefined) {
        recentActivity = recentActivity.filter(
            (event) => upTo === null || event.message_id > upTo,
        );

        for (const event of recentActivity) {
            if (openConversations.has(event.conversation_id)) {
                continue;
            }

            conversations[event.conversation_id] =
                (conversations[event.conversation_id] ?? 0) + 1;

            if (!data.muted.includes(event.conversation_id)) {
                total += 1;
            }
        }
    }

    return {
        total,
        conversations,
        muted: data.muted,
        ready: true,
    };
}

/** Vuelve a pedir los contadores al servidor (se juntan las peticiones simultáneas). */
export function refreshUnread(): Promise<void> {
    if (inflight) {
        return inflight;
    }

    lastRefresh = Date.now();
    inflight = (async () => {
        try {
            const data = await realtimeRequest<UnreadResponse>(
                unreadRoute.url(),
            );

            if (data) {
                emit(fromResponse(data));
            }
        } catch {
            // Se reintenta en la siguiente consulta.
        } finally {
            inflight = null;
        }
    })();

    return inflight;
}

/**
 * Pone a cero una conversación al momento (por ejemplo, justo después de marcarla como leída);
 * el servidor lo confirma en la siguiente consulta.
 */
export function markConversationRead(conversationId: number): void {
    const count = state.conversations[conversationId] ?? 0;
    // Lo leído ya no se vuelve a sumar sobre un recuento que llegue tarde.
    recentActivity = recentActivity.filter(
        (event) => event.conversation_id !== conversationId,
    );

    if (count === 0) {
        return;
    }

    const conversations = { ...state.conversations };
    delete conversations[conversationId];

    emit({
        ...state,
        conversations,
        total: state.muted.includes(conversationId)
            ? state.total
            : Math.max(0, state.total - count),
    });
}

/**
 * Marca una conversación como abierta en pantalla: sus mensajes nuevos no suben el contador
 * (la pantalla los marca como leídos). Devuelve la función que la cierra.
 */
export function registerOpenConversation(conversationId: number): () => void {
    openConversations.set(
        conversationId,
        (openConversations.get(conversationId) ?? 0) + 1,
    );

    return () => {
        const count = (openConversations.get(conversationId) ?? 1) - 1;

        if (count <= 0) {
            openConversations.delete(conversationId);
        } else {
            openConversations.set(conversationId, count);
        }
    };
}

function onActivity(event: ActivityEvent): void {
    if (
        (event.user_id !== null && event.user_id === userId) ||
        openConversations.has(event.conversation_id)
    ) {
        return;
    }

    const muted = state.muted.includes(event.conversation_id);

    // El mismo aviso dos veces (p. ej. al reconectar) no suma dos veces.
    if (seenActivity.includes(event.message_id)) {
        return;
    }

    seenActivity = [...seenActivity, event.message_id].slice(-RECENT_ACTIVITY);
    recentActivity = [...recentActivity, event].slice(-RECENT_ACTIVITY);

    emit({
        ...state,
        conversations: {
            ...state.conversations,
            [event.conversation_id]:
                (state.conversations[event.conversation_id] ?? 0) + 1,
        },
        total: muted ? state.total : state.total + 1,
    });
}

function onRead(event: ReadEvent): void {
    if (event.user_id !== userId) {
        return;
    }

    if (readTimer !== null) {
        clearTimeout(readTimer);
    }

    readTimer = setTimeout(() => {
        readTimer = null;
        void refreshUnread();
    }, READ_REFRESH_DELAY_MS);
}

function start(currentUserId: number): void {
    const channelName = `App.Models.User.${currentUserId}`;
    const channel: RealtimeChannel | null = acquireChannel(
        channelName,
        'private',
    );

    channel?.listen('.chat.activity', onActivity);
    channel?.listen('.conversation.read', onRead);

    const offReconnect = onRealtimeReconnect(() => void refreshUnread());

    // Sin tiempo real (o con la conexión caída), cada 30 s; en vivo, cada 5 minutos.
    const interval = setInterval(() => {
        if (document.visibilityState !== 'visible') {
            return;
        }

        const live = realtimeStatus() === 'connected';

        if (!live || Date.now() - lastRefresh >= UNREAD_RESYNC_MS) {
            void refreshUnread();
        }
    }, UNREAD_POLL_MS);

    const onVisible = () => {
        if (
            document.visibilityState === 'visible' &&
            Date.now() - lastRefresh >= FOCUS_REFRESH_MS
        ) {
            void refreshUnread();
        }
    };

    document.addEventListener('visibilitychange', onVisible);
    void refreshUnread();

    teardown = () => {
        channel?.stopListening('.chat.activity', onActivity);
        channel?.stopListening('.conversation.read', onRead);

        if (channel) {
            releaseChannel(channelName, 'private');
        }

        offReconnect();
        clearInterval(interval);
        document.removeEventListener('visibilitychange', onVisible);

        if (readTimer !== null) {
            clearTimeout(readTimer);
            readTimer = null;
        }
    };
}

/** Empieza a llevar los contadores (el primero que los usa); devuelve cómo dejarlo. */
export function startUnreadCounter(currentUserId: number): () => void {
    consumers += 1;

    if (consumers === 1 || userId !== currentUserId) {
        teardown?.();
        userId = currentUserId;
        start(currentUserId);
    }

    return () => {
        consumers -= 1;

        if (consumers <= 0) {
            consumers = 0;
            teardown?.();
            teardown = null;
            userId = null;
            recentActivity = [];
            seenActivity = [];
            emit(EMPTY);
        }
    };
}

function subscribe(listener: () => void): () => void {
    listeners.add(listener);

    return () => {
        listeners.delete(listener);
    };
}

function getState(): UnreadState {
    return state;
}

export type UnreadCounter = UnreadState & {
    /** Sin leer de una conversación. */
    count: (conversationId: number) => number;
    /** ¿La conversación está silenciada? */
    isMuted: (conversationId: number) => boolean;
    /** Vuelve a pedir los contadores (p. ej. tras silenciar una conversación). */
    refresh: () => Promise<void>;
    /** Pone a cero una conversación al momento, tras marcarla como leída. */
    markRead: (conversationId: number) => void;
};

/**
 * Contadores de no leídos en vivo (ver la cabecera de este fichero). Funciona igual con y sin
 * tiempo real; sin sesión devuelve ceros.
 */
export function useUnreadCounter(): UnreadCounter {
    const currentUserId = usePage().props.auth?.user?.id ?? null;

    useEffect(() => {
        if (currentUserId === null) {
            return;
        }

        return startUnreadCounter(currentUserId);
    }, [currentUserId]);

    const current = useSyncExternalStore(subscribe, getState, getState);

    return useMemo(
        () => ({
            ...current,
            count: (conversationId: number) =>
                current.conversations[conversationId] ?? 0,
            isMuted: (conversationId: number) =>
                current.muted.includes(conversationId),
            refresh: refreshUnread,
            markRead: markConversationRead,
        }),
        [current],
    );
}

/** Solo para los tests: vuelve al estado inicial. */
export function resetUnreadForTests(): void {
    teardown?.();
    teardown = null;
    consumers = 0;
    userId = null;
    inflight = null;
    lastRefresh = 0;
    openConversations.clear();
    listeners.clear();
    recentActivity = [];
    seenActivity = [];
    state = EMPTY;
}
