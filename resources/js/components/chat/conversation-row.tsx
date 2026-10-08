import { Link } from '@inertiajs/react';
import { Archive, BellOff } from 'lucide-react';
import { ConversationAvatar } from '@/components/chat/chat-avatar';
import { formatListTime } from '@/components/chat/chat-format';
import { systemText } from '@/components/chat/system-notice';
import { ConversationUnreadBadge } from '@/components/realtime';
import { FOCUS_RING } from '@/lib/focus-ring';
import { t } from '@/lib/i18n';
import { urls } from '@/lib/urls';
import { cn } from '@/lib/utils';
import type { ChatConversationItem } from '@/types/chat';

/** Props que cambian al pasar de una conversación a otra (la lista no se vuelve a pedir). */
export const CONVERSATION_PROPS = [
    'conversation',
    'messages',
    'pinned',
    'focus',
];

/** Texto de la vista previa: «Tú: …», «Ana: …» (en grupos, canales y proyectos) o el tipo de mensaje. */
export function previewText(item: ChatConversationItem): string {
    const last = item.last_message;

    if (!last) {
        return t('chat.list.no_messages');
    }

    const body =
        last.kind === 'system' && last.system
            ? systemText(last.system)
            : last.kind === 'hidden'
              ? t('chat.list.hidden')
              : last.kind === 'audio'
                ? t('chat.list.audio')
                : last.kind === 'file' && last.preview === ''
                  ? t('chat.list.file')
                  : last.preview;

    if (last.kind === 'system') {
        return body;
    }

    if (last.is_mine) {
        return `${t('chat.list.you')}: ${body}`;
    }

    return item.type !== 'direct' && last.author
        ? `${last.author.split(/\s+/u)[0]}: ${body}`
        : body;
}

/** Por qué una conversación es de solo lectura, para la etiqueta accesible. */
function readOnlyText(item: ChatConversationItem): string {
    switch (item.type) {
        case 'team':
            return t('chat.list.read_only_channel');
        case 'client':
            return t('chat.list.read_only_client');
        default:
            return t('chat.list.read_only');
    }
}

/**
 * Una fila de la lista del chat: nombre, vista previa sin markdown, hora, no leídos (con número y
 * texto; en vivo con los contadores de C2), presencia en las directas y silenciadas y archivadas
 * marcadas (icono y texto visible). `nested` la sangra bajo su cliente.
 */
export function ConversationRow({
    item,
    active,
    unread,
    counterReady,
    nested = false,
}: {
    item: ChatConversationItem;
    active: boolean;
    unread: number;
    counterReady: boolean;
    nested?: boolean;
}) {
    return (
        <Link
            href={urls.chatConversation(item.id)}
            only={CONVERSATION_PROPS}
            preserveState
            preserveScroll
            aria-current={active ? 'page' : undefined}
            className={cn(
                'flex min-w-0 items-center gap-3 rounded-md px-3 py-2.5 hover:bg-muted',
                nested && 'py-2 pl-7',
                active && 'bg-accent hover:bg-accent',
                FOCUS_RING,
                'focus-visible:ring-offset-0',
            )}
            data-test="chat-conversation-item"
            data-type={item.type}
        >
            <ConversationAvatar conversation={item} small={nested} />
            <span className="grid min-w-0 flex-1 gap-0.5">
                <span className="flex min-w-0 items-baseline gap-1.5">
                    <span
                        className={cn(
                            'min-w-0 truncate text-sm text-foreground',
                            unread > 0 && 'font-medium',
                        )}
                    >
                        {item.title}
                    </span>
                    {item.muted ? (
                        <span
                            className="inline-flex shrink-0 items-center gap-0.5 self-center text-[11px] text-muted-foreground"
                            data-test="chat-item-muted"
                        >
                            <BellOff aria-hidden="true" className="size-3.5" />
                            {t('chat.list.muted')}
                        </span>
                    ) : null}
                    {item.read_only ? (
                        <span
                            className="inline-flex shrink-0 items-center gap-0.5 self-center text-[11px] text-muted-foreground"
                            data-test="chat-item-read-only"
                        >
                            <Archive aria-hidden="true" className="size-3.5" />
                            {t('chat.list.read_only_short')}
                        </span>
                    ) : null}
                    <span className="tabular ml-auto shrink-0 text-xs text-muted-foreground">
                        {formatListTime(item.last_activity_at)}
                    </span>
                </span>
                <span className="flex min-w-0 items-center gap-2">
                    <span className="min-w-0 truncate text-xs text-muted-foreground">
                        {item.type === 'client' || item.type === 'team'
                            ? !item.is_participant && !item.last_message
                                ? t('chat.list.not_joined')
                                : previewText(item)
                            : previewText(item)}
                    </span>
                    {counterReady ? (
                        <ConversationUnreadBadge
                            conversationId={item.id}
                            className="ml-auto shrink-0"
                        />
                    ) : unread > 0 ? (
                        <span
                            aria-hidden="true"
                            className={cn(
                                'tabular ml-auto flex h-5 min-w-5 shrink-0 items-center justify-center rounded-full px-1.5 text-[11px] font-medium',
                                item.muted
                                    ? 'bg-neutral-soft text-foreground'
                                    : 'bg-primary text-primary-foreground',
                            )}
                        >
                            {unread > 99 ? '99+' : unread}
                        </span>
                    ) : null}
                </span>
                <span className="sr-only">
                    {[
                        t(`chat.list.type.${item.type}`),
                        // Con C2, el número lo dice su propio contador.
                        !counterReady && unread > 0
                            ? t('chat.list.unread', { count: unread })
                            : null,
                        // «Silenciada» y «Solo lectura» ya se leen en su texto visible; aquí, el porqué.
                        item.read_only ? readOnlyText(item) : null,
                    ]
                        .filter(Boolean)
                        .join('. ')}
                </span>
            </span>
        </Link>
    );
}
