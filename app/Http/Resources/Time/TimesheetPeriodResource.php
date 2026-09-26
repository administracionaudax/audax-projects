<?php

namespace App\Http\Resources\Time;

use App\Domain\Time\Week;
use App\Enums\TimesheetStatus;
use App\Http\Resources\UserSummaryResource;
use App\Models\TimesheetPeriod;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Semana de una persona. Contrato: resources/js/types/time.ts (TimesheetPeriodData).
 * Cargar reviewer (y user si se quiere la persona) antes (N+1). Una semana sin fila está abierta:
 * se serializa sin id.
 *
 * @mixin TimesheetPeriod
 */
class TimesheetPeriodResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $week = Week::containing($this->week_start);

        return [
            'id' => $this->exists ? $this->id : null,
            'user_id' => $this->user_id,
            'user' => UserSummaryResource::make($this->whenLoaded('user')),
            'week' => $week->iso(),
            'week_start' => $week->startString(),
            'week_end' => $week->endString(),
            'status' => $this->status->value,
            'submitted_at' => $this->submitted_at?->toIso8601ZuluString(),
            'reviewed_at' => $this->reviewed_at?->toIso8601ZuluString(),
            'reviewer' => $this->when(
                $this->relationLoaded('reviewer'),
                fn () => $this->reviewer === null ? null : UserSummaryResource::make($this->reviewer),
            ),
            'review_comment' => $this->review_comment,
            // Aprobada sin revisor: responsables, admins o sin aprobación obligatoria (D-020).
            'auto_approved' => in_array($this->status, [TimesheetStatus::Approved, TimesheetStatus::Locked], true)
                && $this->reviewed_by === null,
        ];
    }
}
