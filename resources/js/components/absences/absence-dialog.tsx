import { useForm } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useId, useState } from 'react';
import { absenceTypeLabel } from '@/components/absences/absence-meta';
import type { AbsenceLimits, AbsenceType } from '@/components/absences/types';
import { describedBy, Field } from '@/components/admin/field';
import { NativeSelect } from '@/components/admin/native-select';
import { DatePicker } from '@/components/domain/date-picker';
import { DurationInput } from '@/components/domain/duration-input';
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
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { t } from '@/lib/i18n';
import { store as storeAbsence } from '@/routes/absences';
import { store as registerAbsence } from '@/routes/absences/team';

/** Minutos máximos de una ausencia de parte del día (AbsenceRules::MAX_PARTIAL_MINUTES). */
export const MAX_PARTIAL_MINUTES = 24 * 60 - 1;

type Length = 'full' | 'partial';

type AbsenceForm = {
    user_id: string;
    type: AbsenceType;
    length: Length;
    start_date: string | null;
    end_date: string | null;
    partial_minutes: number | null;
    notes: string;
};

type Props = {
    /** «request»: la persona pide una suya. «register»: un responsable o admin registra una aprobada. */
    mode: 'request' | 'register';
    types: AbsenceType[];
    limits: AbsenceLimits;
    /** Solicitudes de un responsable o admin: se aprueban solas (D-049). */
    selfApproves?: boolean;
    /** Personas de su ámbito (solo «register»). */
    people?: { id: number; name: string }[];
    trigger?: ReactNode;
    open?: boolean;
    onOpenChange?: (open: boolean) => void;
};

function initial(types: AbsenceType[]): AbsenceForm {
    return {
        user_id: '',
        type: types[0] ?? 'vacation',
        length: 'full',
        start_date: null,
        end_date: null,
        partial_minutes: null,
        notes: '',
    };
}

/**
 * Formulario de una ausencia (D-049): tipo, días completos (desde y hasta) o parte de UN día (con
 * sus horas), y notas. Las reglas (solapes, un año como mucho, parcial de un día) las valida el
 * servidor y sus errores salen junto a cada campo.
 */
