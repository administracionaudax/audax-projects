<?php

namespace App\Notifications\People;

use App\Domain\People\Reports\PeopleFormat;
use App\Models\MonthClose;
use App\Notifications\AppNotification;

/**
 * Tu responsable o RR. HH. ha desconfirmado tu mes, con su motivo (D-347 y D-356; W-085).
 */
class MonthCloseReopened extends AppNotification
{
    public function __construct(
        public readonly MonthClose $close,
        public readonly string $by,
    ) {}

    public function kind(): string
    {
        return 'people.month_close_reopened';
    }

    public function title(object $notifiable): string
    {
        return (string) __('people.notifications.close_reopened_title', ['name' => $this->by, 'month' => PeopleFormat::month($this->close->month->toDateString())]);
    }

    public function body(object $notifiable): ?string
    {
        return (string) __('people.notifications.reason', ['reason' => (string) $this->close->reopen_reason]);
    }

    public function url(object $notifiable): ?string
    {
        return '/personas/jornada?mes='.$this->close->monthKey();
    }

    public function icon(): ?string
    {
        return 'file-pen';
    }
}
