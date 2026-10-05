<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ClientSatisfactionSnapshotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satisfacción de un cliente al cerrar una semana (F-093, F-094 y F-132): la nueva puntuación, la
 * anterior, el delta que pidió Gemini y el que deja App\Domain\Weeklies\SatisfactionStabilizer, con
 * la regla aplicada. La actual también está en clients.satisfaction_score.
 *
 * @property int $id
 * @property int $client_id
 * @property int $weekly_cycle_id
 * @property int $score
 * @property int|null $previous_score
 * @property int|null $requested_delta
 * @property int $delta
 * @property string|null $rule
 * @property string|null $reasoning
 * @property string|null $evidence_level
 * @property bool|null $explicit_client_impact
 * @property string|null $confidence
 * @property array<string, mixed>|null $metrics
 * @property string|null $model
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Client $client
 * @property-read WeeklyCycle $cycle
 */
#[Fillable(['client_id', 'weekly_cycle_id', 'score', 'previous_score', 'requested_delta', 'delta', 'rule', 'reasoning', 'evidence_level', 'explicit_client_impact', 'confidence', 'metrics', 'model'])]
class ClientSatisfactionSnapshot extends Model
{
    /** @use HasFactory<ClientSatisfactionSnapshotFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'previous_score' => 'integer',
            'requested_delta' => 'integer',
            'delta' => 'integer',
            'explicit_client_impact' => 'boolean',
            'confidence' => 'decimal:3',
            'metrics' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    /**
     * @return BelongsTo<WeeklyCycle, $this>
     */
    public function cycle(): BelongsTo
    {
        return $this->belongsTo(WeeklyCycle::class, 'weekly_cycle_id');
    }
}
