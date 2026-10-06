<?php

namespace App\Notifications\DayPlan;

use App\Notifications\AppNotification;

/**
 * «Aún no has escrito tu plan de hoy» (docs/PLAN-CARGAS.md §9, P2; D-252): a la hora límite (08:30)
 * a quien no tiene ninguna línea en un día con jornada, o cuando su responsable se lo recuerda
 * (`$by`). Lo envía App\Domain\DayPlan\DayPlanReminders, una sola vez por persona y día.
 */
class DayPlanReminder extends AppNotification
{
    public function __construct(
        public readonly string $date,
        public readonly ?string $by = null,
    ) {}

    public function kind(): string
    {
        return 'day_plan.reminder';
    }

    public function title(object $notifiable): string
    {
        return $this->by === null
            ? (string) __('day_plan.notifications.reminder_title')
            : (string) __('day_plan.notifications.reminder_by_title', ['name' => $this->by]);
    }

    public function body(object $notifiable): ?string
    {
        return (string) __('day_plan.notifications.reminder_body');
    }

    public function url(object $notifiable): ?string
    {
        return '/dia';
    }

    public function icon(): ?string
    {
        return 'list-checks';
    }
}
