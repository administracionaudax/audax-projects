import { useId } from 'react';
import { DatePicker } from '@/components/domain/date-picker';
import { DurationInput } from '@/components/domain/duration-input';
import InputError from '@/components/input-error';
import { MoneyField } from '@/components/projects-list/project-fields';
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
import type {
    HourBank,
    HourBankDepartmentOption,
    OveragePolicy,
} from '@/types';

/** Máximo del total de una bolsa (9999:59), como en el servidor. */
export const MAX_BANK_MINUTES = 9999 * 60 + 59;

export type HourBankFormData = {
    name: string;
    department_id: number | null;
    total_minutes: number | null;
    start_date: string | null;
    end_date: string | null;
    overage_policy: OveragePolicy;
    hourly_rate: string;
    price_amount: string;
    invoice_reference: string;
    notes: string;
};

export function emptyHourBankForm(today: string): HourBankFormData {
    return {
        name: '',
        department_id: null,
        total_minutes: null,
        start_date: today,
        end_date: null,
        overage_policy: 'inherit',
        hourly_rate: '',
        price_amount: '',
        invoice_reference: '',
        notes: '',
    };
}

export function hourBankFormFrom(bank: HourBank): HourBankFormData {
    return {
        name: bank.name,
        department_id: bank.department_id,
        total_minutes: bank.total_minutes,
        start_date: bank.start_date,
        end_date: bank.end_date,
        overage_policy: bank.overage_policy,
        hourly_rate: bank.hourly_rate ?? '',
        price_amount: bank.price_amount ?? '',
        invoice_reference: bank.invoice_reference ?? '',
        notes: bank.notes ?? '',
    };
}

/** Sin view-financials no se envían la tarifa ni el precio (el servidor los ignoraría). */
export function hourBankPayload(
    data: HourBankFormData,
    canViewFinancials: boolean,
): Partial<HourBankFormData> {
    const payload: Partial<HourBankFormData> = { ...data };

    if (!canViewFinancials) {
        delete payload.hourly_rate;
        delete payload.price_amount;
    }

    return payload;
}

const POLICIES: OveragePolicy[] = ['inherit', 'allow', 'block'];

const NO_DEPARTMENT = '__none__';

/**
 * Campos de una bolsa (SPEC §4.2 y §8): nombre, departamento opcional (restringe quién imputa),
 * total, fechas, política de exceso (explicando cuál se aplica) y, con view-financials, tarifa
 * y precio. La referencia de factura no es un dato económico.
 * - Un departamento eliminado solo aparece si es el de la bolsa (se puede conservar).
 * - `totalLocked`: bolsa cerrada, cuyo total no se cambia sin reabrirla.
 */
