<?php

namespace App\Notifications\Time;

use App\Domain\Time\Messages;
use App\Domain\Time\Week;
use App\Notifications\AppNotification;
use Carbon\CarbonImmutable;

/**
 * «Tus horas de la semana del … están aprobadas» (SPEC §13), solo en la app. No se envía en la
 * aprobación automática (responsables, admins o sin aprobación obligatoria).
 */
class TimesheetApproved extends AppNotification
{
    public function __construct(
        public readonly string $weekStart,
        public readonly string $reviewerName,
    ) {}

    public function kind(): string
    {
        return 'time.approved';
    }

    public function title(object $notifiable): string
    {
        return Messages::get('time.notifications.approved_title', ['week' => CarbonImmutable::parse($this->weekStart)->format('d/m/Y')]);
    }

    public function body(object $notifiable): ?string
    {
        return Messages::get('time.notifications.approved_body', ['reviewer' => $this->reviewerName]);
    }

    public function url(object $notifiable): ?string
    {
        return '/horas?semana='.Week::containing($this->weekStart)->iso();
    }

    public function icon(): ?string
    {
        return 'circle-check';
    }
}
