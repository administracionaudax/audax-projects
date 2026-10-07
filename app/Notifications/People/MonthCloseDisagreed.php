<?php

namespace App\Notifications\People;

use App\Domain\People\Reports\PeopleFormat;
use App\Models\MonthClose;
use App\Notifications\AppNotification;

/**
 * Una persona no está de acuerdo con su resumen del mes (D-347 y D-356): a su responsable y a
 * RR. HH., con su motivo, para hablarlo y, si hace falta, corregir.
 */
class MonthCloseDisagreed extends AppNotification
{
    public function __construct(
        public readonly MonthClose $close,
        public readonly string $person,
    ) {}

    public function kind(): string
    {
        return 'people.month_close_disagreed';
    }

    public function title(object $notifiable): string
    {
        return (string) __('people.notifications.close_disagreed_title', ['name' => $this->person, 'month' => PeopleFormat::month($this->close->month->toDateString())]);
    }

    public function body(object $notifiable): ?string
    {
        return (string) __('people.notifications.reason', ['reason' => (string) $this->close->disagreement_note]);
    }

    public function url(object $notifiable): ?string
    {
        return '/personas/cierres?mes='.$this->close->monthKey();
    }

    public function icon(): ?string
    {
        return 'message-square-warning';
    }
}
