<?php

namespace App\Http\Resources\Weeklies;

use App\Models\Dictation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Estado de un dictado para «Transcribiendo…» (F-049, D-152). Contrato: weeklies.ts (Dictation).
 *
 * @mixin Dictation
 */
class DictationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'context' => $this->context->value,
            'status' => $this->status->value,
            'client_id' => $this->client_id,
            'task_id' => $this->task_id,
            'text' => $this->text,
            'warning' => $this->warning,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
