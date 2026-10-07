<?php

namespace App\Notifications\People;

use App\Domain\People\Reports\PeopleFormat;
use App\Models\MonthClose;
use App\Notifications\AppNotification;

/**
 * Recordatorio de confirmar el mes (a los 3 y a los 7 días; D-347 y D-356; W-114).
 */
class MonthCloseReminder extends AppNotification
{
    public function __construct(public readonly MonthClose $close) {}

    public function kind(): string
    {
        return 'people.month_close_reminder';
    }

    public function title(object $notifiable): string
    {
        return (string) __('people.notifications.close_reminder_title', ['month' => PeopleFormat::month($this->close->month->toDateString())]);
    }

    public function url(object $notifiable): ?string
    {
        return '/personas/registro';
    }

    public function icon(): ?string
    {
        return 'file-check';
    }
}
