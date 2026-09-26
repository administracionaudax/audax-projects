import { router } from '@inertiajs/react';
import { notificationIcon } from '@/components/notifications/notification-icon';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatDateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { open } from '@/routes/notifications';
import type { AppNotification } from '@/types';

/**
 * Una notificación: al pulsarla se marca como leída y se va a su destino (lo decide el servidor,
 * que solo acepta rutas de la propia app).
 */
export function NotificationItem({
    notification,
    compact = false,
    onNavigate,
}: {
    notification: AppNotification;
    compact?: boolean;
    onNavigate?: () => void;
}) {
    const Icon = notificationIcon(notification.data.icon);
    const unread = notification.read_at === null;

    return (
        <button
            type="button"
            onClick={() => {
                onNavigate?.();
                router.post(open.url(notification.id));
            }}
            className={cn(
                'flex w-full items-start gap-3 rounded-[3px] px-3 py-2.5 text-left hover:bg-accent',
                unread && 'bg-info-soft',
                FOCUS_RING,
            )}
        >
            <Icon
                aria-hidden="true"
                className={cn(
                    'mt-0.5 size-4 shrink-0',
                    unread ? 'text-info' : 'text-muted-foreground',
                )}
            />
            <span className="grid min-w-0 flex-1 gap-0.5">
                <span className="text-sm break-words text-foreground">
                    {unread ? (
                        <span className="sr-only">
                            {t('notifications.unread')}:{' '}
                        </span>
                    ) : null}
                    {notification.data.title}
                </span>
                {notification.data.body && !compact ? (
                    <span className="text-sm break-words text-muted-foreground">
                        {notification.data.body}
                    </span>
                ) : null}
                <span className="text-xs text-muted-foreground">
                    {formatDateTime(notification.created_at)}
                </span>
            </span>
            {unread ? (
                <span
                    aria-hidden="true"
                    className="mt-1.5 size-2 shrink-0 rounded-full bg-info"
                />
            ) : null}
        </button>
    );
}
