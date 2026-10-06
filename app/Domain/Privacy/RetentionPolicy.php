<?php

namespace App\Domain\Privacy;

use App\Models\Setting;
use Carbon\CarbonImmutable;

/**
 * Plazos de retención configurables (SPEC §15, D-075). Los aplica el comando diario
 * app:prune-data. NUNCA se borran horas, bolsas, tareas ni proyectos: solo registros de acceso,
 * notificaciones leídas, auditoría antigua, mensajes del chat (si se fija un plazo), el registro de
 * avisos y los dictados de la Weekly (D-202), el plan del día (D-256) y exportaciones de datos
 * personales caducadas. Las
 * weeklies (envíos y apuntes) no caducan: son el histórico del equipo, como las horas.
 */
final class RetentionPolicy
{
    public const string LOGIN_EVENTS = 'login_events';

    public const string READ_NOTIFICATIONS = 'read_notifications';

    public const string ACTIVITY_LOG = 'activity_log';

    public const string CHAT_MESSAGES = 'chat_messages';

    /** Registro de avisos de la Weekly (10.5, D-202): lleva el nombre y el email de quien lo recibe. */
    public const string WEEKLY_REMINDER_LOGS = 'weekly_reminder_logs';

    /** Dictados de la Weekly (D-152 y D-202): borradores de texto que ya se copiaron al apunte. */
    public const string DICTATIONS = 'dictations';

    /** Uso de la IA (D-225): pasado el plazo se anonimiza (quién y sobre qué), no se borra. */
    public const string AI_USAGE = 'ai_usage';

    /** Plan del día (D-256): datos de desempeño; las horas enlazadas se conservan sin el enlace. */
    public const string DAY_PLANS = 'day_plans';

    /** Ajuste de cada tipo de dato, en meses (null = sin límite). */
    public const array SETTINGS = [
        self::LOGIN_EVENTS => 'retention_login_events_months',
        self::READ_NOTIFICATIONS => 'retention_read_notifications_months',
        self::ACTIVITY_LOG => 'retention_activity_log_months',
        self::CHAT_MESSAGES => 'retention_chat_messages_months',
        self::WEEKLY_REMINDER_LOGS => 'retention_weekly_reminder_logs_months',
        self::DICTATIONS => 'retention_dictations_months',
        self::AI_USAGE => 'retention_ai_usage_months',
        self::DAY_PLANS => 'retention_day_plans_months',
    ];

    /** Mínimo en meses que se puede fijar (la auditoría, al menos un año). */
    public const array MINIMUM_MONTHS = [
        self::LOGIN_EVENTS => 1,
        self::READ_NOTIFICATIONS => 1,
        self::ACTIVITY_LOG => 12,
        self::CHAT_MESSAGES => 1,
        self::WEEKLY_REMINDER_LOGS => 1,
        self::DICTATIONS => 1,
        self::AI_USAGE => 1,
        self::DAY_PLANS => 1,
    ];

    /** Máximo en meses (10 años). */
    public const int MAXIMUM_MONTHS = 120;

    /** Tipos que admiten «sin límite». */
    public const array UNLIMITED_ALLOWED = [self::CHAT_MESSAGES, self::ACTIVITY_LOG];

    public function months(string $type): ?int
    {
        $value = Setting::get(self::SETTINGS[$type]);

        if ($value === null || $value === '') {
            return in_array($type, self::UNLIMITED_ALLOWED, true) ? null : (int) Setting::DEFAULTS[self::SETTINGS[$type]];
        }

        return max(self::MINIMUM_MONTHS[$type], min(self::MAXIMUM_MONTHS, (int) $value));
    }

    /** Lo anterior a este instante se borra; null si no hay plazo. */
    public function cutoff(string $type, ?CarbonImmutable $now = null): ?CarbonImmutable
    {
        $months = $this->months($type);

        return $months === null ? null : ($now ?? CarbonImmutable::now())->subMonthsNoOverflow($months);
    }

    /** Días que se puede descargar una exportación de datos personales. */
    public function exportDays(): int
    {
        return max(1, min(30, (int) Setting::get('personal_data_export_days', 7)));
    }
}
