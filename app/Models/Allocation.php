<?php

namespace App\Models;

use App\Enums\AllocationMode;
use App\Models\Concerns\LogsDomainActivity;
use Carbon\CarbonImmutable;
use Database\Factories\AllocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Asignación de horas sin tareas (docs/PLAN-CARGAS.md §5.1, §6.2 y §7.2, D-282): una persona **o**
 * un departamento sin persona («hueco») en un proyecto real **o** en uno previsto, con un modo
 * (total, por día, %, por mes) y unas fechas. Es la única fuente de la carga de la previsión (P6 y
 * P7, D-283). Se escribe SIEMPRE con App\Domain\Forecast\AllocationWriter.
 *
 * @property int $id
 * @property int|null $forecast_project_id
 * @property int|null $project_id
 * @property int|null $user_id
 * @property int|null $department_id
 * @property AllocationMode $mode
 * @property int|null $minutes
 * @property int|null $percent
 * @property CarbonImmutable $start_date
 * @property CarbonImmutable|null $end_date
 * @property string|null $note
 * @property int|null $copied_from_allocation_id
 * @property int|null $created_by
 * @property CarbonImmutable|null $deleted_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read ForecastProject|null $forecastProject
 * @property-read Project|null $project
 * @property-read User|null $user
 * @property-read Department|null $department
 */
#[Fillable([
    'forecast_project_id',
    'project_id',
    'user_id',
    'department_id',
    'mode',
    'minutes',
    'percent',
    'start_date',
    'end_date',
    'note',
    'copied_from_allocation_id',
    'created_by',
])]
class Allocation extends Model
{
    /** @use HasFactory<AllocationFactory> */
    use HasFactory, LogsDomainActivity, SoftDeletes;

    public const int NOTE_MAX = 200;

    /** Máximo de un porcentaje de dedicación (dos personas a tiempo completo en un hueco). */
    public const int PERCENT_MAX = 200;

    /** Máximo de minutos de una asignación: 99.999 h. */
    public const int MINUTES_MAX = 99999 * 60;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mode' => AllocationMode::class,
            'minutes' => 'integer',
            'percent' => 'integer',
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
        ];
    }

    /** ¿Es un hueco (departamento sin persona)? */
    public function isGap(): bool
    {
        return $this->user_id === null;
    }

    /** ¿Es de un proyecto previsto? */
    public function isForecast(): bool
    {
        return $this->forecast_project_id !== null;
    }

    /**
     * @return BelongsTo<ForecastProject, $this>
     */
    public function forecastProject(): BelongsTo
    {
        return $this->belongsTo(ForecastProject::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class)->withTrashed();
    }
}
