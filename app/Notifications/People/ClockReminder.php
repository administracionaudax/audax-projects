<?php

namespace App\Notifications\People;

use App\Notifications\AppNotification;
use Carbon\CarbonImmutable;

/**
 * Recordatorios del registro de jornada (D-339; W-110 a W-112): no has fichado la entrada, no has
 * fichado la salida o un día se quedó sin cerrar. Los envía App\Domain\People\ClockReminders, una
 * sola vez por persona, día y tipo. Nunca fichan por la persona: solo le recuerdan que lo haga.
 */
class ClockReminder extends AppNotification
{
    public const string CLOCK_IN = 'clock_in';

    public const string CLOCK_OUT = 'clock_out';

    public const string UNCLOSED = 'unclosed';

    public function __construct(
        public readonly string $type,
        public readonly string $date,
    ) {}

    public function kind(): string
    {
        return match ($this->type) {
            self::CLOCK_IN => 'people.clock_in_missing',
            self::CLOCK_OUT => 'people.clock_out_missing',
            default => 'people.workday_unclosed',
        };
    }

    public function title(object $notifiable): string
    {
        return (string) __("people.notifications.{$this->type}_title", ['date' => CarbonImmutable::parse($this->date)->format('d/m/Y')]);
    }

    public function body(object $notifiable): ?string
    {
        return (string) __("people.notifications.{$this->type}_body");
    }

    public function url(object $notifiable): ?string
    {
        return $this->type === self::UNCLOSED ? '/personas/jornada?dia='.$this->date : '/personas/jornada';
    }

    public function icon(): ?string
    {
        return 'clock';
    }
}
