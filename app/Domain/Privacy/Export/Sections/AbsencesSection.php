<?php

namespace App\Domain\Privacy\Export\Sections;

use App\Models\Absence;
use App\Models\User;

/**
 * Ausencias: tipo, fechas, estado, notas y la respuesta de quien las revisa (D-049).
 */
final class AbsencesSection extends Section
{
    public function key(): string
    {
        return 'ausencias';
    }

    protected function textKey(): string
    {
        return 'absences';
    }

    protected function columnKeys(): array
    {
        return ['id', 'type', 'start_date', 'end_date', 'partial_minutes', 'status', 'notes', 'review_comment', 'reviewed_at', 'created_at'];
    }

    public function rows(User $user): iterable
    {
        $absences = Absence::query()
            ->where('user_id', $user->id)
            ->orderBy('start_date')
            ->orderBy('id')
            ->get();

        foreach ($absences as $absence) {
            yield [
                'id' => $absence->id,
                'type' => $absence->type->label(),
                'start_date' => self::date($absence->start_date),
                'end_date' => self::date($absence->end_date),
                'partial_minutes' => $absence->partial_minutes,
                'status' => $absence->status->label(),
                'notes' => $absence->notes,
                'review_comment' => $absence->review_comment,
                'reviewed_at' => self::instant($absence->reviewed_at),
                'created_at' => self::instant($absence->created_at),
            ];
        }
    }
}
