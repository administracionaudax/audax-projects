/**
 * PUNTO DE ENGANCHE con el tiempo real (Fase 6, área C2).
 *
 * Mismos nombres y firmas que la API estable de C2 (resources/js/hooks/use-realtime.ts en su
 * rama): useConversationChannel, useTyping, useReadReceipts, useUnreadCounter y usePresence.
 * Hasta integrar las dos ramas, estas versiones no hacen nada (`live` y `ready` en false) y el
 * chat funciona con consultas periódicas. Al integrar basta con sustituir este módulo por:
 *
 *     export {
 *         useConversationChannel, useTyping, useReadReceipts, useUnreadCounter, usePresence,
 *         type ConversationChannelHandlers, type PresenceStatus, type TypingUser,
 *     } from '@/hooks/use-realtime';
 *
 * Los eventos solo llevan ids: el chat pide el mensaje o las novedades a sus propias rutas y
 * deduplica por id (los eventos llegan también a la pestaña que hizo el cambio).
 */

export type MessagePostedEvent = {
    conversation_id: number;
    message_id: number;
    type: 'text' | 'audio' | 'file' | 'system';
    user_id: number | null;
    parent_id: number | null;
    created_at: string | null;
};

export type MessageUpdatedEvent = {
    conversation_id: number;
    message_id: number;
    change:
        | 'edited'
        | 'deleted'
        | 'hidden'
        | 'unhidden'
        | 'pinned'
        | 'unpinned'
        | 'updated';
};

export type ConversationReadEvent = {
    conversation_id: number;
    user_id: number;
    last_read_message_id: number;
};

export type AudioTranscribedEvent = {
    conversation_id: number;
    message_id: number;
    transcription_id: number;
    status: string;
};

export type ConversationChannelHandlers = {
    onMessagePosted?: (event: MessagePostedEvent) => void;
    onMessageUpdated?: (event: MessageUpdatedEvent) => void;
    onRead?: (event: ConversationReadEvent) => void;
    onAudioTranscribed?: (event: AudioTranscribedEvent) => void;
    onReconnect?: () => void;
};

export type TypingUser = { id: number; name: string };

export type PresenceStatus = 'online' | 'away' | 'offline';

export type ReadParticipant = {
    id: number;
    name: string;
    avatar: string | null;
    is_active: boolean;
    last_read_message_id: number | null;
};

export function useConversationChannel(
    conversationId: number | null | undefined,
    handlers: ConversationChannelHandlers,
): { live: boolean } {
    void conversationId;
    void handlers;

    return { live: false };
}

export function useTyping(conversationId: number | null | undefined): {
    typers: TypingUser[];
    notifyTyping: () => void;
    stopTyping: () => void;
    live: boolean;
} {
    void conversationId;

    return { typers: [], notifyTyping: noop, stopTyping: noop, live: false };
}

export function useReadReceipts(conversationId: number | null | undefined): {
    participants: ReadParticipant[];
    ready: boolean;
    readersOf: (
        messageId: number,
        authorId: number | null,
    ) => ReadParticipant[];
    recipientsOf: (authorId: number | null) => number;
    refresh: () => Promise<void>;
} {
    void conversationId;

    return {
        participants: [],
        ready: false,
        readersOf: () => [],
        recipientsOf: () => 0,
        refresh: async () => {},
    };
}

export function usePresence(): {
    statusOf: (userId: number) => PresenceStatus;
    connected: number[];
    live: boolean;
    ready: boolean;
} {
    return {
        statusOf: () => 'offline',
        connected: [],
        live: false,
        ready: false,
    };
}

export function useUnreadCounter(): {
    total: number;
    conversations: Record<number, number>;
    muted: number[];
    ready: boolean;
    count: (conversationId: number) => number;
    isMuted: (conversationId: number) => boolean;
    refresh: () => Promise<void>;
    markRead: (conversationId: number) => void;
} {
    return {
        total: 0,
        conversations: {},
        muted: [],
        ready: false,
        count: () => 0,
        isMuted: () => false,
        refresh: async () => {},
        markRead: noop,
    };
}

function noop(): void {}
