<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\WorkScheduleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Jornada versionada de un usuario (SPEC §4.1). Para una fecha vale el horario con
 * valid_from ≤ fecha ≤ valid_to (o sin valid_to). Ver App\Domain\Time\Capacity.
 *
 * @property int $id
 * @property int $user_id
 * @property CarbonImmutable $valid_from
 * @property CarbonImmutable|null $valid_to
 * @property int $mon_minutes
 * @property int $tue_minutes
 * @property int $wed_minutes
 * @property int $thu_minutes
 * @property int $fri_minutes
 * @property int $sat_minutes
 * @property int $sun_minutes
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $user
 */
#[Fillable([
    'user_id',
    'valid_from',
    'valid_to',
    'mon_minutes',
    'tue_minutes',
    'wed_minutes',
    'thu_minutes',
    'fri_minutes',
    'sat_minutes',
    'sun_minutes',
])]
class WorkSchedule extends Model
{
    /** @use HasFactory<WorkScheduleFactory> */
    use HasFactory;

    /**
     * Columnas por día ISO (1 = lunes … 7 = domingo).
     */
    public const array DAY_COLUMNS = [
        1 => 'mon_minutes',
        2 => 'tue_minutes',
        3 => 'wed_minutes',
        4 => 'thu_minutes',
        5 => 'fri_minutes',
        6 => 'sat_minutes',
        7 => 'sun_minutes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'valid_from' => 'date:Y-m-d',
            'valid_to' => 'date:Y-m-d',
            'mon_minutes' => 'integer',
            'tue_minutes' => 'integer',
            'wed_minutes' => 'integer',
            'thu_minutes' => 'integer',
            'fri_minutes' => 'integer',
            'sat_minutes' => 'integer',
            'sun_minutes' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function minutesFor(CarbonInterface $date): int
    {
        return (int) $this->getAttribute(self::DAY_COLUMNS[$date->dayOfWeekIso]);
    }

    public function coversDate(CarbonInterface $date): bool
    {
        $day = $date->toDateString();

        return $this->valid_from->toDateString() <= $day
            && ($this->valid_to === null || $this->valid_to->toDateString() >= $day);
    }

    /**
     * Minutos por día de la semana, lunes primero.
     *
     * @return list<int>
     */
    public function weekMinutes(): array
    {
        return array_map(fn (string $column): int => (int) $this->getAttribute($column), array_values(self::DAY_COLUMNS));
    }
}