export function AbsenceDialog({
    mode,
    types,
    limits,
    selfApproves = false,
    people = [],
    trigger,
    open: controlledOpen,
    onOpenChange,
}: Props) {
    const id = useId();
    const [internalOpen, setInternalOpen] = useState(false);
    const open = controlledOpen ?? internalOpen;
    const form = useForm<AbsenceForm>(initial(types));
    const errors = form.errors as Record<string, string | undefined>;
    const partial = form.data.length === 'partial';
    const register = mode === 'register';

    // Se vacía al abrir desde su botón y al cerrar: así también sale limpio cuando lo abre la
    // página (open controlado, p. ej. con ?solicitar=1).
    const setOpen = (next: boolean) => {
        form.setData(initial(types));
        form.clearErrors();
        setInternalOpen(next);
        onOpenChange?.(next);
    };

    const setStart = (value: string | null) => {
        form.setData((data) => ({
            ...data,
            start_date: value,
            // «Hasta» sigue a «Desde» si queda antes o vacío.
            end_date:
                value !== null &&
                (data.end_date === null || data.end_date < value)
                    ? value
                    : data.end_date,
        }));
    };

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            ...(register
                ? { user_id: data.user_id === '' ? null : Number(data.user_id) }
                : {}),
            type: data.type,
            start_date: data.start_date ?? '',
            end_date:
                data.length === 'partial'
                    ? data.start_date
                    : (data.end_date ?? data.start_date),
            partial_minutes:
                data.length === 'partial' ? data.partial_minutes : null,
            notes: data.notes.trim() === '' ? null : data.notes,
        }));

        const options = {
            preserveScroll: true,
            onSuccess: () => setOpen(false),
        };

        if (register) {
            form.post(registerAbsence.url(), options);
        } else {
            form.post(storeAbsence.url(), options);
        }
    };

    const title = register
        ? t('absences.form.register_title')
        : t('absences.form.request_title');
    const description = register
        ? t('absences.form.register_description')
        : selfApproves
          ? t('absences.form.request_description_self')
          : t('absences.form.request_description');
    const submitLabel = register
        ? t('absences.form.submit_register')
        : selfApproves
          ? t('absences.form.submit_self')
          : t('absences.form.submit_request');

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            {trigger ? <DialogTrigger asChild>{trigger}</DialogTrigger> : null}
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                <form
                    onSubmit={submit}
                    className="grid gap-5"
                    noValidate
                    data-test="absence-form"
                >
                    <DialogHeader>
                        <DialogTitle>{title}</DialogTitle>
                        <DialogDescription>{description}</DialogDescription>
                    </DialogHeader>

                    {register ? (
                        <Field
                            id={`${id}-person`}
                            label={t('absences.form.person')}
                            error={errors.user_id}
                        >
                            <NativeSelect
                                id={`${id}-person`}
                                value={form.data.user_id}
                                onChange={(event) =>
                                    form.setData('user_id', event.target.value)
                                }
                                required
                                aria-invalid={errors.user_id ? true : undefined}
                                aria-describedby={describedBy(`${id}-person`, {
                                    error: errors.user_id,
                                })}
                            >
                                <option value="">
                                    {t('absences.form.person_placeholder')}
                                </option>
                                {people.map((person) => (
                                    <option key={person.id} value={person.id}>
                                        {person.name}
                                    </option>
                                ))}
                            </NativeSelect>
                        </Field>
                    ) : null}

                    <Field
                        id={`${id}-type`}
                        label={t('absences.form.type')}
                        error={errors.type}
                    >
                        <NativeSelect
                            id={`${id}-type`}
                            value={form.data.type}
                            onChange={(event) =>
                                form.setData(
                                    'type',
                                    event.target.value as AbsenceType,
                                )
                            }
                            aria-invalid={errors.type ? true : undefined}
                            aria-describedby={describedBy(`${id}-type`, {
                                error: errors.type,
                            })}
                        >
                            {types.map((type) => (
                                <option key={type} value={type}>
                                    {absenceTypeLabel(type)}
                                </option>
                            ))}
                        </NativeSelect>
                    </Field>

                    <fieldset className="grid gap-2">
                        <legend className="mb-1 text-sm font-medium">
                            {t('absences.form.length')}
                        </legend>
                        <RadioGroup
                            value={form.data.length}
                            onValueChange={(value) =>
                                form.setData('length', value as Length)
                            }
                            className="grid gap-2 sm:grid-cols-2"
                        >
                            <div className="flex items-center gap-2">
                                <RadioGroupItem
                                    id={`${id}-full`}
                                    value="full"
                                />
                                <Label
                                    htmlFor={`${id}-full`}
                                    className="font-normal"
                                >
                                    {t('absences.form.full_days')}
                                </Label>
                            </div>
                            <div className="flex items-center gap-2">
                                <RadioGroupItem
                                    id={`${id}-partial`}
                                    value="partial"
                                />
                                <Label
                                    htmlFor={`${id}-partial`}
                                    className="font-normal"
                                >
                                    {t('absences.form.partial')}
                                </Label>
                            </div>
                        </RadioGroup>
                    </fieldset>

                    {partial ? (
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field
                                id={`${id}-start`}
                                label={t('absences.form.day')}
                                error={errors.start_date}
                            >
                                <DatePicker
                                    id={`${id}-start`}
                                    value={form.data.start_date}
                                    onChange={setStart}
                                    max={limits.to}
                                    clearable={false}
                                    invalid={Boolean(errors.start_date)}
                                    placeholder={t('absences.form.pick_date')}
                                />
                            </Field>
                            <Field
                                id={`${id}-minutes`}
                                label={t('absences.form.partial_minutes')}
                                help={t('absences.form.partial_help')}
                                error={errors.partial_minutes}
                            >
                                <DurationInput
                                    id={`${id}-minutes`}
                                    value={form.data.partial_minutes}
                                    onChange={(minutes) =>
                                        form.setData('partial_minutes', minutes)
                                    }
                                    max={MAX_PARTIAL_MINUTES}
                                    invalid={Boolean(errors.partial_minutes)}
                                    aria-describedby={describedBy(
                                        `${id}-minutes`,
                                        {
                                            help: true,
                                            error: errors.partial_minutes,
                                        },
                                    )}
                                />
                            </Field>
                        </div>
                    ) : (
                        <div className="grid gap-2">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field
                                    id={`${id}-start`}
                                    label={t('absences.form.start')}
                                    error={errors.start_date}
                                >
                                    <DatePicker
                                        id={`${id}-start`}
                                        value={form.data.start_date}
                                        onChange={setStart}
                                        max={limits.to}
                                        clearable={false}
                                        invalid={Boolean(errors.start_date)}
                                        placeholder={t(
                                            'absences.form.pick_date',
                                        )}
                                    />
                                </Field>
                                <Field
                                    id={`${id}-end`}
                                    label={t('absences.form.end')}
                                    error={errors.end_date}
                                >
                                    <DatePicker
                                        id={`${id}-end`}
                                        value={form.data.end_date}
                                        onChange={(value) =>
                                            form.setData('end_date', value)
                                        }
                                        max={limits.to}
                                        clearable={false}
                                        invalid={Boolean(errors.end_date)}
                                        placeholder={t(
                                            'absences.form.pick_date',
                                        )}
                                    />
                                </Field>
                            </div>
                            <p className="text-sm text-muted-foreground">
                                {t('absences.form.dates_help')}
                            </p>
                            <InputError message={errors.partial_minutes} />
                        </div>
                    )}

                    <Field
                        id={`${id}-notes`}
                        label={t('absences.form.notes')}
                        optional={t('absences.form.notes_optional')}
                        error={errors.notes}
                    >
                        <Textarea
                            id={`${id}-notes`}
                            value={form.data.notes}
                            onChange={(event) =>
                                form.setData('notes', event.target.value)
                            }
                            maxLength={2000}
                            rows={3}
                            placeholder={t('absences.form.notes_placeholder')}
                            aria-invalid={errors.notes ? true : undefined}
                            aria-describedby={describedBy(`${id}-notes`, {
                                error: errors.notes,
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
                            {submitLabel}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
