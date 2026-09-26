import { useId } from 'react';
import { DayMinutesInput } from '@/components/admin/day-minutes-input';
import InputError from '@/components/input-error';
import { Label } from '@/components/ui/label';
import { formatMinutes } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';

/** Días de la semana, lunes primero (la semana empieza en lunes, SPEC §3). */
export const WEEK_DAYS: TranslationKey[] = [
    'admin.week.mon',
    'admin.week.tue',
    'admin.week.wed',
    'admin.week.thu',
    'admin.week.fri',
    'admin.week.sat',
    'admin.week.sun',
];

/** Abreviaturas de los días, lunes primero. */
export const WEEK_DAYS_SHORT: TranslationKey[] = [
    'admin.week.mon_short',
    'admin.week.tue_short',
    'admin.week.wed_short',
    'admin.week.thu_short',
    'admin.week.fri_short',
    'admin.week.sat_short',
    'admin.week.sun_short',
];

/** Total semanal (los días vacíos o no válidos cuentan 0). */
export function weekTotal(week: (number | null)[]): number {
    return week.reduce<number>((sum, minutes) => sum + (minutes ?? 0), 0);
}

/**
 * Jornada de una semana: una duración por día (1:30, 7,5, 8h… y 0 para los días sin jornada) con
 * su vista previa y el total.
 * `errors` admite los de Laravel por día («week.0», «default_work_minutes.3»…) con `errorPrefix`.
 */
export function WeekMinutesInput({
    value,
    onChange,
    legend,
    errorPrefix,
    errors = {},
    disabled,
}: {
    value: (number | null)[];
    onChange: (week: (number | null)[]) => void;
    legend: string;
    errorPrefix: string;
    errors?: Record<string, string | undefined>;
    disabled?: boolean;
}) {
    const id = useId();
    const total = weekTotal(value);
    const invalid = value.some((minutes) => minutes === null);

    return (
        <fieldset className="grid gap-3">
            <legend className="mb-1 text-sm font-medium">{legend}</legend>
            <div className="grid grid-cols-2 gap-x-3 gap-y-1 sm:grid-cols-4 lg:grid-cols-7">
                {WEEK_DAYS.map((day, index) => {
                    const inputId = `${id}-${index}`;
                    const error = errors[`${errorPrefix}.${index}`];

                    return (
                        <div key={day} className="grid content-start gap-1.5">
                            <Label htmlFor={inputId}>{t(day)}</Label>
                            <DayMinutesInput
                                id={inputId}
                                value={value[index] ?? null}
                                disabled={disabled}
                                invalid={Boolean(error)}
                                aria-describedby={
                                    error ? `${inputId}-error` : undefined
                                }
                                onChange={(minutes) => {
                                    const next = [...value];
                                    next[index] = minutes;
                                    onChange(next);
                                }}
                            />
                            <InputError
                                id={`${inputId}-error`}
                                message={error}
                            />
                        </div>
                    );
                })}
            </div>
            <p className="text-sm text-muted-foreground" aria-live="polite">
                {invalid
                    ? t('admin.week.invalid')
                    : t('admin.week.total', { total: formatMinutes(total) })}
            </p>
            <InputError message={errors[errorPrefix]} />
        </fieldset>
    );
}
