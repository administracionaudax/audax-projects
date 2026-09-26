<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Dependencia fin-inicio (SPEC §6.1): la sucesora no debería empezar antes de que acabe la
 * predecesora. Es una ayuda, no una restricción rígida (se avisa, no se impide mover).
 *
 * @property int $id
 * @property int $predecessor_task_id
 * @property int $successor_task_id
 * @property string $type
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Task $predecessor
 * @property-read Task $successor
 */
#[Fillable(['predecessor_task_id', 'successor_task_id', 'type', 'created_by'])]
class TaskDependency extends Model
{
    public const string FINISH_TO_START = 'finish_to_start';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'type' => self::FINISH_TO_START,
    ];

    /**
     * @return BelongsTo<Task, $this>
     */
    public function predecessor(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'predecessor_task_id');
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function successor(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'successor_task_id');
    }
}
