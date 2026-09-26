import { useForm } from '@inertiajs/react';
import { Info, TriangleAlert } from 'lucide-react';
import type { ReactNode } from 'react';
import { useId, useState } from 'react';
import {
    HourBankFields,
    hourBankFormFrom,
    hourBankPayload,
} from '@/components/hour-banks/hour-bank-fields';
import type { HourBankFormData } from '@/components/hour-banks/hour-bank-fields';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useAbilities } from '@/hooks/use-auth';
import { formatMinutes } from '@/lib/format';
import { t } from '@/lib/i18n';
import { addDays, todayInMadrid } from '@/lib/week';
import { renew } from '@/routes/projects/hour-banks';
import type { HourBankCard, Option } from '@/types';

type RenewForm = HourBankFormData & { move_open_tasks: boolean };

/** Parámetros propuestos para la bolsa nueva: los mismos, desde el día siguiente al fin. */
export function renewalDefaults(
    bank: HourBankCard,
    today: string = todayInMadrid(),
): RenewForm {
    return {
        ...hourBankFormFrom(bank),
        start_date: bank.end_date ? addDays(bank.end_date, 1) : today,
        end_date: null,
        invoice_reference: '',
        notes: '',
        move_open_tasks: bank.open_tasks_count > 0,
    };
}

/**
 * Renovar una bolsa (SPEC §8.7): crea una bolsa nueva con los mismos parámetros (editables) y la
 * anterior pasa a «renovada». Se ofrece mover las tareas abiertas; las horas imputadas nunca se
 * mueven y el exceso de la anterior se muestra solo como información.
 */
export function HourBankRenewDialog({
    projectId,
    bank,
    departments,
    overageDefault,
    trigger,
}: {
    projectId: number;
    bank: HourBankCard;
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
                    <RenewForm
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

function RenewForm({
    projectId,
    bank,
    departments,
    overageDefault,
    onDone,
}: {
    projectId: number;
    bank: HourBankCard;
    departments: Option[];
    overageDefault: 'allow' | 'block';
    onDone: () => void;
}) {
    const id = useId();
    const can = useAbilities();
    const form = useForm<RenewForm>(renewalDefaults(bank));
    const errors = form.errors as Record<string, string | undefined>;

    return (
        <form
            noValidate
            className="grid gap-5"
            onSubmit={(event) => {
                event.preventDefault();
                form.transform((data) => ({
                    ...hourBankPayload(data, can.viewFinancials),
                    move_open_tasks: data.move_open_tasks,
                }));
                form.submit(renew({ project: projectId, hourBank: bank.id }), {
                    preserveScroll: true,
                    onSuccess: onDone,
                });
            }}
        >
            <DialogHeader>
                <DialogTitle>
                    {t('hour_banks.renew.title', { name: bank.name })}
                </DialogTitle>
                <DialogDescription>
                    {t('hour_banks.renew.description')}
                </DialogDescription>
            </DialogHeader>

            <div className="grid gap-2 rounded-md bg-muted p-3 text-sm">
                <p className="flex items-start gap-2">
                    <Info
                        aria-hidden="true"
                        className="mt-0.5 size-4 shrink-0 text-info"
                    />
                    {t('hour_banks.renew.hours_stay', {
                        consumed: formatMinutes(bank.consumed_minutes),
                    })}
                </p>
                {bank.overage_minutes > 0 ? (
                    <p className="flex items-start gap-2">
                        <TriangleAlert
                            aria-hidden="true"
                            className="mt-0.5 size-4 shrink-0 text-danger"
                        />
                        {t('hour_banks.renew.overage_info', {
                            overage: formatMinutes(bank.overage_minutes),
                        })}
                    </p>
                ) : null}
            </div>

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

            <div className="flex items-start gap-3">
                <Checkbox
                    id={`${id}-move`}
                    checked={form.data.move_open_tasks}
                    disabled={bank.open_tasks_count === 0}
                    aria-describedby={`${id}-move-help`}
                    onCheckedChange={(value) =>
                        form.setData('move_open_tasks', value === true)
                    }
                    className="mt-0.5"
                />
                <div className="grid gap-0.5">
                    <Label htmlFor={`${id}-move`} className="font-normal">
                        {t('hour_banks.renew.move_tasks', {
                            count: bank.open_tasks_count,
                        })}
                    </Label>
                    <p
                        id={`${id}-move-help`}
                        className="text-xs text-muted-foreground"
                    >
                        {bank.open_tasks_count === 0
                            ? t('hour_banks.renew.no_open_tasks')
                            : t('hour_banks.renew.move_tasks_help')}
                    </p>
                </div>
            </div>

            {errors.hour_bank ? (
                <p role="alert" className="text-sm text-danger">
                    {errors.hour_bank}
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
                    {t('hour_banks.renew.submit')}
                </Button>
            </DialogFooter>
        </form>
    );
}
