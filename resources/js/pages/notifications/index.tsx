import { Head, Link, router } from '@inertiajs/react';
import { BellOff, CheckCheck, SlidersHorizontal } from 'lucide-react';
import { EmptyState } from '@/components/empty-state';
import Heading from '@/components/heading';
import { NotificationItem } from '@/components/notifications/notification-item';
import { Button } from '@/components/ui/button';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { t } from '@/lib/i18n';
import { edit as editNotificationSettings } from '@/routes/notification-settings';
import { index as notificationsIndex, readAll } from '@/routes/notifications';
import type { NotificationsPageProps } from '@/types';

export default function Notifications({
    items,
    filter,
    unread,
}: NotificationsPageProps) {
    const { data, meta, links } = items;
    const empty = data.length === 0;

    return (
        <>
            <Head title={t('notifications.title')} />
            <div className="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title={t('notifications.title')}
                        description={t('notifications.description')}
                    />
                    <div className="flex flex-wrap gap-2">
                        {unread > 0 ? (
                            <Button
                                variant="outline"
                                onClick={() =>
                                    router.post(
                                        readAll.url(),
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                <CheckCheck aria-hidden="true" />
                                {t('notifications.mark_all_read')}
                            </Button>
                        ) : null}
                        <Button asChild variant="outline">
                            <Link
                                href={editNotificationSettings()}
                                aria-label={t(
                                    'notification_settings.link_label',
                                )}
                            >
                                <SlidersHorizontal aria-hidden="true" />
                                {t('notification_settings.link')}
                            </Link>
                        </Button>
                    </div>
                </div>

                <ToggleGroup
                    type="single"
                    variant="outline"
                    value={filter}
                    aria-label={t('notifications.filter.label')}
                    onValueChange={(value) => {
                        if (!value) {
                            return;
                        }

                        router.get(
                            notificationsIndex.url({
                                query:
                                    value === 'unread'
                                        ? { filtro: 'sin-leer' }
                                        : {},
                            }),
                            {},
                            { preserveScroll: true },
                        );
                    }}
                    className="w-fit"
                >
                    <ToggleGroupItem value="all">
                        {t('notifications.filter.all')}
                    </ToggleGroupItem>
                    <ToggleGroupItem value="unread">
                        {t('notifications.filter.unread')}
                        {unread > 0 ? ` (${unread})` : ''}
                    </ToggleGroupItem>
                </ToggleGroup>

                {empty ? (
                    <EmptyState
                        icon={BellOff}
                        title={t(
                            filter === 'unread'
                                ? 'notifications.empty_unread.title'
                                : 'notifications.empty.title',
                        )}
                        description={t(
                            filter === 'unread'
                                ? 'notifications.empty_unread.description'
                                : 'notifications.empty.description',
                        )}
                    />
                ) : (
                    <ul className="grid gap-1">
                        {data.map((notification) => (
                            <li key={notification.id}>
                                <NotificationItem notification={notification} />
                            </li>
                        ))}
                    </ul>
                )}

                {meta.last_page > 1 ? (
                    <nav className="flex items-center justify-between gap-2 text-sm">
                        {links.prev ? (
                            <Button asChild variant="outline" size="sm">
                                <Link href={links.prev} preserveScroll>
                                    {t('notifications.previous')}
                                </Link>
                            </Button>
                        ) : (
                            <span />
                        )}
                        <span className="text-muted-foreground">
                            {t('notifications.page', {
                                current: meta.current_page,
                                last: meta.last_page,
                            })}
                        </span>
                        {links.next ? (
                            <Button asChild variant="outline" size="sm">
                                <Link href={links.next} preserveScroll>
                                    {t('notifications.next')}
                                </Link>
                            </Button>
                        ) : (
                            <span />
                        )}
                    </nav>
                ) : null}
            </div>
        </>
    );
}

Notifications.layout = {
    breadcrumbs: [
        { title: t('notifications.title'), href: notificationsIndex() },
    ],
};
