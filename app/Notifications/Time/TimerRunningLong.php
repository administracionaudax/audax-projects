<?php

namespace App\Notifications\Time;

use App\Domain\Time\Messages;
use App\Domain\Time\Week;
use App\Notifications\AppNotification;

/**
 * «Tu temporizador lleva más de X horas en marcha» (SPEC §7), solo en la app. La envía el comando
 * timers:warn una sola vez por temporizador.
 */
class TimerRunningLong extends AppNotification
{
    public function __construct(
        public readonly string $taskTitle,
        public readonly int $hours,
        public readonly string $startedOn,
    ) {}

    public function kind(): string
    {
        return 'time.timer_long';
    }

    public function title(object $notifiable): string
    {
        return Messages::get('time.notifications.timer_long_title', ['hours' => $this->hours]);
    }

    public function body(object $notifiable): ?string
    {
        return Messages::get('time.notifications.timer_long_body', ['task' => $this->taskTitle]);
    }

    public function url(object $notifiable): ?string
    {
        return '/horas?semana='.Week::containing($this->startedOn)->iso();
    }

    public function icon(): ?string
    {
        return 'timer';
    }
}
