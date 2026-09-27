<?php

namespace App\Notifications\Absences;

use App\Domain\Absences\AbsenceText;
use App\Models\Absence;
use App\Models\User;

/**
 * Una ausencia aprobada deja de valer (D-049):
 * - quien la aprueba la anula → se avisa a la persona,
 * - la persona cancela una aprobada que aún no ha empezado → se avisa a quien la aprobó.
 * Retirar una solicitud pendiente no avisa a nadie: desaparece de «pendientes de aprobar».
 */
class AbsenceCancelledNotification extends AbsenceNotification
{
    public bool $byOwner;

    public function __construct(Absence $absence, User $actor, bool $byOwner)
    {
        parent::__construct($absence, $actor);

        $this->byOwner = $byOwner;
    }

    public function kind(): string
    {
        return 'absence.cancelled';
    }

    public function title(object $notifiable): string
    {
        return AbsenceText::get($this->byOwner ? 'absences.notifications.withdrawn.title' : 'absences.notifications.annulled.title', $this->replacements());
    }

    public function body(object $notifiable): ?string
    {
        return AbsenceText::get($this->byOwner ? 'absences.notifications.withdrawn.body' : 'absences.notifications.annulled.body', $this->replacements());
    }

    public function icon(): string
    {
        return 'calendar-off';
    }

    protected function forApprover(): bool
    {
        return $this->byOwner;
    }
}
