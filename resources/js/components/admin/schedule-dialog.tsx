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
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/lib/i18n';
import { store, update } from '@/routes/admin/users/schedules';
import type { AdminWorkSchedule } from '@/types';

type ScheduleForm = {
    valid_from: string;
    week: (number | null)[];
    // Registro de jornada (Fase 11, D-336).
    start_time_from: string;
    start_time_to: string;
    expected_pause_minutes: number;
    summer: boolean;
    summer_starts_on: string;
    summer_ends_on: string;
    summer_week: (number | null)[];
    summer_expected_pause_minutes: number;
};

/** Jornada de verano del convenio de publicidad: 7 h de lunes a viernes, del 1/7 al 31/8. */
const SUMMER_WEEK = [420, 420, 420, 420, 420, 0, 0];

/** Valores del formulario a partir de una versión (la que se edita o la vigente al crear). */
function registerData(
    source: AdminWorkSchedule | undefined,
): Omit<ScheduleForm, 'valid_from' | 'week'> {
    return {
        start_time_from: source?.start_time_from ?? '',
        start_time_to: source?.start_time_to ?? '',
        expected_pause_minutes: source?.expected_pause_minutes ?? 0,
        summer: Boolean(source?.summer),
        summer_starts_on: source?.summer?.starts_on ?? '07-01',
        summer_ends_on: source?.summer?.ends_on ?? '08-31',
        summer_week: source?.summer?.week ?? SUMMER_WEEK,
        summer_expected_pause_minutes:
            source?.summer?.expected_pause_minutes ?? 0,
    };
}

/**
 * Alta de una versión nueva de la jornada o edición de la última (si aún no ha empezado). La nueva
 * cierra la vigente el día anterior (WorkScheduleVersions en el servidor).
 */
