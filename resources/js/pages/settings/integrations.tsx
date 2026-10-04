import { Head, router } from '@inertiajs/react';
import { FileSpreadsheet, TriangleAlert } from 'lucide-react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import Heading from '@/components/heading';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { formatDate } from '@/lib/format';
import { t } from '@/lib/i18n';
import { edit as editIntegrations } from '@/routes/integrations';
import { connect, destroy } from '@/routes/integrations/google';
import type { IntegrationsPageProps } from '@/types/integrations';

/**
 * /ajustes/integraciones (Fase 9, D-142): cada persona de la plantilla conecta su cuenta de Google
 * de Workspace para exportar informes a Google Sheets (alcance `drive.file`). Sin credenciales en
 * el servidor, se explica que no está disponible.
 */
export default function Integrations({ google }: IntegrationsPageProps) {
    const [connecting, setConnecting] = useState(false);
    const [open, setOpen] = useState(false);
    const [disconnecting, setDisconnecting] = useState(false);

    // POST: el servidor guarda el `state` en la sesión y responde con la URL de Google
    // (Inertia::location), que Inertia abre en esta misma pestaña.
    const start = () => {
        router.post(
            connect.url(),
            {},
            {
                onStart: () => setConnecting(true),
                onFinish: () => setConnecting(false),
            },
        );
    };

    const disconnect = () => {
        router.delete(destroy.url(), {
            preserveScroll: true,
            onStart: () => setDisconnecting(true),
            onFinish: () => {
                setDisconnecting(false);
                setOpen(false);
            },
        });
    };

    return (
        <>
            <Head title={t('integrations.title')} />

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title={t('integrations.heading')}
                    description={t('integrations.description')}
                />

                <section
                    className="space-y-4 border p-4"
                    aria-labelledby="integration-google"
                    data-test="integration-google"
                >
                    <div className="flex items-start gap-3">
                        <FileSpreadsheet
                            aria-hidden="true"
                            className="mt-0.5 size-5 shrink-0 text-muted-foreground"
                            strokeWidth={1.5}
                        />
                        <div className="space-y-1">
                            <h3
                                id="integration-google"
                                className="text-sm font-medium"
                            >
                                {t('integrations.google.name')}
                            </h3>
                            <p className="text-sm text-muted-foreground">
                                {t('integrations.google.summary')}
                            </p>
                        </div>
                    </div>

                    {!google.available ? (
                        <Alert role="status" data-test="google-unavailable">
                            <TriangleAlert aria-hidden="true" />
                            <AlertTitle>
                                {t('integrations.google.unavailable_title')}
                            </AlertTitle>
                            <AlertDescription>
                                {t(
                                    'integrations.google.unavailable_description',
                                )}
                            </AlertDescription>
                        </Alert>
                    ) : google.connection ? (
                        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div className="space-y-1 text-sm">
                                <p data-test="google-account">
                                    {t('integrations.google.connected_as', {
                                        email: google.connection.email,
                                    })}
                                </p>
                                {google.connection.connected_at && (
                                    <p className="text-muted-foreground">
                                        {t(
                                            'integrations.google.connected_since',
                                            {
                                                date: formatDate(
                                                    google.connection
                                                        .connected_at,
                                                ),
                                            },
                                        )}
                                    </p>
                                )}
                            </div>
                            <ConfirmDialog
                                open={open}
                                onOpenChange={setOpen}
                                trigger={
                                    <Button variant="outline" size="sm">
                                        {t('integrations.google.disconnect')}
                                    </Button>
                                }
                                title={t(
                                    'integrations.google.disconnect_title',
                                )}
                                description={t(
                                    'integrations.google.disconnect_description',
                                )}
                                confirmLabel={t(
                                    'integrations.google.disconnect',
                                )}
                                processing={disconnecting}
                                onConfirm={disconnect}
                            />
                        </div>
                    ) : (
                        <div className="space-y-2">
                            <Button
                                type="button"
                                onClick={start}
                                disabled={connecting}
                                data-test="google-connect"
                            >
                                {connecting && <Spinner />}
                                {connecting
                                    ? t('integrations.google.connecting')
                                    : t('integrations.google.connect')}
                            </Button>
                            <p className="text-sm text-muted-foreground">
                                {t('integrations.google.connect_help')}
                            </p>
                        </div>
                    )}

                    <p className="text-sm text-muted-foreground">
                        {t('integrations.google.scope')}
                    </p>
                </section>
            </div>
        </>
    );
}

Integrations.layout = {
    breadcrumbs: [{ title: t('integrations.title'), href: editIntegrations() }],
};
