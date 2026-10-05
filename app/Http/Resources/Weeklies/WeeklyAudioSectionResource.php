<?php

namespace App\Http\Resources\Weeklies;

use App\Models\WeeklyAudioSection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

/**
 * Sección de la locución del informe (F-084 a F-086). url: ruta firmada que sirve el MP3 (los
 * ficheros nunca son públicos, SPEC §15). Contrato: weeklies.ts (WeeklyAudioSection).
 *
 * @mixin WeeklyAudioSection
 */
class WeeklyAudioSectionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'kind' => $this->kind->value,
            'client_id' => $this->client_id,
            'position' => $this->position,
            'script' => $this->script,
            'duration_ms' => $this->duration_ms,
            'generated_at' => $this->generated_at?->toIso8601String(),
            'url' => $this->path === null ? null : URL::temporarySignedRoute('weeklies.audio.show', now()->addHours(6), [
                'cycle' => $this->weekly_cycle_id,
                'section' => $this->id,
            ], absolute: false),
        ];
    }
}
