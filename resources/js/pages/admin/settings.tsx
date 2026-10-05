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
import type { AppModule } from '@/types/weeklies';

/** Módulos que se pueden apagar (F-177), en el orden de la pantalla. */
const MODULES: AppModule[] = [
    'weeklies',
    'project_status',
    'help',
    'suggestions',
    'assistant',
];

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
    weekly_digest_enabled: boolean;
    occupancy_low_threshold: string;
    occupancy_high_threshold: string;
    max_audio_seconds: string;
    week_reminder_enabled: boolean;
    modules: Record<AppModule, boolean>;
    banner_message: string;
    banner_tone: 'info' | 'warning';
    weekly_dictation_cleanup: boolean;
    google_login_enabled: boolean;
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

/** Porcentaje entero (umbrales de ocupación del resumen semanal, D-047). */
function PercentField({
    id,
    label,
    help,
    value,
    error,
    onChange,
}: {
    id: string;
    label: string;
    help: string;
    value: string;
    error?: string;
    onChange: (value: string) => void;
}) {
    return (
        <Field id={id} label={label} help={help} error={error}>
            <div className="flex items-center gap-2">
                <Input
                    id={id}
                    type="number"
                    inputMode="numeric"
                    min={1}
                    max={300}
                    step={1}
                    className="tabular w-24"
                    value={value}
                    onChange={(event) => onChange(event.target.value)}
                    aria-invalid={error ? true : undefined}
                    aria-describedby={describedBy(id, { help: true, error })}
                />
                <span
                    aria-hidden="true"
                    className="text-sm text-muted-foreground"
                >
                    %
                </span>
            </div>
        </Field>
    );
}

