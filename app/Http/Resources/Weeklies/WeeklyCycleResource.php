<?php

namespace App\Http\Resources\Weeklies;

use App\Models\WeeklyCycle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Semana de la weekly en listados (histórico, Mi espacio, tarjeta de Inicio). Contrato:
 * resources/js/types/weeklies.ts (WeeklyCycleSummary). Fechas "Y-m-d"; instantes ISO en UTC.
 *
 * @mixin WeeklyCycle
 */
class WeeklyCycleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'label' => $this->label,
            'start_date' => $this->start_date->toDateString(),
            'end_date' => $this->end_date->toDateString(),
            'deadline_date' => $this->deadline_date->toDateString(),
            'status' => $this->status->value,
            'has_report' => $this->report !== null,
            'report_state' => $this->report_state?->value,
            'report_generated_at' => $this->report_generated_at?->toIso8601String(),
            'audio_state' => $this->audio_state?->value,
            'has_audio' => $this->audio_path !== null,
            'submission_count_at_generation' => $this->submission_count_at_generation,
            'closed_at' => $this->closed_at?->toIso8601String(),
            'submissions_count' => $this->whenCounted('submissions'),
        ];
    }
}
