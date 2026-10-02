import { usePage } from '@inertiajs/react';
import { useEffect, useLayoutEffect, useRef } from 'react';
import {
    acquireChannel,
    onRealtimeReconnect,
    releaseChannel,
    useRealtimeStatus,
} from '@/hooks/use-realtime-connection';
import { realtimeRequest } from '@/hooks/use-realtime-http';
import { registerOpenConversation } from '@/hooks/use-realtime-unread';
import { realtimeEnabled } from '@/lib/realtime';
import {
    leave as leaveRoute,
    viewing as viewingRoute,
} from '@/routes/realtime/conversations';
import type { AppNotification } from '@/types';

/**
 * TIEMPO REAL DEL CHAT (Fase 6, D-068): API estable para las pantallas del chat.
 * =============================================================================
 *
 * Todo funciona con y sin tiempo real: si la prop compartida `realtime` es null (o Reverb se
 * cae), los hooks no se suscriben a nada y los datos llegan por consultas periódicas. `live`
 * dice en cada momento si hay conexión en vivo: sin ella, recarga tú lo tuyo cada ~15 s
 * (REALTIME_FALLBACK_POLL_MS).
 *
 * useConversationChannel(conversationId, handlers) → { live }
 *   Úsalo en la pantalla de UNA conversación abierta. Además de escuchar su canal privado:
 *   - la marca como abierta: no sube su contador de no leídos ni se avisa (campana ni
 *     navegador) a quien la tiene delante (latido cada 30 s mientras la pestaña está visible),
 *   - handlers (se leen siempre los últimos, no hace falta memorizarlos):
 *       onMessagePosted({ conversation_id, message_id, type, user_id, parent_id, created_at })
 *       onMessageUpdated({ conversation_id, message_id, change })
 *         change: 'edited' | 'deleted' | 'hidden' | 'unhidden' | 'pinned' | 'unpinned' | 'updated'
 *         (reacciones y otros cambios llegan como 'updated')
 *       onRead({ conversation_id, user_id, last_read_message_id })
 *       onAudioTranscribed({ conversation_id, message_id, transcription_id, status })
 *       onReconnect(): la conexión ha vuelto tras un corte → recarga lo que falte.
 *   Los eventos solo llevan ids (nunca el texto): pide el mensaje por tu ruta, que comprueba los
 *   permisos. Llegan también a la pestaña que hizo el cambio: deduplica por message_id.
 *
 * useTyping(conversationId) → { typers, notifyTyping, stopTyping, live }
 *   «Escribiendo…» por whisper (sin servidor). notifyTyping() en cada pulsación (ya limita a una
 *   cada 3 s), stopTyping() al enviar. typers: [{ id, name }] sin ti; se apagan solos a los 6 s.
 *   Pinta <TypingIndicator typers={typers} /> (components/realtime).
 *
 * useReadReceipts(conversationId) → { participants, readersOf, recipientsOf, ready, refresh }
 *   «Leído por»: readersOf(messageId, authorId) devuelve quién lo ha leído;
 *   <ReadBy readers={…} recipients={recipientsOf(authorId)} /> lo pinta.
 *
 * useUnreadCounter() → { total, conversations, muted, count(id), isMuted(id), ready, refresh, markRead(id) }
 *   Contadores de no leídos en vivo, compartidos por toda la app. Tras marcar una conversación
 *   como leída, llama a markRead(id) para ponerla a cero al momento; tras silenciarla, a refresh().
 *   <ChatUnreadBadge /> (total, para la navegación) y <ConversationUnreadBadge conversationId />.
 *
 * usePresence() → { statusOf(userId), connected, live, ready }  (hooks/use-presence.ts)
 *   'online' | 'away' | 'offline'. <PresenceDot userId />, <PresenceLabel userId /> y
 *   <UserAvatar user showPresence />.
 *
 * useNotificationChannel(onNotification, onReconnect?) → { live }
 *   Notificaciones de la campana en vivo (la usa NotificationBell).
 *
 * Avisos del navegador (Web Push): <PushNotificationsToggle /> («Activar avisos en este
 * navegador»), para el chat o los ajustes.
 */

export { realtimeEnabled } from '@/lib/realtime';
export {
    useRealtimeStatus,
    type RealtimeStatus,
} from '@/hooks/use-realtime-connection';
export { useTyping, type TypingUser } from '@/hooks/use-realtime-typing';
export {
    useReadReceipts,
    type ReadParticipant,
} from '@/hooks/use-realtime-reads';
export {
    useUnreadCounter,
    refreshUnread,
    type UnreadCounter,
} from '@/hooks/use-realtime-unread';
export { usePresence, type PresenceStatus } from '@/hooks/use-presence';

/** Cada cuánto recargar una conversación abierta cuando no hay conexión en vivo. */
export const REALTIME_FALLBACK_POLL_MS = 15_000;

