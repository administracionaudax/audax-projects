<?php

namespace App\Notifications\People;

use App\Domain\People\Reports\PeopleFormat;
use App\Models\MonthClose;
use App\Notifications\AppNotification;

/**
 * Tu resumen del mes está listo para confirmar (PLAN-FASE-11 §7.5; D-347 y D-356; W-114): el día 1
 * o cuando se regenera porque cambió el registro del mes. Obligatorio: es la copia de los arts.
 * 12.4.c y 35.5 ET.
 */
class MonthCloseReady extends AppNotification
{
    public function __construct(
        public readonly MonthClose $close,
        public readonly bool $changed = false,
    ) {}

    public function kind(): string
    {
        return 'people.month_close_ready';
    }

    public function title(object $notifiable): string
    {
        $month = PeopleFormat::month($this->close->month->toDateString());

        return $this->changed
            ? (string) __('people.notifications.close_changed_title', ['month' => $month])
            : (string) __('people.notifications.close_ready_title', ['month' => $month]);
    }

    public function body(object $notifiable): ?string
    {
        return (string) __('people.notifications.close_ready_body', [
            'worked' => PeopleFormat::hm($this->close->worked_minutes),
            'expected' => PeopleFormat::hm($this->close->expected_minutes),
        ]);
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
