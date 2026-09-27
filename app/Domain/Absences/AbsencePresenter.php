<?php

namespace App\Domain\Absences;

use App\Enums\AbsenceStatus;
use App\Models\Absence;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Una ausencia como la recibe la interfaz (resources/js/components/absences/types.ts, AbsenceRow).
 * Fechas locales Y-m-d, instantes ISO en UTC y horas en minutos. Los textos (tipo y estado) los
 * pone el frontend con t().
 */
final class AbsencePresenter
{
    /**
     * @param  array<int, int>  $workingDays  id → días laborables (AbsenceDays)
     * @return array<string, mixed>
     */
    public static function row(Absence $absence, User $viewer, array $workingDays = [], bool $withUser = false): array
    {
        $reviewer = $absence->relationLoaded('approver') ? $absence->approver : null;
        $gate = Gate::forUser($viewer);

        $row = [
            'id' => $absence->id,
            'user_id' => $absence->user_id,
            'type' => $absence->type->value,
            'status' => $absence->status->value,
            'start_date' => $absence->start_date->toDateString(),
            'end_date' => $absence->end_date->toDateString(),
            'partial_minutes' => $absence->partial_minutes,
            'working_days' => $workingDays[$absence->id] ?? null,
            'notes' => $absence->notes,
            'review_comment' => $absence->review_comment,
            'reviewed_at' => $absence->reviewed_at?->toIso8601ZuluString(),
            'reviewer' => $reviewer !== null ? ['id' => $reviewer->id, 'name' => $reviewer->name] : null,
            'auto_approved' => $absence->status === AbsenceStatus::Approved && $absence->approved_by === null,
            'can' => [
                'cancel' => $gate->allows('cancel', $absence),
                'review' => $absence->status === AbsenceStatus::Requested && $gate->allows('review', $absence),
                'update' => $absence->status === AbsenceStatus::Approved && $gate->allows('update', $absence),
            ],
        ];

        if ($withUser && $absence->relationLoaded('user')) {
            $department = $absence->user->relationLoaded('department') ? $absence->user->department : null;
            $row['user'] = [
                'id' => $absence->user->id,
                'name' => $absence->user->name,
                'department' => $department !== null ? ['id' => $department->id, 'name' => $department->name, 'color' => $department->color] : null,
            ];
        }

        return $row;
    }
}
