import {
    useCallback,
    useEffect,
    useLayoutEffect,
    useMemo,
    useRef,
    useState,
} from 'react';
import { toast } from 'sonner';
import { ChatApiError, chatApi } from '@/components/chat/chat-api';
import {
    useConversationChannel,
    useUnreadCounter,
} from '@/components/chat/realtime-bridge';
import { t } from '@/lib/i18n';
import type {
    ChatConversation,
    ChatMessage,
    ChatMessageResponse,
    ChatMessagesPage,
    ChatPinnedMessage,
    ChatUser,
} from '@/types/chat';

/**
 * Estado de una conversación abierta (SPEC §12): la ventana de mensajes cargada (paginación por
 * cursor hacia atrás y, tras saltar a un mensaje, hacia delante), los fijados y las acciones, que
 * devuelven el mensaje actualizado. «Leído por» lo lleva useReadReceipts (C2).
 *
 * Tiempo real: con Echo (C2) llegan avisos con ids y aquí se piden los datos a las rutas del
 * chat; sin él (prop `realtime` null), la consulta periódica cada 10 s (60 s con tiempo real,
 * como red de seguridad) trae los nuevos y los cambiados. Solo con la pestaña visible.
 * Los avisos llegan también a la pestaña que hizo el cambio: todo se mezcla por id, y el aviso de
 * un mensaje propio que aún se está enviando se ignora (llega con la respuesta del envío).
 */

export const POLL_MS = 10_000;
export const POLL_CONNECTED_MS = 60_000;

let pendingSequence = 0;

function byId(messages: ChatMessage[]): Map<number, ChatMessage> {
    return new Map(messages.map((message) => [message.id, message]));
}

/** Mezcla mensajes (por id), ordenados; los que se están enviando van siempre al final. */
export function mergeMessages(
    current: ChatMessage[],
    incoming: ChatMessage[],
): ChatMessage[] {
    const saved = byId(current.filter((message) => message.id > 0));

    for (const message of incoming) {
        saved.set(message.id, message);
    }

    const pending = current.filter(
        (message) => message.id < 0 && !saved.has(message.id),
    );

    return [...saved.values()].sort((a, b) => a.id - b.id).concat(pending);
}

/** Sustituye solo los que ya están cargados (cambios de la consulta periódica). */
function replaceLoaded(
    current: ChatMessage[],
    updated: ChatMessage[],
): ChatMessage[] {
    if (updated.length === 0) {
        return current;
    }

    const changes = byId(updated);

    return current.map((message) => changes.get(message.id) ?? message);
}

function lastSavedId(messages: ChatMessage[]): number {
    for (let index = messages.length - 1; index >= 0; index--) {
        if (messages[index].id > 0) {
            return messages[index].id;
        }
    }

    return 0;
}

function firstSavedId(messages: ChatMessage[]): number {
    return messages.find((message) => message.id > 0)?.id ?? 0;
}

function describeError(error: unknown): string {
    return error instanceof ChatApiError
        ? error.firstError()
        : t('chat.errors.server');
}

export type ConversationController = ReturnType<typeof useConversation>;

