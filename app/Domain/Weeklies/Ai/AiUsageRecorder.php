<?php

namespace App\Domain\Weeklies\Ai;

use App\Enums\AiFeature;
use App\Enums\AiProvider;
use App\Models\AiUsage;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Registro de uso de la IA externa (D-146, F-173): una fila de ai_usage por llamada, con éxito o
 * error. Nunca guarda el texto enviado ni el recibido. Si el registro falla, no rompe la llamada
 * (como recordAiUsageEvent del original), solo lo anota en el log.
 */
final class AiUsageRecorder
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        AiProvider $provider,
        string $model,
        AiFeature $feature,
        bool $success,
        ?string $operation = null,
        ?User $user = null,
        ?Model $subject = null,
        ?int $latencyMs = null,
        ?int $promptTokens = null,
        ?int $responseTokens = null,
        ?int $totalTokens = null,
        ?int $characterCount = null,
        ?string $estimatedCostUsd = null,
        ?string $error = null,
        array $metadata = [],
    ): ?AiUsage {
        try {
            return AiUsage::query()->create([
                'user_id' => $user?->id,
                'provider' => $provider,
                'model' => mb_substr($model, 0, 64),
                'feature' => $feature,
                'operation' => $operation === null ? null : mb_substr($operation, 0, 64),
                'status' => $success ? AiUsage::STATUS_SUCCESS : AiUsage::STATUS_ERROR,
                'latency_ms' => $latencyMs,
                'prompt_tokens' => $promptTokens,
                'response_tokens' => $responseTokens,
                'total_tokens' => $totalTokens,
                'character_count' => $characterCount,
                'estimated_cost_usd' => $estimatedCostUsd,
                'subject_type' => $subject?->getMorphClass(),
                'subject_id' => $subject?->getKey(),
                'error' => $error === null ? null : mb_substr($error, 0, 500),
                'metadata' => $metadata === [] ? null : $metadata,
            ]);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
