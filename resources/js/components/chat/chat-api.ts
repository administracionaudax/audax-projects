import { t } from '@/lib/i18n';
import { xsrfToken } from '@/lib/xsrf';
import {
    conversations as conversationsRoute,
    moderation as moderationRoute,
    mute as muteRoute,
    people as peopleRoute,
    pinned as pinnedRoute,
    read as readRoute,
} from '@/routes/chat';
import {
    destroy as destroyRoute,
    index as messagesRoute,
    moderate as moderateRoute,
    pin as pinRoute,
    poll as pollRoute,
    react as reactRoute,
    show as showRoute,
    store as storeRoute,
    update as updateRoute,
} from '@/routes/chat/messages';
import {
    join as joinChannelRoute,
    leave as leaveChannelRoute,
    store as storeChannelRoute,
    update as updateChannelRoute,
} from '@/routes/chat/channels';
import {
    leave as leaveGroupRoute,
    update as updateGroupRoute,
} from '@/routes/chat/groups';
import {
    destroy as removeMemberRoute,
    store as addMembersRoute,
} from '@/routes/chat/groups/members';
import {
    options as taskOptionsRoute,
    store as taskStoreRoute,
} from '@/routes/chat/messages/task';
import type {
    ChatConversation,
    ChatConversationItem,
    ChatMessageResponse,
    ChatMessagesPage,
    ChatModerationItem,
    ChatPerson,
    ChatPinnedMessage,
    ChatPollResponse,
    ChatTaskOptions,
} from '@/types/chat';

/**
 * Peticiones JSON del chat (la conversación la gestiona el navegador: páginas por cursor, consulta
 * periódica y acciones que devuelven el mensaje actualizado). Mismo origen, con la cookie de sesión
 * y el token XSRF de Laravel. Los errores llegan como ChatApiError con un texto en español.
 */

type Method = 'GET' | 'POST' | 'PATCH' | 'DELETE';

export class ChatApiError extends Error {
    readonly status: number;

    readonly errors: Record<string, string[]>;

    constructor(
        status: number,
        message: string,
        errors: Record<string, string[]> = {},
    ) {
        super(message);
        this.name = 'ChatApiError';
        this.status = status;
        this.errors = errors;
    }

    /** Primer error de validación (o el mensaje general). */
    firstError(): string {
        const first = Object.values(this.errors)[0]?.[0];

        return first ?? this.message;
    }
}

function messageFor(status: number, fallback: string | undefined): string {
    switch (true) {
        case status === 0:
            return t('chat.errors.network');
        case status === 403:
            return t('chat.errors.forbidden');
        case status === 404:
            return t('chat.errors.not_found');
        case status === 419:
            return t('chat.errors.expired');
        case status === 429:
            return t('chat.errors.too_many');
        case status === 422:
            return fallback ?? t('chat.errors.invalid');
        default:
            return t('chat.errors.server');
    }
}

export async function chatRequest<T>(
    method: Method,
    url: string,
    body?: unknown,
    signal?: AbortSignal,
): Promise<T> {
    const headers: Record<string, string> = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    };
    const token = xsrfToken();

    if (token) {
        headers['X-XSRF-TOKEN'] = token;
    }

    if (body !== undefined) {
        headers['Content-Type'] = 'application/json';
    }

    let response: Response;

    try {
        response = await fetch(url, {
            method,
            headers,
            credentials: 'same-origin',
            body: body === undefined ? undefined : JSON.stringify(body),
            signal,
        });
    } catch (error) {
        if (error instanceof DOMException && error.name === 'AbortError') {
            throw error;
        }

        throw new ChatApiError(0, messageFor(0, undefined));
    }

    if (!response.ok) {
        let data: { message?: string; errors?: Record<string, string[]> } = {};

        try {
            data = (await response.json()) as typeof data;
        } catch {
            // Sin cuerpo JSON: basta con el estado.
        }

        const firstValidation = data.errors
            ? Object.values(data.errors)[0]?.[0]
            : undefined;

        throw new ChatApiError(
            response.status,
            messageFor(response.status, firstValidation ?? data.message),
            data.errors ?? {},
        );
    }

    return (await response.json()) as T;
}

type Cursor = { antes?: number; despues?: number; alrededor?: number };

