import { useCallback, useEffect, useState } from 'react';
import { chatApi } from '@/components/chat/chat-api';
import type { ConversationActivity } from '@/components/chat/conversation-view';
import type { ChatConversationItem } from '@/types/chat';

/** Sin tiempo real, la lista se actualiza cada 30 s con la pestaña visible (y al volver a ella). */
export const LIST_POLL_MS = 30_000;

/**
 * Lista de conversaciones de /chat en el navegador: la que llega en las props, la consulta
 * periódica y lo que cuenta la conversación abierta (último mensaje, no leídos, silenciada), que
 * sube la conversación arriba sin esperar a la siguiente consulta.
 */
export function useConversationList(initial: ChatConversationItem[]) {
    const [items, setItems] = useState(initial);
    const [lastInitial, setLastInitial] = useState(initial);
    const [failed, setFailed] = useState(false);

    // Una visita nueva (p. ej. tras crear un grupo) trae la lista del servidor.
    if (initial !== lastInitial) {
        setLastInitial(initial);
        setItems(initial);
    }

    const refresh = useCallback(async (signal?: AbortSignal) => {
        try {
            const data = await chatApi.conversations(signal);
            setItems(data.conversations);
            setFailed(false);
        } catch (error) {
            if (
                !(error instanceof DOMException && error.name === 'AbortError')
            ) {
                setFailed(true);
            }
        }
    }, []);

    useEffect(() => {
        const controller = new AbortController();
        const tick = () => {
            if (document.visibilityState === 'visible') {
                void refresh(controller.signal);
            }
        };
        const id = window.setInterval(tick, LIST_POLL_MS);
        document.addEventListener('visibilitychange', tick);

        return () => {
            window.clearInterval(id);
            document.removeEventListener('visibilitychange', tick);
            controller.abort();
        };
    }, [refresh]);

    const applyActivity = useCallback((activity: ConversationActivity) => {
        setItems((current) => {
            const index = current.findIndex(
                (item) => item.id === activity.conversationId,
            );

            if (index === -1) {
                return current;
            }

            const item: ChatConversationItem = { ...current[index] };

            if (activity.last) {
                item.last_message = activity.last;
                item.last_activity_at = activity.last.created_at;
            }

            if (activity.unread !== undefined) {
                item.unread = activity.unread;
            }

            if (activity.muted !== undefined) {
                item.muted = activity.muted;
            }

            const rest = current.filter((_, position) => position !== index);

            return activity.last
                ? [item, ...rest]
                : [...rest.slice(0, index), item, ...rest.slice(index)];
        });
    }, []);

    return { items, failed, refresh, applyActivity };
}
