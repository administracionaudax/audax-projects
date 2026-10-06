import { useId } from 'react';
import { describedBy, Field } from '@/components/admin/field';
import { NativeSelect } from '@/components/admin/native-select';
import { DatePicker } from '@/components/domain/date-picker';
import {
    emptyHourBankForm,
    HourBankFields,
    hourBankPayload,
} from '@/components/hour-banks/hour-bank-fields';
import type { HourBankFormData } from '@/components/hour-banks/hour-bank-fields';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { t } from '@/lib/i18n';
import type { BillingType, HourBankDepartmentOption } from '@/types';
import type { TemplateOption } from '@/types/templates';
import { templateStatsText } from './template-badges';

/** Campos «Desde plantilla» del alta de proyecto (StoreProjectRequest + CreatesFromTemplate). */
export type TemplateStartData = {
    template_id: number | null;
    /** null: el inicio del proyecto o, si no tiene, hoy. */
    template_start: string | null;
    hour_bank: HourBankFormData;
};

export function emptyTemplateStart(today: string): TemplateStartData {
    return {
        template_id: null,
        template_start: null,
        hour_bank: emptyHourBankForm(today),
    };
}

/**
 * Lo que se envía de estos campos: nada si es «Desde cero»; la plantilla y su inicio; y la primera
 * bolsa solo si el proyecto es de bolsas.
 */
export function templateStartPayload(
    data: TemplateStartData,
    billingType: BillingType,
    canViewFinancials: boolean,
): Partial<{
    template_id: number;
    template_start: string | null;
    hour_bank: Partial<HourBankFormData>;
}> {
    if (data.template_id === null) {
        return {};
    }

    return {
        template_id: data.template_id,
        template_start: data.template_start,
        ...(billingType === 'hour_bank'
            ? { hour_bank: hourBankPayload(data.hour_bank, canViewFinancials) }
            : {}),
    };
}

/** Errores de la primera bolsa (`hour_bank.name` → `name`). */
function bankErrors(
    errors: Record<string, string | undefined>,
): Partial<Record<keyof HourBankFormData, string>> {
    const result: Record<string, string | undefined> = {};

    for (const [key, message] of Object.entries(errors)) {
        if (key.startsWith('hour_bank.')) {
            result[key.slice('hour_bank.'.length)] = message;
        }
    }

    return result as Partial<Record<keyof HourBankFormData, string>>;
}

/**
 * «Crear proyecto desde cero o desde plantilla» (SPEC §6, D-058): elige una plantilla activa, el
 * día desde el que se cuentan sus fechas (por defecto, el inicio del proyecto o hoy) y, si el
 * proyecto es de bolsas, los datos de su primera bolsa, a la que irán todas las tareas.
 */
export function TemplateStartFields({
    data,
    onChange,
    errors,
    templates,
    billingType,
    projectStart,
    today,
    departments,
    overageDefault,
    canViewFinancials,
}: {
    data: TemplateStartData;
    onChange: (data: TemplateStartData) => void;
    errors: Record<string, string | undefined>;
    templates: TemplateOption[];
    billingType: BillingType;
    projectStart: string | null;
    today: string;
    departments: HourBankDepartmentOption[];
    overageDefault: 'allow' | 'block';
    canViewFinancials: boolean;
}) {
    const id = useId();
    const fromTemplate = data.template_id !== null;
    const selected = templates.find(
        (template) => template.id === data.template_id,
    );
    const start = data.template_start ?? projectStart ?? today;

    return (
        <fieldset className="grid gap-5" data-test="template-start">
            <legend className="mb-3 text-base font-medium">
                {t('templates.create.legend')}
            </legend>

            <RadioGroup
                value={fromTemplate ? 'template' : 'blank'}
                onValueChange={(value) =>
                    onChange({
                        ...data,
                        template_id:
                            value === 'template'
                                ? (templates[0]?.id ?? null)
                                : null,
                    })
                }
                aria-label={t('templates.create.legend')}
                aria-describedby={
                    templates.length === 0 ? `${id}-none` : undefined
                }
                className="flex flex-wrap gap-4"
            >
                <div className="flex items-center gap-2">
                    <RadioGroupItem id={`${id}-blank`} value="blank" />
                    <Label htmlFor={`${id}-blank`} className="font-normal">
                        {t('templates.create.blank')}
                    </Label>
                </div>
                <div className="flex items-center gap-2">
                    <RadioGroupItem
                        id={`${id}-template`}
                        value="template"
                        disabled={templates.length === 0}
                    />
                    <Label htmlFor={`${id}-template`} className="font-normal">
                        {t('templates.create.from_template')}
                    </Label>
                </div>
            </RadioGroup>
            {templates.length === 0 ? (
                <p id={`${id}-none`} className="text-sm text-muted-foreground">
                    {t('templates.create.none')}
                </p>
            ) : null}

            {fromTemplate ? (
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field
                        id={`${id}-choose`}
                        label={t('templates.fields.template')}
                        help={
                            selected
                                ? templateStatsText(selected.stats)
                                : undefined
                        }
                        error={errors.template_id}
                        className="sm:col-span-2"
                    >
                        <NativeSelect
                            id={`${id}-choose`}
                            value={String(data.template_id)}
                            aria-invalid={errors.template_id ? true : undefined}
                            aria-describedby={describedBy(`${id}-choose`, {
                                help: selected !== undefined,
                                error: errors.template_id,
                            })}
                            onChange={(event) =>
                                onChange({
                                    ...data,
                                    template_id: Number(event.target.value),
                                })
                            }
                        >
                            {templates.map((template) => (
                                <option
                                    key={template.id}
                                    value={String(template.id)}
                                >
                                    {template.name}
                                </option>
                            ))}
                        </NativeSelect>
                    </Field>
                    {selected?.description ? (
                        <p className="text-sm text-muted-foreground sm:col-span-2">
                            {selected.description}
                        </p>
                    ) : null}
                    <Field
                        id={`${id}-start`}
                        label={t('templates.fields.start_date')}
                        help={t('templates.create.start_help')}
                        error={errors.template_start}
                    >
                        <DatePicker
                            id={`${id}-start`}
                            value={start}
                            clearable={false}
                            invalid={errors.template_start ? true : undefined}
                            onChange={(value) =>
                                onChange({ ...data, template_start: value })
                            }
                        />
                    </Field>

                    {billingType === 'hour_bank' ? (
                        <section
                            aria-labelledby={`${id}-bank`}
                            aria-describedby={`${id}-bank-help`}
                            className="grid gap-4 rounded-md border p-4 sm:col-span-2"
                        >
                            <div className="grid content-start gap-1">
                                <p id={`${id}-bank`} className="font-medium">
                                    {t('templates.create.bank_title')}
                                </p>
                                <p
                                    id={`${id}-bank-help`}
                                    className="text-sm text-muted-foreground"
                                >
                                    {t('templates.create.bank_description')}
                                </p>
                            </div>
                            {errors.hour_bank ? (
                                <p role="alert" className="text-sm text-danger">
                                    {errors.hour_bank}
                                </p>
                            ) : null}
                            <HourBankFields
                                data={data.hour_bank}
                                set={(key, value) =>
                                    onChange({
                                        ...data,
                                        hour_bank: {
                                            ...data.hour_bank,
                                            [key]: value,
                                        },
                                    })
                                }
                                errors={bankErrors(errors)}
                                departments={departments}
                                overageDefault={overageDefault}
                                canViewFinancials={canViewFinancials}
                            />
                        </section>
                    ) : null}
                </div>
            ) : null}
        </fieldset>
    );
}