export function ScheduleDialog({
    userId,
    schedule,
    initial,
    initialWeek,
    defaultFrom,
    trigger,
}: {
    userId: number;
    /** Versión que se edita; sin ella, se crea una nueva. */
    schedule?: AdminWorkSchedule;
    /** Versión de la que se copian el margen, la pausa y el verano al crear (la vigente). */
    initial?: AdminWorkSchedule;
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
        ...registerData(schedule ?? initial),
    });
    const errors = form.errors as Record<string, string | undefined>;
    const invalidWeek =
        form.data.week.some((minutes) => minutes === null) ||
        (form.data.summer &&
            form.data.summer_week.some((minutes) => minutes === null));

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
                        ...registerData(schedule ?? initial),
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

                    <fieldset
                        className="grid gap-4 rounded-md border p-4"
                        data-test="schedule-register"
                    >
                        <legend className="px-1 text-sm font-medium">
                            {t('people.schedule.register')}
                        </legend>
                        <div className="grid gap-2">
                            <span
                                id={`${id}-window`}
                                className="text-sm font-medium"
                            >
                                {t('people.schedule.window')}
                            </span>
                            <div
                                className="flex flex-wrap items-end gap-3"
                                role="group"
                                aria-labelledby={`${id}-window`}
                            >
                                <div className="grid gap-1">
                                    <Label
                                        htmlFor={`${id}-start-from`}
                                        className="text-xs"
                                    >
                                        {t('people.schedule.window_from')}
                                    </Label>
                                    <Input
                                        id={`${id}-start-from`}
                                        type="time"
                                        step={60}
                                        value={form.data.start_time_from}
                                        onChange={(event) =>
                                            form.setData(
                                                'start_time_from',
                                                event.target.value,
                                            )
                                        }
                                        className="tabular w-32"
                                    />
                                </div>
                                <div className="grid gap-1">
                                    <Label
                                        htmlFor={`${id}-start-to`}
                                        className="text-xs"
                                    >
                                        {t('people.schedule.window_to')}
                                    </Label>
                                    <Input
                                        id={`${id}-start-to`}
                                        type="time"
                                        step={60}
                                        value={form.data.start_time_to}
                                        onChange={(event) =>
                                            form.setData(
                                                'start_time_to',
                                                event.target.value,
                                            )
                                        }
                                        className="tabular w-32"
                                    />
                                </div>
                                <div className="grid gap-1">
                                    <Label
                                        htmlFor={`${id}-pause`}
                                        className="text-xs"
                                    >
                                        {t('people.schedule.pause')}
                                    </Label>
                                    <Input
                                        id={`${id}-pause`}
                                        type="number"
                                        min={0}
                                        max={240}
                                        step={5}
                                        value={form.data.expected_pause_minutes}
                                        onChange={(event) =>
                                            form.setData(
                                                'expected_pause_minutes',
                                                Number(event.target.value),
                                            )
                                        }
                                        className="tabular w-32"
                                    />
                                </div>
                            </div>
                            <p className="text-xs text-muted-foreground">
                                {t('people.schedule.window_hint')}
                            </p>
                            <InputError
                                message={
                                    errors.start_time_from ??
                                    errors.start_time_to ??
                                    errors.expected_pause_minutes
                                }
                            />
                        </div>

                        <label className="flex items-center gap-2 text-sm">
                            <Checkbox
                                checked={form.data.summer}
                                onCheckedChange={(checked) =>
                                    form.setData('summer', checked === true)
                                }
                                data-test="schedule-summer"
                            />
                            {t('people.schedule.summer')}
                        </label>
                        <p className="-mt-2 text-xs text-muted-foreground">
                            {t('people.schedule.summer_hint')}
                        </p>
                        {form.data.summer ? (
                            <div className="grid gap-4">
                                <div className="flex flex-wrap items-end gap-3">
                                    <div className="grid gap-1">
                                        <Label
                                            htmlFor={`${id}-summer-from`}
                                            className="text-xs"
                                        >
                                            {t('people.schedule.summer_from')}
                                        </Label>
                                        <Input
                                            id={`${id}-summer-from`}
                                            value={form.data.summer_starts_on}
                                            onChange={(event) =>
                                                form.setData(
                                                    'summer_starts_on',
                                                    event.target.value,
                                                )
                                            }
                                            inputMode="numeric"
                                            placeholder="07-01"
                                            className="tabular w-28"
                                        />
                                    </div>
                                    <div className="grid gap-1">
                                        <Label
                                            htmlFor={`${id}-summer-to`}
                                            className="text-xs"
                                        >
                                            {t('people.schedule.summer_to')}
                                        </Label>
                                        <Input
                                            id={`${id}-summer-to`}
                                            value={form.data.summer_ends_on}
                                            onChange={(event) =>
                                                form.setData(
                                                    'summer_ends_on',
                                                    event.target.value,
                                                )
                                            }
                                            inputMode="numeric"
                                            placeholder="08-31"
                                            className="tabular w-28"
                                        />
                                    </div>
                                    <div className="grid gap-1">
                                        <Label
                                            htmlFor={`${id}-summer-pause`}
                                            className="text-xs"
                                        >
                                            {t('people.schedule.summer_pause')}
                                        </Label>
                                        <Input
                                            id={`${id}-summer-pause`}
                                            type="number"
                                            min={0}
                                            max={240}
                                            step={5}
                                            value={
                                                form.data
                                                    .summer_expected_pause_minutes
                                            }
                                            onChange={(event) =>
                                                form.setData(
                                                    'summer_expected_pause_minutes',
                                                    Number(event.target.value),
                                                )
                                            }
                                            className="tabular w-32"
                                        />
                                    </div>
                                </div>
                                <InputError
                                    message={
                                        errors.summer_starts_on ??
                                        errors.summer_ends_on
                                    }
                                />
                                <WeekMinutesInput
                                    value={form.data.summer_week}
                                    onChange={(week) =>
                                        form.setData('summer_week', week)
                                    }
                                    legend={t('people.schedule.summer_week')}
                                    errorPrefix="summer_week"
                                    errors={errors}
                                />
                            </div>
                        ) : null}
                    </fieldset>

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
