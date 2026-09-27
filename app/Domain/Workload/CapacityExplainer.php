<?php

namespace App\Domain\Workload;

use App\Enums\AbsenceType;

/**
 * Explica la capacidad de una celda de la vista Carga (un día o una semana) a partir del detalle de
 * Capacity::details()/detailsForRanges():
 * - `reason`, si la capacidad es 0 (celda gris, D-052): festivo, ausencia, día no laborable o una
 *   mezcla de festivos y ausencias,
 * - `reduced`, si hay capacidad pero menos que la jornada: festivos, días de ausencia completos y
 *   minutos de ausencias parciales.
 * El texto lo compone el frontend (lang/ui/workload.json); aquí solo van los datos y los nombres
 * propios (el del festivo y el tipo de ausencia).
 *
 * @phpstan-type DayDetail array{base: int, minutes: int, holiday: string|null, absence: array{type: string, partial_minutes: int|null}|null}
 * @phpstan-type Reason array{type: 'holiday'|'absence'|'off'|'mixed', label: string|null}
 * @phpstan-type Reduced array{holidays: int, absence_days: int, partial_minutes: int, absence_label: string|null}
 */
final class CapacityExplainer
{
    /**
     * @param  array<string, DayDetail>  $days  fecha → detalle, solo los días de la celda
     * @return array{reason: Reason|null, reduced: Reduced|null}
     */
    public static function explain(array $days): array
    {
        $capacity = 0;
        $working = [];

        foreach ($days as $date => $day) {
            $capacity += $day['minutes'];

            if ($day['base'] > 0) {
                $working[$date] = $day;
            }
        }

        $holidays = array_filter($working, fn (array $day): bool => $day['holiday'] !== null);
        $absent = array_filter($working, fn (array $day): bool => $day['holiday'] === null && $day['absence'] !== null);
        $fullAbsences = array_filter($absent, fn (array $day): bool => $day['minutes'] === 0);
        $partial = array_filter($absent, fn (array $day): bool => $day['minutes'] > 0);
        $absenceLabel = self::absenceLabel($absent);
        $workingDays = count($working);
        $holidayDays = count($holidays);
        $absenceDays = count($fullAbsences);

        if ($capacity === 0) {
            return ['reason' => match (true) {
                $workingDays === 0 => ['type' => 'off', 'label' => null],
                $holidayDays === $workingDays => ['type' => 'holiday', 'label' => self::holidayLabel($holidays)],
                $absenceDays === $workingDays => ['type' => 'absence', 'label' => $absenceLabel],
                default => ['type' => 'mixed', 'label' => null],
            }, 'reduced' => null];
        }

        if ($holidays === [] && $absent === []) {
            return ['reason' => null, 'reduced' => null];
        }

        return ['reason' => null, 'reduced' => [
            'holidays' => $holidayDays,
            'absence_days' => $absenceDays,
            'partial_minutes' => (int) array_sum(array_map(fn (array $day): int => max($day['base'] - $day['minutes'], 0), $partial)),
            'absence_label' => $absenceLabel,
        ]];
    }

    /**
     * Nombre del festivo si todos los de la celda se llaman igual (un día, casi siempre).
     *
     * @param  array<string, DayDetail>  $holidays
     */
    private static function holidayLabel(array $holidays): ?string
    {
        $names = array_values(array_unique(array_map(fn (array $day): string => (string) $day['holiday'], $holidays)));

        return count($names) === 1 ? $names[0] : null;
    }

    /**
     * Tipo de ausencia («Vacaciones», «Baja»…) si todas las de la celda son del mismo tipo.
     *
     * @param  array<string, DayDetail>  $absent
     */
    private static function absenceLabel(array $absent): ?string
    {
        $types = array_values(array_unique(array_map(fn (array $day): string => (string) ($day['absence']['type'] ?? ''), $absent)));

        if (count($types) !== 1) {
            return null;
        }

        return AbsenceType::tryFrom($types[0])?->label();
    }
}