/** Cada cuánto se renueva «tengo esta conversación abierta» mientras está visible. */
export const VIEWING_HEARTBEAT_MS = 30_000;

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

/**
 * «Tengo esta conversación abierta»: latido cada 30 s mientras la pestaña está visible; se retira
 * al ocultarla, al cerrar la pantalla o al salir de la página.
 */
function useViewingHeartbeat(conversationId: number | null): void {
    useEffect(() => {
        if (conversationId === null) {
            return;
        }

        let timer: ReturnType<typeof setInterval> | null = null;
        let active = false;

        const beat = () => {
            realtimeRequest(viewingRoute.url(conversationId), {
                method: 'POST',
            }).catch(() => undefined);
        };

        const stop = () => {
            if (!active) {
                return;
            }

            active = false;

            if (timer !== null) {
                clearInterval(timer);
                timer = null;
            }

            realtimeRequest(leaveRoute.url(conversationId), {
                method: 'DELETE',
                keepalive: true,
            }).catch(() => undefined);
        };

        const sync = () => {
            if (document.visibilityState !== 'visible') {
                stop();

                return;
            }

            if (!active) {
                active = true;
                beat();
                timer = setInterval(beat, VIEWING_HEARTBEAT_MS);
            }
        };

        sync();
        document.addEventListener('visibilitychange', sync);
        window.addEventListener('pagehide', stop);
        // Al volver de la caché del navegador (atrás/adelante), la página sigue montada.
        window.addEventListener('pageshow', sync);

        return () => {
            document.removeEventListener('visibilitychange', sync);
            window.removeEventListener('pagehide', stop);
            window.removeEventListener('pageshow', sync);
            stop();
        };
    }, [conversationId]);
}

/**
 * Conversación abierta en pantalla: su canal en vivo, su contador congelado y el latido de
 * «la estoy viendo». Ver la cabecera de este fichero.
 */
export function useConversationChannel(
    conversationId: number | null | undefined,
    handlers: ConversationChannelHandlers,
): { live: boolean } {
    const id = conversationId ?? null;
    const handlersRef = useRef(handlers);

    useLayoutEffect(() => {
        handlersRef.current = handlers;
    });

    useEffect(() => {
        if (id === null) {
            return;
        }

        return registerOpenConversation(id);
    }, [id]);

    useViewingHeartbeat(id);

    useEffect(() => {
        if (id === null || !realtimeEnabled()) {
            return;
        }

        const name = `conversation.${id}`;
        const channel = acquireChannel(name, 'private');

        if (!channel) {
            return;
        }

        const posted = (event: MessagePostedEvent) =>
            handlersRef.current.onMessagePosted?.(event);
        const updated = (event: MessageUpdatedEvent) =>
            handlersRef.current.onMessageUpdated?.(event);
        const read = (event: ConversationReadEvent) =>
            handlersRef.current.onRead?.(event);
        const transcribed = (event: AudioTranscribedEvent) =>
            handlersRef.current.onAudioTranscribed?.(event);

        channel.listen('.message.posted', posted);
        channel.listen('.message.updated', updated);
        channel.listen('.conversation.read', read);
        channel.listen('.audio.transcribed', transcribed);
        const offReconnect = onRealtimeReconnect(() =>
            handlersRef.current.onReconnect?.(),
        );

        return () => {
            channel.stopListening('.message.posted', posted);
            channel.stopListening('.message.updated', updated);
            channel.stopListening('.conversation.read', read);
            channel.stopListening('.audio.transcribed', transcribed);
            offReconnect();
            releaseChannel(name, 'private');
        };
    }, [id]);

    return { live: useRealtimeStatus() === 'connected' };
}

/**
 * Notificaciones nuevas de la campana por el canal personal (App.Models.User.{id}), con el mismo
 * formato que GET /notificaciones/recientes. Sin tiempo real no hace nada: la campana consulta.
 */
export function useNotificationChannel(
    onNotification: (notification: AppNotification) => void,
    onReconnect?: () => void,
): { live: boolean } {
    const userId = usePage().props.auth?.user?.id ?? null;
    const callbacks = useRef({ onNotification, onReconnect });

    useLayoutEffect(() => {
        callbacks.current = { onNotification, onReconnect };
    });

    useEffect(() => {
        if (userId === null || !realtimeEnabled()) {
            return;
        }

        const name = `App.Models.User.${userId}`;
        const channel = acquireChannel(name, 'private');

        if (!channel) {
            return;
        }

        const handler = (event: { notification?: AppNotification }) => {
            if (event.notification) {
                callbacks.current.onNotification(event.notification);
            }
        };

        channel.listen('.notification.created', handler);
        const offReconnect = onRealtimeReconnect(() =>
            callbacks.current.onReconnect?.(),
        );

        return () => {
            channel.stopListening('.notification.created', handler);
            offReconnect();
            releaseChannel(name, 'private');
        };
    }, [userId]);

    return { live: useRealtimeStatus() === 'connected' };
}
