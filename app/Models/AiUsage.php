<?php

namespace App\Models;

use App\Enums\AiFeature;
use App\Enums\AiProvider;
use Carbon\CarbonImmutable;
use Database\Factories\AiUsageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Una llamada a la IA externa (D-146, F-173): la escribe App\Domain\Weeklies\Ai\AiUsageRecorder
 * (GeminiClient y GoogleTtsSynthesizer). Nunca guarda el texto enviado ni el recibido.
 *
 * @property int $id
 * @property int|null $user_id
 * @property AiProvider $provider
 * @property string $model
 * @property AiFeature $feature
 * @property string|null $operation
 * @property string $status success|error
 * @property int|null $latency_ms
 * @property int|null $prompt_tokens
 * @property int|null $response_tokens
 * @property int|null $total_tokens
 * @property int|null $character_count
 * @property string|null $estimated_cost_usd
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property string|null $error
 * @property array<string, mixed>|null $metadata
 * @property CarbonImmutable|null $created_at
 * @property-read User|null $user
 * @property-read Model|null $subject
 */
#[Fillable(['user_id', 'provider', 'model', 'feature', 'operation', 'status', 'latency_ms', 'prompt_tokens', 'response_tokens', 'total_tokens', 'character_count', 'estimated_cost_usd', 'subject_type', 'subject_id', 'error', 'metadata'])]
class AiUsage extends Model
{
    /** @use HasFactory<AiUsageFactory> */
    use HasFactory;

    public const string STATUS_SUCCESS = 'success';

    public const string STATUS_ERROR = 'error';

    public const UPDATED_AT = null;

    protected $table = 'ai_usage';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => AiProvider::class,
            'feature' => AiFeature::class,
            'latency_ms' => 'integer',
            'prompt_tokens' => 'integer',
            'response_tokens' => 'integer',
            'total_tokens' => 'integer',
            'character_count' => 'integer',
            'estimated_cost_usd' => 'decimal:6',
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
