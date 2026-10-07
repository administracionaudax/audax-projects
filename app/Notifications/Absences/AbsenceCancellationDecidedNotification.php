<?php

namespace App\Notifications\Absences;

use App\Domain\Absences\AbsenceText;
use App\Models\Absence;
use App\Models\User;

/**
 * La cancelación que pidió la persona, aceptada (la ausencia queda cancelada y el saldo vuelve) o
 * rechazada con un comentario (sigue aprobada) (Fase 11, R3; W-069; D-365).
 */
class AbsenceCancellationDecidedNotification extends AbsenceNotification
{
    public bool $accepted;

    public ?string $comment;

    public function __construct(Absence $absence, User $actor, bool $accepted, ?string $comment = null)
    {
        parent::__construct($absence, $actor);

        $this->accepted = $accepted;
        $this->comment = $comment;
    }

    public function kind(): string
    {
        return 'absence.cancellation_decided';
    }

    public function title(object $notifiable): string
    {
        return AbsenceText::get($this->accepted ? 'leave.notifications.cancellation_accepted.title' : 'leave.notifications.cancellation_rejected.title', $this->replacements());
    }

    public function body(object $notifiable): ?string
    {
        if ($this->accepted) {
            return AbsenceText::get('leave.notifications.cancellation_accepted.body', $this->replacements());
        }

        return AbsenceText::get('leave.notifications.cancellation_rejected.body', [...$this->replacements(), 'comment' => (string) $this->comment]);
    }

    public function icon(): string
    {
        return 'calendar-x';
    }
}
