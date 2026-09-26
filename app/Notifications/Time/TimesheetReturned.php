<?php

namespace App\Notifications\Time;

use App\Domain\Time\Messages;
use App\Domain\Time\Week;
use App\Notifications\AppNotification;
use Carbon\CarbonImmutable;

/**
 * «Te han devuelto las horas de la semana del …» con el comentario del responsable (SPEC §13,
 * D-020), solo en la app.
 */
class TimesheetReturned extends AppNotification
{
    public function __construct(
        public readonly string $weekStart,
        public readonly string $reviewerName,
        public readonly string $comment,
    ) {}

    public function kind(): string
    {
        return 'time.returned';
    }

    public function title(object $notifiable): string
    {
        return Messages::get('time.notifications.returned_title', ['week' => CarbonImmutable::parse($this->weekStart)->format('d/m/Y')]);
    }

    public function body(object $notifiable): ?string
    {
        return Messages::get('time.notifications.returned_body', ['reviewer' => $this->reviewerName, 'comment' => $this->comment]);
    }

    public function url(object $notifiable): ?string
    {
        return '/horas?semana='.Week::containing($this->weekStart)->iso();
    }

    public function icon(): ?string
    {
        return 'undo-2';
    }
}
