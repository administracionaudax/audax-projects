import { useForm } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useId, useState } from 'react';
import {
    LeaveTypeInfo,
    SimulationSummary,
} from '@/components/leave/leave-type-info';
import { useLeaveSimulation } from '@/components/leave/use-leave-simulation';
import { Input } from '@/components/ui/input';
import { absenceTypeLabel } from '@/components/absences/absence-meta';
import type {
    AbsenceLimits,
    AbsenceRow,
    AbsenceType,
} from '@/components/absences/types';
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
import { slotMinutes, usesSlot } from '@/lib/leave';
import type { AbsenceLeave, LeaveTypeOption } from '@/types/leave';
import {
    store as storeAbsence,
    update as updateAbsence,
} from '@/routes/absences';
import { store as registerAbsence } from '@/routes/absences/team';

/** Minutos máximos de una ausencia de parte del día (AbsenceRules::MAX_PARTIAL_MINUTES). */
export const MAX_PARTIAL_MINUTES = 24 * 60 - 1;

type Length = 'full' | 'partial';

type AbsenceForm = {
    user_id: string;
    type: AbsenceType;
    /** Fase 11, R3: el tipo del catálogo (solo con el módulo `people`). */
    leave_type_id: string;
    length: Length;
    start_date: string | null;
    end_date: string | null;
    partial_minutes: number | null;
    start_time: string;
    end_time: string;
    notes: string;
};

/** La ausencia que se modifica (modo «edit»). */
type EditedAbsence = Pick<
    AbsenceRow,
    'id' | 'type' | 'start_date' | 'end_date' | 'partial_minutes' | 'notes'
> & { leave?: AbsenceLeave };

type Props = {
    /**
     * «request»: la persona pide una suya. «register»: un responsable o admin registra una aprobada.
     * «edit»: quien la aprueba modifica una aprobada de otra persona (`absence` y `personName`).
     */
    mode: 'request' | 'register' | 'edit';
    types: AbsenceType[];
    limits: AbsenceLimits;
    /** Solicitudes de un responsable o admin: se aprueban solas (D-049). */
    selfApproves?: boolean;
    /** Personas de su ámbito (solo «register»). */
    people?: { id: number; name: string }[];
    /** Solo «edit». */
    absence?: EditedAbsence;
    personName?: string;
    trigger?: ReactNode;
    open?: boolean;
    onOpenChange?: (open: boolean) => void;
    /**
     * Fase 11, R3: el catálogo de tipos (con el módulo `people`). Sin él, el formulario es el de la
     * Fase 3, con los cinco tipos de siempre.
     */
    leaveTypes?: LeaveTypeOption[] | null;
};

function initial(
    types: AbsenceType[],
    absence?: EditedAbsence,
    leaveTypes?: LeaveTypeOption[] | null,
): AbsenceForm {
    if (absence) {
        const start = absence.leave?.start_time ?? '';
        const end = absence.leave?.end_time ?? '';

        return {
            user_id: '',
            type: absence.type,
            leave_type_id: absence.leave?.type
                ? String(absence.leave.type.id)
                : '',
            length: absence.partial_minutes === null ? 'full' : 'partial',
            start_date: absence.start_date,
            end_date: absence.end_date,
            partial_minutes: absence.partial_minutes,
            start_time: start,
            end_time: end,
            notes: absence.notes ?? '',
        };
    }

    return {
        user_id: '',
        type: types[0] ?? 'vacation',
        leave_type_id: leaveTypes?.[0] ? String(leaveTypes[0].id) : '',
        length: 'full',
        start_date: null,
        end_date: null,
        partial_minutes: null,
        start_time: '',
        end_time: '',
        notes: '',
    };
}

/**
 * Formulario de una ausencia (D-049): tipo, días completos (desde y hasta) o parte de UN día (con
 * sus horas), y notas. Sirve para solicitarla, registrarla ya aprobada o modificar una aprobada.
 * Las reglas (solapes, un año como mucho, parcial de un día) las valida el servidor y sus errores
 * salen junto a cada campo.
 */