export function useConversation({
    conversation,
    initial,
    initialPinned,
    currentUser,
    onIncoming,
    onActivity,
}: {
    conversation: ChatConversation;
    initial: ChatMessagesPage;
    initialPinned: ChatPinnedMessage[];
    currentUser: ChatUser;
    /** Mensajes nuevos de otras personas (aviso accesible y desplazamiento). */
    onIncoming?: (messages: ChatMessage[]) => void;
    /** Algo ha cambiado en la conversación (para la lista: último mensaje, no leídos). */
    onActivity?: (event: {
        lastMessage?: ChatMessage;
        unread?: number;
    }) => void;
}) {
    const [messages, setMessages] = useState<ChatMessage[]>(initial.messages);
    const [hasOlder, setHasOlder] = useState(initial.has_older);
    const [hasNewer, setHasNewer] = useState(initial.has_newer);
    const [pinned, setPinned] = useState(initialPinned);
    const [users, setUsers] = useState<Map<number, ChatUser>>(
        () =>
            new Map(
                [...conversation.participants, ...initial.users].map((user) => [
                    user.id,
                    user,
                ]),
            ),
    );
    const [loadingOlder, setLoadingOlder] = useState(false);
    const [loadingNewer, setLoadingNewer] = useState(false);
    const [pollFailed, setPollFailed] = useState(false);
    const since = useRef<string>(initial.server_time);
    const polling = useRef(false);
    const lastMarked = useRef<number>(conversation.last_read_message_id ?? 0);
    // Lo último de cada render, para la consulta periódica y los avisos del tiempo real (que
    // viven en efectos y no deben reiniciarse con cada mensaje).
    const latest = useRef({ messages, hasNewer, onIncoming, onActivity });

    useLayoutEffect(() => {
        latest.current = { messages, hasNewer, onIncoming, onActivity };
    });

    const names = useMemo(
        () =>
            new Map(
                [...users.values()].map(
                    (user) => [user.id, user.name] as const,
                ),
            ),
        [users],
    );

    const rememberUsers = useCallback((list: ChatUser[]) => {
        if (list.length === 0) {
            return;
        }

        setUsers((current) => {
            const next = new Map(current);

            for (const user of list) {
                next.set(user.id, user);
            }

            return next;
        });
    }, []);

    /** Aplica la respuesta de una acción (el mensaje ya actualizado). */
    const apply = useCallback(
        (response: ChatMessageResponse) => {
            rememberUsers(response.users);
            setMessages((current) =>
                current.some((message) => message.id === response.message.id)
                    ? replaceLoaded(current, [response.message])
                    : current,
            );

            if (response.pinned) {
                setPinned(response.pinned);
            }
        },
        [rememberUsers],
    );

    const poll = useCallback(async () => {
        if (polling.current) {
            return;
        }

        polling.current = true;
        const { messages, hasNewer, onIncoming, onActivity } = latest.current;

        try {
            const after = lastSavedId(messages);
            const data = await chatApi.poll(conversation.id, {
                after,
                from: firstSavedId(messages),
                since: since.current,
            });

            since.current = data.server_time;
            setPollFailed(false);
            rememberUsers(data.users);
            setPinned(data.pinned);

            // Tras saltar a un mensaje antiguo, lo nuevo no es contiguo: se carga al bajar.
            const fresh = hasNewer ? [] : data.messages;

            setMessages((current) =>
                mergeMessages(replaceLoaded(current, data.updated), fresh),
            );

            if (data.has_more && !hasNewer) {
                setHasNewer(true);
            }

            const incoming = fresh.filter(
                (message) => message.author?.id !== currentUser.id,
            );

            if (incoming.length > 0) {
                onIncoming?.(incoming);
            }

            if (fresh.length > 0) {
                onActivity?.({ lastMessage: fresh[fresh.length - 1] });
            }
        } catch {
            setPollFailed(true);
        } finally {
            polling.current = false;
        }
    }, [conversation.id, currentUser.id, rememberUsers]);

    const refreshMessage = useCallback(
        async (messageId: number) => {
            const loaded = latest.current.messages.some(
                (message) => message.id === messageId,
            );

            if (!loaded) {
                // No está en la ventana: si es nuevo, llega con las novedades.
                await poll();

                return;
            }

            try {
                apply(await chatApi.show(messageId));
            } catch {
                // Se volverá a intentar con la siguiente consulta periódica.
            }
        },
        [apply, poll],
    );

    // Funciones estables del almacén de C2 (no cambian con cada recuento).
    const { markRead: markCounterRead, refresh: refreshCounters } =
        useUnreadCounter();

    const { live: connected } = useConversationChannel(conversation.id, {
        onMessagePosted: (event) => {
            const loaded = latest.current.messages;

            // Ya está (lo trajo la consulta o la respuesta del envío) o es uno propio que se está
            // enviando: su respuesta lo sustituirá. Así no se duplica ni se pide dos veces.
            if (
                loaded.some((message) => message.id === event.message_id) ||
                (event.user_id === currentUser.id &&
                    loaded.some((message) => message.pending === 'sending'))
            ) {
                return;
            }

            void poll();
        },
        onMessageUpdated: (event) => void refreshMessage(event.message_id),
        onAudioTranscribed: (event) => void refreshMessage(event.message_id),
        onReconnect: () => void poll(),
    });

    useEffect(() => {
        const interval = connected ? POLL_CONNECTED_MS : POLL_MS;
        let timer = 0;
        let stopped = false;

        const tick = async () => {
            if (document.visibilityState === 'visible') {
                await poll();
            }

            if (!stopped) {
                timer = window.setTimeout(tick, interval);
            }
        };

        const onVisible = () => {
            if (document.visibilityState === 'visible') {
                void poll();
            }
        };

        timer = window.setTimeout(tick, interval);
        document.addEventListener('visibilitychange', onVisible);
        window.addEventListener('focus', onVisible);

        return () => {
            stopped = true;
            window.clearTimeout(timer);
            document.removeEventListener('visibilitychange', onVisible);
            window.removeEventListener('focus', onVisible);
        };
    }, [connected, poll]);

    const loadOlder = useCallback(async () => {
        const oldest = firstSavedId(messages);

        if (loadingOlder || !hasOlder || oldest === 0) {
            return;
        }

        setLoadingOlder(true);

        try {
            const page = await chatApi.messages(conversation.id, {
                antes: oldest,
            });
            rememberUsers(page.users);
            setMessages((current) => mergeMessages(current, page.messages));
            setHasOlder(page.has_older);
        } catch (error) {
            toast.error(describeError(error));
        } finally {
            setLoadingOlder(false);
        }
    }, [conversation.id, hasOlder, loadingOlder, messages, rememberUsers]);

    const loadNewer = useCallback(async () => {
        const newest = lastSavedId(messages);

        if (loadingNewer || !hasNewer || newest === 0) {
            return;
        }

        setLoadingNewer(true);

        try {
            const page = await chatApi.messages(conversation.id, {
                despues: newest,
            });
            rememberUsers(page.users);
            setMessages((current) => mergeMessages(current, page.messages));
            setHasNewer(page.has_newer);
        } catch (error) {
            toast.error(describeError(error));
        } finally {
            setLoadingNewer(false);
        }
    }, [conversation.id, hasNewer, loadingNewer, messages, rememberUsers]);

    /** Carga la ventana alrededor de un mensaje que no está cargado. Devuelve si existe. */
    const loadAround = useCallback(
        async (messageId: number): Promise<boolean> => {
            try {
                const page = await chatApi.messages(conversation.id, {
                    alrededor: messageId,
                });
                rememberUsers(page.users);
                setMessages(page.messages);
                setHasOlder(page.has_older);
                setHasNewer(page.has_newer);

                return true;
            } catch (error) {
                toast.error(
                    error instanceof ChatApiError && error.status === 404
                        ? t('chat.errors.message_gone')
                        : describeError(error),
                );

                return false;
            }
        },
        [conversation.id, rememberUsers],
    );

    /** Vuelve a los últimos mensajes (tras saltar a uno antiguo o una ráfaga de nuevos). */
    const loadLatest = useCallback(async () => {
        try {
            const page = await chatApi.messages(conversation.id);
            rememberUsers(page.users);
            setMessages((current) =>
                mergeMessages(
                    [],
                    [
                        ...page.messages,
                        ...current.filter((message) => message.id < 0),
                    ],
                ),
            );
            setHasOlder(page.has_older);
            setHasNewer(false);
        } catch (error) {
            toast.error(describeError(error));
        }
    }, [conversation.id, rememberUsers]);

    const deliver = useCallback(
        async (localId: number, body: string, parentId: number | null) => {
            try {
                const response = await chatApi.send(conversation.id, {
                    body,
                    parent_id: parentId,
                });
                rememberUsers(response.users);
                setMessages((current) =>
                    mergeMessages(
                        current.filter((message) => message.id !== localId),
                        [response.message],
                    ),
                );
                onActivity?.({ lastMessage: response.message });

                return true;
            } catch (error) {
                setMessages((current) =>
                    current.map((message) =>
                        message.id === localId
                            ? { ...message, pending: 'failed' as const }
                            : message,
                    ),
                );
                toast.error(describeError(error));

                return false;
            }
        },
        [conversation.id, onActivity, rememberUsers],
    );

    /** Publica un mensaje: se ve al momento («Enviando…») y se sustituye por el del servidor. */
    const send = useCallback(
        async (body: string, parent: ChatMessage | null) => {
            pendingSequence += 1;
            const localId = -pendingSequence;
            const optimistic: ChatMessage = {
                id: localId,
                conversation_id: conversation.id,
                type: 'text',
                author: currentUser,
                body,
                created_at: new Date().toISOString(),
                edited_at: null,
                deleted: false,
                hidden: false,
                hidden_by: null,
                pinned: false,
                pinned_by: null,
                parent: parent
                    ? {
                          id: parent.id,
                          type: parent.type,
                          author: parent.author?.name ?? null,
                          excerpt: parent.body,
                          deleted: parent.deleted,
                          hidden: parent.hidden,
                          system: parent.system,
                      }
                    : null,
                reactions: [],
                attachments: [],
                audio: null,
                transcription: null,
                task: null,
                link_preview: null,
                system: null,
                can: {
                    edit: false,
                    delete: false,
                    reply: false,
                    react: false,
                    pin: false,
                    moderate: false,
                    create_task: false,
                },
                pending: 'sending',
            };

            setMessages((current) => [...current, optimistic]);

            // Tras saltar a un mensaje antiguo, al escribir se vuelve a lo último.
            if (hasNewer) {
                void loadLatest();
            }

            return deliver(localId, body, parent?.id ?? null);
        },
        [conversation.id, currentUser, deliver, hasNewer, loadLatest],
    );

    const retry = useCallback(
        (localId: number) => {
            const message = messages.find((item) => item.id === localId);

            if (!message || message.body === null) {
                return;
            }

            setMessages((current) =>
                current.map((item) =>
                    item.id === localId
                        ? { ...item, pending: 'sending' as const }
                        : item,
                ),
            );
            void deliver(localId, message.body, message.parent?.id ?? null);
        },
        [deliver, messages],
    );

    const discard = useCallback((localId: number) => {
        setMessages((current) =>
            current.filter((message) => message.id !== localId),
        );
    }, []);

    /** Ejecuta una acción sobre un mensaje y aplica su respuesta (o avisa del error). */
    const act = useCallback(
        async (action: () => Promise<ChatMessageResponse>) => {
            try {
                const response = await action();
                apply(response);

                return response;
            } catch (error) {
                toast.error(describeError(error));

                return null;
            }
        },
        [apply],
    );

    const react = useCallback(
        (message: ChatMessage, emoji: string) => {
            // Al momento en la pantalla; la respuesta del servidor manda.
            setMessages((current) =>
                current.map((item) => {
                    if (item.id !== message.id) {
                        return item;
                    }

                    const existing = item.reactions.find(
                        (reaction) => reaction.emoji === emoji,
                    );
                    const reactions = existing
                        ? item.reactions
                              .map((reaction) =>
                                  reaction.emoji !== emoji
                                      ? reaction
                                      : {
                                            ...reaction,
                                            count:
                                                reaction.count +
                                                (reaction.reacted ? -1 : 1),
                                            reacted: !reaction.reacted,
                                            users: reaction.reacted
                                                ? reaction.users.filter(
                                                      (name) =>
                                                          name !==
                                                          currentUser.name,
                                                  )
                                                : [
                                                      ...reaction.users,
                                                      currentUser.name,
                                                  ],
                                        },
                              )
                              .filter((reaction) => reaction.count > 0)
                        : [
                              ...item.reactions,
                              {
                                  emoji,
                                  count: 1,
                                  reacted: true,
                                  users: [currentUser.name],
                              },
                          ];

                    return { ...item, reactions };
                }),
            );

            return act(() => chatApi.react(message.id, emoji)).then(
                (response) => {
                    if (response === null) {
                        // Se deshace lo pintado.
                        void refreshMessage(message.id);
                    }

                    return response;
                },
            );
        },
        [act, currentUser.name, refreshMessage],
    );

    const markRead = useCallback(async () => {
        const newest = lastSavedId(messages);

        if (
            !conversation.is_participant ||
            newest === 0 ||
            newest <= lastMarked.current ||
            hasNewer
        ) {
            return;
        }

        lastMarked.current = newest;

        try {
            const result = await chatApi.read(conversation.id, newest);

            // Contadores de C2: a cero al momento o, si han llegado más mientras, a pedirlos.
            if (result.unread === 0) {
                markCounterRead(conversation.id);
            } else {
                void refreshCounters();
            }

            onActivity?.({ unread: result.unread });
        } catch {
            // Se volverá a marcar al ver el siguiente mensaje.
            lastMarked.current = 0;
        }
    }, [
        conversation.id,
        conversation.is_participant,
        hasNewer,
        messages,
        markCounterRead,
        onActivity,
        refreshCounters,
    ]);

    return {
        messages,
        users,
        names,
        hasOlder,
        hasNewer,
        loadingOlder,
        loadingNewer,
        pinned,
        pollFailed,
        connected,
        loadOlder,
        loadNewer,
        loadAround,
        loadLatest,
        send,
        retry,
        discard,
        react,
        markRead,
        poll,
        refreshMessage,
        edit: (message: ChatMessage, body: string) =>
            act(() => chatApi.edit(message.id, body)),
        remove: (message: ChatMessage) => act(() => chatApi.remove(message.id)),
        pin: (message: ChatMessage, value: boolean) =>
            act(() => chatApi.pin(message.id, value)),
        moderate: (message: ChatMessage, hidden: boolean) =>
            act(() => chatApi.moderate(message.id, hidden)),
        applyResponse: apply,
    };
}
