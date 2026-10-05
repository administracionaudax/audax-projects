<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Archivado PERSONAL de una tarea en «Mi espacio» (F-057, D-151): la tarea sigue igual para los
 * demás; solo desaparece de mi lista hasta que la recupero.
 *
 * @property int $id
 * @property int $user_id
 * @property int $task_id
 * @property CarbonImmutable $archived_at
 * @property-read User $user
 * @property-read Task $task
 */
#[Fillable(['user_id', 'task_id', 'archived_at'])]
class TaskArchive extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'archived_at' => 'datetime',
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
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class)->withTrashed();
    }
}
