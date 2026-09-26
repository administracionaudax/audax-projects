<?php

namespace App\Http\Resources;

use App\Models\TimeEntry;
use Illuminate\Http\Request;

/**
 * Contrato: resources/js/types/domain.ts (TimeEntry). Cargar user, task y project antes (N+1).
 * Las instantáneas de tarifa y coste solo con view-financials.
 *
 * @mixin TimeEntry
 */
class TimeEntryResource extends FinancialResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $financials = $this->canSeeFinancials($request);

        return [
            'id' => $this->id,
            'user' => UserSummaryResource::make($this->whenLoaded('user')),
            'user_id' => $this->user_id,
            'task' => $this->whenLoaded('task', fn () => [
                'id' => $this->task->id,
                'title' => $this->task->title,
            ]),
            'task_id' => $this->task_id,
            'project' => $this->whenLoaded('project', fn () => [
                'id' => $this->project->id,
                'code' => $this->project->code,
                'name' => $this->project->name,
                'color' => $this->project->color,
            ]),
            'project_id' => $this->project_id,
            'hour_bank_id' => $this->hour_bank_id,
            'date' => $this->date->toDateString(),
            'minutes' => $this->minutes,
            'overage_minutes' => $this->overage_minutes,
            'in_bank_minutes' => $this->in_bank_minutes,
            'started_at' => $this->started_at?->toIso8601ZuluString(),
            'ended_at' => $this->ended_at?->toIso8601ZuluString(),
            'description' => $this->description,
            'is_billable' => $this->is_billable,
            'status' => $this->status->value,
            'approved_at' => $this->approved_at?->toIso8601ZuluString(),
            'created_by' => $this->created_by,
            'logged_on_behalf' => $this->wasLoggedOnBehalf(),
            'hourly_rate_snapshot' => $this->when($financials, $this->hourly_rate_snapshot),
            'hourly_cost_snapshot' => $this->when($financials, $this->hourly_cost_snapshot),
        ];
    }
}
