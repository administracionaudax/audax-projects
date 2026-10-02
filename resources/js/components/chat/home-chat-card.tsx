import { Link } from '@inertiajs/react';
import { AtSign, MessagesSquare } from 'lucide-react';
import { formatListTime } from '@/components/chat/chat-format';
import { useUnreadCounter } from '@/components/chat/realtime-bridge';
import { EmptyState } from '@/components/empty-state';
import { Skeleton } from '@/components/ui/skeleton';
import { FOCUS_RING } from '@/lib/focus-ring';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { index as chatIndex } from '@/routes/chat';
import type { HomeChatSummary } from '@/types/chat';

/** Mientras llega la prop diferida `chat` de Inicio. */
export function HomeChatSkeleton() {
    return (
        <div className="grid gap-2" aria-hidden="true">
            <Skeleton className="h-4 w-3/4" />
            <Skeleton className="h-4 w-2/3" />
            <Skeleton className="h-4 w-1/2" />
        </div>
    );
}

/**
 * Tarjeta «Menciones» de Inicio (SPEC §5.1 y §12): las menciones recientes (personales y @todos),
 * las conversaciones con mensajes sin leer y el enlace al chat con el total, que va en vivo con
 * los contadores de C2 en cuanto llegan.
 */
export function HomeChatCard({ summary }: { summary: HomeChatSummary }) {
    const counter = useUnreadCounter();
    const total = counter.ready ? counter.total : summary.unread_total;
    const empty =
        summary.mentions.length === 0 && summary.conversations.length === 0;

    return (
        <div className="flex flex-1 flex-col gap-3" data-test="home-chat">
            {empty ? (
                <EmptyState
                    className="flex-1"
                    icon={AtSign}
                    title={t('home.cards.mentions.empty')}
                />
            ) : null}

            {summary.mentions.length > 0 ? (
                <section className="grid gap-1">
                    <h3 className="text-sm font-medium">
                        {t('chat.home.mentions')}
                    </h3>
                    <ul className="divide-y rounded-md border">
                        {summary.mentions.map((mention) => (
                            <li key={mention.id} data-test="home-chat-mention">
                                <Link
                                    href={mention.url}
                                    className={cn(
                                        'grid gap-0.5 px-2 py-1.5 text-sm hover:bg-muted',
                                        FOCUS_RING,
                                    )}
                                >
                                    <span className="flex items-baseline gap-2 text-xs text-muted-foreground">
                                        <span className="min-w-0 truncate">
                                            {t(
                                                mention.everyone
                                                    ? 'chat.home.mention_everyone'
                                                    : 'chat.home.mention_by',
                                                {
                                                    name:
                                                        mention.author ??
                                                        t('chat.home.someone'),
                                                    conversation:
                                                        mention.conversation,
                                                },
                                            )}
                                        </span>
                                        <span className="tabular ml-auto shrink-0">
                                            {formatListTime(mention.created_at)}
                                        </span>
                                    </span>
                                    <span
                                        className={cn(
                                            'truncate text-foreground',
                                            mention.unread && 'font-medium',
                                        )}
                                    >
                                        {mention.excerpt}
                                    </span>
                                    {mention.unread ? (
                                        <span className="sr-only">
                                            {t('chat.home.unread_mention')}
                                        </span>
                                    ) : null}
                                </Link>
                            </li>
                        ))}
                    </ul>
                </section>
            ) : null}

            {summary.conversations.length > 0 ? (
                <section className="grid gap-1">
                    <h3 className="text-sm font-medium">
                        {t('chat.home.unread')}
                    </h3>
                    <ul className="grid gap-1 text-sm">
                        {summary.conversations.map((conversation) => (
                            <li
                                key={conversation.id}
                                className="flex items-center justify-between gap-2"
                                data-test="home-chat-conversation"
                            >
                                <Link
                                    href={conversation.url}
                                    className={cn(
                                        'min-w-0 truncate rounded-sm hover:underline',
                                        FOCUS_RING,
                                    )}
                                >
                                    {conversation.title}
                                </Link>
                                <span className="tabular shrink-0 text-xs text-muted-foreground">
                                    {t('chat.home.count', {
                                        count: conversation.unread,
                                    })}
                                </span>
                            </li>
                        ))}
                    </ul>
                </section>
            ) : null}

            <Link
                href={chatIndex()}
                className={cn(
                    'mt-auto inline-flex items-center gap-1.5 self-start rounded-sm text-sm text-primary-text hover:underline',
                    FOCUS_RING,
                )}
            >
                <MessagesSquare aria-hidden="true" className="size-4" />
                {total > 0
                    ? t('chat.home.open_unread', { count: total })
                    : t('chat.home.open')}
            </Link>
        </div>
    );
}
