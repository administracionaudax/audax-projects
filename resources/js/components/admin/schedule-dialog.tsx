import { useForm } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useId, useState } from 'react';
import { WeekMinutesInput } from '@/components/admin/week-minutes-input';
import { DatePicker } from '@/components/domain/date-picker';
import InputError from '@/components/input-error';
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
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/lib/i18n';
import { store, update } from '@/routes/admin/users/schedules';
import type { AdminWorkSchedule } from '@/types';

type ScheduleForm = {
    valid_from: string;
    week: (number | null)[];
};

/**
 * Alta de una versión nueva de la jornada o edición de la última (si aún no ha empezado). La nueva
 * cierra la vigente el día anterior (WorkScheduleVersions en el servidor).
 */
export function ScheduleDialog({
    userId,
    schedule,
    initialWeek,
    defaultFrom,
    trigger,
}: {
    userId: number;
    /** Versión que se edita; sin ella, se crea una nueva. */
    schedule?: AdminWorkSchedule;
    /** Jornada de partida al crear (la vigente). */
    initialWeek: number[];
    /** Fecha de inicio propuesta al crear ("YYYY-MM-DD"). */
    defaultFrom: string;
    trigger: ReactNode;
}) {
    const id = useId();
    const [open, setOpen] = useState(false);
    const form = useForm<ScheduleForm>({
        valid_from: schedule?.valid_from ?? defaultFrom,
        week: schedule?.week ?? initialWeek,
    });
    const errors = form.errors as Record<string, string | undefined>;
    const invalidWeek = form.data.week.some((minutes) => minutes === null);

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        if (invalidWeek) {
            return;
        }

        const options = {
            preserveScroll: true,
            onSuccess: () => setOpen(false),
        };

        if (schedule) {
            form.put(
                update.url({ user: userId, workSchedule: schedule.id }),
                options,
            );
        } else {
            form.post(store.url(userId), options);
        }
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                setOpen(next);
                if (next) {
                    form.setData({
                        valid_from: schedule?.valid_from ?? defaultFrom,
                        week: schedule?.week ?? initialWeek,
                    });
                    form.clearErrors();
                }
            }}
        >
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-3xl">
                <form onSubmit={submit} className="grid gap-6" noValidate>
                    <DialogHeader>
                        <DialogTitle>
                            {schedule
                                ? t('admin.schedules.edit_title')
                                : t('admin.schedules.new_title')}
                        </DialogTitle>
                        <DialogDescription>
                            {schedule
                                ? t('admin.schedules.edit_description')
                                : t('admin.schedules.new_description')}
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid max-w-xs gap-2">
                        <Label htmlFor={`${id}-from`}>
                            {t('admin.schedules.valid_from')}
                        </Label>
                        <DatePicker
                            id={`${id}-from`}
                            value={form.data.valid_from}
                            clearable={false}
                            invalid={Boolean(errors.valid_from)}
                            onChange={(value) =>
                                form.setData('valid_from', value ?? defaultFrom)
                            }
                        />
                        <InputError
                            message={errors.valid_from ?? errors.schedule}
                        />
                    </div>

                    <WeekMinutesInput
                        value={form.data.week}
                        onChange={(week) => form.setData('week', week)}
                        legend={t('admin.schedules.week_legend')}
                        errorPrefix="week"
                        errors={errors}
                    />

                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button
                                type="button"
                                variant="secondary"
                                disabled={form.processing}
                            >
                                {t('common.cancel')}
                            </Button>
                        </DialogClose>
                        <Button
                            type="submit"
                            disabled={form.processing || invalidWeek}
                        >
                            {form.processing && <Spinner />}
                            {t('common.save')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
