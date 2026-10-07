<?php

namespace App\Notifications\Absences;

use App\Domain\Absences\AbsenceText;

/**
 * Segundo nivel de aprobación (Fase 11, R3; W-067; D-364): a RR. HH. cuando el responsable ya ha
 * dado el primero (o cuando lo pide un responsable, que pasa el primero solo).
 */
class AbsenceSecondApprovalNotification extends AbsenceNotification
{
    public function kind(): string
    {
        return 'absence.second_approval';
    }

    public function title(object $notifiable): string
    {
        return AbsenceText::get('leave.notifications.second_approval.title', $this->replacements());
    }

    public function body(object $notifiable): ?string
    {
        return AbsenceText::get(
            $this->actorName === $this->ownerName ? 'leave.notifications.second_approval.body_self' : 'leave.notifications.second_approval.body',
            $this->replacements(),
        );
    }

    public function icon(): string
    {
        return 'calendar-check';
    }

    protected function forApprover(): bool
    {
        return true;
    }
}
