<?php

namespace App\Notifications\People;

use App\Domain\People\Reports\PeopleFormat;
use App\Models\User;
use App\Notifications\AppNotification;

/**
 * Una persona pasa de 60 h (aviso) o de 80 h (tope legal, art. 35.2 ET) de horas extra en el año
 * (D-349 y D-356): a su responsable y a RR. HH. Obligatorio. Avisa; nunca impide registrar.
 */
class OvertimeCapReached extends AppNotification
{
    public function __construct(
        public readonly User $person,
        public readonly int $minutes,
        public readonly bool $over,
    ) {}

    public function kind(): string
    {
        return 'people.overtime_cap';
    }

    public function title(object $notifiable): string
    {
        return (string) __($this->over ? 'people.notifications.overtime_cap_over_title' : 'people.notifications.overtime_cap_near_title', [
            'name' => $this->person->name,
            'hours' => PeopleFormat::hm($this->minutes),
        ]);
    }

    public function body(object $notifiable): ?string
    {
        return (string) __('people.notifications.overtime_cap_body');
    }

    public function url(object $notifiable): ?string
    {
        return '/personas/horas-extra';
    }

    public function icon(): ?string
    {
        return 'triangle-alert';
    }
}
