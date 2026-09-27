/**
 * Preferencias de notificación (/ajustes/notificaciones, D-073). Contrato con
 * App\Domain\Notifications\NotificationPreferences::forUser.
 */

/** Canales lógicos: en la app (campana), email y Web Push. */
export type NotificationChannel = 'app' | 'email' | 'push';

export type NotificationChannelPreference = {
    /** El evento se puede recibir por este canal (Web Push, solo si está configurado). */
    offered: boolean;
    /** La persona lo recibe por este canal. */
    enabled: boolean;
};

export type NotificationEventPreference = {
    /** kind() de la notificación, p. ej. «task.assigned». */
    kind: string;
    label: string;
    description: string;
    /** Obligatorio: se muestra, pero no se puede desactivar. */
    mandatory: boolean;
    channels: Record<NotificationChannel, NotificationChannelPreference>;
};

export type NotificationPreferenceGroup = {
    key: string;
    label: string;
    events: NotificationEventPreference[];
};

export type NotificationSettings = {
    /** Resumen diario por email en lugar de los emails sueltos. */
    daily_digest: boolean;
    /** Web Push configurado en el servidor (claves VAPID). */
    push_available: boolean;
    groups: NotificationPreferenceGroup[];
};

/** Lo que envía el formulario: kind → canal → activado, y el resumen diario. */
export type NotificationSettingsForm = {
    events: Record<string, Partial<Record<NotificationChannel, boolean>>>;
    daily_digest: boolean;
};
