<?php

namespace App\Http\Resources\Weeklies;

use App\Models\WeeklyCycle;
use Illuminate\Http\Request;

/**
 * Semana con su informe (página del informe, F-072 a F-091). Contrato: weeklies.ts (WeeklyCycleDetail).
 * El informe es WeeklyReport::toArray(); las secciones de audio, si se han cargado.
 *
 * @mixin WeeklyCycle
 */
class WeeklyCycleDetailResource extends WeeklyCycleResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'report' => $this->reportData()?->toArray(),
            'report_text' => $this->report_text,
            'report_error' => $this->report_error,
            'report_edited_at' => $this->report_edited_at?->toIso8601String(),
            'audio_error' => $this->audio_error,
            'has_full_audio' => $this->audio_path !== null,
            'audio_sections' => WeeklyAudioSectionResource::collection($this->whenLoaded('audioSections')),
        ];
    }
}
