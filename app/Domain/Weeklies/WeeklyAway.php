<?php

namespace App\Domain\Weeklies;

use App\Enums\AbsenceStatus;
use App\Enums\WeeklyAwayReason;
use App\Models\Absence;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * «Estoy fuera» de la Weekly (10.9b, D-228): el estado VACATION/ABSENT con fecha de vuelta que en
 * WeeklySync se ponía uno mismo desde el avatar (o quien gestiona desde el resumen), con efecto
 * inmediato y sin aprobación. Solo toca la Weekly: no resta capacidad ni sustituye a una ausencia
 * (para eso está «Solicitar ausencia», que se puede pedir a la vez).
 *
 * - Activo: con motivo y sin vuelta o con la vuelta hoy o después (en Madrid). Pasada la vuelta, se
 *   apaga solo (como el original), aunque la fila guarda el último estado.
 * - Exime de una semana si empezó antes o el mismo día del plazo y la vuelta es ese día o después
 *   (o no tiene): WeeklyEligibility, motivo `away`. A diferencia del original, no exime de una
 *   semana cuyo plazo ya había pasado al marcarse.
 * - Quita los recordatorios mientras dura, aunque se vuelva antes del plazo (`awayOn`), igual que
 *   una ausencia aprobada de día completo que cubre el día del envío.
 */
final class WeeklyAway
{
    public static function today(?CarbonInterface $now = null): string
    {
        return CarbonImmutable::instance($now ?? CarbonImmutable::now())->setTimezone(WeeklyCalendar::TIMEZONE)->toDateString();
    }

    /**
     * El estado de una persona si sigue activo hoy.
     *
     * @return array{reason: string, since: string|null, until: string|null}|null
     */
    public static function of(User $user, ?CarbonInterface $now = null): ?array
    {
        $reason = $user->weekly_away_reason;

        if ($reason === null) {
            return null;
        }

        $until = $user->weekly_away_until?->toDateString();

        if ($until !== null && $until < self::today($now)) {
            return null;
        }

        return [
            'reason' => $reason->value,
            'since' => $user->weekly_away_since?->toDateString(),
            'until' => $until,
        ];
    }

    /**
     * ¿El «Estoy fuera» de $user cubre $day? (con la persona ya cargada, sin consultas).
     */
    public static function coversDay(User $user, CarbonInterface $day): bool
    {
        $date = $day->toDateString();

        return $user->weekly_away_reason !== null
            && ($user->weekly_away_since === null || $user->weekly_away_since->toDateString() <= $date)
            && ($user->weekly_away_until === null || $user->weekly_away_until->toDateString() >= $date);
    }

    /**
     * De $userIds, quién está fuera el día del plazo (exentos por `away`).
     *
     * @param  list<int>  $userIds
     * @return array<int, true>
     */
    public function coveringDeadline(array $userIds, CarbonInterface $deadline): array
    {
        if ($userIds === []) {
            return [];
        }

        $day = $deadline->toDateString();

        return User::query()
            ->whereIn('id', $userIds)
            ->whereNotNull('weekly_away_reason')
            ->where(fn ($query) => $query->whereNull('weekly_away_since')->orWhereDate('weekly_away_since', '<=', $day))
            ->where(fn ($query) => $query->whereNull('weekly_away_until')->orWhereDate('weekly_away_until', '>=', $day))
            ->pluck('id')
            ->mapWithKeys(fn ($id): array => [(int) $id => true])
            ->all();
    }

    /**
     * De $userIds, quién está fuera $day: con «Estoy fuera» activo o con una ausencia aprobada de
     * día completo que lo cubre. Nadie de ellos recibe un recordatorio ese día.
     *
     * @param  list<int>  $userIds
     * @return list<int>
     */
    public function awayOn(array $userIds, CarbonInterface $day): array
    {
        if ($userIds === []) {
            return [];
        }

        $date = $day->toDateString();
        $away = User::query()
            ->whereIn('id', $userIds)
            ->whereNotNull('weekly_away_reason')
            ->where(fn ($query) => $query->whereNull('weekly_away_since')->orWhereDate('weekly_away_since', '<=', $date))
            ->where(fn ($query) => $query->whereNull('weekly_away_until')->orWhereDate('weekly_away_until', '>=', $date))
            ->pluck('id');
        $absent = Absence::query()
            ->whereIn('user_id', $userIds)
            ->where('status', AbsenceStatus::Approved->value)
            ->whereNull('partial_minutes')
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->pluck('user_id');

        return $away->merge($absent)->map(fn ($id): int => (int) $id)->unique()->sort()->values()->all();
    }

    /** Marca a $person fuera desde hoy (la propia persona o quien gestiona). */
    public function set(User $person, WeeklyAwayReason $reason, ?string $until, ?CarbonInterface $now = null): void
    {
        $person->forceFill([
            'weekly_away_reason' => $reason,
            'weekly_away_since' => self::today($now),
            'weekly_away_until' => $until,
        ])->save();

        MyWeeklyStatus::forgetActive($person->id);
    }

    /** «Vuelvo a estar disponible». */
    public function clear(User $person): void
    {
        $person->forceFill([
            'weekly_away_reason' => null,
            'weekly_away_since' => null,
            'weekly_away_until' => null,
        ])->save();

        MyWeeklyStatus::forgetActive($person->id);
    }
}
