<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Ajuste global clave-valor (SPEC §4.6 y §14). Se leen con Setting::get() (cacheado) y se
 * escriben con Setting::set(), que invalida la caché.
 *
 * @property int $id
 * @property string $key
 * @property mixed $value
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['key', 'value'])]
class Setting extends Model
{
    public const string CACHE_KEY = 'settings.all';

    /**
     * Valores por defecto del SPEC (§7, §8, §14). DefaultSettingsSeeder los crea si no existen.
     *
     * @var array<string, mixed>
     */
    public const array DEFAULTS = [
        'company_name' => 'Audax Studio',
        'require_2fa' => false,
        'timer_rounding_minutes' => 1,
        'timer_warning_hours' => 10,
        'hour_bank_alert_thresholds' => [75, 90, 100],
        'allow_hour_bank_overage' => true,
        'require_timesheet_approval' => true,
        'allow_future_time_entries' => false,
        'time_entry_description_required' => false,
        'max_attachment_mb' => 50,
        // Duración máxima de los audios del chat en segundos (SPEC §12, D-069).
        'max_audio_seconds' => 300,
        // Jornada por defecto de los usuarios sin horario propio, lunes primero (D-036).
        'default_work_minutes' => [480, 480, 480, 480, 480, 0, 0],
        // Resumen semanal de productividad por email (D-047): ocupación fuera de estos umbrales (%).
        'weekly_digest_enabled' => true,
        'occupancy_low_threshold' => 70,
        'occupancy_high_threshold' => 110,
        // Privacidad (D-075): texto informativo en markdown (null = el borrador de
        // lang/es/privacy.php, pendiente de asesor) y su versión; al cambiar el texto sube la
        // versión y se vuelve a pedir su lectura.
        'privacy_notice' => null,
        'privacy_notice_version' => 1,
        // Retención (D-075, App\Domain\Privacy\RetentionPolicy), en meses; null = sin límite.
        // Nunca se borran horas.
        'retention_login_events_months' => 12,
        'retention_read_notifications_months' => 6,
        'retention_activity_log_months' => 60,
        'retention_chat_messages_months' => null,
        // La Weekly (10.5, D-202): el registro de avisos, un año; los dictados, tres meses.
        'retention_weekly_reminder_logs_months' => 12,
        'retention_dictations_months' => 3,
        'retention_ai_usage_months' => 12,
        // Días que se puede descargar una exportación de datos personales (D-075).
        'personal_data_export_days' => 7,
        // Avisos al admin (D-076): disco por encima de este %, adjuntos por encima de estos GB (null = sin aviso).
        'disk_warning_percent' => 85,
        'attachments_warning_gb' => null,
        // Recordatorio de los viernes para enviar la semana (D-073).
        'week_reminder_enabled' => true,
        // Fase 10 (D-151). Módulos activos (F-177): apagado, sus rutas dan 404 y no salen en la
        // navegación (App\Domain\Weeklies\AppModules).
        'modules' => ['weeklies' => true, 'project_status' => true, 'help' => true, 'suggestions' => true, 'assistant' => true],
        // Aviso global (F-178) en todas las páginas internas: null o {message, tone: info|warning}.
        'global_banner' => null,
        // Plantillas de aviso de la weekly (F-104 y F-105): null = las de lang/es/weeklies.php; si
        // no, {automatic|manual|weekly_closed: {subject, body}}.
        'weekly_email_templates' => null,
        // La weekly pendiente en el recordatorio de los viernes de las horas (10.5, D-200).
        'weekly_friday_reminder' => true,
        // Centro de ayuda (F-157): enlace de soporte y manual en PDF ({disk, path, name, size}).
        'help_support_url' => null,
        'help_manual' => null,
        // Limpieza del dictado de la weekly con IA (F-172, D-146, D-227): corrige nombres de clientes y
        // personas en la transcripción de Whisper, como hacía siempre WeeklySync. Solo el texto va a Gemini.
        'weekly_dictation_cleanup' => true,
        // Entrar con Google (D-165): solo se ofrece si además hay credenciales de Google.
        'google_login_enabled' => true,
    ];

    protected static function booted(): void
    {
        static::saved(fn () => static::flushCache());
        static::deleted(fn () => static::flushCache());
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'json',
        ];
    }

    /**
     * Valor de un ajuste: el guardado, si no el $default indicado y, si tampoco, el del SPEC.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $all = static::allCached();

        if (array_key_exists($key, $all)) {
            return $all[$key];
        }

        return $default ?? (self::DEFAULTS[$key] ?? null);
    }

    public static function set(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function allCached(): array
    {
        /** @var array<string, mixed> */
        return Cache::rememberForever(self::CACHE_KEY, fn (): array => static::query()
            ->pluck('value', 'key')
            ->all());
    }

    public static function flushCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
