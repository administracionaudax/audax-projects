<?php

namespace App\Domain\Weeklies\Ai;

/**
 * Respuesta de LlmClient. $json, decodificado, solo si se pidió JSON.
 */
final readonly class LlmResponse
{
    /**
     * @param  array<array-key, mixed>|null  $json
     */
    public function __construct(
        public string $text,
        public ?array $json,
        public string $model,
        public ?int $promptTokens = null,
        public ?int $responseTokens = null,
        public ?int $totalTokens = null,
        public ?int $latencyMs = null,
        public ?string $finishReason = null,
    ) {}
}
