<?php

namespace App\Notifications\Time;

use App\Domain\Time\Messages;
use App\Domain\Time\Week;
use App\Domain\Weeklies\Reminders\TracksWeeklyReminderLogs;
use App\Domain\Weeklies\WeeklyCalendar;
use App\Notifications\AppNotification;
use App\Support\Duration;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Recordatorio de los viernes (SPEC §13, D-073 y D-200): los viernes a las 13:00 de Madrid, un solo
 * aviso con lo que le falta a cada persona:
 * - la semana de horas, si tiene capacidad esa semana y aún no la ha enviado (abierta o devuelta),
 *   con una foto de las cifras (horas imputadas frente a la capacidad),
 * - y, desde la 10.5, la weekly de la semana activa si debe enviarla y no lo ha hecho.
 * La envía time:remind-week una sola vez por persona y semana. Por defecto, en la app; por email si
 * la persona lo activa en /ajustes/notificaciones. Lleva a la hoja de esa semana o, si solo falta la
 * weekly, a «Mi weekly». La parte de la weekly queda en el registro de avisos de la Weekly.
 */
class WeekSubmissionReminder extends AppNotification
{
    use TracksWeeklyReminderLogs;

    /**
     * @param  array{cycle_id: int, label: string, deadline: string}|null  $weekly  la weekly pendiente
     */
    public function __construct(
        public readonly string $weekStart,
        public readonly int $loggedMinutes,
        public readonly int $capacityMinutes,
        public readonly bool $returned = false,
        public readonly bool $hours = true,
        public readonly ?array $weekly = null,
    ) {}

    public function kind(): string
    {
        return 'time.week_reminder';
    }

    public function title(object $notifiable): string
    {
        return Messages::get(match (true) {
            $this->hours && $this->weekly !== null => 'notifications.reminder.title_both',
            $this->hours => 'notifications.reminder.title',
            default => 'notifications.reminder.title_weekly',
        });
    }

    public function body(object $notifiable): ?string
    {
        $parts = [];

        if ($this->hours) {
            $parts[] = Messages::get($this->returned ? 'notifications.reminder.body_returned' : 'notifications.reminder.body', [
                'week' => Week::containing($this->weekStart)->label(),
                'logged' => Duration::format($this->loggedMinutes),
                'capacity' => Duration::format($this->capacityMinutes),
            ]);
        }

        if ($this->weekly !== null) {
            $parts[] = Messages::get('notifications.reminder.body_weekly', [
                'cycle' => $this->weekly['label'],
                'deadline' => CarbonImmutable::parse($this->weekly['deadline'], WeeklyCalendar::TIMEZONE)->settings(['locale' => 'es'])->isoFormat('dddd D [de] MMMM'),
            ]);
        }

        return implode(' ', $parts);
    }

    public function url(object $notifiable): ?string
    {
        return $this->hours
            ? route('time.index', ['semana' => Week::containing($this->weekStart)->iso()], absolute: false)
            : $this->weeklyPath();
    }

    public function icon(): ?string
    {
        return 'calendar-check';
    }

    /**
     * El email genérico y, si lleva las dos partes, también el enlace a «Mi weekly».
     */
    public function toMail(object $notifiable): MailMessage
    {
        $message = parent::toMail($notifiable);
        $weekly = $this->weeklyPath();

        if ($this->hours && $weekly !== null) {
            $message->line(Messages::get('notifications.reminder.weekly_action').': '.url($weekly));
        }

        return $message;
    }

    private function weeklyPath(): ?string
    {
        return $this->weekly !== null
            ? route('my-space.index', ['semana' => $this->weekly['cycle_id']], absolute: false)
            : null;
    }
}
