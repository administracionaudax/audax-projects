import { Head, useForm } from '@inertiajs/react';
import { Plus, TriangleAlert, X } from 'lucide-react';
import type { ReactNode } from 'react';
import { useId } from 'react';
import { AdminPage } from '@/components/admin/admin-page';
import { describedBy, Field } from '@/components/admin/field';
import { NativeSelect } from '@/components/admin/native-select';
import { WeekMinutesInput } from '@/components/admin/week-minutes-input';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { t } from '@/lib/i18n';
import { index as adminIndex } from '@/routes/admin';
import { edit, update } from '@/routes/admin/settings';
import type { AdminSettingsProps } from '@/types';

/** Máximo de umbrales de alerta de las bolsas. */
const MAX_THRESHOLDS = 5;

/** Duraciones máximas de los audios del chat que se ofrecen (segundos; Fase 6). */
const AUDIO_DURATIONS = [30, 60, 120, 180, 300, 600];

function audioDurationLabel(seconds: number): string {
    if (seconds < 60) {
        return t('chat_media.settings.seconds_option', { seconds });
    }

    if (seconds % 60 !== 0) {
        return t('chat_media.settings.clock_option', {
            clock: `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`,
        });
    }

    return seconds === 60
        ? t('chat_media.settings.minute_option')
        : t('chat_media.settings.minutes_option', { minutes: seconds / 60 });
}

type SettingsForm = {
    company_name: string;
    require_2fa: boolean;
    timer_rounding_minutes: string;
    timer_warning_hours: string;
    hour_bank_alert_thresholds: string[];
    allow_hour_bank_overage: boolean;
    require_timesheet_approval: boolean;
    allow_future_time_entries: boolean;
    time_entry_description_required: boolean;
    max_attachment_mb: string;
    default_work_minutes: (number | null)[];
    max_audio_seconds: string;
};

function Section({
    title,
    description,
    children,
}: {
    title: string;
    description?: string;
    children: ReactNode;
}) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>
                    <h2 className="text-base font-medium">{title}</h2>
                </CardTitle>
                {description ? (
                    <CardDescription>{description}</CardDescription>
                ) : null}
            </CardHeader>
            <CardContent className="grid gap-5">{children}</CardContent>
        </Card>
    );
}

function Toggle({
    id,
    label,
    help,
    checked,
    onChange,
    error,
}: {
    id: string;
    label: string;
    help: string;
    checked: boolean;
    onChange: (checked: boolean) => void;
    error?: string;
}) {
    return (
        <div className="flex items-start gap-3">
            <Switch
                id={id}
                checked={checked}
                onCheckedChange={onChange}
                aria-describedby={`${id}-help`}
                className="mt-0.5"
            />
            <div className="grid gap-1">
                <Label htmlFor={id}>{label}</Label>
                <p id={`${id}-help`} className="text-sm text-muted-foreground">
                    {help}
                </p>
                <InputError message={error} />
            </div>
        </div>
    );
}

