import { useForm } from '@inertiajs/react';
import { CalendarClock, Info } from 'lucide-react';
import type { ReactNode } from 'react';
import { useId, useState } from 'react';
import { describedBy, Field } from '@/components/admin/field';
import { NativeSelect } from '@/components/admin/native-select';
import { DatePicker } from '@/components/domain/date-picker';
import { DurationInput } from '@/components/domain/duration-input';
import InputError from '@/components/input-error';
import { IntegerInput } from '@/components/templates/integer-input';
import { MAX_ESTIMATE_MINUTES } from '@/components/templates/template-editor';
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
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { formatDate } from '@/lib/format';
import { t } from '@/lib/i18n';
import { store, update } from '@/routes/recurring';
import type { TaskPriority } from '@/types';
import type {
    RecurringFrequency,
    RecurringOptions,
    RecurringRuleItem,
} from '@/types/templates';
import {
    describeRecurrence,
    isoWeekday,
    nextOccurrence,
    WEEKDAYS,
    weekdayName,
} from './recurrence';

export const PRIORITIES: TaskPriority[] = ['low', 'normal', 'high', 'urgent'];

/** Límites del servidor (RecurringRuleRequest). */
export const MAX_INTERVAL = 12;
export const MAX_DUE_OFFSET = 60;

/** Props que se recargan tras cambiar una regla (recarga parcial de la página de Ajustes). */
export const RECURRING_RELOAD = ['recurring'];

export type RuleForm = {
    title: string;
    description: string;
    task_type_id: number | null;
    assignee_user_id: number | null;
    hour_bank_id: number | null;
    estimated_minutes: number | null;
    priority: TaskPriority;
    frequency: RecurringFrequency;
    interval: number;
    weekday: number | null;
    month_day: number | null;
    due_offset_days: number;
    starts_on: string;
    ends_on: string | null;
    is_active: boolean;
};

/**
 * Datos iniciales del formulario. Al editar, un responsable que ya no es miembro activo o una
 * bolsa que ya no está abierta no se pueden conservar: se vacían y se explica por qué.
 */
export function initialRuleForm(
    options: RecurringOptions,
    today: string,
    rule?: RecurringRuleItem,
): RuleForm {
    if (rule) {
        return {
            title: rule.title,
            description: rule.description ?? '',
            task_type_id: options.types.some(
                (type) => type.id === rule.task_type_id,
            )
                ? rule.task_type_id
                : null,
            assignee_user_id: options.members.some(
                (member) => member.id === rule.assignee_user_id,
            )
                ? rule.assignee_user_id
                : null,
            hour_bank_id: options.banks.some(
                (bank) => bank.id === rule.hour_bank_id,
            )
                ? rule.hour_bank_id
                : null,
            estimated_minutes: rule.estimated_minutes,
            priority: rule.priority,
            frequency: rule.frequency,
            interval: rule.interval,
            weekday: rule.weekday ?? isoWeekday(rule.starts_on),
            month_day: rule.month_day ?? Number(rule.starts_on.slice(8, 10)),
            due_offset_days: rule.due_offset_days,
            starts_on: rule.starts_on,
            ends_on: rule.ends_on,
            is_active: rule.is_active,
        };
    }

    return {
        title: '',
        description: '',
        task_type_id: null,
        assignee_user_id: null,
        hour_bank_id: options.uses_hour_banks
            ? (options.banks[0]?.id ?? null)
            : null,
        estimated_minutes: null,
        priority: 'normal',
        frequency: 'weekly',
        interval: 1,
        weekday: isoWeekday(today),
        month_day: Number(today.slice(8, 10)),
        due_offset_days: 0,
        starts_on: today,
        ends_on: null,
        is_active: true,
    };
}

/** Lo que se envía: solo el día de la frecuencia elegida. */
export function rulePayload(data: RuleForm): RuleForm {
    return {
        ...data,
        weekday: data.frequency === 'weekly' ? data.weekday : null,
        month_day: data.frequency === 'monthly' ? data.month_day : null,
        description: data.description.trim(),
    };
}

/**
 * Vista previa en vivo de la regla: la frase, la próxima fecha y, si toca hoy, que la tarea de hoy
 * se creará al guardar (D-059, generación inmediata).
 */
