<?php

namespace App\Notifications\People;

/**
 * «El día se quedó sin cerrar en el registro» (W-112): a la mañana siguiente, si faltó la salida o
 * no hubo ningún fichaje, para que la persona proponga la corrección. Ver ClockReminder.
 */
class WorkdayUnclosedReminder extends ClockReminder
{
    public function type(): string
    {
        return self::UNCLOSED;
    }

    public function kind(): string
    {
        return 'people.workday_unclosed';
    }

    public function url(object $notifiable): ?string
    {
        return '/personas/jornada?dia='.$this->date;
    }
}
