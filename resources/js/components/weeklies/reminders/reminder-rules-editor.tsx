import { Plus, Trash2 } from 'lucide-react';
import { useId } from 'react';
import { NativeSelect } from '@/components/admin/native-select';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { t } from '@/lib/i18n';
import type {
    WeeklyReminderChannel,
    WeeklyReminderRuleInput,
} from '@/types/weeklies';

/** Días ISO (1 = lunes … 7 = domingo), como las reglas del servidor. */
export const WEEK_DAYS = [1, 2, 3, 4, 5, 6, 7] as const;

export const REMINDER_CHANNELS: WeeklyReminderChannel[] = [
    'app',
    'email',
    'push',
];

/** Máximo de reglas (UpdateWeeklyRemindersRequest::MAX_RULES). */
export const MAX_RULES = 20;

/** Regla nueva: el jueves a las 10:00 por email, como «Añadir» en WeeklySync. */
export function newRule(): WeeklyReminderRuleInput {
    return {
        id: null,
        channel: 'email',
        day_of_week: 4,
        time: '10:00',
        enabled: true,
    };
}

export function dayLabel(day: number): string {
    return t(`weekly_reminders.days.${day}` as 'weekly_reminders.days.1');
}

export function channelLabel(channel: WeeklyReminderChannel): string {
    return t(`weekly_reminders.channels.${channel}`);
}

/**
 * Resumen de las reglas activas, ordenadas por día y hora («2 recordatorios activos: jueves a las
 * 10:00 (Email), viernes a las 16:00 (En la app)»), como la tarjeta «Resumen» de WeeklySync.
 */
export function rulesSummary(rules: WeeklyReminderRuleInput[]): string {
    const active = rules
        .filter((rule) => rule.enabled)
        .slice()
        .sort(
            (a, b) =>
                a.day_of_week - b.day_of_week || a.time.localeCompare(b.time),
        );

    if (active.length === 0) {
        return t('weekly_reminders.rules.summary_none');
    }

    const list = active
        .map((rule) =>
            t('weekly_reminders.rules.summary_item', {
                day: dayLabel(rule.day_of_week).toLowerCase(),
                time: rule.time,
                channel: channelLabel(rule.channel),
            }),
        )
        .join(', ');

    return active.length === 1
        ? t('weekly_reminders.rules.summary_one', { list })
        : t('weekly_reminders.rules.summary_many', {
              count: active.length,
              list,
          });
}

/**
 * Las reglas de recordatorio (F-101 y F-102): una fila por regla con «Activo», día, hora de Madrid y
 * canal, y quitar. Las reglas de correo y de navegador de WeeklySync son aquí una sola lista con el
 * canal de cada una (también «En la app»).
 */
