<?php

namespace App\Domain\Weeklies\Ai;

use App\Enums\AiFeature;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Texto que se locuta (un guion de sección, F-084). $user y $subject, solo para ai_usage.
 */
final readonly class SpeechRequest
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $text,
        public ?User $user = null,
        public ?Model $subject = null,
        public AiFeature $feature = AiFeature::Speech,
        public string $operation = 'synthesize',
        public array $metadata = [],
    ) {}
}
