import { usePage } from '@inertiajs/react';
import { BellRing, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import {
    enablePush,
    loadPushConfig,
    pushStatus,
    pushSupported,
} from '@/components/realtime/push';
import type { PushConfig } from '@/components/realtime/push';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/lib/i18n';

/** «Ahora no» se recuerda en este navegador (no se vuelve a ofrecer). */
export const PUSH_PROMPT_DISMISSED_KEY = 'audax.weekly.push-prompt.dismissed';

function dismissed(): boolean {
    try {
        return window.localStorage.getItem(PUSH_PROMPT_DISMISSED_KEY) === '1';
    } catch {
        return true;
    }
}

/**
 * Pedir los avisos del navegador de forma amable (10.9b, D-230; WeeklySync lo pedía solo al entrar,
 * `ws:App.tsx:857-873`): un aviso con «Activar avisos» y «Ahora no», solo si el navegador los
 * admite, el servidor tiene Web Push y aún no se ha dado ni negado el permiso. El permiso solo se
 * pide al pulsar «Activar avisos». Va en «Mi weekly» tras enviar y en el resumen de la Weekly.
 */
export function PushPrompt() {
    const userId = usePage().props.auth?.user?.id ?? null;
    const [config, setConfig] = useState<PushConfig | null>(null);
    const [busy, setBusy] = useState(false);
    const [hidden, setHidden] = useState(false);

    useEffect(() => {
        if (
            userId === null ||
            !pushSupported() ||
            Notification.permission !== 'default' ||
            dismissed()
        ) {
            return;
        }

        let cancelled = false;

        void (async () => {
            try {
                const loaded = await loadPushConfig();

                if (
                    !cancelled &&
                    loaded &&
                    (await pushStatus(loaded)) === 'disabled'
                ) {
                    setConfig(loaded);
                }
            } catch {
                // Sin configuración no se ofrece.
            }
        })();

        return () => {
            cancelled = true;
        };
    }, [userId]);

    if (config === null || hidden || userId === null) {
        return null;
    }

    const later = () => {
        try {
            window.localStorage.setItem(PUSH_PROMPT_DISMISSED_KEY, '1');
        } catch {
            // Sin almacenamiento, solo se oculta ahora.
        }

        setHidden(true);
    };

    const enable = async () => {
        setBusy(true);

        try {
            const result = await enablePush(config, userId);

            if (result === 'enabled') {
                toast.success(t('realtime.push.enabled_toast'));
            }

            setHidden(true);
        } catch {
            toast.error(t('weeklies.push_prompt.failed'));
        } finally {
            setBusy(false);
        }
    };

    return (
        <div
            className="flex flex-col gap-3 border bg-info-soft p-3 text-sm sm:flex-row sm:items-center sm:justify-between"
            role="region"
            aria-label={t('weeklies.push_prompt.title')}
            data-test="weekly-push-prompt"
        >
            <div className="flex gap-2">
                <BellRing
                    aria-hidden="true"
                    className="mt-0.5 size-4 shrink-0 text-info"
                />
                <div className="grid gap-0.5">
                    <p className="font-medium">
                        {t('weeklies.push_prompt.title')}
                    </p>
                    <p>{t('weeklies.push_prompt.description')}</p>
                </div>
            </div>
            <div className="flex shrink-0 items-center gap-2">
                <Button
                    type="button"
                    size="sm"
                    onClick={() => void enable()}
                    disabled={busy}
                >
                    {busy ? <Spinner /> : <BellRing aria-hidden="true" />}
                    {t('weeklies.push_prompt.enable')}
                </Button>
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    onClick={later}
                    disabled={busy}
                >
                    <X aria-hidden="true" />
                    {t('weeklies.push_prompt.later')}
                </Button>
            </div>
        </div>
    );
}