export function RulePreview({
    data,
    today,
    lastGeneratedOn,
}: {
    data: RuleForm;
    today: string;
    lastGeneratedOn: string | null;
}) {
    const recurrence = {
        frequency: data.frequency,
        interval: data.interval,
        weekday: data.weekday,
        month_day: data.month_day,
        starts_on: data.starts_on,
        ends_on: data.ends_on,
    };
    const next = data.is_active
        ? nextOccurrence(recurrence, today, lastGeneratedOn)
        : null;

    return (
        <div
            className="grid gap-1 rounded-md border bg-muted/60 p-3 text-sm"
            aria-live="polite"
            data-test="rule-preview"
        >
            <p className="flex items-start gap-2 font-medium">
                <CalendarClock
                    aria-hidden="true"
                    className="mt-0.5 size-4 shrink-0 text-muted-foreground"
                />
                {describeRecurrence(recurrence)}
            </p>
            <p className="text-muted-foreground">
                {!data.is_active
                    ? t('recurring.preview.inactive')
                    : next === null
                      ? t('recurring.preview.no_next')
                      : next === today
                        ? t('recurring.preview.today')
                        : t('recurring.preview.next', {
                              date: formatDate(next),
                          })}
            </p>
            <p className="text-muted-foreground">
                {data.due_offset_days === 0
                    ? t('recurring.preview.due_same_day')
                    : data.due_offset_days === 1
                      ? t('recurring.preview.due_one')
                      : t('recurring.preview.due_days', {
                            count: data.due_offset_days,
                        })}
            </p>
        </div>
    );
}

/**
 * Crear o editar una tarea recurrente de un proyecto (D-059): la tarea (título, descripción, tipo,
 * responsable entre los miembros, bolsa si el proyecto es de bolsas, estimación y prioridad) y la
 * regla (semanal cada N semanas y un día, o mensual un día del mes; vencimiento; desde y hasta),
 * con la frase y la próxima fecha en vivo. Los errores del servidor van junto a cada campo.
 */
