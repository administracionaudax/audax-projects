<?php

namespace App\Http\Resources\Forecast;

use App\Models\Allocation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Asignación (D-282). Contrato: resources/js/types/forecast.ts (Allocation). Las cifras del plan
 * (`figures`) las calcula ForecastPresenter y llegan en el constructor.
 *
 * @mixin Allocation
 */
class AllocationResource extends JsonResource
{
    /**
     * @param  array<string, mixed>  $figures
     * @param  array{update: bool, assign: bool}  $can
     */
    public function __construct(Allocation $allocation, private readonly array $figures = [], private readonly array $can = ['update' => false, 'assign' => false])
    {
        parent::__construct($allocation);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'forecast_project_id' => $this->forecast_project_id,
            'project_id' => $this->project_id,
            'user' => $this->user === null ? null : ['id' => $this->user->id, 'name' => $this->user->name, 'department_id' => $this->user->department_id],
            'department' => $this->department === null ? null : ['id' => $this->department->id, 'name' => $this->department->name, 'color' => $this->department->color],
            'is_gap' => $this->isGap(),
            'mode' => $this->mode->value,
            'minutes' => $this->minutes,
            'percent' => $this->percent,
            'start_date' => $this->start_date->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'note' => $this->note,
            'copied_from_allocation_id' => $this->copied_from_allocation_id,
            'planned_minutes' => (int) ($this->figures['planned_minutes'] ?? 0),
            'months' => (object) ($this->figures['months'] ?? []),
            'logged_minutes' => $this->figures['logged_minutes'] ?? null,
            'remaining_minutes' => $this->figures['remaining_minutes'] ?? null,
            'planned_to_date_minutes' => $this->figures['planned_to_date_minutes'] ?? null,
            'overdue' => (bool) ($this->figures['overdue'] ?? false),
            'unscheduled' => (bool) ($this->figures['unscheduled'] ?? false),
            'can' => $this->can,
        ];
    }
}
