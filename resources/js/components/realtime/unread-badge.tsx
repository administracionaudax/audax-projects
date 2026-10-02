import { useUnreadCounter } from '@/hooks/use-realtime-unread';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';

function unreadLabel(count: number): string {
    return count === 1
        ? t('realtime.unread.one')
        : t('realtime.unread.many', { count });
}

function Badge({
    count,
    muted = false,
    className,
}: {
    count: number;
    muted?: boolean;
    className?: string;
}) {
    const label = muted
        ? `${unreadLabel(count)} ${t('realtime.unread.muted')}`
        : unreadLabel(count);

    return (
        <span
            className={cn(
                'inline-flex min-w-5 items-center justify-center rounded-full px-1.5 text-[11px] leading-5 font-medium tabular-nums',
                muted
                    ? 'bg-neutral-soft text-muted-foreground'
                    : 'bg-primary text-primary-foreground',
                className,
            )}
            title={label}
        >
            <span aria-hidden="true">{count > 99 ? '99+' : count}</span>
            <span className="sr-only">{label}</span>
        </span>
    );
}

/** Total de mensajes sin leer (sin las silenciadas), para la entrada «Chat» de la navegación. */
export function ChatUnreadBadge({ className }: { className?: string }) {
    const { total } = useUnreadCounter();

    return total > 0 ? <Badge count={total} className={className} /> : null;
}

/** Sin leer de una conversación (en gris si está silenciada), para la lista del chat. */
export function ConversationUnreadBadge({
    conversationId,
    className,
}: {
    conversationId: number;
    className?: string;
}) {
    const { count, isMuted } = useUnreadCounter();
    const value = count(conversationId);

    return value > 0 ? (
        <Badge
            count={value}
            muted={isMuted(conversationId)}
            className={className}
        />
    ) : null;
}
