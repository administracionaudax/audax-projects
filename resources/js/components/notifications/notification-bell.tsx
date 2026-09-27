import { Link, router, usePage } from '@inertiajs/react';
import { Bell, SlidersHorizontal } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { NotificationItem } from '@/components/notifications/notification-item';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/lib/i18n';
import { edit as editNotificationSettings } from '@/routes/notification-settings';
import {
    index as notificationsIndex,
    readAll,
    recent,
} from '@/routes/notifications';
import type { AppNotification, RecentNotificationsResponse } from '@/types';

/** La campana consulta el recuento cada 60 s (D-037: tiempo real con Reverb en la Fase 6). */
export const POLL_MS = 60_000;

async function fetchRecent(): Promise<RecentNotificationsResponse> {
    const response = await fetch(recent.url(), {
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
    });

    if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
    }

    return (await response.json()) as RecentNotificationsResponse;
}

export function NotificationBell() {
    const sharedUnread = usePage().props.notifications?.unread ?? 0;
    const [unread, setUnread] = useState(sharedUnread);
    const [lastShared, setLastShared] = useState(sharedUnread);
    const [open, setOpen] = useState(false);
    const [items, setItems] = useState<AppNotification[] | null>(null);
    const [failed, setFailed] = useState(false);

    // Cada navegación trae el recuento actualizado en las props compartidas.
    if (sharedUnread !== lastShared) {
        setLastShared(sharedUnread);
        setUnread(sharedUnread);
    }

    const refresh = useCallback(async (withItems: boolean) => {
        try {
            const data = await fetchRecent();
            setUnread(data.unread);
            setFailed(false);

            if (withItems) {
                setItems(data.notifications);
            }
        } catch {
            if (withItems) {
                setFailed(true);
            }
        }
    }, []);

    useEffect(() => {
        const id = window.setInterval(() => {
            if (document.visibilityState === 'visible') {
                void refresh(false);
            }
        }, POLL_MS);

        return () => window.clearInterval(id);
    }, [refresh]);

    const label =
        unread > 0
            ? t('notifications.bell_unread', { count: unread })
            : t('notifications.bell');

    return (
        <Popover
            open={open}
            onOpenChange={(next) => {
                setOpen(next);

                if (next) {
                    setItems(null);
                    void refresh(true);
                }
            }}
        >
            <PopoverTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    className="relative size-9"
                    aria-label={label}
                    title={label}
                >
                    <Bell aria-hidden="true" />
                    {unread > 0 ? (
                        <span
                            aria-hidden="true"
                            className="absolute top-1 right-1 flex min-w-4 items-center justify-center rounded-full bg-primary px-1 text-[10px] leading-4 font-medium text-primary-foreground"
                        >
                            {unread > 99 ? '99+' : unread}
                        </span>
                    ) : null}
                </Button>
            </PopoverTrigger>
            <PopoverContent
                align="end"
                className="w-[min(22rem,calc(100vw-2rem))] p-0"
            >
                <div className="flex items-center justify-between gap-2 border-b px-3 py-2">
                    <p className="text-sm font-medium">
                        {t('notifications.title')}
                    </p>
                    {unread > 0 ? (
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() =>
                                router.post(
                                    readAll.url(),
                                    {},
                                    {
                                        preserveScroll: true,
                                        onSuccess: () => {
                                            setUnread(0);
                                            setItems(
                                                (current) =>
                                                    current?.map((item) => ({
                                                        ...item,
                                                        read_at:
                                                            item.read_at ??
                                                            new Date().toISOString(),
                                                    })) ?? null,
                                            );
                                        },
                                    },
                                )
                            }
                        >
                            {t('notifications.mark_all_read')}
                        </Button>
                    ) : null}
                </div>
                <div
                    className="max-h-96 overflow-y-auto p-1"
                    aria-live="polite"
                >
                    {items === null && !failed ? (
                        <div className="flex justify-center p-4">
                            <Spinner />
                            <span className="sr-only">
                                {t('common.loading')}
                            </span>
                        </div>
                    ) : null}
                    {failed ? (
                        <p className="p-3 text-sm text-muted-foreground">
                            {t('notifications.error')}
                        </p>
                    ) : null}
                    {items !== null && items.length === 0 ? (
                        <p className="p-3 text-sm text-muted-foreground">
                            {t('notifications.empty.title')}
                        </p>
                    ) : null}
                    {items?.map((notification) => (
                        <NotificationItem
                            key={notification.id}
                            notification={notification}
                            compact
                            onNavigate={() => setOpen(false)}
                        />
                    ))}
                </div>
                <div className="flex gap-1 border-t p-1">
                    <Button
                        asChild
                        variant="ghost"
                        size="sm"
                        className="flex-1"
                    >
                        <Link
                            href={notificationsIndex()}
                            onClick={() => setOpen(false)}
                        >
                            {t('notifications.see_all')}
                        </Link>
                    </Button>
                    <Button
                        asChild
                        variant="ghost"
                        size="sm"
                        className="flex-1"
                    >
                        <Link
                            href={editNotificationSettings()}
                            onClick={() => setOpen(false)}
                            aria-label={t('notification_settings.link_label')}
                        >
                            <SlidersHorizontal aria-hidden="true" />
                            {t('notification_settings.link')}
                        </Link>
                    </Button>
                </div>
            </PopoverContent>
        </Popover>
    );
}