export function ReminderRulesEditor({
    rules,
    errors,
    pushAvailable,
    onChange,
}: {
    rules: WeeklyReminderRuleInput[];
    errors: Record<string, string | undefined>;
    pushAvailable: boolean;
    onChange: (rules: WeeklyReminderRuleInput[]) => void;
}) {
    const id = useId();

    const update = (index: number, patch: Partial<WeeklyReminderRuleInput>) =>
        onChange(
            rules.map((rule, position) =>
                position === index ? { ...rule, ...patch } : rule,
            ),
        );

    const hasPush = rules.some((rule) => rule.channel === 'push');

    return (
        <div className="grid gap-3">
            {rules.length === 0 ? (
                <p
                    className="border border-dashed p-4 text-sm text-muted-foreground"
                    data-test="reminder-rules-empty"
                >
                    {t('weekly_reminders.rules.empty')}
                </p>
            ) : (
                <ul className="grid gap-3" data-test="reminder-rules">
                    {rules.map((rule, index) => {
                        const number = index + 1;
                        const prefix = `${id}-rule-${index}`;
                        const error =
                            errors[`rules.${index}.time`] ??
                            errors[`rules.${index}.day_of_week`] ??
                            errors[`rules.${index}.channel`];

                        return (
                            <li
                                key={rule.id ?? `new-${index}`}
                                className="grid gap-3 border bg-card p-3 sm:grid-cols-[auto_1fr_1fr_1fr_auto] sm:items-end"
                                data-test="reminder-rule"
                                aria-label={t('weekly_reminders.rules.number', {
                                    number,
                                })}
                            >
                                <div className="flex items-center gap-2 sm:h-9">
                                    <Switch
                                        id={`${prefix}-enabled`}
                                        checked={rule.enabled}
                                        onCheckedChange={(checked) =>
                                            update(index, { enabled: checked })
                                        }
                                        aria-label={t(
                                            'weekly_reminders.rules.enabled_label',
                                            { number },
                                        )}
                                    />
                                    <span
                                        aria-hidden="true"
                                        className="text-sm text-muted-foreground sm:sr-only"
                                    >
                                        {t('weekly_reminders.rules.enabled')}
                                    </span>
                                </div>
                                <div className="grid gap-1">
                                    <Label htmlFor={`${prefix}-day`}>
                                        {t('weekly_reminders.rules.day')}
                                    </Label>
                                    <NativeSelect
                                        id={`${prefix}-day`}
                                        value={String(rule.day_of_week)}
                                        onChange={(event) =>
                                            update(index, {
                                                day_of_week: Number(
                                                    event.target.value,
                                                ),
                                            })
                                        }
                                    >
                                        {WEEK_DAYS.map((day) => (
                                            <option key={day} value={day}>
                                                {dayLabel(day)}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                </div>
                                <div className="grid gap-1">
                                    <Label htmlFor={`${prefix}-time`}>
                                        {t('weekly_reminders.rules.time')}
                                    </Label>
                                    <Input
                                        id={`${prefix}-time`}
                                        type="time"
                                        step={60}
                                        value={rule.time}
                                        required
                                        onChange={(event) =>
                                            update(index, {
                                                time: event.target.value.slice(
                                                    0,
                                                    5,
                                                ),
                                            })
                                        }
                                        aria-invalid={error ? true : undefined}
                                        aria-describedby={
                                            error
                                                ? `${prefix}-error`
                                                : undefined
                                        }
                                    />
                                </div>
                                <div className="grid gap-1">
                                    <Label htmlFor={`${prefix}-channel`}>
                                        {t('weekly_reminders.rules.channel')}
                                    </Label>
                                    <NativeSelect
                                        id={`${prefix}-channel`}
                                        value={rule.channel}
                                        onChange={(event) =>
                                            update(index, {
                                                channel: event.target
                                                    .value as WeeklyReminderChannel,
                                            })
                                        }
                                    >
                                        {REMINDER_CHANNELS.map((channel) => (
                                            <option
                                                key={channel}
                                                value={channel}
                                            >
                                                {channelLabel(channel)}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                </div>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    onClick={() =>
                                        onChange(
                                            rules.filter(
                                                (_, position) =>
                                                    position !== index,
                                            ),
                                        )
                                    }
                                    aria-label={t(
                                        'weekly_reminders.rules.remove',
                                        { number },
                                    )}
                                    data-test="reminder-rule-remove"
                                >
                                    <Trash2 aria-hidden="true" />
                                </Button>
                                {error ? (
                                    <InputError
                                        id={`${prefix}-error`}
                                        message={error}
                                        className="sm:col-span-5"
                                    />
                                ) : null}
                            </li>
                        );
                    })}
                </ul>
            )}
            <InputError message={errors.rules} />
            {hasPush && !pushAvailable ? (
                <p className="bg-warning-soft p-3 text-sm" role="note">
                    {t('weekly_reminders.rules.push_unavailable')}
                </p>
            ) : null}
            <div className="flex flex-wrap items-center justify-between gap-3">
                <p
                    className="text-sm text-muted-foreground"
                    aria-live="polite"
                    data-test="reminder-rules-summary"
                >
                    {rulesSummary(rules)}
                </p>
                <Button
                    type="button"
                    variant="secondary"
                    size="sm"
                    disabled={rules.length >= MAX_RULES}
                    onClick={() => onChange([...rules, newRule()])}
                    data-test="reminder-rule-add"
                >
                    <Plus aria-hidden="true" />
                    {t('weekly_reminders.rules.add')}
                </Button>
            </div>
        </div>
    );
}