/** Ajustes generales (SPEC §7, §8 y §14): empresa, seguridad, temporizador, bolsas, horas y adjuntos. */
export default function AdminSettings({
    settings,
    roundings,
    serverUploadLimitMb,
}: AdminSettingsProps) {
    const id = useId();
    const form = useForm<SettingsForm>({
        company_name: settings.company_name,
        require_2fa: settings.require_2fa,
        timer_rounding_minutes: String(settings.timer_rounding_minutes),
        timer_warning_hours: String(settings.timer_warning_hours),
        hour_bank_alert_thresholds:
            settings.hour_bank_alert_thresholds.map(String),
        allow_hour_bank_overage: settings.allow_hour_bank_overage,
        require_timesheet_approval: settings.require_timesheet_approval,
        allow_future_time_entries: settings.allow_future_time_entries,
        time_entry_description_required:
            settings.time_entry_description_required,
        max_attachment_mb: String(settings.max_attachment_mb),
        default_work_minutes: settings.default_work_minutes,
        max_audio_seconds: String(settings.max_audio_seconds),
    });
    const audioDurations = AUDIO_DURATIONS.includes(settings.max_audio_seconds)
        ? AUDIO_DURATIONS
        : [...AUDIO_DURATIONS, settings.max_audio_seconds].sort(
              (a, b) => a - b,
          );
    const errors = form.errors as Record<string, string | undefined>;
    const thresholds = form.data.hour_bank_alert_thresholds;
    const invalidWeek = form.data.default_work_minutes.some(
        (minutes) => minutes === null,
    );
    const attachmentMb = Number(form.data.max_attachment_mb);
    const overServerLimit =
        serverUploadLimitMb !== null &&
        Number.isFinite(attachmentMb) &&
        attachmentMb > serverUploadLimitMb;

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        if (invalidWeek) {
            return;
        }

        form.transform((data) => ({
            ...data,
            timer_rounding_minutes: Number(data.timer_rounding_minutes),
            timer_warning_hours: Number(data.timer_warning_hours),
            hour_bank_alert_thresholds: data.hour_bank_alert_thresholds.map(
                (value) => Number(value),
            ),
            max_attachment_mb: Number(data.max_attachment_mb),
            max_audio_seconds: Number(data.max_audio_seconds),
        }));
        form.put(update.url(), { preserveScroll: true });
    };

    const setThreshold = (index: number, value: string) => {
        form.setData(
            'hour_bank_alert_thresholds',
            thresholds.map((current, position) =>
                position === index ? value : current,
            ),
        );
    };

    return (
        <>
            <Head title={t('admin.settings.title')} />

            <AdminPage
                section="settings"
                title={t('admin.settings.heading')}
                description={t('admin.settings.description')}
            >
                <form onSubmit={submit} className="grid gap-6" noValidate>
                    <Section title={t('admin.settings.company.title')}>
                        <Field
                            id={`${id}-company`}
                            label={t('admin.settings.company.name')}
                            help={t('admin.settings.company.name_help')}
                            error={errors.company_name}
                            className="max-w-md"
                        >
                            <Input
                                id={`${id}-company`}
                                value={form.data.company_name}
                                onChange={(event) =>
                                    form.setData(
                                        'company_name',
                                        event.target.value,
                                    )
                                }
                                required
                                maxLength={120}
                                aria-invalid={
                                    errors.company_name ? true : undefined
                                }
                                aria-describedby={describedBy(`${id}-company`, {
                                    help: true,
                                    error: errors.company_name,
                                })}
                            />
                        </Field>
                    </Section>

                    <Section title={t('admin.settings.security.title')}>
                        <Toggle
                            id={`${id}-2fa`}
                            label={t('admin.settings.security.require_2fa')}
                            help={t('admin.settings.security.require_2fa_help')}
                            checked={form.data.require_2fa}
                            onChange={(checked) =>
                                form.setData('require_2fa', checked)
                            }
                            error={errors.require_2fa}
                        />
                    </Section>

                    <Section title={t('admin.settings.timer.title')}>
                        <div className="grid gap-5 sm:grid-cols-2">
                            <Field
                                id={`${id}-rounding`}
                                label={t('admin.settings.timer.rounding')}
                                help={t('admin.settings.timer.rounding_help')}
                                error={errors.timer_rounding_minutes}
                            >
                                <NativeSelect
                                    id={`${id}-rounding`}
                                    value={form.data.timer_rounding_minutes}
                                    onChange={(event) =>
                                        form.setData(
                                            'timer_rounding_minutes',
                                            event.target.value,
                                        )
                                    }
                                    aria-describedby={describedBy(
                                        `${id}-rounding`,
                                        {
                                            help: true,
                                            error: errors.timer_rounding_minutes,
                                        },
                                    )}
                                >
                                    {roundings.map((minutes) => (
                                        <option
                                            key={minutes}
                                            value={String(minutes)}
                                        >
                                            {minutes === 1
                                                ? t(
                                                      'admin.settings.timer.rounding_one',
                                                  )
                                                : t(
                                                      'admin.settings.timer.rounding_option',
                                                      { minutes },
                                                  )}
                                        </option>
                                    ))}
                                </NativeSelect>
                            </Field>
                            <Field
                                id={`${id}-warning`}
                                label={t('admin.settings.timer.warning')}
                                help={t('admin.settings.timer.warning_help')}
                                error={errors.timer_warning_hours}
                            >
                                <Input
                                    id={`${id}-warning`}
                                    type="number"
                                    inputMode="numeric"
                                    min={1}
                                    max={24}
                                    step={1}
                                    className="tabular w-32"
                                    value={form.data.timer_warning_hours}
                                    onChange={(event) =>
                                        form.setData(
                                            'timer_warning_hours',
                                            event.target.value,
                                        )
                                    }
                                    aria-invalid={
                                        errors.timer_warning_hours
                                            ? true
                                            : undefined
                                    }
                                    aria-describedby={describedBy(
                                        `${id}-warning`,
                                        {
                                            help: true,
                                            error: errors.timer_warning_hours,
                                        },
                                    )}
                                />
                            </Field>
                        </div>
                    </Section>

                    <Section
                        title={t('admin.settings.banks.title')}
                        description={t('admin.settings.banks.description')}
                    >
                        <fieldset
                            className="grid gap-3"
                            aria-describedby={`${id}-thresholds-help`}
                        >
                            <legend className="mb-1 text-sm font-medium">
                                {t('admin.settings.banks.thresholds')}
                            </legend>
                            <p
                                id={`${id}-thresholds-help`}
                                className="text-sm text-muted-foreground"
                            >
                                {t('admin.settings.banks.thresholds_help')}
                            </p>
                            <ul className="flex flex-wrap items-start gap-3">
                                {thresholds.map((value, index) => {
                                    const inputId = `${id}-threshold-${index}`;
                                    const error =
                                        errors[
                                            `hour_bank_alert_thresholds.${index}`
                                        ];

                                    return (
                                        <li key={index} className="grid gap-1">
                                            <Label
                                                htmlFor={inputId}
                                                className="sr-only"
                                            >
                                                {t(
                                                    'admin.settings.banks.threshold_label',
                                                    { position: index + 1 },
                                                )}
                                            </Label>
                                            <div className="flex items-center gap-1">
                                                <Input
                                                    id={inputId}
                                                    type="number"
                                                    inputMode="numeric"
                                                    min={1}
                                                    max={200}
                                                    step={1}
                                                    className="tabular w-20"
                                                    value={value}
                                                    onChange={(event) =>
                                                        setThreshold(
                                                            index,
                                                            event.target.value,
                                                        )
                                                    }
                                                    aria-invalid={
                                                        error ? true : undefined
                                                    }
                                                    aria-describedby={
                                                        error
                                                            ? `${inputId}-error`
                                                            : undefined
                                                    }
                                                />
                                                <span
                                                    aria-hidden="true"
                                                    className="text-sm text-muted-foreground"
                                                >
                                                    %
                                                </span>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    className="size-8"
                                                    disabled={
                                                        thresholds.length <= 1
                                                    }
                                                    aria-label={t(
                                                        'admin.settings.banks.remove_threshold',
                                                        {
                                                            value:
                                                                value === ''
                                                                    ? index + 1
                                                                    : `${value} %`,
                                                        },
                                                    )}
                                                    onClick={() =>
                                                        form.setData(
                                                            'hour_bank_alert_thresholds',
                                                            thresholds.filter(
                                                                (_, position) =>
                                                                    position !==
                                                                    index,
                                                            ),
                                                        )
                                                    }
                                                >
                                                    <X aria-hidden="true" />
                                                </Button>
                                            </div>
                                            <InputError
                                                id={`${inputId}-error`}
                                                message={error}
                                            />
                                        </li>
                                    );
                                })}
                            </ul>
                            <div>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    disabled={
                                        thresholds.length >= MAX_THRESHOLDS
                                    }
                                    onClick={() =>
                                        form.setData(
                                            'hour_bank_alert_thresholds',
                                            [...thresholds, ''],
                                        )
                                    }
                                >
                                    <Plus aria-hidden="true" />
                                    {t('admin.settings.banks.add_threshold')}
                                </Button>
                            </div>
                            <InputError
                                message={errors.hour_bank_alert_thresholds}
                            />
                        </fieldset>
                        <Toggle
                            id={`${id}-overage`}
                            label={t('admin.settings.banks.allow_overage')}
                            help={t('admin.settings.banks.allow_overage_help')}
                            checked={form.data.allow_hour_bank_overage}
                            onChange={(checked) =>
                                form.setData('allow_hour_bank_overage', checked)
                            }
                            error={errors.allow_hour_bank_overage}
                        />
                    </Section>

                    <Section title={t('admin.settings.time.title')}>
                        <Toggle
                            id={`${id}-approval`}
                            label={t('admin.settings.time.require_approval')}
                            help={t(
                                'admin.settings.time.require_approval_help',
                            )}
                            checked={form.data.require_timesheet_approval}
                            onChange={(checked) =>
                                form.setData(
                                    'require_timesheet_approval',
                                    checked,
                                )
                            }
                            error={errors.require_timesheet_approval}
                        />
                        <Toggle
                            id={`${id}-future`}
                            label={t('admin.settings.time.allow_future')}
                            help={t('admin.settings.time.allow_future_help')}
                            checked={form.data.allow_future_time_entries}
                            onChange={(checked) =>
                                form.setData(
                                    'allow_future_time_entries',
                                    checked,
                                )
                            }
                            error={errors.allow_future_time_entries}
                        />
                        <Toggle
                            id={`${id}-description`}
                            label={t(
                                'admin.settings.time.description_required',
                            )}
                            help={t(
                                'admin.settings.time.description_required_help',
                            )}
                            checked={form.data.time_entry_description_required}
                            onChange={(checked) =>
                                form.setData(
                                    'time_entry_description_required',
                                    checked,
                                )
                            }
                            error={errors.time_entry_description_required}
                        />
                        <WeekMinutesInput
                            value={form.data.default_work_minutes}
                            onChange={(week) =>
                                form.setData('default_work_minutes', week)
                            }
                            legend={t('admin.settings.time.default_week')}
                            errorPrefix="default_work_minutes"
                            errors={errors}
                        />
                        <p className="-mt-2 text-sm text-muted-foreground">
                            {t('admin.settings.time.default_week_help')}
                        </p>
                    </Section>

                    <Section title={t('admin.settings.attachments.title')}>
                        <Field
                            id={`${id}-attachments`}
                            label={t('admin.settings.attachments.max')}
                            help={
                                serverUploadLimitMb !== null
                                    ? t(
                                          'admin.settings.attachments.server_limit',
                                          { mb: serverUploadLimitMb },
                                      )
                                    : t('admin.settings.attachments.max_help')
                            }
                            error={errors.max_attachment_mb}
                        >
                            <div className="flex items-center gap-2">
                                <Input
                                    id={`${id}-attachments`}
                                    type="number"
                                    inputMode="numeric"
                                    min={1}
                                    max={200}
                                    step={1}
                                    className="tabular w-28"
                                    value={form.data.max_attachment_mb}
                                    onChange={(event) =>
                                        form.setData(
                                            'max_attachment_mb',
                                            event.target.value,
                                        )
                                    }
                                    aria-invalid={
                                        errors.max_attachment_mb
                                            ? true
                                            : undefined
                                    }
                                    aria-describedby={describedBy(
                                        `${id}-attachments`,
                                        {
                                            help: true,
                                            error: errors.max_attachment_mb,
                                        },
                                    )}
                                />
                                <span
                                    aria-hidden="true"
                                    className="text-sm text-muted-foreground"
                                >
                                    MB
                                </span>
                            </div>
                        </Field>
                        {overServerLimit ? (
                            <p
                                role="status"
                                className="flex items-start gap-2 rounded-md bg-warning-soft px-3 py-2 text-sm text-foreground"
                            >
                                <TriangleAlert
                                    aria-hidden="true"
                                    className="mt-0.5 size-4 shrink-0 text-warning"
                                />
                                {t(
                                    'admin.settings.attachments.over_server_limit',
                                    { mb: serverUploadLimitMb },
                                )}
                            </p>
                        ) : null}
                    </Section>

                    <Section
                        title={t('chat_media.settings.title')}
                        description={t('chat_media.settings.description')}
                    >
                        <Field
                            id={`${id}-audio`}
                            label={t('chat_media.settings.max_audio')}
                            help={t('chat_media.settings.max_audio_help')}
                            error={errors.max_audio_seconds}
                            className="max-w-md"
                        >
                            <NativeSelect
                                id={`${id}-audio`}
                                className="w-48"
                                value={form.data.max_audio_seconds}
                                onChange={(event) =>
                                    form.setData(
                                        'max_audio_seconds',
                                        event.target.value,
                                    )
                                }
                                aria-invalid={
                                    errors.max_audio_seconds ? true : undefined
                                }
                                aria-describedby={describedBy(`${id}-audio`, {
                                    help: true,
                                    error: errors.max_audio_seconds,
                                })}
                            >
                                {audioDurations.map((seconds) => (
                                    <option
                                        key={seconds}
                                        value={String(seconds)}
                                    >
                                        {audioDurationLabel(seconds)}
                                    </option>
                                ))}
                            </NativeSelect>
                        </Field>
                    </Section>

                    {/* La confirmación llega como aviso (toast) desde el servidor. */}
                    <div className="flex flex-wrap items-center gap-3">
                        <Button
                            type="submit"
                            disabled={form.processing || invalidWeek}
                        >
                            {form.processing && <Spinner />}
                            {t('admin.settings.save')}
                        </Button>
                    </div>
                </form>
            </AdminPage>
        </>
    );
}

AdminSettings.layout = {
    breadcrumbs: [
        { title: t('nav.admin'), href: adminIndex() },
        { title: t('admin.settings.title'), href: edit() },
    ],
};
