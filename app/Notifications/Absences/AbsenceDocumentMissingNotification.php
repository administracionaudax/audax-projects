<?php

namespace App\Notifications\Absences;

use App\Domain\Absences\AbsenceText;

/**
 * Justificante pendiente (Fase 11, R3; W-071; D-369): a la persona, una vez, cuando una ausencia
 * aprobada de un tipo que lo pide ya ha empezado y aún no tiene ninguno (`people:leave-daily`).
 */
class AbsenceDocumentMissingNotification extends AbsenceNotification
{
    public function kind(): string
    {
        return 'absence.document_missing';
    }

    public function title(object $notifiable): string
    {
        return AbsenceText::get('leave.notifications.document_missing.title', $this->replacements());
    }

    public function body(object $notifiable): ?string
    {
        return AbsenceText::get('leave.notifications.document_missing.body');
    }

    public function url(object $notifiable): ?string
    {
        return route('absences.index', absolute: false).'?ausencia='.$this->absenceId;
    }

    public function icon(): string
    {
        return 'paperclip';
    }
}
