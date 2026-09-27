<?php

namespace App\Notifications\Absences;

use App\Domain\Absences\AbsenceText;
use App\Models\Absence;
use App\Models\User;

/**
 * «Ausencia no aprobada: Vacaciones del … al …» con el comentario de quien la revisa, a la persona
 * (D-049: el rechazo siempre lleva comentario).
 */
class AbsenceRejectedNotification extends AbsenceNotification
{
    public string $comment;

    public function __construct(Absence $absence, User $actor, string $comment)
    {
        parent::__construct($absence, $actor);

        $this->comment = $comment;
    }

    public function kind(): string
    {
        return 'absence.rejected';
    }

    public function title(object $notifiable): string
    {
        return AbsenceText::get('absences.notifications.rejected.title', $this->replacements());
    }

    public function body(object $notifiable): ?string
    {
        return AbsenceText::get('absences.notifications.rejected.body', [...$this->replacements(), 'comment' => $this->comment]);
    }

    public function icon(): string
    {
        return 'calendar-x';
    }
}
