import { Head, useForm } from '@inertiajs/react';
import { Info, Lock } from 'lucide-react';
import type { FormEvent } from 'react';
import { useId } from 'react';
import AlertError from '@/components/alert-error';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { t } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { edit, update } from '@/routes/notification-settings';
import type {
    NotificationChannel,
    NotificationEventPreference,
    NotificationSettings,
    NotificationSettingsForm,
    NotificationSettingsPageProps,
} from '@/types/notification-settings';

/** Canales, en el orden de las columnas. */
export const CHANNELS: readonly NotificationChannel[] = [
    'app',
    'email',
    'push',
];

const CHANNEL_LABELS: Record<NotificationChannel, TranslationKey> = {
    app: 'notification_settings.channels.app',
    email: 'notification_settings.channels.email',
    push: 'notification_settings.channels.push',
};

/**
 * Cada fila, cuando hay sitio (contenedor ≥ 32rem): el evento y una columna por canal. Con menos
 * sitio (móvil), los canales van debajo de cada evento, cada uno con su nombre visible.
 */
const ROW_GRID =
    '@lg:grid @lg:grid-cols-[minmax(0,1fr)_repeat(3,5.5rem)] @lg:gap-x-2';

/**
 * Lo que se puede cambiar, tal como llega del servidor: los eventos no obligatorios y los canales
 * que ofrecen. Lo obligatorio y lo que no se ofrece no se envía (el servidor lo ignoraría).
 */
export function initialForm(
    settings: NotificationSettings,
): NotificationSettingsForm {
    const events: NotificationSettingsForm['events'] = {};

    for (const group of settings.groups) {
        for (const event of group.events) {
            if (event.mandatory) {
                continue;
            }

            const channels: Partial<Record<NotificationChannel, boolean>> = {};

            for (const channel of CHANNELS) {
                if (event.channels[channel].offered) {
                    channels[channel] = event.channels[channel].enabled;
                }
            }

            if (Object.keys(channels).length > 0) {
                events[event.kind] = channels;
            }
        }
    }

    return { events, daily_digest: settings.daily_digest };
}

type RowProps = {
    event: NotificationEventPreference;
    /** Prefijo único de los id de la fila. */
    rowId: string;
    /** Lo elegido en el formulario (sin tocar, lo que llegó del servidor). */
    values: Partial<Record<NotificationChannel, boolean>> | undefined;
    pushAvailable: boolean;
    pushNoteId: string;
    onChange: (channel: NotificationChannel, enabled: boolean) => void;
};

function ChannelCell({
    event,
    channel,
    rowId,
    values,
    pushAvailable,
    pushNoteId,
    onChange,
}: RowProps & { channel: NotificationChannel }) {
    const channelLabel = t(CHANNEL_LABELS[channel]);
    const preference = event.channels[channel];
    // Sin Web Push configurado, la columna entera se muestra desactivada con su explicación.
    const pushPending = channel === 'push' && !pushAvailable;

    if (!preference.offered && !pushPending) {
        return (
            <p className="flex items-center gap-2 text-sm text-muted-foreground @lg:justify-center">
                <span aria-hidden="true">—</span>
                <span className="@lg:sr-only">
                    {t('notification_settings.not_offered', {
                        channel: channelLabel,
                    })}
                </span>
            </p>
        );
    }

    const switchId = `${rowId}-${channel}`;
    const checked = pushPending
        ? false
        : event.mandatory
          ? preference.enabled
          : (values?.[channel] ?? preference.enabled);

    return (
        <div className="flex items-center gap-2 @lg:justify-center">
            <Switch
                id={switchId}
                checked={checked}
                disabled={event.mandatory || pushPending}
                onCheckedChange={(enabled) => onChange(channel, enabled)}
                aria-label={t('notification_settings.switch', {
                    event: event.label,
                    channel: channelLabel,
                })}
                aria-describedby={
                    pushPending
                        ? pushNoteId
                        : event.mandatory
                          ? `${rowId}-mandatory`
                          : undefined
                }
            />
            <Label htmlFor={switchId} className="font-normal @lg:sr-only">
                {channelLabel}
            </Label>
        </div>
    );
}

function EventRow(props: RowProps) {
    const { event, rowId } = props;

    return (
        <li
            className={cn('grid gap-3 py-4 @lg:items-center', ROW_GRID)}
            data-test={`notification-event-${event.kind}`}
        >
            <div className="grid gap-1">
                <p className="text-sm font-medium">{event.label}</p>
                <p className="text-sm text-muted-foreground">
                    {event.description}
                </p>
                {event.mandatory ? (
                    <p
                        id={`${rowId}-mandatory`}
                        className="flex items-center gap-1.5 text-sm text-muted-foreground"
                    >
                        <Lock
                            aria-hidden="true"
                            className="size-3.5 shrink-0"
                        />
                        {t('notification_settings.mandatory')}
                    </p>
                ) : null}
            </div>
            <div className="flex flex-wrap gap-x-6 gap-y-3 @lg:contents">
                {CHANNELS.map((channel) => (
                    <ChannelCell key={channel} {...props} channel={channel} />
                ))}
            </div>
        </li>
    );
}

