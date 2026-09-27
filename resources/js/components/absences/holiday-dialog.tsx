import { useForm } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useId, useState } from 'react';
import type { Holiday } from '@/components/absences/types';
import { describedBy, Field } from '@/components/admin/field';
import { DatePicker } from '@/components/domain/date-picker';
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
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/lib/i18n';
import { store, update } from '@/routes/admin/holidays';

type HolidayForm = { date: string | null; name: string };

/** Crear o editar un festivo (D-050): una fecha sin otro festivo y un nombre. */
export function HolidayDialog({
    holiday,
    trigger,
}: {
    holiday?: Holiday;
    trigger: ReactNode;
}) {
    const id = useId();
    const [open, setOpen] = useState(false);
    const initial = (): HolidayForm => ({
        date: holiday?.date ?? null,
        name: holiday?.name ?? '',
    });
    const form = useForm<HolidayForm>(initial());

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({ date: data.date ?? '', name: data.name }));
        const options = {
            preserveScroll: true,
            onSuccess: () => setOpen(false),
        };

        if (holiday) {
            form.put(update.url(holiday.id), options);
        } else {
            form.post(store.url(), options);
        }
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                setOpen(next);
                if (next) {
                    form.setData(initial());
                    form.clearErrors();
                }
            }}
        >
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent className="sm:max-w-md">
                <form onSubmit={submit} className="grid gap-5" noValidate>
                    <DialogHeader>
                        <DialogTitle>
                            {holiday
                                ? t('holidays.edit_title', {
                                      name: holiday.name,
                                  })
                                : t('holidays.new_title')}
                        </DialogTitle>
                        <DialogDescription>
                            {t('holidays.form_description')}
                        </DialogDescription>
                    </DialogHeader>

                    <Field
                        id={`${id}-date`}
                        label={t('holidays.form.date')}
                        error={form.errors.date}
                    >
                        <DatePicker
                            id={`${id}-date`}
                            value={form.data.date}
                            onChange={(value) => form.setData('date', value)}
                            clearable={false}
                            invalid={Boolean(form.errors.date)}
                        />
                    </Field>

                    <Field
                        id={`${id}-name`}
                        label={t('holidays.form.name')}
                        error={form.errors.name}
                    >
                        <Input
                            id={`${id}-name`}
                            value={form.data.name}
                            onChange={(event) =>
                                form.setData('name', event.target.value)
                            }
                            required
                            maxLength={100}
                            autoComplete="off"
                            placeholder={t('holidays.form.name_placeholder')}
                            aria-invalid={form.errors.name ? true : undefined}
                            aria-describedby={describedBy(`${id}-name`, {
                                error: form.errors.name,
                            })}
                        />
                    </Field>

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
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <Spinner />}
                            {t('common.save')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
