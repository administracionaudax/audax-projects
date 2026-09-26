<?php

namespace App\Models;

use App\Enums\TaskStatusCategory;
use Carbon\CarbonImmutable;
use Database\Factories\TaskStatusFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Estado de tarea global (SPEC §4.3). Los cálculos usan `category`, nunca el nombre.
 *
 * @property int $id
 * @property string $name
 * @property string $color
 * @property TaskStatusCategory $category
 * @property int $position
 * @property bool $is_default
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'color', 'category', 'position', 'is_default'])]
class TaskStatus extends Model
{
    /** @use HasFactory<TaskStatusFactory> */
    use HasFactory;

    /**
     * Estados por defecto (SPEC §4.3). «En revisión» y «Bloqueada» cuentan como en curso (D-037).
     */
    public const array DEFAULTS = [
        ['name' => 'Por hacer', 'color' => '#56667A', 'category' => 'todo', 'is_default' => true],
        ['name' => 'En curso', 'color' => '#0171FF', 'category' => 'in_progress', 'is_default' => false],
        ['name' => 'En revisión', 'color' => '#5E2DAD', 'category' => 'in_progress', 'is_default' => false],
        ['name' => 'Bloqueada', 'color' => '#E65FB3', 'category' => 'in_progress', 'is_default' => false],
        ['name' => 'Hecha', 'color' => '#179FA5', 'category' => 'done', 'is_default' => false],
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => TaskStatusCategory::class,
            'position' => 'integer',
            'is_default' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'status_id');
    }

    public function isDone(): bool
    {
        return $this->category === TaskStatusCategory::Done;
    }

    /**
     * Estado inicial de las tareas nuevas: el marcado por defecto o el primero de «Por hacer».
     */
    public static function defaultStatus(): self
    {
        return static::query()->where('is_default', true)->first()
            ?? static::query()->where('category', TaskStatusCategory::Todo->value)->orderBy('position')->first()
            ?? static::query()->orderBy('position')->firstOrFail();
    }

    /**
     * Crea los estados por defecto si aún no hay ninguno (app:install y tests).
     */
    public static function ensureDefaults(): void
    {
        if (static::query()->exists()) {
            return;
        }

        foreach (self::DEFAULTS as $position => $status) {
            static::query()->create([...$status, 'position' => $position]);
        }
    }

    /**
     * @param  Builder<TaskStatus>  $query
     */
    #[Scope]
    protected function ordered(Builder $query): void
    {
        $query->orderBy('position')->orderBy('id');
    }
}