export const chatApi = {
    conversations: (signal?: AbortSignal) =>
        chatRequest<{
            conversations: ChatConversationItem[];
            unread_total: number;
        }>('GET', conversationsRoute.url(), undefined, signal),

    people: () =>
        chatRequest<{ people: ChatPerson[] }>('GET', peopleRoute.url()),

    messages: (conversationId: number, cursor: Cursor = {}) =>
        chatRequest<ChatMessagesPage>(
            'GET',
            messagesRoute.url(conversationId, { query: cursor }),
        ),

    poll: (
        conversationId: number,
        params: { after: number; from: number; since: string | null },
        signal?: AbortSignal,
    ) =>
        chatRequest<ChatPollResponse>(
            'GET',
            pollRoute.url(conversationId, {
                query: {
                    despues: params.after,
                    desde: params.from,
                    ...(params.since ? { cambios: params.since } : {}),
                },
            }),
            undefined,
            signal,
        ),

    send: (
        conversationId: number,
        data: { body: string; parent_id: number | null },
    ) =>
        chatRequest<ChatMessageResponse>(
            'POST',
            storeRoute.url(conversationId),
            data,
        ),

    show: (messageId: number) =>
        chatRequest<ChatMessageResponse>('GET', showRoute.url(messageId)),

    edit: (messageId: number, body: string) =>
        chatRequest<ChatMessageResponse>('PATCH', updateRoute.url(messageId), {
            body,
        }),

    remove: (messageId: number) =>
        chatRequest<ChatMessageResponse>('DELETE', destroyRoute.url(messageId)),

    react: (messageId: number, emoji: string) =>
        chatRequest<ChatMessageResponse>('POST', reactRoute.url(messageId), {
            emoji,
        }),

    pin: (messageId: number, pinned: boolean) =>
        chatRequest<ChatMessageResponse>('PATCH', pinRoute.url(messageId), {
            pinned,
        }),

    moderate: (messageId: number, hidden: boolean) =>
        chatRequest<ChatMessageResponse>(
            'PATCH',
            moderateRoute.url(messageId),
            { hidden },
        ),

    read: (conversationId: number, messageId: number) =>
        chatRequest<{ unread: number; unread_total: number }>(
            'POST',
            readRoute.url(conversationId),
            { message_id: messageId },
        ),

    mute: (conversationId: number, muted: boolean) =>
        chatRequest<{ muted: boolean; unread_total: number }>(
            'PATCH',
            muteRoute.url(conversationId),
            { muted },
        ),

    pinned: (conversationId: number) =>
        chatRequest<{ pinned: ChatPinnedMessage[] }>(
            'GET',
            pinnedRoute.url(conversationId),
        ),

    moderation: () =>
        chatRequest<{ conversations: ChatModerationItem[] }>(
            'GET',
            moderationRoute.url(),
        ),

    renameGroup: (conversationId: number, name: string) =>
        chatRequest<{ conversation: ChatConversation }>(
            'PATCH',
            updateGroupRoute.url(conversationId),
            { name },
        ),

    addToGroup: (conversationId: number, userIds: number[]) =>
        chatRequest<{ conversation: ChatConversation }>(
            'POST',
            addMembersRoute.url(conversationId),
            { user_ids: userIds },
        ),

    removeFromGroup: (conversationId: number, userId: number) =>
        chatRequest<{ conversation: ChatConversation }>(
            'DELETE',
            removeMemberRoute.url({
                conversation: conversationId,
                member: userId,
            }),
        ),

    leaveGroup: (conversationId: number) =>
        chatRequest<{ left: boolean; url: string }>(
            'POST',
            leaveGroupRoute.url(conversationId),
        ),

    createChannel: (data: { name: string; icon: string | null }) =>
        chatRequest<{ id: number; url: string }>(
            'POST',
            storeChannelRoute.url(),
            data,
        ),

    updateChannel: (
        conversationId: number,
        data: { name: string; icon: string | null; archived: boolean },
    ) =>
        chatRequest<{ conversation: ChatConversation }>(
            'PATCH',
            updateChannelRoute.url(conversationId),
            data,
        ),

    joinChannel: (conversationId: number) =>
        chatRequest<{ conversation: ChatConversation }>(
            'POST',
            joinChannelRoute.url(conversationId),
        ),

    leaveChannel: (conversationId: number) =>
        chatRequest<{ conversation: ChatConversation }>(
            'POST',
            leaveChannelRoute.url(conversationId),
        ),

    taskOptions: (messageId: number) =>
        chatRequest<ChatTaskOptions>('GET', taskOptionsRoute.url(messageId)),

    createTask: (
        messageId: number,
        data: {
            title: string;
            hour_bank_id: number | null;
            assignee_user_id: number | null;
            due_date: string | null;
        },
    ) =>
        chatRequest<ChatMessageResponse>(
            'POST',
            taskStoreRoute.url(messageId),
            data,
        ),
};
