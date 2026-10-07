<?php

namespace App\Notifications\People;

/**
 * «¿Sigues trabajando? No has fichado la salida» (W-111). Ver ClockReminder.
 */
class ClockOutReminder extends ClockReminder
{
    public function type(): string
    {
        return self::CLOCK_OUT;
    }

    public function kind(): string
    {
        return 'people.clock_out_missing';
    }
}
