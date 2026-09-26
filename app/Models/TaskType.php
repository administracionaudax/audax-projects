<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\TaskTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tipo de tarea configurable por el admin (SPEC §4.3).
 *
 * @property int $id
 * @property string $name
 * @property string $color
 * @property string|null $icon
 * @property int|null $department_id
 * @property bool $is_billable_default
 * @property bool $is_active
 * @property int $position
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read Department|null $department
 */
#[Fillable(['name', 'color', 'icon', 'department_id', 'is_billable_default', 'is_active', 'position'])]
class TaskType extends Model
{
    /** @use HasFactory<TaskTypeFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Tipos por defecto que crea app:install (SPEC §4.3). `department` es el nombre de un
     * departamento por defecto (Department::DEFAULTS) o null. Iconos de lucide.
     */
    public const array DEFAULTS = [
        ['name' => 'Diseño UI', 'color' => '#0171FF', 'icon' => 'palette', 'department' => 'Diseño', 'is_billable_default' => true],
        ['name' => 'Maquetación', 'color' => '#0892C4', 'icon' => 'layout-template', 'department' => 'Desarrollo', 'is_billable_default' => true],
        ['name' => 'Desarrollo', 'color' => '#179FA5', 'icon' => 'code-xml', 'department' => 'Desarrollo', 'is_billable_default' => true],
        ['name' => 'Bug', 'color' => '#E65FB3', 'icon' => 'bug', 'department' => 'Desarrollo', 'is_billable_default' => true],
        ['name' => 'Reunión', 'color' => '#3C41AE', 'icon' => 'users', 'department' => null, 'is_billable_default' => true],
        ['name' => 'SEO', 'color' => '#5E2DAD', 'icon' => 'search', 'department' => 'Marketing', 'is_billable_default' => true],
        ['name' => 'Contenidos', 'color' => '#5E2DAD', 'icon' => 'file-text', 'department' => 'Marketing', 'is_billable_default' => true],
        ['name' => 'Campaña', 'color' => '#5E2DAD', 'icon' => 'megaphone', 'department' => 'Marketing', 'is_billable_default' => true],
        ['name' => 'Soporte', 'color' => '#0892C4', 'icon' => 'life-buoy', 'department' => null, 'is_billable_default' => true],
        ['name' => 'Gestión', 'color' => '#56667A', 'icon' => 'briefcase', 'department' => null, 'is_billable_default' => true],
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_billable_default' => true,
        'is_active' => true,
        'position' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_billable_default' => 'boolean',
            'is_active' => 'boolean',
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * @param  Builder<TaskType>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * @param  Builder<TaskType>  $query
     */
    #[Scope]
    protected function ordered(Builder $query): void
    {
        $query->orderBy('position')->orderBy('name');
    }
}
