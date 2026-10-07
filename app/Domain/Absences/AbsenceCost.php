<?php

namespace App\Domain\Absences;

use App\Domain\Time\Capacity;
use App\Enums\LeaveUnit;
use App\Models\Absence;
use App\Models\LeaveType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Lo que «cuesta» una ausencia en las unidades de su tipo (Fase 11, R3; W-034, W-055 y W-056;
 * D-361), día a día (el saldo gasta primero lo que caduca antes y eso depende de la fecha de cada
 * día):
 * - **días laborables**: cada día con jornada que no es festivo vale 1 (100 centésimas) y uno de
 *   media jornada, medio (50). Una de parte del día vale su parte de la jornada de ese día
 *   (4 h de 8 h = 50).
 * - **días naturales**: cada día del periodo, 1 (100); una de parte del día, como las laborables.
 * - **horas**: los minutos pedidos (la franja); un día completo, los minutos de su jornada (la mitad
 *   si es de media jornada; 0 si es festivo o no trabaja).
 * La jornada de cada día es la de Capacity (versión vigente o de verano), antes de restar ausencias.
 */
final class AbsenceCost
{
    public function __construct(private readonly Capacity $capacity) {}

    /**
     * El coste día a día de una ausencia que aún no existe (para validarla o simularla).
     *
     * @return array<string, int> AAAA-MM-DD → unidades
     */
    public function days(int $userId, LeaveType $type, string $start, string $end, ?int $partialMinutes): array
    {
        $details = $this->capacity->detailsForRanges([[
            'user_id' => $userId,
            'from' => CarbonImmutable::parse($start),
            'to' => CarbonImmutable::parse($end),
        ]])[0];

        return self::perDay($type->unit, $details, LeaveCalendar::halfDays($start, $end), $partialMinutes);
    }

    /**
     * El coste día a día de varias ausencias (de cualquier persona), con una consulta de horarios,
     * festivos y medias jornadas en total.
     *
     * @param  iterable<Absence>  $absences  Con leaveType cargado (o se carga).
     * @return array<int, array<string, int>> id → (fecha → unidades)
     */
    public function forAbsences(iterable $absences): array
    {
        $absences = new EloquentCollection(array_values(Collection::make($absences)->all()));

        if ($absences->isEmpty()) {
            return [];
        }

        $absences->loadMissing('leaveType');
        $byUser = $absences->groupBy('user_id');
        $ranges = [];

        foreach ($byUser as $userId => $own) {
            $ranges[] = [
                'user_id' => (int) $userId,
                'from' => CarbonImmutable::parse((string) $own->min(fn (Absence $absence): string => $absence->start_date->toDateString())),
                'to' => CarbonImmutable::parse((string) $own->max(fn (Absence $absence): string => $absence->end_date->toDateString())),
            ];
        }

        if ($ranges === []) {
            return [];
        }

        $details = $this->capacity->detailsForRanges($ranges);
        $from = min(array_map(fn (array $range): string => $range['from']->toDateString(), $ranges));
        $to = max(array_map(fn (array $range): string => $range['to']->toDateString(), $ranges));
        $halfDays = LeaveCalendar::halfDays($from, $to);
        $result = [];

        foreach ($ranges as $index => $range) {
            foreach ($byUser->get($range['user_id']) ?? [] as $absence) {
                $unit = $absence->leaveType->unit ?? LeaveUnit::WorkingDays;
                $own = array_filter(
                    $details[$index],
                    fn (string $date): bool => $date >= $absence->start_date->toDateString() && $date <= $absence->end_date->toDateString(),
                    ARRAY_FILTER_USE_KEY,
                );
                $result[$absence->id] = self::perDay($unit, $own, $halfDays, $absence->partial_minutes);
            }
        }

        return $result;
    }

    /**
     * @param  array<string, array{base: int, minutes: int, holiday: string|null, absence: mixed}>  $details
     * @param  array<string, string>  $halfDays
     * @return array<string, int>
     */
    public static function perDay(LeaveUnit $unit, array $details, array $halfDays, ?int $partialMinutes): array
    {
        $costs = [];

        foreach ($details as $date => $detail) {
            $holiday = $detail['holiday'] !== null;
            $half = ! $holiday && isset($halfDays[$date]);
            $base = $holiday ? 0 : ($half ? intdiv($detail['base'], 2) : $detail['base']);

            if ($partialMinutes !== null) {
                $costs[$date] = match ($unit) {
                    LeaveUnit::Hours => $partialMinutes,
                    default => $base > 0 ? (int) min(round($partialMinutes * LeaveCatalog::DAY * ($half ? 0.5 : 1) / $base), $half ? 50 : LeaveCatalog::DAY) : 0,
                };

                continue;
            }

            $costs[$date] = match ($unit) {
                LeaveUnit::CalendarDays => LeaveCatalog::DAY,
                LeaveUnit::WorkingDays => $base > 0 ? ($half ? intdiv(LeaveCatalog::DAY, 2) : LeaveCatalog::DAY) : 0,
                LeaveUnit::Hours => $base,
            };
        }

        return $costs;
    }

    /**
     * @param  array<string, int>  $days
     */
    public static function total(array $days): int
    {
        return array_sum($days);
    }
}
