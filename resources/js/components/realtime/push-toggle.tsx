import { usePage } from '@inertiajs/react';
import {
    Ban,
    BellOff,
    BellRing,
    LoaderCircle,
    ShieldAlert,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useEffect, useId, useState } from 'react';
import { toast } from 'sonner';
import {
    disablePush,
    enablePush,
    loadPushConfig,
    pushStatus,
    pushSupported,
} from '@/components/realtime/push';
import type { PushConfig, PushStatus } from '@/components/realtime/push';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';

const STATE_ICON: Record<PushStatus, LucideIcon> = {
    loading: LoaderCircle,
    unsupported: Ban,
    unavailable: Ban,
    denied: ShieldAlert,
    enabled: BellRing,
    disabled: BellOff,
};

/**
 * «Activar avisos en este navegador» (Web Push, D-072). Para colocarlo en el chat o en los
 * ajustes: se basta solo (pide la configuración al servidor y el permiso al navegador).
 * Estados siempre con icono y texto: activados, desactivados, bloqueados, no admitidos…
 */
export function PushNotificationsToggle({ className }: { className?: string }) {
    const userId = usePage().props.auth?.user?.id ?? null;
    const id = useId();
    const [config, setConfig] = useState<PushConfig | null>(null);
    const [status, setStatus] = useState<PushStatus>(() =>
        pushSupported() ? 'loading' : 'unsupported',
    );
    const [busy, setBusy] = useState(false);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        if (!pushSupported()) {
            return;
        }

        let cancelled = false;

        void (async () => {
            try {
                const loaded = await loadPushConfig();
                const next = loaded ? await pushStatus(loaded) : 'unavailable';

                if (!cancelled) {
                    setConfig(loaded);
                    setStatus(next);
                }
            } catch {
                if (!cancelled) {
                    setStatus('unavailable');
                }
            }
        })();

        return () => {
            cancelled = true;
        };
    }, []);

    const toggle = async (checked: boolean) => {
        if (!config || userId === null) {
            return;
        }

        setBusy(true);
        setFailed(false);

        try {
            if (checked) {
                const result = await enablePush(config, userId);
                setStatus(result);

                if (result === 'enabled') {
                    toast.success(t('realtime.push.enabled_toast'));
                }
            } else {
                await disablePush();
                setStatus('disabled');
                toast.success(t('realtime.push.disabled_toast'));
            }

            const fresh = await loadPushConfig();

            if (fresh) {
                setConfig(fresh);
            }
        } catch {
            setFailed(true);
        } finally {
            setBusy(false);
        }
    };

    const Icon = busy ? LoaderCircle : STATE_ICON[status];
    const spinning = busy || status === 'loading';
    const actionable = status === 'enabled' || status === 'disabled';

    return (
        <div className={cn('flex items-start gap-3', className)}>
            <Switch
                id={id}
                className="mt-0.5"
                checked={status === 'enabled'}
                disabled={busy || !actionable}
                aria-describedby={`${id}-description ${id}-state`}
                onCheckedChange={(checked) => void toggle(checked)}
            />
            <div className="grid min-w-0 gap-1">
                <Label htmlFor={id}>{t('realtime.push.label')}</Label>
                <p
                    id={`${id}-description`}
                    className="text-xs text-muted-foreground"
                >
                    {t('realtime.push.description')}
                </p>
                <p
                    id={`${id}-state`}
                    role="status"
                    data-status={status}
                    className="flex items-center gap-1.5 text-xs text-muted-foreground"
                >
                    <Icon
                        aria-hidden="true"
                        className={cn(
                            'size-3.5 shrink-0',
                            status === 'enabled' && !busy && 'text-success',
                            spinning && 'motion-safe:animate-spin',
                        )}
                    />
                    {t(`realtime.push.state.${status}`)}
                </p>
                {failed ? (
                    <p role="alert" className="text-xs text-destructive">
                        {t('realtime.push.error')}
                    </p>
                ) : null}
            </div>
        </div>
    );
}
