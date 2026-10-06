<?php

namespace App\Http\Resources\Forecast;

use App\Models\ForecastProject;
use App\Models\User;
use App\Support\LocalTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * Proyecto previsto (D-281). Contrato: resources/js/types/forecast.ts (ForecastProject). El importe
 * estimado solo llega a quien tiene view-financials (si no, null). `allocated_minutes` es el plan
 * completo de sus asignaciones (ForecastPresenter).
 *
 * @mixin ForecastProject
 */
class ForecastProjectResource extends JsonResource
{
    public function __construct(ForecastProject $forecast, private readonly ?int $allocatedMinutes = null)
    {
        parent::__construct($forecast);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User|null $viewer */
        $viewer = $request->user();
        $financials = $viewer !== null && Gate::forUser($viewer)->allows('view-financials');

        return [
            'id' => $this->id,
            'name' => $this->name,
            'client' => $this->client === null ? null : ['id' => $this->client->id, 'name' => $this->client->name],
            'prospect_name' => $this->prospect_name,
            'client_name' => $this->clientName(),
            'color' => $this->color,
            'description' => $this->description,
            'owner' => ['id' => $this->owner->id, 'name' => $this->owner->name],
            'confidence' => $this->confidence->value,
            'status' => $this->status->value,
            'lost_reason' => $this->lost_reason,
            'lost_at' => $this->lost_at?->toIso8601String(),
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'estimated_minutes' => $this->estimated_minutes,
            'estimated_amount' => $financials ? $this->estimated_amount : null,
            'allocated_minutes' => $this->allocatedMinutes,
            'project' => $this->project === null ? null : ['id' => $this->project->id, 'code' => $this->project->code, 'name' => $this->project->name],
            'linked_at' => $this->linked_at?->toIso8601String(),
            'starts_in_past' => $this->start_date !== null && $this->status->counts() && $this->start_date->toDateString() < LocalTime::todayString(),
            'can' => $viewer === null ? [] : [
                'update' => $viewer->can('update', $this->resource),
                'confirm' => $viewer->can('confirm', $this->resource),
                'lose' => $viewer->can('lose', $this->resource),
                'reopen' => $viewer->can('reopen', $this->resource),
                'delete' => $viewer->can('delete', $this->resource),
                'link' => $viewer->can('link', $this->resource),
                'create_project' => $viewer->can('createProject', $this->resource),
                'unlink' => $viewer->can('unlink', $this->resource),
            ],
        ];
    }
}
