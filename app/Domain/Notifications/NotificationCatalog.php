<?php

namespace App\Domain\Notifications;

/**
 * Catálogo de los eventos que avisan (SPEC §13, D-073): un evento por cada kind() de
 * AppNotification. Es la única fuente de los canales posibles, los que llegan por defecto y a quién
 * se ofrece cada evento; tests/Feature/Notifications/NotificationCatalogTest comprueba que toda
 * AppNotification tiene aquí su evento.
 *
 * Canales lógicos (NotificationPreferences los traduce a los de Laravel):
 *   app   → campana y /notificaciones (canal database; en tiempo real desde la Fase 6),
 *   email → cola `mail`,
 *   push  → Web Push (D-072), solo si config('notifications.channels.push') está definido.
 *
 * Los valores por defecto conservan lo que ya avisaba cada fase y añaden lo que pide D-073: el
 * email para bolsas, horas devueltas, ausencias y vencimientos; Web Push para menciones y directos.
 */
final class NotificationCatalog
{
    public const string APP = 'app';

    public const string EMAIL = 'email';

    public const string PUSH = 'push';

    /** @var list<string> */
    public const array CHANNELS = [self::APP, self::EMAIL, self::PUSH];

    /** Cualquier persona interna. */
    public const string AUDIENCE_ALL = 'all';

    /** Quien aprueba ausencias y semanas: admin y responsables de departamento. */
    public const string AUDIENCE_APPROVERS = 'approvers';

    /** Quien recibe avisos de bolsas: admin, responsables y gestores de algún proyecto. */
    public const string AUDIENCE_MANAGERS = 'managers';

    public const string AUDIENCE_ADMINS = 'admins';

    /** Quien escribe la weekly (Fase 10, D-147) con el módulo encendido; nunca un colaborador externo. */
    public const string AUDIENCE_WEEKLIES = 'weeklies';

    /** Quien usa las sugerencias del centro de ayuda (10.7, D-209), con sus módulos encendidos. */
    public const string AUDIENCE_SUGGESTIONS = 'suggestions';

    /** Quien usa el plan del día (D-251), con su módulo visible; nunca un colaborador externo. */
    public const string AUDIENCE_DAY_PLAN = 'day_plan';

    /** Quien ficha (Fase 11, D-331): la plantilla sujeta al registro con el módulo `people` visible. */
    public const string AUDIENCE_PEOPLE = 'people';

    /** Grupos, en el orden de la página de preferencias. */
    public const array GROUPS = ['tasks', 'time', 'people', 'day_plan', 'weeklies', 'suggestions', 'hour_banks', 'absences', 'chat', 'reports', 'system'];

    /** @var array<string, NotificationEvent>|null */
    private ?array $events = null;

    /**
     * @return array<string, NotificationEvent> por kind, en el orden de GROUPS
     */
    public function all(): array
    {
        return $this->events ??= $this->build();
    }

    public function find(string $kind): ?NotificationEvent
    {
        return $this->all()[$kind] ?? null;
    }

