import { useForm } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import {
    emptyHourBankForm,
    HourBankFields,
    hourBankFormFrom,
    hourBankPayload,
} from '@/components/hour-banks/hour-bank-fields';
import type { HourBankFormData } from '@/components/hour-banks/hour-bank-fields';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { useAbilities } from '@/hooks/use-auth';
import { t } from '@/lib/i18n';
import { todayInMadrid } from '@/lib/week';
import { store, update } from '@/routes/projects/hour-banks';
import type { HourBank, Option } from '@/types';

/**
 * Alta o edición de una bolsa en un diálogo. Cambiar el total recalcula el consumo y el exceso
 * en el servidor (HourBankLedger); una bolsa renovada no se edita.
 */
export function HourBankFormDialog({
    projectId,
    bank,
    departments,
    overageDefault,
    trigger,
}: {
    projectId: number;
    /** Sin bolsa, es un alta. */
    bank?: HourBank;
    departments: Option[];
    overageDefault: 'allow' | 'block';
    trigger: ReactNode;
}) {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-2xl">
                {open ? (
                    <HourBankForm
                        projectId={projectId}
                        bank={bank}
                        departments={departments}
                        overageDefault={overageDefault}
                        onDone={() => setOpen(false)}
                    />
                ) : null}
            </DialogContent>
        </Dialog>
    );
}

function HourBankForm({
    projectId,
    bank,
    departments,
    overageDefault,
    onDone,
}: {
    projectId: number;
    bank?: HourBank;
    departments: Option[];
    overageDefault: 'allow' | 'block';
    onDone: () => void;
}) {
    const can = useAbilities();
    const form = useForm<HourBankFormData>(
        bank ? hourBankFormFrom(bank) : emptyHourBankForm(todayInMadrid()),
    );

    return (
        <form
            noValidate
            className="grid gap-5"
            onSubmit={(event) => {
                event.preventDefault();
                form.transform((data) =>
                    hourBankPayload(data, can.viewFinancials),
                );
                form.submit(
                    bank
                        ? update({ project: projectId, hourBank: bank.id })
                        : store(projectId),
                    { preserveScroll: true, onSuccess: onDone },
                );
            }}
        >
            <DialogHeader>
                <DialogTitle>
                    {bank
                        ? t('hour_banks.form.edit_title', { name: bank.name })
                        : t('hour_banks.form.create_title')}
                </DialogTitle>
                <DialogDescription>
                    {bank
                        ? t('hour_banks.form.edit_description')
                        : t('hour_banks.form.create_description')}
                </DialogDescription>
            </DialogHeader>

            <HourBankFields
                data={form.data}
                set={(key, value) =>
                    form.setData((data) => ({ ...data, [key]: value }))
                }
                errors={form.errors}
                departments={departments}
                overageDefault={overageDefault}
                canViewFinancials={can.viewFinancials}
            />

            {(form.errors as Record<string, string>).hour_bank ? (
                <p role="alert" className="text-sm text-danger">
                    {(form.errors as Record<string, string>).hour_bank}
                </p>
            ) : null}

            <DialogFooter>
                <DialogClose asChild>
                    <Button
                        type="button"
                        variant="secondary"
                        disabled={form.processing}
                    >
                        {t('common.cancel')}
                    </Button>
                </DialogClose>
                <Button type="submit" disabled={form.processing}>
                    {form.processing ? <Spinner /> : null}
                    {bank
                        ? t('common.save')
                        : t('hour_banks.form.create_submit')}
                </Button>
            </DialogFooter>
        </form>
    );
}
