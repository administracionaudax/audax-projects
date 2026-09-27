import { useCallback, useEffect, useMemo, useState } from 'react';
import {
    acquireChannel,
    onRealtimeReconnect,
    releaseChannel,
    useRealtimeStatus,
} from '@/hooks/use-realtime-connection';
import { realtimeRequest } from '@/hooks/use-realtime-http';
import { realtimeEnabled } from '@/lib/realtime';
import { reads as readsRoute } from '@/routes/realtime/conversations';

/**
 * «Leído por» (SPEC §12): hasta qué mensaje ha leído cada participante de una conversación
 * (last_read_message_id). Se pide al abrirla; con tiempo real se actualiza con conversation.read,
 * y sin él (o con la conexión caída) se vuelve a pedir cada 30 s mientras la pestaña está visible.
 */

export const READS_POLL_MS = 30_000;

export type ReadParticipant = {
    id: number;
    name: string;
    avatar: string | null;
    is_active: boolean;
    last_read_message_id: number | null;
};

type ReadEvent = {
    conversation_id: number;
    user_id: number;
    last_read_message_id: number;
};

export type ReadReceipts = {
    /** Participantes activos con hasta dónde han leído. */
    participants: ReadParticipant[];
    ready: boolean;
    /** Quién ha leído ese mensaje (sin su autor). */
    readersOf: (
        messageId: number,
        authorId: number | null,
    ) => ReadParticipant[];
    /** Cuántas personas deberían leerlo (participantes activos sin el autor). */
    recipientsOf: (authorId: number | null) => number;
    refresh: () => Promise<void>;
};

export function useReadReceipts(
    conversationId: number | null | undefined,
): ReadReceipts {
    const id = conversationId ?? null;
    const [state, setState] = useState<{
        conversationId: number | null;
        participants: ReadParticipant[];
        /** Ha leído alguien que no está en la lista (entró después): hay que volver a pedirla. */
        stale: boolean;
    }>({ conversationId: null, participants: [], stale: false });
    const status = useRealtimeStatus();
    const live = status === 'connected';

    const refresh = useCallback(async () => {
        if (id === null) {
            return;
        }

        try {
            const data = await realtimeRequest<{
                participants: ReadParticipant[];
            }>(readsRoute.url(id));

            if (data) {
                setState({
                    conversationId: id,
                    participants: data.participants,
                    stale: false,
                });
            }
        } catch {
            // Se reintenta en la siguiente consulta.
        }
    }, [id]);

    useEffect(() => {
        void refresh();
    }, [refresh]);

    useEffect(() => {
        if (id === null || !realtimeEnabled()) {
            return;
        }

        const name = `conversation.${id}`;
        const channel = acquireChannel(name, 'private');

        const onRead = (event: ReadEvent) => {
            setState((current) => {
                if (current.conversationId !== event.conversation_id) {
                    return current;
                }

                if (
                    !current.participants.some(
                        (participant) => participant.id === event.user_id,
                    )
                ) {
                    return { ...current, stale: true };
                }

                return {
                    ...current,
                    participants: current.participants.map((participant) =>
                        participant.id === event.user_id
                            ? {
                                  ...participant,
                                  last_read_message_id: Math.max(
                                      participant.last_read_message_id ?? 0,
                                      event.last_read_message_id,
                                  ),
                              }
                            : participant,
                    ),
                };
            });
        };

        channel?.listen('.conversation.read', onRead);
        const offReconnect = onRealtimeReconnect(() => void refresh());

        return () => {
            channel?.stopListening('.conversation.read', onRead);
            offReconnect();

            if (channel) {
                releaseChannel(name, 'private');
            }
        };
    }, [id, refresh]);

    useEffect(() => {
        if (state.stale) {
            void refresh();
        }
    }, [state.stale, refresh]);

    useEffect(() => {
        if (id === null || live) {
            return;
        }

        const interval = setInterval(() => {
            if (document.visibilityState === 'visible') {
                void refresh();
            }
        }, READS_POLL_MS);

        return () => clearInterval(interval);
    }, [id, live, refresh]);

    const participants = useMemo(
        () => (state.conversationId === id ? state.participants : []),
        [state, id],
    );

    const readersOf = useCallback(
        (messageId: number, authorId: number | null) =>
            participants.filter(
                (participant) =>
                    participant.id !== authorId &&
                    (participant.last_read_message_id ?? 0) >= messageId,
            ),
        [participants],
    );

    const recipientsOf = useCallback(
        (authorId: number | null) =>
            participants.filter((participant) => participant.id !== authorId)
                .length,
        [participants],
    );

    return {
        participants,
        ready: state.conversationId === id && id !== null,
        readersOf,
        recipientsOf,
        refresh,
    };
}