export function RecurringRuleDialog({
    projectId,
    options,
    today,
    rule,
    trigger,
}: {
    projectId: number;
    options: RecurringOptions;
    today: string;
    rule?: RecurringRuleItem;
    trigger: ReactNode;
}) {
    const id = useId();
    const [open, setOpen] = useState(false);
    const form = useForm<RuleForm>(initialRuleForm(options, today, rule));
    const errors = form.errors as Partial<Record<keyof RuleForm, string>>;
    const lostAssignee =
        rule?.assignee !== null &&
        rule?.assignee !== undefined &&
        !options.members.some((member) => member.id === rule.assignee_user_id);
    const lostBank =
        rule?.hour_bank !== null &&
        rule?.hour_bank !== undefined &&
        !options.banks.some((bank) => bank.id === rule.hour_bank_id);

    const set = <K extends keyof RuleForm>(key: K, value: RuleForm[K]) =>
        form.setData((data) => ({ ...data, [key]: value }));

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.transform(rulePayload);

        const visit = {
            preserveScroll: true,
            only: RECURRING_RELOAD,
            onSuccess: () => setOpen(false),
        };

        if (rule) {
            form.put(update.url({ project: projectId, rule: rule.id }), visit);
        } else {
            form.post(store.url(projectId), visit);
        }
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                setOpen(next);
                if (next) {
                    form.setData(initialRuleForm(options, today, rule));
                    form.clearErrors();
                }
            }}
        >
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <form noValidate onSubmit={submit} className="grid gap-6">
                    <DialogHeader>
                        <DialogTitle>
                            {rule
                                ? t('recurring.form.edit_title', {
                                      title: rule.title,
                                  })
                                : t('recurring.form.new_title')}
                        </DialogTitle>
                        <DialogDescription>
                            {t('recurring.form.description')}
                        </DialogDescription>
                    </DialogHeader>

                    <fieldset className="grid gap-4">
                        <legend className="mb-2 text-sm font-medium">
                            {t('recurring.form.task')}
                        </legend>
                        <Field
                            id={`${id}-title`}
                            label={t('recurring.fields.title')}
                            error={errors.title}
                        >
                            <Input
                                id={`${id}-title`}
                                value={form.data.title}
                                maxLength={255}
                                required
                                autoComplete="off"
                                aria-invalid={errors.title ? true : undefined}
                                aria-describedby={describedBy(`${id}-title`, {
                                    error: errors.title,
                                })}
                                onChange={(event) =>
                                    set('title', event.target.value)
                                }
                            />
                        </Field>
                        <Field
                            id={`${id}-description`}
                            label={t('recurring.fields.description')}
                            optional={t('recurring.fields.optional')}
                            error={errors.description}
                        >
                            <Textarea
                                id={`${id}-description`}
                                rows={2}
                                maxLength={5000}
                                value={form.data.description}
                                aria-invalid={
                                    errors.description ? true : undefined
                                }
                                aria-describedby={describedBy(
                                    `${id}-description`,
                                    { error: errors.description },
                                )}
                                onChange={(event) =>
                                    set('description', event.target.value)
                                }
                            />
                        </Field>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field
                                id={`${id}-assignee`}
                                label={t('recurring.fields.assignee')}
                                help={
                                    lostAssignee
                                        ? t('recurring.form.lost_assignee', {
                                              name: rule?.assignee?.name ?? '',
                                          })
                                        : undefined
                                }
                                error={errors.assignee_user_id}
                            >
                                <NativeSelect
                                    id={`${id}-assignee`}
                                    value={
                                        form.data.assignee_user_id === null
                                            ? ''
                                            : String(form.data.assignee_user_id)
                                    }
                                    aria-invalid={
                                        errors.assignee_user_id
                                            ? true
                                            : undefined
                                    }
                                    aria-describedby={describedBy(
                                        `${id}-assignee`,
                                        {
                                            help: lostAssignee,
                                            error: errors.assignee_user_id,
                                        },
                                    )}
                                    onChange={(event) =>
                                        set(
                                            'assignee_user_id',
                                            event.target.value === ''
                                                ? null
                                                : Number(event.target.value),
                                        )
                                    }
                                >
                                    <option value="">
                                        {t('recurring.fields.no_assignee')}
                                    </option>
                                    {options.members.map((member) => (
                                        <option
                                            key={member.id}
                                            value={String(member.id)}
                                        >
                                            {member.name}
                                        </option>
                                    ))}
                                </NativeSelect>
                            </Field>
                            <Field
                                id={`${id}-type`}
                                label={t('recurring.fields.type')}
                                error={errors.task_type_id}
                            >
                                <NativeSelect
                                    id={`${id}-type`}
                                    value={
                                        form.data.task_type_id === null
                                            ? ''
                                            : String(form.data.task_type_id)
                                    }
                                    aria-invalid={
                                        errors.task_type_id ? true : undefined
                                    }
                                    aria-describedby={describedBy(
                                        `${id}-type`,
                                        {
                                            error: errors.task_type_id,
                                        },
                                    )}
                                    onChange={(event) =>
                                        set(
                                            'task_type_id',
                                            event.target.value === ''
                                                ? null
                                                : Number(event.target.value),
                                        )
                                    }
                                >
                                    <option value="">
                                        {t('recurring.fields.no_type')}
                                    </option>
                                    {options.types.map((type) => (
                                        <option
                                            key={type.id}
                                            value={String(type.id)}
                                        >
                                            {type.name}
                                        </option>
                                    ))}
                                </NativeSelect>
                            </Field>
                            {options.uses_hour_banks ? (
                                <Field
                                    id={`${id}-bank`}
                                    label={t('recurring.fields.bank')}
                                    help={
                                        options.banks.length === 0
                                            ? t('recurring.form.no_banks')
                                            : lostBank
                                              ? t('recurring.form.lost_bank', {
                                                    bank:
                                                        rule?.hour_bank?.name ??
                                                        '',
                                                })
                                              : undefined
                                    }
                                    error={errors.hour_bank_id}
                                >
                                    <NativeSelect
                                        id={`${id}-bank`}
                                        value={
                                            form.data.hour_bank_id === null
                                                ? ''
                                                : String(form.data.hour_bank_id)
                                        }
                                        aria-invalid={
                                            errors.hour_bank_id
                                                ? true
                                                : undefined
                                        }
                                        aria-describedby={describedBy(
                                            `${id}-bank`,
                                            {
                                                help:
                                                    options.banks.length ===
                                                        0 || lostBank,
                                                error: errors.hour_bank_id,
                                            },
                                        )}
                                        onChange={(event) =>
                                            set(
                                                'hour_bank_id',
                                                event.target.value === ''
                                                    ? null
                                                    : Number(
                                                          event.target.value,
                                                      ),
                                            )
                                        }
                                    >
                                        <option value="">
                                            {t('recurring.fields.choose_bank')}
                                        </option>
                                        {options.banks.map((bank) => (
                                            <option
                                                key={bank.id}
                                                value={String(bank.id)}
                                            >
                                                {bank.name}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                </Field>
                            ) : null}
                            <Field
                                id={`${id}-estimate`}
                                label={t('recurring.fields.estimate')}
                                optional={t('recurring.fields.optional')}
                                error={errors.estimated_minutes}
                            >
                                <DurationInput
                                    id={`${id}-estimate`}
                                    value={form.data.estimated_minutes}
                                    max={MAX_ESTIMATE_MINUTES}
                                    invalid={
                                        errors.estimated_minutes
                                            ? true
                                            : undefined
                                    }
                                    aria-describedby={describedBy(
                                        `${id}-estimate`,
                                        { error: errors.estimated_minutes },
                                    )}
                                    onChange={(minutes) =>
                                        set('estimated_minutes', minutes)
                                    }
                                />
                            </Field>
                            <Field
                                id={`${id}-priority`}
                                label={t('recurring.fields.priority')}
                                error={errors.priority}
                            >
                                <NativeSelect
                                    id={`${id}-priority`}
                                    value={form.data.priority}
                                    onChange={(event) =>
                                        set(
                                            'priority',
                                            event.target.value as TaskPriority,
                                        )
                                    }
                                >
                                    {PRIORITIES.map((priority) => (
                                        <option key={priority} value={priority}>
                                            {t(`task.priority.${priority}`)}
                                        </option>
                                    ))}
                                </NativeSelect>
                            </Field>
                        </div>
                    </fieldset>

                    <fieldset className="grid gap-4">
                        <legend className="mb-2 text-sm font-medium">
                            {t('recurring.form.repeat')}
                        </legend>
                        <RadioGroup
                            value={form.data.frequency}
                            onValueChange={(value) =>
                                set('frequency', value as RecurringFrequency)
                            }
                            className="flex flex-wrap gap-4"
                            aria-label={t('recurring.fields.frequency')}
                        >
                            {(['weekly', 'monthly'] as const).map(
                                (frequency) => (
                                    <div
                                        key={frequency}
                                        className="flex items-center gap-2"
                                    >
                                        <RadioGroupItem
                                            id={`${id}-frequency-${frequency}`}
                                            value={frequency}
                                        />
                                        <Label
                                            htmlFor={`${id}-frequency-${frequency}`}
                                            className="font-normal"
                                        >
                                            {t(
                                                `recurring.frequency.${frequency}`,
                                            )}
                                        </Label>
                                    </div>
                                ),
                            )}
                        </RadioGroup>
                        <InputError message={errors.frequency} />

                        <div className="grid gap-4 sm:grid-cols-3">
                            <Field
                                id={`${id}-interval`}
                                label={
                                    form.data.frequency === 'weekly'
                                        ? t('recurring.fields.interval_weeks')
                                        : t('recurring.fields.interval_months')
                                }
                                error={errors.interval}
                            >
                                <IntegerInput
                                    id={`${id}-interval`}
                                    value={form.data.interval}
                                    min={1}
                                    max={MAX_INTERVAL}
                                    onChange={(value) => set('interval', value)}
                                    aria-invalid={
                                        errors.interval ? true : undefined
                                    }
                                    aria-describedby={describedBy(
                                        `${id}-interval`,
                                        { error: errors.interval },
                                    )}
                                />
                            </Field>
                            {form.data.frequency === 'weekly' ? (
                                <Field
                                    id={`${id}-weekday`}
                                    label={t('recurring.fields.weekday')}
                                    error={errors.weekday}
                                >
                                    <NativeSelect
                                        id={`${id}-weekday`}
                                        value={String(form.data.weekday ?? 1)}
                                        aria-invalid={
                                            errors.weekday ? true : undefined
                                        }
                                        aria-describedby={describedBy(
                                            `${id}-weekday`,
                                            { error: errors.weekday },
                                        )}
                                        onChange={(event) =>
                                            set(
                                                'weekday',
                                                Number(event.target.value),
                                            )
                                        }
                                    >
                                        {WEEKDAYS.map((weekday) => (
                                            <option
                                                key={weekday}
                                                value={String(weekday)}
                                            >
                                                {weekdayName(weekday)}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                </Field>
                            ) : (
                                <Field
                                    id={`${id}-month-day`}
                                    label={t('recurring.fields.month_day')}
                                    help={t('recurring.fields.month_day_help')}
                                    error={errors.month_day}
                                >
                                    <IntegerInput
                                        id={`${id}-month-day`}
                                        value={form.data.month_day ?? 1}
                                        min={1}
                                        max={31}
                                        onChange={(value) =>
                                            set('month_day', value)
                                        }
                                        aria-invalid={
                                            errors.month_day ? true : undefined
                                        }
                                        aria-describedby={describedBy(
                                            `${id}-month-day`,
                                            {
                                                help: true,
                                                error: errors.month_day,
                                            },
                                        )}
                                    />
                                </Field>
                            )}
                            <Field
                                id={`${id}-due`}
                                label={t('recurring.fields.due_offset')}
                                help={t('recurring.fields.due_offset_help')}
                                error={errors.due_offset_days}
                            >
                                <IntegerInput
                                    id={`${id}-due`}
                                    value={form.data.due_offset_days}
                                    min={0}
                                    max={MAX_DUE_OFFSET}
                                    onChange={(value) =>
                                        set('due_offset_days', value)
                                    }
                                    aria-invalid={
                                        errors.due_offset_days
                                            ? true
                                            : undefined
                                    }
                                    aria-describedby={describedBy(`${id}-due`, {
                                        help: true,
                                        error: errors.due_offset_days,
                                    })}
                                />
                            </Field>
                        </div>

                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field
                                id={`${id}-starts`}
                                label={t('recurring.fields.starts_on')}
                                error={errors.starts_on}
                            >
                                <DatePicker
                                    id={`${id}-starts`}
                                    value={form.data.starts_on}
                                    clearable={false}
                                    invalid={
                                        errors.starts_on ? true : undefined
                                    }
                                    onChange={(value) =>
                                        set('starts_on', value ?? today)
                                    }
                                />
                            </Field>
                            <Field
                                id={`${id}-ends`}
                                label={t('recurring.fields.ends_on')}
                                optional={t('recurring.fields.optional')}
                                error={errors.ends_on}
                            >
                                <DatePicker
                                    id={`${id}-ends`}
                                    value={form.data.ends_on}
                                    placeholder={t('recurring.fields.no_end')}
                                    invalid={errors.ends_on ? true : undefined}
                                    onChange={(value) => set('ends_on', value)}
                                />
                            </Field>
                        </div>

                        <div className="flex items-start gap-3">
                            <Switch
                                id={`${id}-active`}
                                checked={form.data.is_active}
                                aria-describedby={`${id}-active-help`}
                                onCheckedChange={(checked) =>
                                    set('is_active', checked)
                                }
                            />
                            <div className="grid gap-1">
                                <Label htmlFor={`${id}-active`}>
                                    {t('recurring.fields.is_active')}
                                </Label>
                                <p
                                    id={`${id}-active-help`}
                                    className="text-sm text-muted-foreground"
                                >
                                    {t('recurring.fields.is_active_help')}
                                </p>
                                <InputError message={errors.is_active} />
                            </div>
                        </div>

                        <RulePreview
                            data={form.data}
                            today={today}
                            lastGeneratedOn={rule?.last_generated_on ?? null}
                        />
                        {options.uses_hour_banks &&
                        options.banks.length === 0 ? (
                            <p className="flex items-start gap-2 text-sm text-muted-foreground">
                                <Info
                                    aria-hidden="true"
                                    className="mt-0.5 size-4 shrink-0"
                                />
                                {t('recurring.form.no_banks')}
                            </p>
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
                        <Button type="submit" disabled={form.processing}>
                            {form.processing ? <Spinner /> : null}
                            {rule
                                ? t('recurring.form.save')
                                : t('recurring.form.create')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
