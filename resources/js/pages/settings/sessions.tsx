import { Head, router } from '@inertiajs/react';
import { LogOut, Monitor, Smartphone } from 'lucide-react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { EmptyState } from '@/components/empty-state';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatDateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import {
    destroy,
    destroyOthers,
    index as sessionsIndex,
} from '@/routes/sessions';
import type { ActiveSession, SessionsPageProps } from '@/types';

const MOBILE_PLATFORMS = /android|ios|iphone|ipad/i;

export function describeDevice(session: ActiveSession): string {
    const browser = session.browser || t('sessions.unknown_browser');
    const platform = session.platform || t('sessions.unknown_platform');

    return t('sessions.device', { browser, platform });
}

function SessionRow({ session }: { session: ActiveSession }) {
    const [open, setOpen] = useState(false);
    const [processing, setProcessing] = useState(false);
    const Icon = MOBILE_PLATFORMS.test(session.platform ?? '')
        ? Smartphone
        : Monitor;
    const device = describeDevice(session);

    const close = () => {
        router.delete(destroy.url(session.id), {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => {
                setProcessing(false);
                setOpen(false);
            },
        });
    };

    return (
        <li
            className="flex flex-col gap-3 py-4 sm:flex-row sm:items-center"
            data-test="session-row"
        >
            <Icon
                aria-hidden="true"
                className="size-6 shrink-0 text-muted-foreground"
                strokeWidth={1.5}
            />
            <div className="min-w-0 flex-1 space-y-1">
                <p className="flex flex-wrap items-center gap-2 text-sm font-medium">
                    {device}
                    {session.is_current && (
                        <Badge className="border-transparent bg-success-soft font-medium text-success">
                            {t('sessions.current')}
                        </Badge>
                    )}
                </p>
                <p className="text-sm text-muted-foreground">
                    {session.ip_address ?? t('sessions.unknown_ip')}
                    {' · '}
                    {session.last_active_at
                        ? t('sessions.last_active', {
                              date: formatDateTime(session.last_active_at),
                          })
                        : t('sessions.last_active_unknown')}
                </p>
            </div>
            {!session.is_current && (
                <ConfirmDialog
                    open={open}
                    onOpenChange={setOpen}
                    trigger={
                        <Button
                            variant="outline"
                            size="sm"
                            aria-label={t('sessions.close_one_label', {
                                device,
                            })}
                        >
                            <LogOut aria-hidden="true" />
                            {t('sessions.close_one')}
                        </Button>
                    }
                    title={t('sessions.close_one_title')}
                    description={t('sessions.close_one_description', {
                        device,
                    })}
                    confirmLabel={t('sessions.close_one')}
                    processing={processing}
                    onConfirm={close}
                />
            )}
        </li>
    );
}

export default function Sessions({ sessions }: SessionsPageProps) {
    const [open, setOpen] = useState(false);
    const [processing, setProcessing] = useState(false);
    const others = sessions.filter((session) => !session.is_current).length;

    const closeOthers = () => {
        router.delete(destroyOthers.url(), {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => {
                setProcessing(false);
                setOpen(false);
            },
        });
    };

    return (
        <>
            <Head title={t('sessions.title')} />

            <h1 className="sr-only">{t('sessions.title')}</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title={t('sessions.heading')}
                    description={t('sessions.description')}
                />

                {sessions.length === 0 ? (
                    <EmptyState icon={Monitor} title={t('sessions.empty')} />
                ) : (
                    <ul className="divide-y border-y">
                        {sessions.map((session) => (
                            <SessionRow key={session.id} session={session} />
                        ))}
                    </ul>
                )}

                <ConfirmDialog
                    open={open}
                    onOpenChange={setOpen}
                    trigger={
                        <Button variant="destructive" disabled={others === 0}>
                            {t('sessions.close_others')}
                        </Button>
                    }
                    title={t('sessions.close_others_title')}
                    description={t('sessions.close_others_description')}
                    confirmLabel={t('sessions.close_others')}
                    processing={processing}
                    onConfirm={closeOthers}
                />
            </div>
        </>
    );
}

Sessions.layout = {
    breadcrumbs: [{ title: t('sessions.title'), href: sessionsIndex() }],
};