/**
 * Preferencias de notificación (SPEC §13, D-073): el resumen diario por email y una sección por
 * grupo con una fila por evento y un interruptor por canal. Solo para internos.
 */
export default function NotificationSettingsPage({
    settings,
}: NotificationSettingsPageProps) {
    const id = useId();
    const form = useForm<NotificationSettingsForm>(initialForm(settings));
    const digestId = `${id}-digest`;
    const pushNoteId = `${id}-push-note`;
    const errors = Object.values(form.errors).filter(
        (message): message is string =>
            typeof message === 'string' && message !== '',
    );

    const setChannel = (
        kind: string,
        channel: NotificationChannel,
        enabled: boolean,
    ) => {
        form.setData((data) => ({
            ...data,
            events: {
                ...data.events,
                [kind]: { ...data.events[kind], [channel]: enabled },
            },
        }));
    };

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.put(update.url(), { preserveScroll: true });
    };

    return (
        <>
            <Head title={t('notification_settings.heading')} />

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title={t('notification_settings.heading')}
                    description={t('notification_settings.description')}
                />

                <form onSubmit={submit} className="space-y-8" noValidate>
                    {errors.length > 0 ? (
                        <AlertError
                            errors={errors}
                            title={t('notification_settings.error_title')}
                        />
                    ) : null}

                    <div className="flex items-start gap-3 rounded-md border p-4">
                        <Switch
                            id={digestId}
                            checked={form.data.daily_digest}
                            onCheckedChange={(checked) =>
                                form.setData('daily_digest', checked)
                            }
                            aria-describedby={`${digestId}-help`}
                            className="mt-0.5"
                        />
                        <div className="grid gap-1">
                            <Label htmlFor={digestId}>
                                {t('notification_settings.digest.label')}
                            </Label>
                            <p
                                id={`${digestId}-help`}
                                className="text-sm text-muted-foreground"
                            >
                                {t('notification_settings.digest.help')}
                            </p>
                            <div aria-live="polite">
                                {form.data.daily_digest ? (
                                    <p className="flex items-start gap-1.5 text-sm text-muted-foreground">
                                        <Info
                                            aria-hidden="true"
                                            className="mt-0.5 size-4 shrink-0"
                                        />
                                        {t(
                                            'notification_settings.digest.active',
                                        )}
                                    </p>
                                ) : null}
                            </div>
                        </div>
                    </div>

                    {settings.groups.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            {t('notification_settings.empty')}
                        </p>
                    ) : (
                        <div className="@container space-y-8">
                            {!settings.push_available ? (
                                <p
                                    id={pushNoteId}
                                    className="flex items-start gap-1.5 text-sm text-muted-foreground"
                                >
                                    <Info
                                        aria-hidden="true"
                                        className="mt-0.5 size-4 shrink-0"
                                    />
                                    {t(
                                        'notification_settings.push_unavailable',
                                    )}
                                </p>
                            ) : null}

                            {settings.groups.map((group) => (
                                <fieldset key={group.key} className="min-w-0">
                                    <legend className="text-base font-medium">
                                        {group.label}
                                    </legend>

                                    {/* Cabeceras de las columnas: cada interruptor ya lleva el canal en su nombre accesible. */}
                                    <div
                                        aria-hidden="true"
                                        className={cn(
                                            'hidden items-end border-b pt-3 pb-2 text-xs text-muted-foreground',
                                            ROW_GRID,
                                        )}
                                    >
                                        <span />
                                        {CHANNELS.map((channel) => (
                                            <span
                                                key={channel}
                                                className="text-center"
                                            >
                                                {t(CHANNEL_LABELS[channel])}
                                            </span>
                                        ))}
                                    </div>

                                    <ul className="divide-y">
                                        {group.events.map((event) => (
                                            <EventRow
                                                key={event.kind}
                                                event={event}
                                                rowId={`${id}-${event.kind.replaceAll('.', '-')}`}
                                                values={
                                                    form.data.events[event.kind]
                                                }
                                                pushAvailable={
                                                    settings.push_available
                                                }
                                                pushNoteId={pushNoteId}
                                                onChange={(channel, enabled) =>
                                                    setChannel(
                                                        event.kind,
                                                        channel,
                                                        enabled,
                                                    )
                                                }
                                            />
                                        ))}
                                    </ul>
                                </fieldset>
                            ))}
                        </div>
                    )}

                    <div className="flex flex-wrap items-center gap-4">
                        <Button
                            type="submit"
                            disabled={form.processing}
                            data-test="save-notification-settings"
                        >
                            {form.processing ? <Spinner /> : null}
                            {form.processing
                                ? t('notification_settings.saving')
                                : t('common.save')}
                        </Button>
                        {form.isDirty && !form.processing ? (
                            <p className="text-sm text-muted-foreground">
                                {t('notification_settings.unsaved')}
                            </p>
                        ) : null}
                    </div>
                </form>
            </div>
        </>
    );
}

NotificationSettingsPage.layout = {
    breadcrumbs: [{ title: t('notification_settings.heading'), href: edit() }],
};
