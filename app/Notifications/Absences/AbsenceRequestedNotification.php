<?php

namespace App\Notifications\Absences;

use App\Domain\Absences\AbsenceText;
use App\Models\Absence;
use App\Models\User;

/**
 * «Elena ha solicitado vacaciones del … al …», a quien puede aprobarla: los responsables de su
 * departamento o, si no hay, los admins (D-049, AbsenceApprovers).
 */
class AbsenceRequestedNotification extends AbsenceNotification
{
    public ?string $notes;

    public function __construct(Absence $absence, User $actor)
    {
        parent::__construct($absence, $actor);

        $this->notes = $absence->notes;
    }

    public function kind(): string
    {
        return 'absence.requested';
    }

    public function title(object $notifiable): string
    {
        return AbsenceText::get('absences.notifications.requested.title', $this->replacements());
    }

    public function body(object $notifiable): ?string
    {
        return $this->notes !== null && $this->notes !== ''
            ? '«'.$this->notes.'»'
            : AbsenceText::get('absences.notifications.requested.hint');
    }

    public function icon(): string
    {
        return 'calendar-plus';
    }

    protected function forApprover(): bool
    {
        return true;
    }
}
