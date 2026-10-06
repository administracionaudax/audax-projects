import { router, useForm } from '@inertiajs/react';
import {
    toastUnshownErrors,
    toastVisitErrors,
} from '@/components/admin/visit-errors';
import { Trash2 } from 'lucide-react';
import type { FormEvent, ReactNode } from 'react';
import { useId, useState } from 'react';
import { describedBy, Field } from '@/components/admin/field';
import { NativeSelect } from '@/components/admin/native-select';
import { DatePicker } from '@/components/domain/date-picker';
import { DurationInput } from '@/components/domain/duration-input';
import InputError from '@/components/input-error';
import { ESTIMATE_MAX_MINUTES } from '@/components/forecast/forecast-project-dialog';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Spinner } from '@/components/ui/spinner';
import { useResetOnOpen } from '@/hooks/use-reset-on-open';
import { t } from '@/lib/i18n';
import {
    destroy,
    store as storeForecast,
    update,
} from '@/routes/forecast/allocations';
import { store as storeProject } from '@/routes/projects/allocations';
import type {
    Allocation,
    AllocationMode,
    DepartmentOption,
    PersonOption,
} from '@/types/forecast';

/** Los cuatro modos de una asignación (D-282), en el orden en que se ofrecen. */
export const ALLOCATION_MODES: AllocationMode[] = [
    'total',
    'per_day',
    'percent',
    'monthly',
];

type FormData = {
    who: 'person' | 'gap';
    user_id: string;
    department_id: string;
    mode: AllocationMode;
    minutes: number | null;
    percent: string;
    start_date: string | null;
    end_date: string | null;
    note: string;
};

function initial(
    allocation?: Allocation,
    defaults?: { start?: string | null; end?: string | null },
): FormData {
    return {
        who: allocation ? (allocation.is_gap ? 'gap' : 'person') : 'person',
        user_id: allocation?.user ? String(allocation.user.id) : '',
        department_id: allocation?.department
            ? String(allocation.department.id)
            : '',
        mode: allocation?.mode ?? 'total',
        minutes: allocation?.minutes ?? null,
        percent: allocation?.percent ? String(allocation.percent) : '',
        start_date: allocation?.start_date ?? defaults?.start ?? null,
        end_date: allocation?.end_date ?? defaults?.end ?? null,
        note: allocation?.note ?? '',
    };
}

/**
 * Alta y edición de una asignación (D-282) de un previsto o de un proyecto real: a una persona (de
 * la plantilla o colaborador externo, D-300) o a un departamento sin persona (hueco), con uno de
 * los cuatro modos (horas en total, al día, % de la jornada o al mes) y sus fechas. Solo el modo
 * mensual puede quedarse sin fin. Al editar, también se borra.
 */
/** Campos con su error junto al control; el resto se avisa. */
const ALLOCATION_FIELDS = [
    'user_id',
    'department_id',
    'mode',
    'minutes',
    'percent',
    'start_date',
    'end_date',
    'note',
] as const;