export function HourBankFields({
    data,
    set,
    errors,
    departments,
    overageDefault,
    canViewFinancials,
    totalLocked = false,
}: {
    data: HourBankFormData;
    set: <K extends keyof HourBankFormData>(
        key: K,
        value: HourBankFormData[K],
    ) => void;
    errors: Partial<Record<keyof HourBankFormData, string>>;
    departments: HourBankDepartmentOption[];
    overageDefault: 'allow' | 'block';
    canViewFinancials: boolean;
    totalLocked?: boolean;
}) {
    const id = useId();

    return (
        <div className="grid gap-5">
            <div className="grid gap-2">
                <Label htmlFor={`${id}-name`}>
                    {t('hour_banks.form.name')}
                </Label>
                <Input
                    id={`${id}-name`}
                    value={data.name}
                    required
                    maxLength={255}
                    autoComplete="off"
                    placeholder={t('hour_banks.form.name_placeholder')}
                    aria-invalid={errors.name ? true : undefined}
                    onChange={(event) => set('name', event.target.value)}
                />
                <InputError message={errors.name} />
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
                <div className="grid content-start gap-2">
                    <Label htmlFor={`${id}-total`}>
                        {t('hour_banks.form.total')}
                    </Label>
                    <DurationInput
                        id={`${id}-total`}
                        value={data.total_minutes}
                        onChange={(minutes) => set('total_minutes', minutes)}
                        max={MAX_BANK_MINUTES}
                        placeholder={t('hour_banks.form.total_placeholder')}
                        invalid={Boolean(errors.total_minutes)}
                        disabled={totalLocked}
                        aria-describedby={
                            totalLocked ? `${id}-total-help` : undefined
                        }
                    />
                    {totalLocked ? (
                        <p
                            id={`${id}-total-help`}
                            className="text-xs text-muted-foreground"
                        >
                            {t('hour_banks.form.total_locked')}
                        </p>
                    ) : null}
                    <InputError message={errors.total_minutes} />
                </div>

                <div className="grid min-w-0 grid-cols-1 content-start gap-2">
                    <Label htmlFor={`${id}-department`}>
                        {t('hour_banks.form.department')}
                    </Label>
                    <Select
                        value={
                            data.department_id === null
                                ? NO_DEPARTMENT
                                : String(data.department_id)
                        }
                        onValueChange={(value) =>
                            set(
                                'department_id',
                                value === NO_DEPARTMENT ? null : Number(value),
                            )
                        }
                    >
                        <SelectTrigger
                            id={`${id}-department`}
                            className="w-full min-w-0"
                            aria-describedby={`${id}-department-help`}
                            aria-invalid={
                                errors.department_id ? true : undefined
                            }
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={NO_DEPARTMENT}>
                                {t('hour_banks.form.no_department')}
                            </SelectItem>
                            {departments.map((department) => (
                                <SelectItem
                                    key={department.id}
                                    value={String(department.id)}
                                >
                                    {department.deleted
                                        ? t(
                                              'hour_banks.form.department_deleted',
                                              {
                                                  name: department.name,
                                              },
                                          )
                                        : department.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <p
                        id={`${id}-department-help`}
                        className="text-xs text-muted-foreground"
                    >
                        {t('hour_banks.form.department_help')}
                    </p>
                    <InputError message={errors.department_id} />
                </div>
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
                <div className="grid content-start gap-2">
                    <Label htmlFor={`${id}-start`}>
                        {t('hour_banks.form.start_date')}
                    </Label>
                    <DatePicker
                        id={`${id}-start`}
                        value={data.start_date}
                        onChange={(value) => set('start_date', value)}
                        clearable={false}
                        invalid={Boolean(errors.start_date)}
                    />
                    <InputError message={errors.start_date} />
                </div>
                <div className="grid content-start gap-2">
                    <Label htmlFor={`${id}-end`}>
                        {t('hour_banks.form.end_date')}
                    </Label>
                    <DatePicker
                        id={`${id}-end`}
                        value={data.end_date}
                        onChange={(value) => set('end_date', value)}
                        placeholder={t('hour_banks.form.no_end_date')}
                        invalid={Boolean(errors.end_date)}
                    />
                    <InputError message={errors.end_date} />
                </div>
            </div>

            <fieldset className="grid gap-2">
                <legend className="mb-2 text-sm font-medium">
                    {t('hour_banks.form.policy')}
                </legend>
                <RadioGroup
                    value={data.overage_policy}
                    onValueChange={(value) =>
                        set('overage_policy', value as OveragePolicy)
                    }
                    className="grid gap-2"
                >
                    {POLICIES.map((policy) => (
                        <label
                            key={policy}
                            className="flex items-start gap-2 text-sm"
                        >
                            <RadioGroupItem value={policy} className="mt-0.5" />
                            <span className="grid gap-0.5">
                                <span>{t(`hour_bank.policy.${policy}`)}</span>
                                <span className="text-xs text-muted-foreground">
                                    {policy === 'inherit'
                                        ? t(
                                              `hour_banks.policy_help.inherit_${overageDefault}`,
                                          )
                                        : t(`hour_banks.policy_help.${policy}`)}
                                </span>
                            </span>
                        </label>
                    ))}
                </RadioGroup>
                <InputError message={errors.overage_policy} />
            </fieldset>

            {canViewFinancials ? (
                <div className="grid gap-4 sm:grid-cols-2">
                    <MoneyField
                        id={`${id}-rate`}
                        label={t('hour_banks.form.hourly_rate')}
                        help={t('hour_banks.form.hourly_rate_help')}
                        value={data.hourly_rate}
                        error={errors.hourly_rate}
                        onChange={(value) => set('hourly_rate', value)}
                    />
                    <MoneyField
                        id={`${id}-price`}
                        label={t('hour_banks.form.price')}
                        help={t('hour_banks.form.price_help')}
                        value={data.price_amount}
                        error={errors.price_amount}
                        onChange={(value) => set('price_amount', value)}
                    />
                </div>
            ) : null}

            <div className="grid gap-2">
                <Label htmlFor={`${id}-invoice`}>
                    {t('hour_banks.form.invoice_reference')}
                </Label>
                <Input
                    id={`${id}-invoice`}
                    value={data.invoice_reference}
                    maxLength={255}
                    autoComplete="off"
                    placeholder={t('hour_banks.form.optional')}
                    aria-invalid={errors.invoice_reference ? true : undefined}
                    onChange={(event) =>
                        set('invoice_reference', event.target.value)
                    }
                />
                <InputError message={errors.invoice_reference} />
            </div>

            <div className="grid gap-2">
                <Label htmlFor={`${id}-notes`}>
                    {t('hour_banks.form.notes')}
                </Label>
                <Textarea
                    id={`${id}-notes`}
                    value={data.notes}
                    rows={3}
                    maxLength={5000}
                    placeholder={t('hour_banks.form.optional')}
                    aria-invalid={errors.notes ? true : undefined}
                    onChange={(event) => set('notes', event.target.value)}
                />
                <InputError message={errors.notes} />
            </div>
        </div>
    );
}
