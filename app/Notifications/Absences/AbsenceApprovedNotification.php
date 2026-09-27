<?php

namespace App\Notifications\Absences;

use App\Domain\Absences\AbsenceText;
use App\Models\Absence;
use App\Models\User;

/**
 * «Ausencia aprobada: Vacaciones del … al …», a la persona (D-049). También cuando un responsable
 * o un admin le registra una ausencia ya aprobada (una baja, por ejemplo), con otro texto.
 */
class AbsenceApprovedNotification extends AbsenceNotification
{
    public bool $registered;

    public function __construct(Absence $absence, User $actor, bool $registered = false)
    {
        parent::__construct($absence, $actor);

        $this->registered = $registered;
    }

    public function kind(): string
    {
        return 'absence.approved';
    }

    public function title(object $notifiable): string
    {
        return AbsenceText::get($this->registered ? 'absences.notifications.registered.title' : 'absences.notifications.approved.title', $this->replacements());
    }

    public function body(object $notifiable): ?string
    {
        return AbsenceText::get($this->registered ? 'absences.notifications.registered.body' : 'absences.notifications.approved.body', $this->replacements());
    }

    public function icon(): string
    {
        return 'calendar-check';
    }
}
