/**
 * Componentes del tiempo real (Fase 6, área C2) para las pantallas del chat y la navegación.
 * Los hooks y su contrato están documentados en resources/js/hooks/use-realtime.ts.
 */
export { RealtimeConnectionNotice } from '@/components/realtime/connection-notice';
export {
    PresenceDot,
    PresenceIcon,
    PresenceLabel,
    UserAvatar,
    presenceLabel,
    type AvatarUser,
} from '@/components/realtime/presence-indicator';
export { PushNotificationsToggle } from '@/components/realtime/push-toggle';
export { ReadBy, readByText } from '@/components/realtime/read-by';
export { RealtimeRoot } from '@/components/realtime/realtime-root';
export {
    TypingIndicator,
    typingText,
} from '@/components/realtime/typing-indicator';
export {
    ChatUnreadBadge,
    ConversationUnreadBadge,
} from '@/components/realtime/unread-badge';
