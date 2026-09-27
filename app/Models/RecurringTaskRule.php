<?php

namespace App\Models;

use App\Models\Concerns\LogsDomainActivity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tarea recurrente (SPEC §4.3): semanal (cada `interval` semanas el `weekday` ISO, 1 = lunes) o
 * mensual (cada `interval` meses el día `month_day`; si el mes es más corto, su último día). Cada
 * instancia vence `due_offset_days` después de su fecha (D-059).
 *
 * @property int $id
 * @property int $project_id
 * @property int|null $hour_bank_id
 * @property string $title
 * @property string|null $description
 * @property int|null $task_type_id
 * @property int|null $assignee_user_id
 * @property int|null $estimated_minutes
 * @property string $priority
 * @property string $frequency
 * @property int $interval
 * @property int|null $weekday
 * @property int|null $month_day
 * @property int $due_offset_days
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable|null $ends_on
 * @property CarbonImmutable|null $last_generated_on
 * @property bool $is_active
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Project $project
 * @property-read User|null $creator
 */
#[Fillable(['project_id', 'hour_bank_id', 'title', 'description', 'task_type_id', 'assignee_user_id', 'estimated_minutes', 'priority',
    'frequency', 'interval', 'weekday', 'month_day', 'due_offset_days', 'starts_on', 'ends_on', 'last_generated_on', 'is_active', 'created_by'])]
class RecurringTaskRule extends Model
{
    use LogsDomainActivity;

    public const string WEEKLY = 'weekly';

    public const string MONTHLY = 'monthly';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'priority' => 'normal',
        'interval' => 1,
        'due_offset_days' => 0,
        'is_active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'interval' => 'integer',
            'weekday' => 'integer',
            'month_day' => 'integer',
            'due_offset_days' => 'integer',
            'estimated_minutes' => 'integer',
            'starts_on' => 'date:Y-m-d',
            'ends_on' => 'date:Y-m-d',
            'last_generated_on' => 'date:Y-m-d',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Quien creó la regla: «crea» sus tareas mientras siga activo (RecurringTaskGenerator).
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * Fechas de las instancias entre $from y $to (ambas incluidas), respetando starts_on y ends_on.
     *
     * @return list<string>
     */
    public function occurrencesBetween(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $start = $this->starts_on;
        $end = $this->ends_on !== null && $this->ends_on < $to ? $this->ends_on : $to;
        $interval = max($this->interval, 1);
        $dates = [];

        if ($this->frequency === self::WEEKLY) {
            $weekday = min(max($this->weekday ?? $start->dayOfWeekIso, 1), 7);
            $cursor = $start->startOfWeek()->addDays($weekday - 1);
            if ($cursor < $start) {
                $cursor = $cursor->addWeeks($interval);
            }
            for (; $cursor <= $end; $cursor = $cursor->addWeeks($interval)) {
                if ($cursor >= $from) {
                    $dates[] = $cursor->toDateString();
                }
            }

            return $dates;
        }

        $monthDay = min(max($this->month_day ?? $start->day, 1), 31);
        for ($month = $start->startOfMonth(); $month <= $end; $month = $month->addMonthsNoOverflow($interval)) {
            $candidate = $month->setDay(min($monthDay, $month->daysInMonth));
            if ($candidate >= $start && $candidate >= $from && $candidate <= $end) {
                $dates[] = $candidate->toDateString();
            }
        }

        return $dates;
    }
}
