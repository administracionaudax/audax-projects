<?php

namespace App\Domain\People\Reports;

use App\Domain\People\WorkdayCalculator;
use App\Enums\HourType;
use App\Enums\OvertimeDestination;
use App\Models\OvertimeDecision;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Los datos del registro de varias personas en un periodo, ya listos para los cierres, los informes
 * y la exportación para la Inspección (R2; D-347, D-349 y D-351): el diario de WorkdayCalculator de
 * cada día con la clasificación vigente de su exceso (hora extra o complementaria, flexibilidad,
 * destino y si hay que revisarla porque el exceso ha cambiado) y los totales.
 *
 * Una sola consulta de diario para todas las personas (WorkdayCalculator::forUsers) y una de
 * decisiones. Con $hideAbsenceType, el tipo de las ausencias no sale (la Inspección: minimización,
 * D-088 y L-11).
 *
 * @phpstan-import-type Day from WorkdayCalculator
 *
 * @phpstan-type Line array{date: string, weekday: int, expected_minutes: int, worked_minutes: int, pause_minutes: int, difference_minutes: int|null, excess_minutes: int, overtime_minutes: int, complementary_minutes: int, flex_minutes: int, unclassified_minutes: int, destination: string|null, decision_stale: bool, modes: list<string>, incidents: list<string>, status: string, first_in: string|null, last_out: string|null, segments: list<array{kind: string, from: string, to: string|null, work_mode: string|null}>, holiday: string|null, absence: bool, absence_type: string|null, employed: bool, registered: bool, pending_corrections: int, disputed_corrections: int}
 * @phpstan-type Totals array{worked_minutes: int, expected_minutes: int, difference_minutes: int, excess_minutes: int, overtime_minutes: int, overtime_compensate_minutes: int, overtime_pay_minutes: int, complementary_minutes: int, flex_minutes: int, unclassified_minutes: int, days_worked: int, incident_days: int, onsite_days: int, remote_days: int, absence_days: int, pending_correction_days: int, disputed_correction_days: int}
 */
final class RegisterDataset
{
    public function __construct(private readonly WorkdayCalculator $calculator) {}

    /**
     * @param  list<User>  $users
     * @return array<int, array{user: User, lines: array<string, Line>, totals: Totals}>
     */
    public function build(array $users, string $from, string $to, bool $hideAbsenceType = false, ?CarbonImmutable $now = null): array
    {
        if ($users === []) {
            return [];
        }

        $diaries = $this->calculator->forUsers($users, $from, $to, null, $now);
        $decisions = $this->decisions(array_map(fn (User $user): int => $user->id, $users), $from, $to);
        $result = [];

        foreach ($users as $user) {
            $lines = [];

            foreach ($diaries[$user->id] ?? [] as $date => $day) {
                $lines[$date] = self::line($day, $decisions[$user->id][$date] ?? null, $hideAbsenceType);
            }

            $result[$user->id] = ['user' => $user, 'lines' => $lines, 'totals' => self::totals($lines)];
        }

        return $result;
    }

    /**
     * Decisiones vigentes por persona y día.
     *
     * @param  list<int>  $userIds
     * @return array<int, array<string, OvertimeDecision>>
     */
    public function decisions(array $userIds, string $from, string $to): array
    {
        $byUser = [];

        OvertimeDecision::query()
            ->effective()
            ->whereIn('user_id', $userIds)
            ->whereBetween('date', [$from, $to])
            ->orderBy('id')
            ->get()
            ->each(function (OvertimeDecision $decision) use (&$byUser): void {
                $byUser[$decision->user_id][$decision->date->toDateString()] = $decision;
            });

        return $byUser;
    }