export function AbsenceDialog({
    mode,
    types,
    limits,
    selfApproves = false,
    people = [],
    absence,
    personName = '',
    trigger,
    open: controlledOpen,
    onOpenChange,
    leaveTypes = null,
}: Props) {
    const id = useId();
    const [internalOpen, setInternalOpen] = useState(false);
    const open = controlledOpen ?? internalOpen;
    const form = useForm<AbsenceForm>(initial(types, absence, leaveTypes));
    const errors = form.errors as Record<string, string | undefined>;
    const partial = form.data.length === 'partial';
    const register = mode === 'register';
    const edit = mode === 'edit' && absence !== undefined;
    const catalog = leaveTypes !== null && leaveTypes.length > 0;
    const leaveType = catalog
        ? (leaveTypes.find(
              (type) => String(type.id) === form.data.leave_type_id,
          ) ?? null)
        : null;
    const slot = leaveType !== null && usesSlot(leaveType.unit) && partial;
    // La simulación (coste, saldo y avisos) es de quien pide: solo al solicitar la propia.
    const simulation = useLeaveSimulation(
        open && catalog && mode === 'request'
            ? {
                  leave_type_id: form.data.leave_type_id,
                  start_date: form.data.start_date,
                  end_date: partial ? form.data.start_date : form.data.end_date,
                  partial_minutes:
                      partial && !slot ? form.data.partial_minutes : null,
                  start_time: slot ? form.data.start_time : '',
                  end_time: slot ? form.data.end_time : '',
              }
            : null,
    );

    // Se vacía (o, al modificar, vuelve a los datos de la ausencia) al abrir desde su botón y al
    // cerrar: así también sale limpio cuando lo abre la página (open controlado, p. ej. con
    // ?solicitar=1).
    const setOpen = (next: boolean) => {
        form.setData(initial(types, absence, leaveTypes));
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

        if (
            slot &&
            slotMinutes(form.data.start_time, form.data.end_time) === 0
        ) {
            form.setError('start_time', t('leave.form.slot_required'));

            return;
        }

        // Sin horas, una «parte del día» se guardaría como el día entero: se piden antes.
        if (partial && !slot && form.data.partial_minutes === null) {
            form.setError(
                'partial_minutes',
                t('absences.form.partial_required'),
            );

            return;
        }

        form.transform((data) => ({
            ...(register
                ? { user_id: data.user_id === '' ? null : Number(data.user_id) }
                : {}),
            ...(catalog
                ? { leave_type_id: Number(data.leave_type_id) }
                : { type: data.type }),
            start_date: data.start_date ?? '',
            end_date:
                data.length === 'partial'
                    ? data.start_date
                    : (data.end_date ?? data.start_date),
            partial_minutes:
                data.length === 'partial' && !slot
                    ? data.partial_minutes
                    : null,
            ...(slot
                ? { start_time: data.start_time, end_time: data.end_time }
                : {}),
            notes: data.notes.trim() === '' ? null : data.notes,
        }));

        const options = {
            preserveScroll: true,
            onSuccess: () => setOpen(false),
        };

        if (edit) {
            form.put(updateAbsence.url(absence.id), options);
        } else if (register) {
            form.post(registerAbsence.url(), options);
        } else {
            form.post(storeAbsence.url(), options);
        }
    };

    const title = edit
        ? t('absences.form.edit_title', { name: personName })
        : register
          ? t('absences.form.register_title')
          : t('absences.form.request_title');
    const description = edit
        ? t('absences.form.edit_description', { name: personName })
        : register
          ? t('absences.form.register_description')
          : selfApproves
            ? t('absences.form.request_description_self')
            : t('absences.form.request_description');
    const submitLabel = edit
        ? t('absences.form.submit_edit')
        : register
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

                    {catalog ? (
                        <Field
                            id={`${id}-type`}
                            label={t('absences.form.type')}
                            error={errors.leave_type_id ?? errors.type}
                        >
                            <NativeSelect
                                id={`${id}-type`}
                                value={form.data.leave_type_id}
                                onChange={(event) => {
                                    const next = leaveTypes.find(
                                        (type) =>
                                            String(type.id) ===
                                            event.target.value,
                                    );
                                    form.setData((data) => ({
                                        ...data,
                                        leave_type_id: event.target.value,
                                        type: next?.category ?? data.type,
                                    }));
                                }}
                                aria-invalid={
                                    errors.leave_type_id ? true : undefined
                                }
                                aria-describedby={describedBy(`${id}-type`, {
                                    help: leaveType !== null,
                                    error: errors.leave_type_id,
                                })}
                                data-test="leave-type-select"
                            >
                                {leaveTypes.map((type) => (
                                    <option key={type.id} value={type.id}>
                                        {type.name}
                                    </option>
                                ))}
                            </NativeSelect>
                            {leaveType ? (
                                <LeaveTypeInfo
                                    id={`${id}-type-help`}
                                    type={leaveType}
                                />
                            ) : null}
                        </Field>
                    ) : (
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
                    )}

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
                                    {leaveType !== null &&
                                    usesSlot(leaveType.unit)
                                        ? t('leave.form.slot')
                                        : t('absences.form.partial')}
                                </Label>
                            </div>
                        </RadioGroup>
                    </fieldset>

                    {slot ? (
                        <div className="grid gap-4 sm:grid-cols-3">
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
                                id={`${id}-from`}
                                label={t('leave.form.start_time')}
                                error={errors.start_time}
                            >
                                <Input
                                    id={`${id}-from`}
                                    type="time"
                                    value={form.data.start_time}
                                    onChange={(event) =>
                                        form.setData(
                                            'start_time',
                                            event.target.value,
                                        )
                                    }
                                    aria-invalid={
                                        errors.start_time ? true : undefined
                                    }
                                    data-test="leave-start-time"
                                />
                            </Field>
                            <Field
                                id={`${id}-to`}
                                label={t('leave.form.end_time')}
                                error={errors.end_time}
                            >
                                <Input
                                    id={`${id}-to`}
                                    type="time"
                                    value={form.data.end_time}
                                    onChange={(event) =>
                                        form.setData(
                                            'end_time',
                                            event.target.value,
                                        )
                                    }
                                    aria-invalid={
                                        errors.end_time ? true : undefined
                                    }
                                    data-test="leave-end-time"
                                />
                            </Field>
                            <p className="text-sm text-muted-foreground sm:col-span-3">
                                {t('leave.form.slot_help')}
                            </p>
                        </div>
                    ) : partial ? (
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
                            {/* La ayuda, a todo el ancho bajo la fila (en la columna de las horas
                                ocupaba 2-3 líneas y dejaba un hueco bajo el día). D-310. */}
                            <p
                                id={`${id}-minutes-help`}
                                className="text-sm text-muted-foreground sm:col-span-2"
                            >
                                {t('absences.form.partial_help')}
                            </p>
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

                    {simulation !== null ? (
                        <SimulationSummary simulation={simulation} />
                    ) : null}

                    {/* Errores de la ausencia en sí (p. ej., ya no se puede modificar) y, al
                        modificarla, los de la persona, que no tiene campo propio. */}
                    <InputError
                        message={
                            errors.absence ??
                            (edit ? errors.user_id : undefined)
                        }
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
