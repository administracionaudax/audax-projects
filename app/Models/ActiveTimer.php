<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Temporizador activo (SPEC §4.4 y §7): como máximo uno por usuario (clave primaria user_id).
 * Lo gestiona App\Domain\Time\TimerService.
 *
 * @property int $user_id
 * @property int $task_id
 * @property CarbonImmutable $started_at
 * @property string|null $description
 * @property int|null $day_plan_item_id Línea del plan del día desde la que se arrancó (D-254)
 * @property CarbonImmutable|null $warned_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $user
 * @property-read Task $task
 * @property-read DayPlanItem|null $dayPlanItem
 */
#[Fillable(['user_id', 'task_id', 'started_at', 'description', 'warned_at', 'day_plan_item_id'])]
class ActiveTimer extends Model
{
    protected $primaryKey = 'user_id';

    public $incrementing = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'warned_at' => 'datetime',
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

    /**
     * @return BelongsTo<DayPlanItem, $this>
     */
    public function dayPlanItem(): BelongsTo
    {
        return $this->belongsTo(DayPlanItem::class)->withTrashed();
    }

    /**
     * Segundos transcurridos hasta ahora.
     */
    public function elapsedSeconds(): int
    {
        return max((int) $this->started_at->diffInSeconds(now(), true), 0);
    }
}
