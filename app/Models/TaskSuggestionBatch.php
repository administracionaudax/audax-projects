<?php

namespace App\Models;

use App\Enums\WeeklyJobState;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * La última tanda de tareas sugeridas por IA de una persona (F-062, D-204). Cada propuesta de `items`:
 * `{key, title, client_id, client_name, project_id, hour_bank_id, author_id, author_name}`; el
 * proyecto y la bolsa son la sugerencia, que la persona revisa antes de crear la tarea. La escribe
 * App\Domain\Weeklies\Tasks\TaskSuggester.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $weekly_cycle_id
 * @property WeeklyJobState $state
 * @property list<array<string, mixed>>|null $items
 * @property int $skipped duplicadas omitidas al generar
 * @property string|null $error
 * @property string|null $model
 * @property CarbonImmutable|null $generated_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $user
 * @property-read WeeklyCycle|null $cycle
 */
#[Fillable(['user_id', 'weekly_cycle_id', 'state', 'items', 'skipped', 'error', 'model', 'generated_at'])]
class TaskSuggestionBatch extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => WeeklyJobState::class,
            'items' => 'array',
            'skipped' => 'integer',
            'generated_at' => 'immutable_datetime',
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
     * @return BelongsTo<WeeklyCycle, $this>
     */
    public function cycle(): BelongsTo
    {
        return $this->belongsTo(WeeklyCycle::class, 'weekly_cycle_id');
    }
}
