/**
 * Tiempo real del chat (Fase 6): la API estable de C2 (resources/js/hooks/use-realtime.ts),
 * con los mismos nombres y firmas que usaba el chat mientras se hacían en paralelo.
 *
 * Los eventos solo llevan ids: el chat pide el mensaje o las novedades a sus propias rutas y
 * deduplica por id (los eventos llegan también a la pestaña que hizo el cambio).
 */
export {
    useConversationChannel,
    usePresence,
    useReadReceipts,
    useTyping,
    useUnreadCounter,
} from '@/hooks/use-realtime';
export type {
    AudioTranscribedEvent,
    ConversationChannelHandlers,
    ConversationReadEvent,
    MessagePostedEvent,
    MessageUpdatedEvent,
    PresenceStatus,
    ReadParticipant,
    TypingUser,
} from '@/hooks/use-realtime';
