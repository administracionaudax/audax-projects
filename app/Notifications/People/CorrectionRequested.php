<?php

namespace App\Notifications\People;

use App\Models\ClockCorrection;
use App\Notifications\AppNotification;

/**
 * Una corrección del registro espera tu conformidad (D-335 y D-339; W-113): a su responsable y a
 * RR. HH. si la propone la persona, o a la persona si la propone su responsable o RR. HH.
 */
class CorrectionRequested extends AppNotification
{
    public function __construct(
        public readonly ClockCorrection $correction,
        public readonly string $proposer,
        public readonly string $subject,
    ) {}

    public function kind(): string
    {
        return 'people.correction_requested';
    }

    public function title(object $notifiable): string
    {
        $date = $this->correction->date->format('d/m/Y');

        return $this->correction->proposedBySubject()
            ? (string) __('people.notifications.requested_title', ['name' => $this->proposer, 'date' => $date])
            : (string) __('people.notifications.requested_for_you_title', ['name' => $this->proposer, 'date' => $date]);
    }

    public function body(object $notifiable): ?string
    {
        return (string) __('people.notifications.reason', ['reason' => $this->correction->reason]);
    }

    public function url(object $notifiable): ?string
    {
        return $this->correction->proposedBySubject()
            ? '/personas/pendientes'
            : '/personas/jornada?dia='.$this->correction->date->toDateString();
    }

    public function icon(): ?string
    {
        return 'clock';
    }
}
