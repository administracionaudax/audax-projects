<?php

namespace App\Notifications\People;

/**
 * «Aún no has fichado la entrada de hoy» (W-110). Ver ClockReminder.
 */
class ClockInReminder extends ClockReminder
{
    public function type(): string
    {
        return self::CLOCK_IN;
    }

    public function kind(): string
    {
        return 'people.clock_in_missing';
    }
}