/** Ajustes generales (SPEC §7, §8 y §14): empresa, seguridad, temporizador, bolsas, horas, adjuntos y resumen semanal (D-047). */
export default function AdminSettings({
    settings,
    roundings,
    serverUploadLimitMb,
    googleLogin,
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
        weekly_digest_enabled: settings.weekly_digest_enabled,
        occupancy_low_threshold: String(settings.occupancy_low_threshold),
        occupancy_high_threshold: String(settings.occupancy_high_threshold),
        max_audio_seconds: String(settings.max_audio_seconds),
        week_reminder_enabled: settings.week_reminder_enabled ?? true,
        modules: Object.fromEntries(
            MODULES.map((module) => [
                module,
                settings.modules?.[module] ?? true,
            ]),
        ) as Record<AppModule, boolean>,
        banner_message: settings.global_banner?.message ?? '',
        banner_tone: settings.global_banner?.tone ?? 'info',
        weekly_dictation_cleanup: settings.weekly_dictation_cleanup ?? false,
        google_login_enabled: settings.google_login_enabled ?? true,
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
    // Aviso en vivo si la ocupación baja no queda por debajo de la alta (el servidor también lo valida).
    const occupancyLow = Number(form.data.occupancy_low_threshold);
    const occupancyHigh = Number(form.data.occupancy_high_threshold);
    const occupancyInverted =
        form.data.occupancy_low_threshold !== '' &&
        form.data.occupancy_high_threshold !== '' &&
        Number.isFinite(occupancyLow) &&
        Number.isFinite(occupancyHigh) &&
        occupancyLow >= occupancyHigh;

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        if (invalidWeek) {
            return;
        }

        form.transform(({ banner_message, banner_tone, ...data }) => ({
            ...data,
            // Aviso global (F-178): sin texto, no hay aviso.
            global_banner:
                banner_message.trim() === ''
                    ? null
                    : { message: banner_message.trim(), tone: banner_tone },
            timer_rounding_minutes: Number(data.timer_rounding_minutes),
            timer_warning_hours: Number(data.timer_warning_hours),
            hour_bank_alert_thresholds: data.hour_bank_alert_thresholds.map(
                (value) => Number(value),
            ),
            max_attachment_mb: Number(data.max_attachment_mb),
            occupancy_low_threshold: Number(data.occupancy_low_threshold),
            occupancy_high_threshold: Number(data.occupancy_high_threshold),
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
                        <Toggle
                            id={`${id}-google-login`}
                            label={t('admin.settings.security.google_login')}
                            help={t(
                                'admin.settings.security.google_login_help',
                                {
                                    domains: (googleLogin?.domains ?? [])
                                        .map((domain) => `@${domain}`)
                                        .join(', '),
                                },
                            )}
                            checked={form.data.google_login_enabled}
                            onChange={(checked) =>
                                form.setData('google_login_enabled', checked)
                            }
                            error={errors.google_login_enabled}
                        />
                        {googleLogin && !googleLogin.configured && (
                            <p
                                className="flex items-start gap-2 text-sm text-muted-foreground"
                                data-test="google-login-unconfigured"
                            >
                                <TriangleAlert
                                    aria-hidden="true"
                                    className="mt-0.5 size-4 shrink-0 text-warning"
                                />
                                {t(
                                    'admin.settings.security.google_login_unconfigured',
                                )}
                            </p>
                        )}
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
                        <Toggle
                            id={`${id}-week-reminder`}
                            label={t('admin.settings.time.week_reminder')}
                            help={t('admin.settings.time.week_reminder_help')}
                            checked={form.data.week_reminder_enabled}
                            onChange={(checked) =>
                                form.setData('week_reminder_enabled', checked)
                            }
                            error={errors.week_reminder_enabled}
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
                        title={t('reports_r3.settings.title')}
                        description={t('reports_r3.settings.description')}
                    >
                        <Toggle
                            id={`${id}-digest`}
                            label={t('reports_r3.settings.enabled')}
                            help={t('reports_r3.settings.enabled_help')}
                            checked={form.data.weekly_digest_enabled}
                            onChange={(checked) =>
                                form.setData('weekly_digest_enabled', checked)
                            }
                            error={errors.weekly_digest_enabled}
                        />
                        <div className="grid gap-5 sm:grid-cols-2">
                            <PercentField
                                id={`${id}-occupancy-low`}
                                label={t('reports_r3.settings.low')}
                                help={t('reports_r3.settings.low_help')}
                                value={form.data.occupancy_low_threshold}
                                error={errors.occupancy_low_threshold}
                                onChange={(value) =>
                                    form.setData(
                                        'occupancy_low_threshold',
                                        value,
                                    )
                                }
                            />
                            <PercentField
                                id={`${id}-occupancy-high`}
                                label={t('reports_r3.settings.high')}
                                help={t('reports_r3.settings.high_help')}
                                value={form.data.occupancy_high_threshold}
                                error={errors.occupancy_high_threshold}
                                onChange={(value) =>
                                    form.setData(
                                        'occupancy_high_threshold',
                                        value,
                                    )
                                }
                            />
                        </div>
                        {occupancyInverted &&
                        !errors.occupancy_low_threshold ? (
                            <p
                                role="status"
                                className="flex items-start gap-2 rounded-md bg-warning-soft px-3 py-2 text-sm text-foreground"
                            >
                                <TriangleAlert
                                    aria-hidden="true"
                                    className="mt-0.5 size-4 shrink-0 text-warning"
                                />
                                {t('reports_r3.settings.low_above_high')}
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

                    <Section
                        title={t('weeklies.settings.title')}
                        description={t('weeklies.settings.description')}
                    >
                        <fieldset className="grid gap-4">
                            <legend className="mb-1 text-sm font-medium">
                                {t('weeklies.settings.modules')}
                            </legend>
                            {MODULES.map((module) => (
                                <Toggle
                                    key={module}
                                    id={`${id}-module-${module}`}
                                    label={t(`app_modules.${module}`)}
                                    help={t(
                                        `weeklies.settings.module_help.${module}`,
                                    )}
                                    checked={form.data.modules[module]}
                                    onChange={(checked) =>
                                        form.setData('modules', {
                                            ...form.data.modules,
                                            [module]: checked,
                                        })
                                    }
                                    error={errors[`modules.${module}`]}
                                />
                            ))}
                        </fieldset>
                        <Toggle
                            id={`${id}-dictation-cleanup`}
                            label={t('weeklies.settings.dictation_cleanup')}
                            help={t('weeklies.settings.dictation_cleanup_help')}
                            checked={form.data.weekly_dictation_cleanup}
                            onChange={(checked) =>
                                form.setData(
                                    'weekly_dictation_cleanup',
                                    checked,
                                )
                            }
                            error={errors.weekly_dictation_cleanup}
                        />
                        <Field
                            id={`${id}-banner`}
                            label={t('weeklies.settings.banner')}
                            help={t('weeklies.settings.banner_help')}
                            error={
                                errors['global_banner.message'] ??
                                errors.global_banner
                            }
                            className="max-w-2xl"
                        >
                            <Input
                                id={`${id}-banner`}
                                value={form.data.banner_message}
                                maxLength={300}
                                onChange={(event) =>
                                    form.setData(
                                        'banner_message',
                                        event.target.value,
                                    )
                                }
                                aria-describedby={describedBy(`${id}-banner`, {
                                    help: true,
                                    error:
                                        errors['global_banner.message'] ??
                                        errors.global_banner,
                                })}
                            />
                        </Field>
                        <Field
                            id={`${id}-banner-tone`}
                            label={t('weeklies.settings.banner_tone')}
                            className="max-w-md"
                        >
                            <NativeSelect
                                id={`${id}-banner-tone`}
                                className="w-48"
                                value={form.data.banner_tone}
                                onChange={(event) =>
                                    form.setData(
                                        'banner_tone',
                                        event.target.value === 'warning'
                                            ? 'warning'
                                            : 'info',
                                    )
                                }
                            >
                                <option value="info">
                                    {t('weeklies.settings.banner_info')}
                                </option>
                                <option value="warning">
                                    {t('weeklies.settings.banner_warning')}
                                </option>
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
