<?php

namespace App\Models;

use App\Models\Concerns\LogsDomainActivity;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
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
     * Fechas admitidas (desde, hasta y las de las instancias): de 2000 a 2100, como el calendario
     * de tareas (App\Domain\Planning\CalendarPeriod). Lo valida RecurringRuleRequest y
     * occurrencesBetween() no da fechas fuera de ese rango.
     */
    public const string MIN_DATE = '2000-01-01';

    public const string MAX_DATE = '2100-12-31';

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
     * Fechas de las instancias entre $from y $to (ambas incluidas), respetando starts_on, ends_on y
     * el rango admitido (MIN_DATE a MAX_DATE). Semanal: cada `interval` semanas desde la semana de
     * starts_on; mensual: cada `interval` meses desde su mes. El cursor salta directamente a la
     * primera fecha de la serie dentro de la ventana, sin recorrerla desde starts_on: una regla que
     * empezó hace siglos cuesta lo mismo que una de hoy. Gemelo de occurrencesBetween() en
     * resources/js/components/recurring/recurrence.ts (casos compartidos en
     * tests/fixtures/recurrence-cases.json).
     *
     * @return list<string>
     */
    public function occurrencesBetween(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $low = max($from->toDateString(), $this->starts_on->toDateString(), self::MIN_DATE);
        $high = min($to->toDateString(), $this->ends_on?->toDateString() ?? self::MAX_DATE, self::MAX_DATE);

        if ($low > $high) {
            return [];
        }

        $interval = max($this->interval, 1);

        return $this->frequency === self::WEEKLY
            ? $this->weeklyBetween($low, $high, $interval)
            : $this->monthlyBetween($low, $high, $interval);
    }

    /**
     * @return list<string>
     */
    private function weeklyBetween(string $low, string $high, int $interval): array
    {
        $start = self::dayNumber($this->starts_on->toDateString());
        $startWeekday = $this->starts_on->dayOfWeekIso;
        $weekday = min(max($this->weekday ?? $startWeekday, 1), 7);
        $step = 7 * $interval;

        // La primera de la serie: ese día de la semana de starts_on o, si ya pasó, N semanas después.
        $day = $start - $startWeekday + $weekday;
        if ($day < $start) {
            $day += $step;
        }

        // Salta a la primera de la serie que no es anterior a la ventana.
        $first = self::dayNumber($low);
        if ($day < $first) {
            $day += intdiv($first - $day + $step - 1, $step) * $step;
        }

        $dates = [];
        for ($last = self::dayNumber($high); $day <= $last; $day += $step) {
            $dates[] = gmdate('Y-m-d', $day * 86400);
        }

        return $dates;
    }

    /**
     * @return list<string>
     */
    private function monthlyBetween(string $low, string $high, int $interval): array
    {
        $monthDay = min(max($this->month_day ?? $this->starts_on->day, 1), 31);

        // Meses de la serie (año × 12 + mes − 1): el de starts_on y cada N meses, desde el primero
        // que no es anterior al mes de la ventana.
        $month = $this->starts_on->year * 12 + $this->starts_on->month - 1;
        $first = self::monthIndex($low);
        if ($month < $first) {
            $month += intdiv($first - $month + $interval - 1, $interval) * $interval;
        }

        $dates = [];
        for ($last = self::monthIndex($high); $month <= $last; $month += $interval) {
            $year = intdiv($month, 12);
            $number = $month % 12 + 1;
            $days = (int) CarbonImmutable::create($year, $number, 1)->format('t');
            $candidate = sprintf('%04d-%02d-%02d', $year, $number, min($monthDay, $days));

            if ($candidate >= $low && $candidate <= $high) {
                $dates[] = $candidate;
            }
        }

        return $dates;
    }

    /** Días desde el 1 de enero de 1970 (negativos antes), para cualquier año. */
    private static function dayNumber(string $date): int
    {
        return intdiv((new DateTimeImmutable($date.' 00:00:00', new DateTimeZone('UTC')))->getTimestamp(), 86400);
    }

    /** «2026-10-05» → 2026 × 12 + 9. */
    private static function monthIndex(string $date): int
    {
        return (int) substr($date, 0, 4) * 12 + (int) substr($date, 5, 2) - 1;
    }
}
