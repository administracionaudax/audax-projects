import { router } from '@inertiajs/react';
import { CircleAlert, Lock } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/lib/i18n';
import { close as closeCycle } from '@/routes/weeklies';
import type { WeeklyCloseBlocker, WeeklyCycleSummary } from '@/types/weeklies';

/** Lo que falta para cerrar, con lo que dice la semana (para el resumen de /weeklies). */
export function closeBlockers(
    cycle: Pick<WeeklyCycleSummary, 'status' | 'has_report' | 'has_audio'>,
): WeeklyCloseBlocker[] {
    return [
        ...(cycle.status === 'active' ? [] : ['not_active' as const]),
        ...(cycle.has_report ? [] : ['report' as const]),
        ...(cycle.has_audio ? [] : ['audio' as const]),
    ];
}

/** «Bloqueado: falta generar texto y generar audio», como el título del botón del original. */
export function closeHint(
    blockers: WeeklyCloseBlocker[],
    pending: number,
): string {
    if (blockers.includes('not_active')) {
        return t('weeklies.close.already_closed');
    }

    const missing = blockers.filter((blocker) => blocker !== 'not_active');

    if (missing.length > 0) {
        return t('weeklies.close.blocked', {
            missing: missing
                .map((blocker) => t(`weeklies.close.missing.${blocker}`))
                .join(t('weeklies.close.and')),
        });
    }

    return pending > 0
        ? t('weeklies.close.pending_warning', { count: pending })
        : t('weeklies.close.ready');
}

/**
 * «Cerrar semana» (F-035 y F-089, D-191): exige el texto y el audio generados; si falta alguien por
 * enviar, solo avisa. Al confirmar se cierra, se congela la participación, se abre la siguiente
 * y la satisfacción se calcula en segundo plano.
 */
export function CloseWeekDialog({
    cycle,
    blockers,
    pending,
    pendingNames = [],
    size = 'sm',
}: {
    cycle: Pick<WeeklyCycleSummary, 'id' | 'label'>;
    blockers: WeeklyCloseBlocker[];
    pending: number;
    /** Quién no ha enviado, para nombrarlos al confirmar (como WeeklySync, 10.9b). */
    pendingNames?: string[];
    size?: 'sm' | 'default';
}) {
    const [open, setOpen] = useState(false);
    const [processing, setProcessing] = useState(false);
    const hint = closeHint(blockers, pending);
    const disabled = blockers.length > 0;

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button
                    type="button"
                    size={size}
                    disabled={disabled}
                    title={hint}
                    aria-describedby={`weekly-close-hint-${cycle.id}`}
                    data-test="weekly-close"
                >
                    <Lock aria-hidden="true" />
                    {t('weeklies.close.open')}
                </Button>
            </DialogTrigger>
            <span id={`weekly-close-hint-${cycle.id}`} className="sr-only">
                {hint}
            </span>
            <DialogContent>
                <DialogTitle>
                    {t('weeklies.close.title', { label: cycle.label })}
                </DialogTitle>
                <DialogDescription>
                    {t('weeklies.close.description')}
                </DialogDescription>
                {pending > 0 ? (
                    <p
                        className="flex items-start gap-2 bg-warning-soft p-3 text-sm"
                        data-test="weekly-close-pending"
                    >
                        <CircleAlert
                            aria-hidden="true"
                            className="mt-0.5 size-4 shrink-0 text-warning"
                        />
                        <span>
                            {t('weeklies.close.pending_warning', {
                                count: pending,
                            })}
                            {pendingNames.length > 0 ? (
                                <span
                                    className="block"
                                    data-test="weekly-close-pending-names"
                                >
                                    {t('weeklies.close.pending_names', {
                                        names: pendingNames.join(', '),
                                    })}
                                </span>
                            ) : null}
                        </span>
                    </p>
                ) : null}
                <DialogFooter className="gap-2">
                    <DialogClose asChild>
                        <Button variant="secondary" disabled={processing}>
                            {t('common.cancel')}
                        </Button>
                    </DialogClose>
                    <Button
                        disabled={processing}
                        onClick={() =>
                            router.post(
                                closeCycle.url(cycle.id),
                                {},
                                {
                                    preserveScroll: true,
                                    onStart: () => setProcessing(true),
                                    onFinish: () => setProcessing(false),
                                    onSuccess: () => setOpen(false),
                                },
                            )
                        }
                        data-test="weekly-close-confirm"
                    >
                        {processing && <Spinner />}
                        {t('weeklies.close.confirm')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
