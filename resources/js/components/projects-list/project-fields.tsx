import { useId } from 'react';
import { DatePicker } from '@/components/domain/date-picker';
import { DurationInput } from '@/components/domain/duration-input';
import InputError from '@/components/input-error';
import { PROJECT_COLORS } from '@/components/projects-list/project-colors';
import {
    normalizeProjectCode,
    suggestProjectCode,
} from '@/components/projects-list/project-code';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { BillingType, ProjectClientOption, ProjectStatus } from '@/types';

/** Máximo del presupuesto (9999:59), como en el servidor. */
export const MAX_BUDGET_MINUTES = 9999 * 60 + 59;

export const BILLING_TYPES: BillingType[] = [
    'hour_bank',
    'fixed_price',
    'time_and_materials',
    'internal',
];

/** Estados que se eligen en el formulario (archivar tiene su propio botón). */
export const EDITABLE_STATUSES: ProjectStatus[] = [
    'planned',
    'active',
    'on_hold',
    'completed',
];

export type ProjectFormData = {
    name: string;
    client_id: number | null;
    billing_type: BillingType;
    code: string;
    color: string;
    description: string;
    status: ProjectStatus;
    start_date: string | null;
    due_date: string | null;
    budget_minutes: number | null;
    fixed_price_amount: string;
    hourly_rate: string;
};

type Errors = Partial<Record<keyof ProjectFormData, string>>;

const NONE = '__none__';

/**
 * Campos de alta y edición de un proyecto (SPEC §4.2 y §6). Pocos obligatorios: nombre, tipo y
 * (salvo en internos) cliente. El código se sugiere a partir del cliente y el nombre mientras no
 * lo edites a mano. El importe cerrado y la tarifa solo se ven con view-financials.
 */
