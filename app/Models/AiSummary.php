<?php

namespace App\Models;

use App\Enums\AiSummaryKind;
use App\Enums\WeeklyJobState;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * El último resumen con IA de un tipo para un cliente o una persona (entrega 10.4, D-194). Lo escribe
 * App\Domain\Weeklies\Insights\AiSummaries (pedirlo) y el Job GenerateAiSummary (el resultado). Se
 * guarda hasta que alguien lo regenera; quien lo ve lo decide el controlador (D-147).
 *
 * @property int $id
 * @property AiSummaryKind $kind
 * @property string $subject_type
 * @property int $subject_id
 * @property WeeklyJobState $state
 * @property string|null $content Markdown (resumen del cliente y desempeño)
 * @property array<string, string>|null $items id → frase (actividad del equipo y por cliente)
 * @property string|null $error
 * @property string|null $model
 * @property int|null $requested_by
 * @property CarbonImmutable|null $generated_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Model|null $subject
 * @property-read User|null $requester
 */
#[Fillable(['kind', 'subject_type', 'subject_id', 'state', 'content', 'items', 'error', 'model', 'requested_by', 'generated_at'])]
class AiSummary extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => AiSummaryKind::class,
            'state' => WeeklyJobState::class,
            'items' => 'array',
            'generated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
