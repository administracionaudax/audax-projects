<?php

namespace App\Notifications\People;

use App\Models\ClockCorrection;
use App\Notifications\AppNotification;

/**
 * La otra parte ha aceptado tu corrección del registro (D-335 y D-339; W-113): ya cuenta.
 */
class CorrectionAccepted extends AppNotification
{
    public function __construct(
        public readonly ClockCorrection $correction,
        public readonly string $decider,
    ) {}

    public function kind(): string
    {
        return 'people.correction_accepted';
    }

    public function title(object $notifiable): string
    {
        return (string) __('people.notifications.accepted_title', [
            'name' => $this->decider,
            'date' => $this->correction->date->format('d/m/Y'),
        ]);
    }

    public function url(object $notifiable): ?string
    {
        return $this->correction->proposedBySubject()
            ? '/personas/jornada?dia='.$this->correction->date->toDateString()
            : '/personas/equipo/'.$this->correction->user_id.'?dia='.$this->correction->date->toDateString();
    }

    public function icon(): ?string
    {
        return 'circle-check';
    }
}
