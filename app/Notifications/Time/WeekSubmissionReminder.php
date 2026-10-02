<?php

namespace App\Notifications\Time;

use App\Domain\Time\Messages;
use App\Domain\Time\Week;
use App\Notifications\AppNotification;
use App\Support\Duration;

/**
 * «Recuerda enviar tu semana» (SPEC §13, D-073): los viernes a las 13:00 de Madrid, a quien tiene
 * capacidad esa semana y aún no la ha enviado (abierta o devuelta). La envía time:remind-week una
 * sola vez por persona y semana. Por defecto, en la app; por email si la persona lo activa en
 * /ajustes/notificaciones (el email genérico de AppNotification). Guarda una foto de las cifras
 * (horas imputadas frente a la capacidad de la semana) y lleva a la hoja de esa semana.
 */
class WeekSubmissionReminder extends AppNotification
{
    public function __construct(
        public readonly string $weekStart,
        public readonly int $loggedMinutes,
        public readonly int $capacityMinutes,
        public readonly bool $returned = false,
    ) {}

    public function kind(): string
    {
        return 'time.week_reminder';
    }

    public function title(object $notifiable): string
    {
        return Messages::get('notifications.reminder.title');
    }

    public function body(object $notifiable): ?string
    {
        return Messages::get($this->returned ? 'notifications.reminder.body_returned' : 'notifications.reminder.body', [
            'week' => Week::containing($this->weekStart)->label(),
            'logged' => Duration::format($this->loggedMinutes),
            'capacity' => Duration::format($this->capacityMinutes),
        ]);
    }

    public function url(object $notifiable): ?string
    {
        return route('time.index', ['semana' => Week::containing($this->weekStart)->iso()], absolute: false);
    }

    public function icon(): ?string
    {
        return 'calendar-check';
    }
}
