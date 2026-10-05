<?php

namespace App\Http\Resources\Weeklies;

use App\Http\Resources\UserSummaryResource;
use App\Models\WeeklySubmission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Weekly de una persona (borrador o enviada). Contrato: weeklies.ts (WeeklySubmission).
 *
 * @mixin WeeklySubmission
 */
class WeeklySubmissionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'weekly_cycle_id' => $this->weekly_cycle_id,
            'user_id' => $this->user_id,
            'user' => UserSummaryResource::make($this->whenLoaded('user')),
            'is_submitted' => $this->isSubmitted(),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'resubmitted_at' => $this->resubmitted_at?->toIso8601String(),
            'draft_saved_at' => $this->draft_saved_at?->toIso8601String(),
            'entries' => WeeklyEntryResource::collection($this->whenLoaded('entries')),
        ];
    }
}