    /**
     * Un día del diario con la clasificación de su exceso.
     *
     * @param  Day  $day
     * @return Line
     */
    public static function line(array $day, ?OvertimeDecision $decision, bool $hideAbsenceType = false): array
    {
        $excess = $day['excess_minutes'];
        $overtime = 0;
        $complementary = 0;
        $flex = 0;

        if ($decision !== null) {
            if ($decision->hour_type === HourType::Complementary) {
                $complementary = $decision->overtime_minutes;
            } else {
                $overtime = $decision->overtime_minutes;
            }

            $flex = $decision->flex_minutes;
        }

        $firstIn = null;
        $lastOut = null;
        $segments = [];

        foreach ($day['workdays'] as $workday) {
            $firstIn ??= $workday['clock_in'];
            $lastOut = $workday['clock_out'] ?? $lastOut;

            foreach ($workday['segments'] as $segment) {
                $segments[] = $segment;
            }
        }

        return [
            'date' => $day['date'],
            'weekday' => $day['weekday'],
            'expected_minutes' => $day['expected_minutes'],
            'worked_minutes' => $day['worked_minutes'],
            'pause_minutes' => $day['pause_minutes'],
            'difference_minutes' => $day['difference_minutes'],
            'excess_minutes' => $excess,
            'overtime_minutes' => $overtime,
            'complementary_minutes' => $complementary,
            'flex_minutes' => $flex,
            'unclassified_minutes' => $decision === null ? $excess : 0,
            'destination' => $decision?->destination?->value,
            'decision_stale' => $decision !== null && $decision->excess_minutes !== $excess,
            'modes' => $day['modes'],
            'incidents' => $day['incidents'],
            'status' => $day['status'],
            'first_in' => $firstIn,
            'last_out' => $lastOut,
            'segments' => $segments,
            'holiday' => $day['holiday'],
            'absence' => $day['absence'] !== null,
            'absence_type' => $hideAbsenceType ? null : ($day['absence']['type'] ?? null),
            'employed' => $day['employed'],
            'registered' => $day['registered'],
            'pending_corrections' => $day['pending_corrections'],
            'disputed_corrections' => $day['disputed_corrections'],
        ];
    }

    /**
     * Totales de un periodo (los días futuros y hoy sin cerrar no suman teórica: WorkdayCalculator).
     *
     * @param  array<string, Line>  $lines
     * @return Totals
     */
    public static function totals(array $lines): array
    {
        $totals = [
            'worked_minutes' => 0, 'expected_minutes' => 0, 'difference_minutes' => 0, 'excess_minutes' => 0,
            'overtime_minutes' => 0, 'overtime_compensate_minutes' => 0, 'overtime_pay_minutes' => 0,
            'complementary_minutes' => 0, 'flex_minutes' => 0, 'unclassified_minutes' => 0, 'days_worked' => 0,
            'incident_days' => 0, 'onsite_days' => 0, 'remote_days' => 0, 'absence_days' => 0,
            'pending_correction_days' => 0, 'disputed_correction_days' => 0,
        ];

        foreach ($lines as $line) {
            if ($line['status'] === 'future') {
                continue;
            }

            $totals['worked_minutes'] += $line['worked_minutes'];
            $totals['expected_minutes'] += $line['difference_minutes'] === null ? 0 : $line['expected_minutes'];
            $totals['excess_minutes'] += $line['excess_minutes'];
            $totals['overtime_minutes'] += $line['overtime_minutes'];
            $totals['overtime_compensate_minutes'] += $line['destination'] === OvertimeDestination::Compensate->value ? $line['overtime_minutes'] : 0;
            $totals['overtime_pay_minutes'] += $line['destination'] === OvertimeDestination::Pay->value ? $line['overtime_minutes'] : 0;
            $totals['complementary_minutes'] += $line['complementary_minutes'];
            $totals['flex_minutes'] += $line['flex_minutes'];
            $totals['unclassified_minutes'] += $line['unclassified_minutes'];
            $totals['days_worked'] += $line['segments'] === [] ? 0 : 1;
            $totals['incident_days'] += $line['incidents'] === [] ? 0 : 1;
            $totals['onsite_days'] += in_array('on_site', $line['modes'], true) ? 1 : 0;
            $totals['remote_days'] += in_array('remote', $line['modes'], true) ? 1 : 0;
            $totals['absence_days'] += $line['absence'] ? 1 : 0;
            $totals['pending_correction_days'] += $line['pending_corrections'] > 0 ? 1 : 0;
            $totals['disputed_correction_days'] += $line['disputed_corrections'] > 0 ? 1 : 0;
        }

        $totals['difference_minutes'] = $totals['worked_minutes'] - $totals['expected_minutes'];

        return $totals;
    }
}
