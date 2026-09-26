<?php

namespace App\Models;

use App\Enums\TaskPriority;
use App\Enums\TaskStatusCategory;
use App\Models\Concerns\LogsDomainActivity;
use Carbon\CarbonImmutable;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tarea (SPEC §4.3). Cada tarea pertenece a una bolsa si el proyecto usa bolsas (SPEC §8.2).
 * Subtareas de un solo nivel, en el mismo proyecto y la misma bolsa que el padre (D-037).
 * completed_at se mantiene solo al cambiar a un estado de categoría «done».
 *
 * @property int $id
 * @property int $project_id
 * @property int|null $hour_bank_id
 * @property int|null $parent_task_id
 * @property string $title
 * @property string|null $description
 * @property int|null $task_type_id
 * @property int $status_id
 * @property TaskPriority $priority
 * @property int|null $assignee_user_id
 * @property CarbonImmutable|null $start_date
 * @property CarbonImmutable|null $due_date
 * @property int|null $estimated_minutes
 * @property bool $is_billable
 * @property bool $is_milestone
 * @property int $position
 * @property CarbonImmutable|null $completed_at
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read Project $project
 * @property-read HourBank|null $hourBank
 * @property-read Task|null $parent
 * @property-read Collection<int, Task> $subtasks
 * @property-read TaskType|null $type
 * @property-read TaskStatus $status
 * @property-read User|null $assignee
 * @property-read User|null $creator
 * @property-read Collection<int, User> $watchers
 * @property-read Collection<int, TaskComment> $comments
 * @property-read Collection<int, Attachment> $attachments
 * @property-read Collection<int, TimeEntry> $timeEntries
 */
#[Fillable([
    'project_id',
    'hour_bank_id',
    'parent_task_id',
    'title',
    'description',
    'task_type_id',
    'status_id',
    'priority',
    'assignee_user_id',
    'start_date',
    'due_date',
    'estimated_minutes',
    'is_billable',
    'is_milestone',
    'position',
    'created_by',
])]
class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use HasFactory, LogsDomainActivity, SoftDeletes;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'priority' => 'normal',
        'is_billable' => true,
        'is_milestone' => false,
        'position' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'priority' => TaskPriority::class,
            'start_date' => 'date:Y-m-d',
            'due_date' => 'date:Y-m-d',
            'estimated_minutes' => 'integer',
            'is_billable' => 'boolean',
            'is_milestone' => 'boolean',
            'position' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * El orden manual cambia al arrastrar: no se audita.
     *
     * @return list<string>
     */
    protected static function activityExcept(): array
    {
        return ['position'];
    }

    protected static function booted(): void
    {
        static::saving(function (Task $task): void {
            if (! $task->isDirty('status_id') && $task->exists) {
                return;
            }

            $done = TaskStatus::query()->whereKey($task->status_id)
                ->where('category', TaskStatusCategory::Done->value)
                ->exists();

            if ($done && $task->completed_at === null) {
                $task->completed_at = now();
            } elseif (! $done) {
                $task->completed_at = null;
            }
        });
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<HourBank, $this>
     */
    public function hourBank(): BelongsTo
    {
        return $this->belongsTo(HourBank::class);
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'parent_task_id');
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function subtasks(): HasMany
    {
        return $this->hasMany(Task::class, 'parent_task_id');
    }

    /**
     * @return BelongsTo<TaskType, $this>
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(TaskType::class, 'task_type_id')->withTrashed();
    }

    /**
     * @return BelongsTo<TaskStatus, $this>
     */
    public function status(): BelongsTo
    {
        return $this->belongsTo(TaskStatus::class, 'status_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function watchers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'task_watchers')->withTimestamps();
    }

    /**
     * @return HasMany<TaskComment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(TaskComment::class);
    }

    /**
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    /**
     * @return HasMany<TimeEntry, $this>
     */
    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }

    public function isSubtask(): bool
    {
        return $this->parent_task_id !== null;
    }

    /**
     * Estimación efectiva (SPEC §6): si tiene subtareas con estimación, la suma de las subtareas
     * (solo lectura); si no, la suya. Requiere `subtasks` cargadas para evitar consultas extra.
     */
    public function effectiveEstimatedMinutes(): ?int
    {
        $subtasks = $this->relationLoaded('subtasks') ? $this->subtasks : $this->subtasks()->get(['id', 'estimated_minutes']);
        $estimated = $subtasks->whereNotNull('estimated_minutes');

        if ($estimated->isNotEmpty()) {
            return (int) $estimated->sum('estimated_minutes');
        }

        return $this->estimated_minutes;
    }

    /**
     * Tareas abiertas (estado de categoría distinta de «done»).
     *
     * @param  Builder<Task>  $query
     */
    #[Scope]
    protected function open(Builder $query): void
    {
        $query->whereNull('completed_at');
    }

    /**
     * @param  Builder<Task>  $query
     */
    #[Scope]
    protected function assignedTo(Builder $query, User|int $user): void
    {
        $query->where('assignee_user_id', $user instanceof User ? $user->id : $user);
    }

    /**
     * Solo tareas de primer nivel.
     *
     * @param  Builder<Task>  $query
     */
    #[Scope]
    protected function roots(Builder $query): void
    {
        $query->whereNull('parent_task_id');
    }
}