    /**
     * @return array<string, NotificationEvent>
     */
    private function build(): array
    {
        $app = self::APP;
        $email = self::EMAIL;
        $push = self::PUSH;
        $all = [$app, $email, $push];

        $events = [
            // Registro de jornada (Fase 11, D-339): fichar la entrada y la salida y la jornada sin
            // cerrar, en la app y en el navegador; las correcciones, a la otra parte (en la app y por
            // email la que espera tu conformidad o acaba en discrepancia).
            new NotificationEvent('people.clock_in_missing', 'people', $all, [$app, $push], self::AUDIENCE_PEOPLE),
            new NotificationEvent('people.clock_out_missing', 'people', $all, [$app, $push], self::AUDIENCE_PEOPLE),
            new NotificationEvent('people.workday_unclosed', 'people', $all, [$app, $push], self::AUDIENCE_PEOPLE),
            new NotificationEvent('people.correction_requested', 'people', $all, [$app, $email], self::AUDIENCE_PEOPLE),
            new NotificationEvent('people.correction_accepted', 'people', $all, [$app], self::AUDIENCE_PEOPLE),
            new NotificationEvent('people.correction_disputed', 'people', $all, [$app, $email], self::AUDIENCE_PEOPLE),

            // Tareas (Fase 1): asignación, mención, comentario y cambio de estado de una tarea que
            // sigo, y tareas que vencen mañana o vencidas.
            new NotificationEvent('task.assigned', 'tasks', $all, [$app]),
            new NotificationEvent('task.mentioned', 'tasks', $all, [$app, $push]),
            new NotificationEvent('task.commented', 'tasks', $all, [$app]),
            new NotificationEvent('task.status_changed', 'tasks', $all, [$app]),
            new NotificationEvent('task.due', 'tasks', $all, [$app, $email]),

            // Horas (Fase 1 y el recordatorio de los viernes de la Fase 7, que desde la 10.5 lleva
            // también la weekly pendiente: un solo aviso los viernes, D-150 y D-200).
            new NotificationEvent('time.returned', 'time', $all, [$app, $email]),
            new NotificationEvent('time.approved', 'time', $all, [$app]),
            new NotificationEvent('time.week_reminder', 'time', $all, [$app]),
            new NotificationEvent('time.timer_long', 'time', $all, [$app]),

            // Plan del día (D-252 y D-255): el recordatorio de la hora límite (08:30) a quien aún no ha
            // escrito su plan en un día con jornada, por defecto en la app y en el navegador; el email
            // se puede activar. Y el comentario de su responsable en una de sus líneas, en la app.
            new NotificationEvent('day_plan.reminder', 'day_plan', $all, [$app, $push], self::AUDIENCE_DAY_PLAN),
            new NotificationEvent('day_plan.commented', 'day_plan', $all, [$app], self::AUDIENCE_DAY_PLAN),

            // La Weekly (Fase 10, entrega 10.5, D-199 a D-201). Los recordatorios por reglas salen por
            // el canal de cada regla (y solo si la persona no lo ha desactivado aquí); por defecto,
            // en la app, por email (como WeeklySync) y en el navegador, que solo llega a quien lo ha
            // activado (10.9b: se aclara el comentario, el valor ya era este). «Weekly cerrada» con el enlace al
            // informe (F-095) y el plazo cambiado, a quien aún debe enviarla.
            new NotificationEvent('weeklies.reminder', 'weeklies', $all, [$app, $email, $push], self::AUDIENCE_WEEKLIES),
            new NotificationEvent('weeklies.closed', 'weeklies', $all, [$app, $email], self::AUDIENCE_WEEKLIES),
            new NotificationEvent('weeklies.deadline_changed', 'weeklies', $all, [$app], self::AUDIENCE_WEEKLIES),

            // Sugerencias del centro de ayuda (10.7, D-209): cambia el estado de la tuya, te responden
            // (en tu sugerencia o a tu comentario) o te mencionan. Por defecto, en la app.
            new NotificationEvent('suggestions.status_changed', 'suggestions', $all, [$app], self::AUDIENCE_SUGGESTIONS),
            new NotificationEvent('suggestions.replied', 'suggestions', $all, [$app], self::AUDIENCE_SUGGESTIONS),
            new NotificationEvent('suggestions.mentioned', 'suggestions', $all, [$app, $push], self::AUDIENCE_SUGGESTIONS),

            // Bolsas (Fase 1): umbrales y exceso.
            new NotificationEvent('hour_bank.threshold', 'hour_banks', $all, [$app, $email], self::AUDIENCE_MANAGERS),
            new NotificationEvent('hour_bank.overage', 'hour_banks', $all, [$app, $email], self::AUDIENCE_MANAGERS),

            // Ausencias (Fase 3).
            new NotificationEvent('absence.requested', 'absences', $all, [$app, $email], self::AUDIENCE_APPROVERS),
            new NotificationEvent('absence.approved', 'absences', $all, [$app, $email]),
            new NotificationEvent('absence.rejected', 'absences', $all, [$app, $email]),
            new NotificationEvent('absence.updated', 'absences', $all, [$app, $email]),
            new NotificationEvent('absence.cancelled', 'absences', $all, [$app, $email]),

            // Chat (Fase 6): mensajes directos y menciones (también @todos).
            new NotificationEvent('chat.direct', 'chat', $all, [$app, $push]),
            new NotificationEvent('chat.mention', 'chat', $all, [$app, $push]),

            // Informes (Fase 2, D-047): el resumen semanal es un email; también queda en la campana.
            new NotificationEvent('reports.weekly_digest', 'reports', [$email, $app], [$email, $app], self::AUDIENCE_MANAGERS),
            // Envíos programados (Fase 9, D-141): uno se pausa porque su propietario ha perdido el
            // acceso al informe, está desactivado o se ha quedado sin destinatarios.
            new NotificationEvent('reports.schedule_paused', 'reports', [$app, $email], [$app, $email]),

            // Sistema: solo para el admin y obligatorios (Fases 6 y 7).
            new NotificationEvent('system.transcriptions_failing', 'system', [$app, $email], [$app, $email], self::AUDIENCE_ADMINS, mandatory: true),
            new NotificationEvent('system.disk_space', 'system', [$app, $email], [$app, $email], self::AUDIENCE_ADMINS, mandatory: true),
            new NotificationEvent('system.backup_failed', 'system', [$app, $email], [$app, $email], self::AUDIENCE_ADMINS, mandatory: true),
        ];

        $byKind = [];

        foreach ($events as $event) {
            $byKind[$event->kind] = $event;
        }

        return $byKind;
    }
}
