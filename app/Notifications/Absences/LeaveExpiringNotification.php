<?php

namespace App\Notifications\Absences;

use App\Domain\Absences\LeaveFormat;
use App\Enums\LeaveUnit;
use App\Notifications\AppNotification;
use Carbon\CarbonImmutable;

/**
 * Saldo a punto de caducar (Fase 11, R3; W-061 y W-062; D-369): a la persona, una vez por
 * asignación, 30 días antes de que caduque lo que le queda sin gastar (`people:leave-daily`).
 */
class LeaveExpiringNotification extends AppNotification
{
    public function __construct(
        public readonly string $typeName,
        public readonly string $unit,
        public readonly int $amount,
        public readonly string $expiresOn,
    ) {}

    public function kind(): string
    {
        return 'absence.balance_expiring';
    }

    public function title(object $notifiable): string
    {
        return (string) __('leave.notifications.expiring.title', [
            'amount' => LeaveFormat::amount($this->amount, LeaveUnit::from($this->unit)),
            'type' => $this->typeName,
            'date' => CarbonImmutable::parse($this->expiresOn)->format('d/m/Y'),
        ]);
    }

    public function body(object $notifiable): ?string
    {
        return (string) __('leave.notifications.expiring.body');
    }

    public function url(object $notifiable): ?string
    {
        return route('absences.index', absolute: false);
    }

    public function icon(): ?string
    {
        return 'hourglass';
    }
}