export function AllocationDialog({
    container,
    allocation,
    people,
    departments,
    defaults,
    trigger,
    open: controlledOpen,
    onOpenChange,
}: {
    container: { kind: 'forecast' | 'project'; id: number };
    allocation?: Allocation;
    people: PersonOption[];
    departments: DepartmentOption[];
    /** Fechas por defecto de una nueva (las del previsto o del proyecto). */
    defaults?: { start?: string | null; end?: string | null };
    trigger?: ReactNode;
    open?: boolean;
    onOpenChange?: (open: boolean) => void;
}) {
    const id = useId();
    const [internalOpen, setInternalOpen] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const open = controlledOpen ?? internalOpen;
    const form = useForm<FormData>(initial(allocation, defaults));
    const errors = form.errors as Record<string, string | undefined>;
    const staff = people.filter((person) => !person.collaborator);
    const collaborators = people.filter((person) => person.collaborator);

    useResetOnOpen(open, () => {
        form.setDefaults(initial(allocation, defaults));
        form.setData(initial(allocation, defaults));
        form.clearErrors();
    });

    const setOpen = (next: boolean) => {
        setInternalOpen(next);
        onOpenChange?.(next);
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            user_id:
                data.who === 'person' && data.user_id !== ''
                    ? Number(data.user_id)
                    : null,
            department_id:
                data.who === 'gap' && data.department_id !== ''
                    ? Number(data.department_id)
                    : null,
            mode: data.mode,
            minutes: data.mode === 'percent' ? null : data.minutes,
            percent:
                data.mode === 'percent' && data.percent !== ''
                    ? Number(data.percent)
                    : null,
            start_date: data.start_date ?? '',
            end_date: data.end_date,
            note: data.note.trim() === '' ? null : data.note.trim(),
        }));

        const options = {
            preserveScroll: true,
            onSuccess: () => setOpen(false),
            // «La asignación está congelada» y otros sin campo: que se vean (D-310).
            onError: (errors: Record<string, string>) =>
                toastUnshownErrors(errors, ALLOCATION_FIELDS),
        };

        if (allocation) {
            form.put(update.url(allocation.id), options);
        } else if (container.kind === 'forecast') {
            form.post(storeForecast.url(container.id), options);
        } else {
            form.post(storeProject.url(container.id), options);
        }
    };

    const remove = () => {
        if (!allocation) {
            return;
        }

        setDeleting(true);
        router.delete(destroy.url(allocation.id), {
            preserveScroll: true,
            onSuccess: () => setOpen(false),
            onError: toastVisitErrors,
            onFinish: () => setDeleting(false),
        });
    };

    const amountLabel =
        form.data.mode === 'percent'
            ? t(
                  form.data.who === 'gap'
                      ? 'forecast.allocation.percent_gap'
                      : 'forecast.allocation.percent',
              )
            : t(`forecast.allocation.minutes_${form.data.mode}`);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            {trigger ? <DialogTrigger asChild>{trigger}</DialogTrigger> : null}
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                <form
                    onSubmit={submit}
                    className="grid gap-5"
                    noValidate
                    data-test="allocation-form"
                >
                    <DialogHeader>
                        <DialogTitle>
                            {t(
                                allocation
                                    ? 'forecast.allocation.edit_title'
                                    : 'forecast.allocation.new_title',
                            )}
                        </DialogTitle>
                        <DialogDescription>
                            {t('forecast.allocation.description')}
                        </DialogDescription>
                    </DialogHeader>

                    <fieldset className="grid gap-2">
                        <legend className="mb-1 text-sm font-medium">
                            {t('forecast.allocation.who')}
                        </legend>
                        <RadioGroup
                            value={form.data.who}
                            onValueChange={(value) =>
                                form.setData('who', value as FormData['who'])
                            }
                            className="grid gap-2 sm:grid-cols-2"
                        >
                            <div className="flex items-center gap-2">
                                <RadioGroupItem
                                    id={`${id}-person`}
                                    value="person"
                                />
                                <Label
                                    htmlFor={`${id}-person`}
                                    className="font-normal"
                                >
                                    {t('forecast.allocation.person')}
                                </Label>
                            </div>
                            <div className="flex items-center gap-2">
                                <RadioGroupItem id={`${id}-gap`} value="gap" />
                                <Label
                                    htmlFor={`${id}-gap`}
                                    className="font-normal"
                                >
                                    {t('forecast.allocation.gap')}
                                </Label>
                            </div>
                        </RadioGroup>
                        {form.data.who === 'person' ? (
                            <NativeSelect
                                aria-label={t('forecast.allocation.person')}
                                value={form.data.user_id}
                                onChange={(event) =>
                                    form.setData('user_id', event.target.value)
                                }
                                aria-invalid={errors.user_id ? true : undefined}
                                data-test="allocation-person"
                            >
                                <option value="">
                                    {t(
                                        'forecast.allocation.person_placeholder',
                                    )}
                                </option>
                                <optgroup
                                    label={t('forecast.allocation.staff')}
                                >
                                    {staff.map((person) => (
                                        <option
                                            key={person.id}
                                            value={person.id}
                                        >
                                            {person.name}
                                        </option>
                                    ))}
                                </optgroup>
                                {collaborators.length > 0 ? (
                                    <optgroup
                                        label={t(
                                            'forecast.matrix.collaborators',
                                        )}
                                    >
                                        {collaborators.map((person) => (
                                            <option
                                                key={person.id}
                                                value={person.id}
                                            >
                                                {person.name}
                                            </option>
                                        ))}
                                    </optgroup>
                                ) : null}
                                {/* La persona guardada que ya no se puede asignar: que se vea quién es
                                    (si no, el selector mostraba «Elige…» y se enviaba otra cosa). */}
                                {allocation?.user &&
                                !people.some(
                                    (person) =>
                                        person.id === allocation.user?.id,
                                ) ? (
                                    <option value={allocation.user.id} disabled>
                                        {t('forecast.allocation.unavailable', {
                                            name: allocation.user.name,
                                        })}
                                    </option>
                                ) : null}
                            </NativeSelect>
                        ) : (
                            <NativeSelect
                                aria-label={t('forecast.allocation.department')}
                                value={form.data.department_id}
                                onChange={(event) =>
                                    form.setData(
                                        'department_id',
                                        event.target.value,
                                    )
                                }
                                aria-invalid={
                                    errors.department_id || errors.user_id
                                        ? true
                                        : undefined
                                }
                                data-test="allocation-department"
                            >
                                <option value="">
                                    {t(
                                        'forecast.allocation.department_placeholder',
                                    )}
                                </option>
                                {departments.map((department) => (
                                    <option
                                        key={department.id}
                                        value={department.id}
                                    >
                                        {department.name}
                                    </option>
                                ))}
                            </NativeSelect>
                        )}
                        <InputError
                            message={errors.user_id ?? errors.department_id}
                        />
                    </fieldset>

                    <fieldset className="grid gap-2">
                        <legend className="mb-1 text-sm font-medium">
                            {t('forecast.allocation.mode')}
                        </legend>
                        <RadioGroup
                            value={form.data.mode}
                            onValueChange={(value) =>
                                form.setData('mode', value as AllocationMode)
                            }
                            className="grid gap-2 sm:grid-cols-2"
                        >
                            {ALLOCATION_MODES.map((mode) => (
                                <div
                                    key={mode}
                                    className="flex items-center gap-2"
                                >
                                    <RadioGroupItem
                                        id={`${id}-mode-${mode}`}
                                        value={mode}
                                    />
                                    <Label
                                        htmlFor={`${id}-mode-${mode}`}
                                        className="font-normal"
                                    >
                                        {t(`forecast.allocation.mode_${mode}`)}
                                    </Label>
                                </div>
                            ))}
                        </RadioGroup>
                        <p className="text-sm text-muted-foreground">
                            {t(
                                `forecast.allocation.mode_${form.data.mode}_help`,
                            )}
                        </p>
                        <InputError message={errors.mode} />
                    </fieldset>

                    {form.data.mode === 'percent' ? (
                        <Field
                            id={`${id}-percent-value`}
                            label={amountLabel}
                            error={errors.percent}
                        >
                            <Input
                                id={`${id}-percent-value`}
                                type="number"
                                inputMode="numeric"
                                min={1}
                                max={200}
                                value={form.data.percent}
                                onChange={(event) =>
                                    form.setData('percent', event.target.value)
                                }
                                aria-invalid={errors.percent ? true : undefined}
                                aria-describedby={describedBy(
                                    `${id}-percent-value`,
                                    {
                                        error: errors.percent,
                                    },
                                )}
                            />
                        </Field>
                    ) : (
                        <Field
                            id={`${id}-amount`}
                            label={amountLabel}
                            error={errors.minutes}
                        >
                            <DurationInput
                                id={`${id}-amount`}
                                value={form.data.minutes}
                                onChange={(value) =>
                                    form.setData('minutes', value)
                                }
                                max={ESTIMATE_MAX_MINUTES}
                                invalid={Boolean(errors.minutes)}
                            />
                        </Field>
                    )}

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            id={`${id}-start`}
                            label={t('forecast.allocation.start')}
                            error={errors.start_date}
                        >
                            <DatePicker
                                id={`${id}-start`}
                                value={form.data.start_date}
                                onChange={(value) =>
                                    form.setData((data) => ({
                                        ...data,
                                        start_date: value,
                                        end_date:
                                            value !== null &&
                                            data.end_date !== null &&
                                            data.end_date < value
                                                ? value
                                                : data.end_date,
                                    }))
                                }
                                invalid={Boolean(errors.start_date)}
                                clearable={false}
                            />
                        </Field>
                        <Field
                            id={`${id}-end`}
                            label={t('forecast.allocation.end')}
                            error={errors.end_date}
                            optional={
                                form.data.mode === 'monthly'
                                    ? t('forecast.allocation.end_optional')
                                    : undefined
                            }
                        >
                            <DatePicker
                                id={`${id}-end`}
                                value={form.data.end_date}
                                onChange={(value) =>
                                    form.setData('end_date', value)
                                }
                                min={form.data.start_date ?? undefined}
                                invalid={Boolean(errors.end_date)}
                            />
                        </Field>
                    </div>

                    <Field
                        id={`${id}-note`}
                        label={t('forecast.allocation.note')}
                        error={errors.note}
                        optional={t('forecast.form.optional')}
                    >
                        <Input
                            id={`${id}-note`}
                            value={form.data.note}
                            onChange={(event) =>
                                form.setData('note', event.target.value)
                            }
                            maxLength={200}
                        />
                    </Field>

                    <DialogFooter className="gap-2 sm:justify-between">
                        {allocation && allocation.can.update ? (
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={remove}
                                disabled={deleting}
                                className="text-danger sm:mr-auto"
                            >
                                <Trash2 aria-hidden="true" />
                                {t('forecast.actions.delete')}
                            </Button>
                        ) : (
                            <span />
                        )}
                        <span className="flex flex-col-reverse gap-2 sm:flex-row">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setOpen(false)}
                            >
                                {t('forecast.actions.cancel')}
                            </Button>
                            <Button type="submit" disabled={form.processing}>
                                {form.processing ? (
                                    <Spinner aria-hidden="true" />
                                ) : null}
                                {t(
                                    allocation
                                        ? 'forecast.actions.save'
                                        : 'forecast.allocation.create',
                                )}
                            </Button>
                        </span>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
