<?php

namespace App\Notifications\People;

use App\Models\ClockCorrection;
use App\Models\User;
use App\Notifications\AppNotification;

/**
 * Una corrección del registro queda en discrepancia (D-335 y D-339): la otra parte la ha rechazado
 * con un motivo ($decider) o nadie ha contestado en 7 días ($decider null). Cuenta la versión
 * original y constan las dos.
 */
class CorrectionDisputed extends AppNotification
{
    public function __construct(
        public readonly ClockCorrection $correction,
        public readonly ?string $decider,
    ) {}

    public function kind(): string
    {
        return 'people.correction_disputed';
    }

    public function title(object $notifiable): string
    {
        $date = $this->correction->date->format('d/m/Y');

        return $this->decider === null
            ? (string) __('people.notifications.expired_title', ['date' => $date])
            : (string) __('people.notifications.rejected_title', ['name' => $this->decider, 'date' => $date]);
    }

    public function body(object $notifiable): ?string
    {
        return $this->correction->decision_note === null
            ? (string) __('people.notifications.disputed_body')
            : (string) __('people.notifications.reason', ['reason' => $this->correction->decision_note]);
    }

    public function url(object $notifiable): ?string
    {
        $mine = $notifiable instanceof User && $notifiable->id === $this->correction->user_id;

        return $mine
            ? '/personas/jornada?dia='.$this->correction->date->toDateString()
            : '/personas/equipo/'.$this->correction->user_id.'?dia='.$this->correction->date->toDateString();
    }

    public function icon(): ?string
    {
        return 'triangle-alert';
    }
}
