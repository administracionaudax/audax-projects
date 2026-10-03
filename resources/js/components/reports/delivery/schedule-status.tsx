import { router } from '@inertiajs/react';
import { Pause, Play, Send, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { destroy, pause, resume, sendNow } from '@/routes/reports/schedules';
import type {
    DeliveryStatus,
    ReportScheduleRow,
} from '@/types/report-deliveries';

/** Activo, en pausa (por una persona o por el programador) o ya enviado («una vez»). */
export function scheduleState(
    schedule: Pick<
        ReportScheduleRow,
        'is_active' | 'paused_reason' | 'frequency' | 'last_run_at'
    >,
): 'active' | 'paused' | 'done' {
    if (schedule.is_active) {
        return 'active';
    }

    return schedule.frequency === 'once' &&
        schedule.paused_reason === null &&
        schedule.last_run_at !== null
        ? 'done'
        : 'paused';
}

const STATE_CLASS = {
    active: 'border-transparent bg-success-soft text-success',
    paused: 'border-transparent bg-warning-soft text-warning',
    done: 'border-transparent bg-muted text-muted-foreground',
} as const;

export function ScheduleStatusBadge({
    schedule,
}: {
    schedule: ReportScheduleRow;
}) {
    const state = scheduleState(schedule);

    return (
        <Badge
            variant="outline"
            className={cn('font-normal', STATE_CLASS[state])}
            title={schedule.paused_reason_label ?? undefined}
            data-test="schedule-status"
        >
            {t(`deliveries.status.${state}`)}
        </Badge>
    );
}

const DELIVERY_CLASS: Record<DeliveryStatus, string> = {
    queued: 'border-transparent bg-info-soft text-info',
    sent: 'border-transparent bg-success-soft text-success',
    failed: 'border-transparent bg-danger-soft text-danger',
    skipped: 'border-transparent bg-warning-soft text-warning',
};

export function DeliveryStatusBadge({ status }: { status: DeliveryStatus }) {
    return (
        <Badge
            variant="outline"
            className={cn('font-normal', DELIVERY_CLASS[status])}
        >
            {t(`deliveries.delivery_status.${status}`)}
        </Badge>
    );
}

/**
 * Pausar o reanudar, «Enviar ahora» y borrar (con confirmación). Cada acción vuelve a la página
 * con el aviso del servidor; borrar lleva a la lista.
 */
export function ScheduleActions({
    schedule,
    compact = false,
}: {
    schedule: ReportScheduleRow;
    compact?: boolean;
}) {
    const [busy, setBusy] = useState(false);
    const [confirming, setConfirming] = useState(false);
    const state = scheduleState(schedule);
    const visit = {
        preserveScroll: true,
        onStart: () => setBusy(true),
        onFinish: () => setBusy(false),
    };
    const size = compact ? 'sm' : 'default';

    return (
        <div className="flex flex-wrap gap-2">
            {state === 'active' ? (
                <Button
                    type="button"
                    variant="outline"
                    size={size}
                    disabled={busy}
                    aria-label={
                        compact
                            ? `${t('deliveries.actions.pause')}: ${schedule.title}`
                            : undefined
                    }
                    onClick={() =>
                        router.post(pause.url(schedule.id), {}, visit)
                    }
                >
                    <Pause aria-hidden="true" />
                    {t('deliveries.actions.pause')}
                </Button>
            ) : (
                <Button
                    type="button"
                    variant="outline"
                    size={size}
                    disabled={busy}
                    aria-label={
                        compact
                            ? `${t('deliveries.actions.resume')}: ${schedule.title}`
                            : undefined
                    }
                    onClick={() =>
                        router.post(resume.url(schedule.id), {}, visit)
                    }
                >
                    <Play aria-hidden="true" />
                    {t('deliveries.actions.resume')}
                </Button>
            )}
            <Button
                type="button"
                variant="outline"
                size={size}
                disabled={busy}
                aria-label={
                    compact
                        ? `${t('deliveries.actions.send_now')}: ${schedule.title}`
                        : undefined
                }
                onClick={() => router.post(sendNow.url(schedule.id), {}, visit)}
            >
                <Send aria-hidden="true" />
                {t('deliveries.actions.send_now')}
            </Button>
            <ConfirmDialog
                open={confirming}
                onOpenChange={setConfirming}
                trigger={
                    <Button
                        type="button"
                        variant="outline"
                        size={size}
                        disabled={busy}
                        aria-label={
                            compact
                                ? `${t('deliveries.actions.delete')}: ${schedule.title}`
                                : undefined
                        }
                    >
                        <Trash2 aria-hidden="true" />
                        {t('deliveries.actions.delete')}
                    </Button>
                }
                title={t('deliveries.actions.delete_title', {
                    title: schedule.title,
                })}
                description={t('deliveries.actions.delete_description')}
                confirmLabel={t('deliveries.actions.delete')}
                processing={busy}
                onConfirm={() =>
                    router.delete(destroy.url(schedule.id), {
                        ...visit,
                        onSuccess: () => setConfirming(false),
                    })
                }
            />
        </div>
    );
}
