<?php

namespace App\Notifications\Absences;

use App\Domain\Absences\AbsenceText;
use App\Models\Absence;
use App\Models\User;

/**
 * «Pedir cancelación» (Fase 11, R3; W-069; D-365): la persona pide cancelar una ausencia aprobada
 * que ya ha empezado; se avisa a quien aprueba sus ausencias.
 */
class AbsenceCancellationRequestedNotification extends AbsenceNotification
{
    public ?string $reason;

    public function __construct(Absence $absence, User $actor)
    {
        parent::__construct($absence, $actor);

        $this->reason = $absence->cancellation_reason;
    }

    public function kind(): string
    {
        return 'absence.cancellation_requested';
    }

    public function title(object $notifiable): string
    {
        return AbsenceText::get('leave.notifications.cancellation_requested.title', $this->replacements());
    }

    public function body(object $notifiable): ?string
    {
        return $this->reason !== null ? '«'.$this->reason.'»' : null;
    }

    public function icon(): string
    {
        return 'calendar-x';
    }

    protected function forApprover(): bool
    {
        return true;
    }
}
