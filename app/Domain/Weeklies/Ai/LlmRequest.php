<?php

namespace App\Domain\Weeklies\Ai;

use App\Enums\AiFeature;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Petición a LlmClient. Con $responseSchema (esquema OpenAPI de Gemini) o $json, la respuesta se pide
 * como JSON (responseMimeType application/json) y llega decodificada en LlmResponse::$json.
 *
 * $user y $subject solo se usan para ai_usage (quién la pidió y sobre qué: una semana, un cliente…).
 * $audio solo lo lleva la transcripción del dictado de la weekly (D-243).
 */
final readonly class LlmRequest
{
    /**
     * @param  array<string, mixed>|null  $responseSchema
     * @param  array<string, mixed>  $metadata  datos para ai_usage (nunca el texto de las weeklies)
     */
    public function __construct(
        public AiFeature $feature,
        public string $prompt,
        public ?string $system = null,
        public ?array $responseSchema = null,
        public bool $json = false,
        public ?float $temperature = null,
        public ?int $maxOutputTokens = null,
        public ?User $user = null,
        public ?Model $subject = null,
        public string $operation = 'generate',
        public array $metadata = [],
        public ?LlmAudio $audio = null,
    ) {}

    public function wantsJson(): bool
    {
        return $this->json || $this->responseSchema !== null;
    }
}
