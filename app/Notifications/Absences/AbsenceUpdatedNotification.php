<?php

namespace App\Notifications\Absences;

use App\Domain\Absences\AbsenceText;
use App\Models\Absence;
use App\Models\User;

/**
 * «Raúl ha modificado tu ausencia: Baja del … al …», a la persona, con lo que había antes (D-049):
 * quien la aprueba cambia una aprobada (una baja que termina antes, por ejemplo) sin anularla ni
 * registrar otra, así que llega un solo aviso.
 */
class AbsenceUpdatedNotification extends AbsenceNotification
{
    /** La ausencia antes del cambio: «Baja del 01/09/2026 al 30/09/2026». */
    public string $before;

    public function __construct(Absence $absence, User $actor, string $before)
    {
        parent::__construct($absence, $actor);

        $this->before = $before;
    }

    public function kind(): string
    {
        return 'absence.updated';
    }

    public function title(object $notifiable): string
    {
        return AbsenceText::get('absences.notifications.updated.title', $this->replacements());
    }

    public function body(object $notifiable): ?string
    {
        return AbsenceText::get('absences.notifications.updated.body', [...$this->replacements(), 'before' => $this->before]);
    }

    public function icon(): string
    {
        return 'calendar-clock';
    }
}
