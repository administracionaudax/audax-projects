<?php

namespace App\Models;

use App\Models\Concerns\LogsDomainActivity;
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
 * Registro de jornada (Fase 11, D-336): cada versión lleva además el margen de entrada
 * (start_time_from a start_time_to), la pausa prevista y, si la hay, la temporada de verano: del
 * summer_starts_on al summer_ends_on (MM-DD, todos los años; si el inicio es posterior al final,
 * cruza el fin de año) vale summer_week con su propia pausa prevista. minutesFor() y weekOn() ya la
 * tienen en cuenta, y Capacity también (toda la app: carga, informes y previsión).
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
 * @property string|null $start_time_from
 * @property string|null $start_time_to
 * @property int $expected_pause_minutes
 * @property string|null $summer_starts_on
 * @property string|null $summer_ends_on
 * @property list<int>|null $summer_week
 * @property int|null $summer_expected_pause_minutes
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
    'start_time_from',
    'start_time_to',
    'expected_pause_minutes',
    'summer_starts_on',
    'summer_ends_on',
    'summer_week',
    'summer_expected_pause_minutes',
])]
class WorkSchedule extends Model
{
    /** @use HasFactory<WorkScheduleFactory> */
    use HasFactory, LogsDomainActivity;

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
            'expected_pause_minutes' => 'integer',
            'summer_week' => 'array',
            'summer_expected_pause_minutes' => 'integer',
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
     * Minutos de ese día de la semana, con la jornada de verano si la fecha cae en su temporada.
     */
    public function minutesFor(CarbonInterface $date): int
    {
        return $this->weekOn($date->toDateString())[$date->dayOfWeekIso - 1];
    }

    /**
     * Semana (lunes primero) que vale en $date (AAAA-MM-DD): la de verano si cae en su temporada.
     *
     * @return list<int>
     */
    public function weekOn(string $date): array
    {
        return $this->inSummer($date) ? $this->summerWeek() : $this->weekMinutes();
    }

    /** Pausa prevista ese día (la de verano si cae en su temporada). */
    public function expectedPauseOn(string $date): int
    {
        return $this->inSummer($date)
            ? (int) ($this->summer_expected_pause_minutes ?? 0)
            : $this->expected_pause_minutes;
    }

    public function hasSummer(): bool
    {
        return $this->summer_starts_on !== null && $this->summer_ends_on !== null && is_array($this->summer_week);
    }

    /** ¿Cae $date (AAAA-MM-DD) en la temporada de verano? Compara el mes y el día (MM-DD). */
    public function inSummer(string $date): bool
    {
        if (! $this->hasSummer()) {
            return false;
        }

        return self::seasonCovers((string) $this->summer_starts_on, (string) $this->summer_ends_on, substr($date, 5, 5));
    }

    /**
     * ¿Cubre la temporada [MM-DD, MM-DD] el día MM-DD? Si el inicio es posterior al final, cruza el
     * fin de año (del 15/12 al 15/01, por ejemplo).
     */
    public static function seasonCovers(string $starts, string $ends, string $monthDay): bool
    {
        return $starts <= $ends
            ? $monthDay >= $starts && $monthDay <= $ends
            : $monthDay >= $starts || $monthDay <= $ends;
    }

    /**
     * Semana de verano, lunes primero (7 valores; los que falten, 0).
     *
     * @return list<int>
     */
    public function summerWeek(): array
    {
        $week = array_map(fn (mixed $minutes): int => max((int) $minutes, 0), $this->summer_week ?? []);

        return array_pad(array_slice($week, 0, 7), 7, 0);
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