export function ProjectFields({
    data,
    set,
    errors,
    clients,
    canViewFinancials,
    statusEditable = true,
    codeTouched,
    onCodeTouched,
    creating = true,
}: {
    data: ProjectFormData;
    set: <K extends keyof ProjectFormData>(
        key: K,
        value: ProjectFormData[K],
    ) => void;
    errors: Errors;
    clients: ProjectClientOption[];
    canViewFinancials: boolean;
    /** false en un proyecto archivado (se recupera con su botón). */
    statusEditable?: boolean;
    /** Si el código se ha tocado a mano, ya no se sugiere. */
    codeTouched: boolean;
    onCodeTouched?: () => void;
    /** En el alta el código puede quedar vacío (lo genera el servidor); al editar, no. */
    creating?: boolean;
}) {
    const id = useId();
    const internal = data.billing_type === 'internal';
    const clientName = (clientId: number | null) =>
        clients.find((client) => client.id === clientId)?.name ?? null;

    const suggest = (clientId: number | null, name: string) => {
        if (!codeTouched) {
            set('code', suggestProjectCode(clientName(clientId), name));
        }
    };

    return (
        <div className="grid gap-8">
            <fieldset className="grid gap-5">
                <legend className="mb-3 text-base font-medium">
                    {t('projects.form.basics')}
                </legend>

                <div className="grid gap-2">
                    <Label htmlFor={`${id}-name`}>
                        {t('projects.form.name')}
                    </Label>
                    <Input
                        id={`${id}-name`}
                        value={data.name}
                        required
                        maxLength={255}
                        autoComplete="off"
                        placeholder={t('projects.form.name_placeholder')}
                        aria-invalid={errors.name ? true : undefined}
                        onChange={(event) => {
                            set('name', event.target.value);
                            suggest(
                                internal ? null : data.client_id,
                                event.target.value,
                            );
                        }}
                    />
                    <InputError message={errors.name} />
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="grid min-w-0 grid-cols-1 gap-2">
                        <Label htmlFor={`${id}-billing`}>
                            {t('projects.form.billing_type')}
                        </Label>
                        <Select
                            value={data.billing_type}
                            onValueChange={(value) => {
                                const type = value as BillingType;
                                set('billing_type', type);

                                if (type === 'internal') {
                                    set('client_id', null);
                                    suggest(null, data.name);
                                }
                            }}
                        >
                            <SelectTrigger
                                id={`${id}-billing`}
                                className="w-full min-w-0"
                                aria-invalid={
                                    errors.billing_type ? true : undefined
                                }
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {BILLING_TYPES.map((type) => (
                                    <SelectItem key={type} value={type}>
                                        {t(`project.billing_type.${type}`)}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={errors.billing_type} />
                    </div>

                    <div className="grid min-w-0 grid-cols-1 gap-2">
                        <Label htmlFor={internal ? undefined : `${id}-client`}>
                            {t('projects.form.client')}
                        </Label>
                        {internal ? (
                            <p className="flex h-9 items-center text-sm text-muted-foreground">
                                {t('projects.form.internal_no_client')}
                            </p>
                        ) : (
                            <Select
                                value={
                                    data.client_id === null
                                        ? NONE
                                        : String(data.client_id)
                                }
                                onValueChange={(value) => {
                                    const clientId =
                                        value === NONE ? null : Number(value);
                                    set('client_id', clientId);
                                    suggest(clientId, data.name);
                                }}
                            >
                                <SelectTrigger
                                    id={`${id}-client`}
                                    className="w-full min-w-0"
                                    aria-invalid={
                                        errors.client_id ? true : undefined
                                    }
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NONE}>
                                        {t('projects.form.choose_client')}
                                    </SelectItem>
                                    {clients.map((client) => (
                                        <SelectItem
                                            key={client.id}
                                            value={String(client.id)}
                                        >
                                            {client.is_active
                                                ? client.name
                                                : t(
                                                      'projects.form.inactive_client',
                                                      { name: client.name },
                                                  )}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        )}
                        <InputError message={errors.client_id} />
                    </div>
                </div>

                <div className="grid gap-2">
                    <Label htmlFor={`${id}-code`}>
                        {t('projects.form.code')}
                    </Label>
                    <Input
                        id={`${id}-code`}
                        value={data.code}
                        maxLength={20}
                        autoComplete="off"
                        className="w-full uppercase sm:w-64"
                        aria-describedby={`${id}-code-help`}
                        aria-invalid={errors.code ? true : undefined}
                        onChange={(event) => {
                            onCodeTouched?.();
                            set(
                                'code',
                                normalizeProjectCode(event.target.value),
                            );
                        }}
                    />
                    <p
                        id={`${id}-code-help`}
                        className="text-sm text-muted-foreground"
                    >
                        {t(
                            creating
                                ? 'projects.form.code_help'
                                : 'projects.form.code_help_edit',
                        )}
                    </p>
                    <InputError message={errors.code} />
                </div>

                <fieldset className="grid gap-2">
                    <legend className="mb-2 text-sm font-medium">
                        {t('projects.form.color')}
                    </legend>
                    <RadioGroup
                        value={data.color}
                        onValueChange={(value) => set('color', value)}
                        className="flex flex-wrap gap-3"
                        aria-invalid={errors.color ? true : undefined}
                    >
                        {PROJECT_COLORS.map((color) => (
                            <label
                                key={color.value}
                                className="flex items-center gap-2 text-sm"
                            >
                                <RadioGroupItem value={color.value} />
                                <span
                                    aria-hidden="true"
                                    className="size-4 rounded-full border border-border"
                                    style={{ backgroundColor: color.value }}
                                />
                                {t(color.label)}
                            </label>
                        ))}
                    </RadioGroup>
                    <InputError message={errors.color} />
                </fieldset>

                <div className="grid gap-2">
                    <Label htmlFor={`${id}-description`}>
                        {t('projects.form.description')}
                    </Label>
                    <Textarea
                        id={`${id}-description`}
                        value={data.description}
                        rows={3}
                        maxLength={5000}
                        placeholder={t('projects.form.optional')}
                        aria-invalid={errors.description ? true : undefined}
                        onChange={(event) =>
                            set('description', event.target.value)
                        }
                    />
                    <InputError message={errors.description} />
                </div>
            </fieldset>

            <fieldset className="grid gap-5">
                <legend className="mb-3 text-base font-medium">
                    {t('projects.form.planning')}
                </legend>

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    {statusEditable ? (
                        <div className="grid min-w-0 grid-cols-1 gap-2">
                            <Label htmlFor={`${id}-status`}>
                                {t('projects.form.status')}
                            </Label>
                            <Select
                                value={data.status}
                                onValueChange={(value) =>
                                    set('status', value as ProjectStatus)
                                }
                            >
                                <SelectTrigger
                                    id={`${id}-status`}
                                    className="w-full min-w-0"
                                    aria-invalid={
                                        errors.status ? true : undefined
                                    }
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {EDITABLE_STATUSES.map((status) => (
                                        <SelectItem key={status} value={status}>
                                            {t(`project.status.${status}`)}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.status} />
                        </div>
                    ) : null}

                    <div className="grid gap-2">
                        <Label htmlFor={`${id}-start`}>
                            {t('projects.form.start_date')}
                        </Label>
                        <DatePicker
                            id={`${id}-start`}
                            value={data.start_date}
                            onChange={(value) => set('start_date', value)}
                            invalid={Boolean(errors.start_date)}
                        />
                        <InputError message={errors.start_date} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor={`${id}-due`}>
                            {t('projects.form.due_date')}
                        </Label>
                        <DatePicker
                            id={`${id}-due`}
                            value={data.due_date}
                            onChange={(value) => set('due_date', value)}
                            invalid={Boolean(errors.due_date)}
                        />
                        <InputError message={errors.due_date} />
                    </div>

                    <div className="grid content-start gap-2">
                        <Label htmlFor={`${id}-budget`}>
                            {t('projects.form.budget')}
                        </Label>
                        <DurationInput
                            id={`${id}-budget`}
                            value={data.budget_minutes}
                            onChange={(minutes) =>
                                set('budget_minutes', minutes)
                            }
                            max={MAX_BUDGET_MINUTES}
                            placeholder={t('projects.form.budget_placeholder')}
                            invalid={Boolean(errors.budget_minutes)}
                        />
                        <InputError message={errors.budget_minutes} />
                    </div>
                </div>
            </fieldset>

            {canViewFinancials ? (
                <fieldset className="grid gap-5">
                    <legend className="mb-3 text-base font-medium">
                        {t('projects.form.financials')}
                    </legend>
                    <div className="grid gap-4 sm:grid-cols-2">
                        {data.billing_type === 'fixed_price' ? (
                            <MoneyField
                                id={`${id}-fixed`}
                                label={t('projects.form.fixed_price')}
                                value={data.fixed_price_amount}
                                error={errors.fixed_price_amount}
                                onChange={(value) =>
                                    set('fixed_price_amount', value)
                                }
                            />
                        ) : null}
                        <MoneyField
                            id={`${id}-rate`}
                            label={t('projects.form.hourly_rate')}
                            help={t('projects.form.hourly_rate_help')}
                            value={data.hourly_rate}
                            error={errors.hourly_rate}
                            onChange={(value) => set('hourly_rate', value)}
                        />
                    </div>
                </fieldset>
            ) : null}
        </div>
    );
}

/** Importe en euros: admite coma o punto decimal y se envía con punto. */
export function MoneyField({
    id,
    label,
    help,
    value,
    error,
    onChange,
    className,
}: {
    id: string;
    label: string;
    help?: string;
    value: string;
    error?: string;
    onChange: (value: string) => void;
    className?: string;
}) {
    return (
        <div className={cn('grid content-start gap-2', className)}>
            <Label htmlFor={id}>{label}</Label>
            <div className="relative">
                <Input
                    id={id}
                    inputMode="decimal"
                    autoComplete="off"
                    value={value}
                    className="tabular pr-8"
                    aria-describedby={help ? `${id}-help` : undefined}
                    aria-invalid={error ? true : undefined}
                    onChange={(event) =>
                        onChange(event.target.value.replace(',', '.'))
                    }
                />
                <span
                    aria-hidden="true"
                    className="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-sm text-muted-foreground"
                >
                    €
                </span>
            </div>
            {help ? (
                <p id={`${id}-help`} className="text-sm text-muted-foreground">
                    {help}
                </p>
            ) : null}
            <InputError message={error} />
        </div>
    );
}
