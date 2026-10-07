<?php

namespace App\Notifications\People;

use App\Notifications\AppNotification;
use Carbon\CarbonImmutable;

/**
 * Recordatorios del registro de jornada (D-339; W-110 a W-112): no has fichado la entrada
 * (ClockInReminder), no has fichado la salida (ClockOutReminder) o un día se quedó sin cerrar
 * (WorkdayUnclosedReminder). Los envía App\Domain\People\ClockReminders, una sola vez por persona,
 * día y tipo. Nunca fichan por la persona: solo le recuerdan que lo haga.
 */
abstract class ClockReminder extends AppNotification
{
    public const string CLOCK_IN = 'clock_in';

    public const string CLOCK_OUT = 'clock_out';

    public const string UNCLOSED = 'unclosed';

    public function __construct(public readonly string $date) {}

    /** El aviso de ese tipo (CLOCK_IN, CLOCK_OUT o UNCLOSED) para ese día. */
    public static function make(string $type, string $date): self
    {
        return match ($type) {
            self::CLOCK_IN => new ClockInReminder($date),
            self::CLOCK_OUT => new ClockOutReminder($date),
            default => new WorkdayUnclosedReminder($date),
        };
    }

    /** CLOCK_IN, CLOCK_OUT o UNCLOSED: la clave de sus textos y de `clock_reminders`. */
    abstract public function type(): string;

    public function title(object $notifiable): string
    {
        return (string) __("people.notifications.{$this->type()}_title", ['date' => CarbonImmutable::parse($this->date)->format('d/m/Y')]);
    }

    public function body(object $notifiable): ?string
    {
        return (string) __("people.notifications.{$this->type()}_body");
    }

    public function url(object $notifiable): ?string
    {
        return '/personas/jornada';
    }

    public function icon(): ?string
    {
        return 'clock';
    }
}
