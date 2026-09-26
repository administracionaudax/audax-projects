<?php

namespace App\Http\Resources\Admin;

use App\Models\WorkSchedule;
use App\Support\LocalTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Versión de jornada. Contrato: resources/js/types/admin.ts (AdminWorkSchedule).
 * Solo la última versión (la única sin valid_to) se edita o borra, y solo si aún no ha empezado.
 *
 * @mixin WorkSchedule
 */
class WorkScheduleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $today = LocalTime::today();
        $week = $this->weekMinutes();

        return [
            'id' => $this->id,
            'valid_from' => $this->valid_from->toDateString(),
            'valid_to' => $this->valid_to?->toDateString(),
            'week' => $week,
            'weekly_minutes' => array_sum($week),
            'is_current' => $this->coversDate($today),
            'is_editable' => $this->valid_to === null && $this->valid_from->toDateString() > $today->toDateString(),
        ];
    }
}
