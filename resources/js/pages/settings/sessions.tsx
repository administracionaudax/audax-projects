import { Head, router } from '@inertiajs/react';
import { LogOut, Monitor, Smartphone, TriangleAlert } from 'lucide-react';
import { useRef, useState } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { EmptyState } from '@/components/empty-state';
import Heading from '@/components/heading';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
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

function SessionRow({
    session,
    onClosed,
}: {
    session: ActiveSession;
    /** Se llama cuando la sesión se ha cerrado: la fila (y su botón) desaparecen. */
    onClosed: () => void;
}) {
    const [open, setOpen] = useState(false);
    const [processing, setProcessing] = useState(false);
    const closed = useRef(false);
    const Icon = MOBILE_PLATFORMS.test(session.platform ?? '')
        ? Smartphone
        : Monitor;
    const device = describeDevice(session);

    const close = () => {
        router.delete(destroy.url(session.id), {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onSuccess: () => {
                closed.current = true;
                onClosed();
            },
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
                    onCloseAutoFocus={(event) => {
                        // El disparador ya no existe: Radix dejaría el foco en <body> (UI-08).
                        if (closed.current) {
                            event.preventDefault();
                            onClosed();
                        }
                    }}
                />
            )}
        </li>
    );
}

export default function Sessions({ sessions, supported }: SessionsPageProps) {
    const [open, setOpen] = useState(false);
    const [processing, setProcessing] = useState(false);
    const closedOthers = useRef(false);
    const heading = useRef<HTMLHeadingElement>(null);
    const others = sessions.filter((session) => !session.is_current).length;

    /**
     * Destino estable del foco tras cerrar sesiones (UI-08): la fila o el botón que tenía el
     * foco desaparece o se desactiva, así que se lleva al encabezado de la sección. Se aplaza
     * para ir después de que Radix devuelva el foco al disparador (que ya no está).
     */
    const focusHeading = () => {
        window.setTimeout(() => heading.current?.focus(), 0);
    };

    const closeOthers = () => {
        router.delete(destroyOthers.url(), {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onSuccess: () => {
                closedOthers.current = true;
                focusHeading();
            },
            onFinish: () => {
                setProcessing(false);
                setOpen(false);
            },
        });
    };

    return (
        <>
            <Head title={t('sessions.title')} />

            <div className="space-y-6">
                <Heading
                    ref={heading}
                    tabIndex={-1}
                    variant="small"
                    title={t('sessions.heading')}
                    description={t('sessions.description')}
                />

                {!supported ? (
                    // Sin el driver de sesión «database» no se pueden listar ni cerrar sesiones:
                    // se avisa en vez de mostrar una lista vacía y un botón que no haría nada.
                    <Alert role="status" data-test="sessions-unsupported">
                        <TriangleAlert aria-hidden="true" />
                        <AlertTitle>
                            {t('sessions.unsupported_title')}
                        </AlertTitle>
                        <AlertDescription>
                            {t('sessions.unsupported_description')}
                        </AlertDescription>
                    </Alert>
                ) : (
                    <>
                        {sessions.length === 0 ? (
                            <EmptyState
                                icon={Monitor}
                                title={t('sessions.empty')}
                            />
                        ) : (
                            <ul className="divide-y border-y">
                                {sessions.map((session) => (
                                    <SessionRow
                                        key={session.id}
                                        session={session}
                                        onClosed={focusHeading}
                                    />
                                ))}
                            </ul>
                        )}

                        <ConfirmDialog
                            open={open}
                            onOpenChange={setOpen}
                            trigger={
                                <Button
                                    variant="destructive"
                                    disabled={others === 0}
                                >
                                    {t('sessions.close_others')}
                                </Button>
                            }
                            title={t('sessions.close_others_title')}
                            description={t('sessions.close_others_description')}
                            confirmLabel={t('sessions.close_others')}
                            processing={processing}
                            onConfirm={closeOthers}
                            onCloseAutoFocus={(event) => {
                                if (closedOthers.current) {
                                    closedOthers.current = false;
                                    event.preventDefault();
                                    focusHeading();
                                }
                            }}
                        />
                    </>
                )}
            </div>
        </>
    );
}

Sessions.layout = {
    breadcrumbs: [{ title: t('sessions.title'), href: sessionsIndex() }],
};
