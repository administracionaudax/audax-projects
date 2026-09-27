<?php

namespace App\Domain\Absences;

use App\Enums\AbsenceStatus;
use App\Models\Absence;
use App\Models\User;
use App\Support\LocalTime;

/**
 * Tarjeta «Mis ausencias» de Inicio (SPEC §5.1): las próximas aprobadas (también la que está en
 * curso) y las solicitudes pendientes de quien mira. Una sola consulta.
 */
final class MyAbsencesSummary
{
    public const int LIMIT = 4;

    /**
     * @return array{upcoming: list<array{id: int, type: string, status: string, start_date: string, end_date: string, partial_minutes: int|null}>, pending: list<array{id: int, type: string, status: string, start_date: string, end_date: string, partial_minutes: int|null}>}
     */
    public function for(User $user): array
    {
        $absences = Absence::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [AbsenceStatus::Requested->value, AbsenceStatus::Approved->value])
            ->where('end_date', '>=', LocalTime::todayString())
            ->orderBy('start_date')
            ->limit(self::LIMIT * 5)
            ->get(['id', 'type', 'status', 'start_date', 'end_date', 'partial_minutes']);

        $summary = ['upcoming' => [], 'pending' => []];

        foreach ($absences as $absence) {
            $group = $absence->status === AbsenceStatus::Approved ? 'upcoming' : 'pending';

            if (count($summary[$group]) >= self::LIMIT) {
                continue;
            }

            $summary[$group][] = [
                'id' => $absence->id,
                'type' => $absence->type->value,
                'status' => $absence->status->value,
                'start_date' => $absence->start_date->toDateString(),
                'end_date' => $absence->end_date->toDateString(),
                'partial_minutes' => $absence->partial_minutes,
            ];
        }

        return $summary;
    }
}
