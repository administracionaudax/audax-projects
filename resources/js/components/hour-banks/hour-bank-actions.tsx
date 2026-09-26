import { router, usePage } from '@inertiajs/react';
import { Lock, LockOpen, Pencil, RefreshCw, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { HourBankFormDialog } from '@/components/hour-banks/hour-bank-form-dialog';
import { HourBankRenewDialog } from '@/components/hour-banks/hour-bank-renew-dialog';
import { Button } from '@/components/ui/button';
import { formatMinutes } from '@/lib/format';
import { t } from '@/lib/i18n';
import { close, destroy, reopen } from '@/routes/projects/hour-banks';
import type { HourBankAbilities, HourBankCard, Option } from '@/types';

/** Primer umbral de alerta configurado (75 % por defecto, D-035). */
export function useFirstThreshold(): number {
    return usePage().props.config?.hour_bank_thresholds[0] ?? 75;
}

/**
 * ¿Se ofrece renovar? Solo si está agotada o «próxima a agotarse» (consumo ≥ primer umbral,
 * D-035) y quien mira puede renovarla.
 */
export function offersRenewal(
    bank: Pick<HourBankCard, 'status' | 'consumed_pct'>,
    can: Pick<HourBankAbilities, 'renew'>,
    threshold: number,
): boolean {
    return (
        can.renew &&
        (bank.status === 'exhausted' ||
            (bank.status === 'active' && bank.consumed_pct >= threshold))
    );
}

/**
 * Acciones de una bolsa: renovar, editar, cerrar, reabrir (admin) y eliminar (admin, sin horas).
 * Los botones solo aparecen si se pueden usar; el servidor vuelve a comprobarlo.
 */
export function HourBankActions({
    projectId,
    bank,
    departments,
    overageDefault,
}: {
    projectId: number;
    bank: HourBankCard & { can: HourBankAbilities };
    departments: Option[];
    overageDefault: 'allow' | 'block';
}) {
    const threshold = useFirstThreshold();
    const [pending, setPending] = useState<
        null | 'close' | 'reopen' | 'delete'
    >(null);
    const [open, setOpen] = useState<null | 'close' | 'reopen' | 'delete'>(
        null,
    );
    const route = { project: projectId, hourBank: bank.id };

    const run = (action: 'close' | 'reopen' | 'delete') => {
        const options = {
            preserveScroll: true,
            onStart: () => setPending(action),
            onFinish: () => {
                setPending(null);
                setOpen(null);
            },
        };

        if (action === 'delete') {
            router.delete(destroy.url(route), options);
        } else {
            router.post(
                (action === 'close' ? close : reopen).url(route),
                {},
                options,
            );
        }
    };

    const dialog = (action: 'close' | 'reopen' | 'delete') => ({
        open: open === action,
        onOpenChange: (value: boolean) => setOpen(value ? action : null),
        processing: pending === action,
        onConfirm: () => run(action),
    });

    return (
        <div className="flex flex-wrap gap-2">
            {offersRenewal(bank, bank.can, threshold) ? (
                <HourBankRenewDialog
                    projectId={projectId}
                    bank={bank}
                    departments={departments}
                    overageDefault={overageDefault}
                    trigger={
                        <Button size="sm">
                            <RefreshCw aria-hidden="true" />
                            {t('hour_banks.actions.renew')}
                        </Button>
                    }
                />
            ) : null}

            {bank.can.update ? (
                <HourBankFormDialog
                    projectId={projectId}
                    bank={bank}
                    departments={departments}
                    overageDefault={overageDefault}
                    trigger={
                        <Button size="sm" variant="outline">
                            <Pencil aria-hidden="true" />
                            {t('hour_banks.actions.edit')}
                        </Button>
                    }
                />
            ) : null}

            {bank.can.close ? (
                <ConfirmDialog
                    {...dialog('close')}
                    destructive={false}
                    trigger={
                        <Button size="sm" variant="outline">
                            <Lock aria-hidden="true" />
                            {t('hour_banks.actions.close')}
                        </Button>
                    }
                    title={t('hour_banks.actions.close_title', {
                        name: bank.name,
                    })}
                    description={t('hour_banks.actions.close_description', {
                        remaining: formatMinutes(bank.remaining_minutes),
                    })}
                    confirmLabel={t('hour_banks.actions.close')}
                />
            ) : null}

            {bank.can.reopen ? (
                <ConfirmDialog
                    {...dialog('reopen')}
                    destructive={false}
                    trigger={
                        <Button size="sm" variant="outline">
                            <LockOpen aria-hidden="true" />
                            {t('hour_banks.actions.reopen')}
                        </Button>
                    }
                    title={t('hour_banks.actions.reopen_title', {
                        name: bank.name,
                    })}
                    description={t('hour_banks.actions.reopen_description')}
                    confirmLabel={t('hour_banks.actions.reopen')}
                />
            ) : null}

            {bank.can.delete ? (
                <ConfirmDialog
                    {...dialog('delete')}
                    trigger={
                        <Button size="sm" variant="ghost">
                            <Trash2 aria-hidden="true" />
                            {t('hour_banks.actions.delete')}
                        </Button>
                    }
                    title={t('hour_banks.actions.delete_title', {
                        name: bank.name,
                    })}
                    description={t('hour_banks.actions.delete_description')}
                    confirmLabel={t('hour_banks.actions.delete')}
                />
            ) : null}
        </div>
    );
}
