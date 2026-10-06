<?php

namespace App\Models;

use App\Enums\DayPlanItemOrigin;
use App\Enums\DayPlanItemStatus;
use App\Models\Concerns\LogsDomainActivity;
use Carbon\CarbonImmutable;
use Database\Factories\DayPlanItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Una línea del plan del día (docs/PLAN-CARGAS.md §4.1 y §7.1, D-250): una intención en texto libre,
 * con cliente, proyecto, tarea y horas previstas opcionales. Se escribe SIEMPRE con
 * App\Domain\DayPlan\DayPlanWriter. `user_id` y `date` van desnormalizados (consultas del equipo).
 * Las horas imputadas de la línea son las entradas con `time_entries.day_plan_item_id` (D-254).
 *
 * @property int $id
 * @property int $day_plan_id
 * @property int $user_id
 * @property CarbonImmutable $date
 * @property int $position
 * @property string $text
 * @property int|null $client_id
 * @property int|null $project_id
 * @property int|null $task_id
 * @property int|null $planned_minutes
 * @property DayPlanItemStatus $status
 * @property CarbonImmutable|null $status_changed_at
 * @property string|null $not_done_reason
 * @property int|null $carried_from_id
 * @property int $carry_count
 * @property DayPlanItemOrigin $origin
 * @property int|null $created_by
 * @property CarbonImmutable|null $deleted_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read DayPlan $plan
 * @property-read User $user
 * @property-read Client|null $client
 * @property-read Project|null $project
 * @property-read Task|null $task
 * @property-read DayPlanItem|null $carriedFrom
 */
#[Fillable([
    'day_plan_id',
    'user_id',
    'date',
    'position',
    'text',
    'client_id',
    'project_id',
    'task_id',
    'planned_minutes',
    'status',
    'status_changed_at',
    'not_done_reason',
    'carried_from_id',
    'carry_count',
    'origin',
    'created_by',
])]
class DayPlanItem extends Model
{
    /** @use HasFactory<DayPlanItemFactory> */
    use HasFactory, LogsDomainActivity, SoftDeletes;

    /** Caracteres del texto de una línea y del motivo de «no hecha». */
    public const int TEXT_MAX = 200;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
        'origin' => 'manual',
        'carry_count' => 0,
        'position' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'position' => 'integer',
            'planned_minutes' => 'integer',
            'carry_count' => 'integer',
            'status' => DayPlanItemStatus::class,
            'origin' => DayPlanItemOrigin::class,
            'status_changed_at' => 'datetime',
        ];
    }

    /** Reordenar no es un cambio que interese en la auditoría. */
    protected static function activityExcept(): array
    {
        return ['position', 'status_changed_at'];
    }

    /**
     * @return BelongsTo<DayPlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(DayPlan::class, 'day_plan_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withTrashed();
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
    public function carriedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'carried_from_id')->withTrashed();
    }

    /**
     * @return HasMany<DayPlanComment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(DayPlanComment::class)->orderBy('id');
    }

    /**
     * @return HasMany<TimeEntry, $this>
     */
    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }
}
