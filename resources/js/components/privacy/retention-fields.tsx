import InputError from '@/components/input-error';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { t } from '@/lib/i18n';
import { retentionLabel } from '@/components/privacy/retention';
import type { RetentionField } from '@/types/privacy';

/** Valor de un plazo en el formulario: meses como texto y «sin límite». */
export type RetentionValue = { months: string; unlimited: boolean };

/**
 * Plazos de conservación de /admin/privacidad (D-075): un campo de meses por tipo de dato, con su
 * mínimo y su máximo, y «Sin límite» solo donde se admite (auditoría y chat). Con «Sin límite»
 * marcado, el número se desactiva y se envía null.
 */
export function RetentionFields({
    fields,
    values,
    errors,
    onChange,
}: {
    fields: RetentionField[];
    values: Record<string, RetentionValue>;
    errors: Partial<Record<string, string>>;
    onChange: (key: string, value: RetentionValue) => void;
}) {
    return (
        <div className="grid gap-5 sm:grid-cols-2">
            {fields.map((field) => {
                const value = values[field.key] ?? {
                    months: '',
                    unlimited: false,
                };
                const inputId = `retention-${field.key}`;
                const helpId = `${inputId}-help`;
                const errorId = `${inputId}-error`;
                const error = errors[field.key];
                const label = retentionLabel(field.type);

                return (
                    <div
                        key={field.key}
                        role="group"
                        aria-labelledby={`${inputId}-label`}
                        className="grid content-start gap-2"
                        data-test={`retention-${field.type}`}
                    >
                        <Label id={`${inputId}-label`} htmlFor={inputId}>
                            {label}
                        </Label>
                        <div className="flex items-center gap-2">
                            <Input
                                id={inputId}
                                type="number"
                                inputMode="numeric"
                                min={field.min}
                                max={field.max}
                                step={1}
                                className="tabular w-28"
                                value={value.unlimited ? '' : value.months}
                                disabled={value.unlimited}
                                required={!field.unlimited_allowed}
                                onChange={(event) =>
                                    onChange(field.key, {
                                        ...value,
                                        months: event.target.value,
                                    })
                                }
                                aria-invalid={error ? true : undefined}
                                aria-describedby={
                                    error ? `${helpId} ${errorId}` : helpId
                                }
                            />
                            <span
                                aria-hidden="true"
                                className="text-sm text-muted-foreground"
                            >
                                {t('privacy.admin.months_suffix')}
                            </span>
                        </div>
                        {field.unlimited_allowed ? (
                            <div className="flex items-center gap-2">
                                <Checkbox
                                    id={`${inputId}-unlimited`}
                                    checked={value.unlimited}
                                    onCheckedChange={(checked) =>
                                        onChange(field.key, {
                                            ...value,
                                            unlimited: checked === true,
                                        })
                                    }
                                />
                                <Label
                                    htmlFor={`${inputId}-unlimited`}
                                    className="font-normal"
                                >
                                    {t('privacy.admin.unlimited')}
                                </Label>
                            </div>
                        ) : null}
                        <p
                            id={helpId}
                            className="text-sm text-muted-foreground"
                        >
                            {t('privacy.admin.months_help', {
                                min: field.min,
                                max: field.max,
                            })}
                            {field.type === 'chat_messages'
                                ? ` ${t('privacy.admin.chat_messages_help')}`
                                : null}
                            {field.type === 'ai_usage'
                                ? ` ${t('privacy.admin.ai_usage_help')}`
                                : null}
                            {field.type === 'dictations'
                                ? ` ${t('privacy.admin.dictations_help')}`
                                : null}
                            {field.type === 'day_plans'
                                ? ` ${t('privacy.admin.day_plans_help')}`
                                : null}
                            {field.type === 'people_register'
                                ? ` ${t('privacy.admin.people_register_help')}`
                                : null}
                        </p>
                        <InputError id={errorId} message={error} />
                    </div>
                );
            })}
        </div>
    );
}

/** Valores del formulario a partir de los ajustes vigentes (null = sin límite). */
export function retentionValues(
    fields: RetentionField[],
    settings: Record<string, number | null>,
): Record<string, RetentionValue> {
    const values: Record<string, RetentionValue> = {};

    for (const field of fields) {
        const months = settings[field.key] ?? null;
        values[field.key] = {
            months: months === null ? '' : String(months),
            unlimited: months === null && field.unlimited_allowed,
        };
    }

    return values;
}

/** Lo que se envía: meses como número, o null con «Sin límite». */
export function retentionPayload(
    values: Record<string, RetentionValue>,
): Record<string, number | null> {
    const payload: Record<string, number | null> = {};

    for (const [key, value] of Object.entries(values)) {
        payload[key] =
            value.unlimited || value.months.trim() === ''
                ? null
                : Number(value.months);
    }

    return payload;
}
