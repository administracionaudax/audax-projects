<?php

namespace App\Domain\Forecast;

use App\Domain\Time\CapacityPlan;
use App\Models\Allocation;
use App\Models\ForecastProject;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;

/**
 * Foto congelada de lo estimado en un previsto al vincularlo (docs/PLAN-CARGAS.md §6.6.4, D-286): el
 * plan completo de sus asignaciones (AllocationPlanner sin «hoy»), en total, por persona, por
 * departamento, por mes y por departamento y mes, con las fechas. Se guarda en
 * forecast_projects.baseline para que «Estimado frente a real» no cambie si luego alguien toca algo.
 *
 * - El departamento de una persona es el suyo en el momento de la foto; el de un hueco, el del hueco.
 * - Una mensual sin fin llega hasta el fin del previsto o, si no tiene, 12 meses desde su inicio.
 *
 * @phpstan-type Baseline array{
 *     version: int,
 *     taken_at: string,
 *     start_date: string|null,
 *     end_date: string|null,
 *     planned_start: string|null,
 *     planned_end: string|null,
 *     estimated_minutes: int|null,
 *     allocated_minutes: int,
 *     by_user: list<array{user_id: int, name: string, department_id: int|null, minutes: int}>,
 *     by_department: list<array{department_id: int|null, name: string|null, minutes: int}>,
 *     by_month: array<string, int>,
 *     by_department_month: list<array{department_id: int|null, months: array<string, int>}>,
 *     allocations: list<array{id: int, user_id: int|null, user_name: string|null, department_id: int|null, department_name: string|null, mode: string, minutes: int|null, percent: int|null, start_date: string, end_date: string|null, planned_minutes: int}>
 * }
 */
final class ForecastBaseline
{
    public const int VERSION = 1;

    /** Meses que cubre una mensual sin fin en un previsto sin fecha de fin. */
    public const int OPEN_MONTHS = 12;

    public function __construct(private readonly AllocationPlanner $planner) {}

    /**
     * @return Baseline
     */
    public function take(ForecastProject $forecast): array
    {
        $allocations = $forecast->allocations()->with(['user:id,name,department_id', 'user.department:id,name', 'department:id,name'])->get()->all();
        $plan = $this->planner->plan($allocations, self::horizon($forecast->end_date?->toDateString(), $forecast->start_date?->toDateString(), $allocations));

        $byUser = [];
        $byDepartment = [];
        $byMonth = [];
        $byDepartmentMonth = [];
        $rows = [];
        $first = null;
        $last = null;

        foreach ($allocations as $allocation) {
            $user = $allocation->user;
            $departmentId = $user !== null ? $user->department_id : $allocation->department_id;
            $departmentName = $user !== null ? $user->department?->name : $allocation->department?->name;
            $total = 0;

            foreach ($plan->days[$allocation->id] ?? [] as $day => $minutes) {
                $month = substr(CapacityPlan::date($day), 0, 7);
                $total += $minutes;
                $byMonth[$month] = ($byMonth[$month] ?? 0) + $minutes;
                $key = (string) ($departmentId ?? '');
                $byDepartmentMonth[$key][$month] = ($byDepartmentMonth[$key][$month] ?? 0) + $minutes;
                $first = $first === null ? $day : min($first, $day);
                $last = $last === null ? $day : max($last, $day);
            }

            if ($user !== null) {
                $byUser[$user->id] ??= ['user_id' => $user->id, 'name' => $user->name, 'department_id' => $user->department_id, 'minutes' => 0];
                $byUser[$user->id]['minutes'] += $total;
            }

            $key = (string) ($departmentId ?? '');
            $byDepartment[$key] ??= ['department_id' => $departmentId, 'name' => $departmentName, 'minutes' => 0];
            $byDepartment[$key]['minutes'] += $total;

            $rows[] = [
                'id' => $allocation->id,
                'user_id' => $allocation->user_id,
                'user_name' => $user?->name,
                'department_id' => $allocation->department_id,
                'department_name' => $allocation->department?->name,
                'mode' => $allocation->mode->value,
                'minutes' => $allocation->minutes,
                'percent' => $allocation->percent,
                'start_date' => $allocation->start_date->toDateString(),
                'end_date' => $allocation->end_date?->toDateString(),
                'planned_minutes' => $total,
            ];
        }

        ksort($byMonth);

        return [
            'version' => self::VERSION,
            'taken_at' => now()->toIso8601String(),
            'start_date' => $forecast->start_date?->toDateString(),
            'end_date' => $forecast->end_date?->toDateString(),
            'planned_start' => $first === null ? null : CapacityPlan::date($first),
            'planned_end' => $last === null ? null : CapacityPlan::date($last),
            'estimated_minutes' => $forecast->estimated_minutes,
            'allocated_minutes' => array_sum($byMonth),
            'by_user' => array_values($byUser),
            'by_department' => array_values($byDepartment),
            'by_month' => $byMonth,
            'by_department_month' => array_map(function (string $key) use ($byDepartmentMonth): array {
                $months = $byDepartmentMonth[$key];
                ksort($months);

                return ['department_id' => $key === '' ? null : (int) $key, 'months' => $months];
            }, array_map('strval', array_keys($byDepartmentMonth))),
            'allocations' => $rows,
        ];
    }

    /**
     * Fin de las mensuales sin fin de un contenedor (previsto o proyecto real): su fecha de fin o, si
     * no tiene, 12 meses desde el inicio más tardío (el suyo o el de sus asignaciones).
     *
     * @param  iterable<Allocation>  $allocations
     */
    public static function horizon(?string $end, ?string $start, iterable $allocations): int
    {
        if ($end !== null) {
            return CapacityPlan::day($end);
        }

        $latest = $start ?? LocalTime::todayString();
        foreach ($allocations as $allocation) {
            $latest = max($latest, $allocation->start_date->toDateString());
        }

        return CapacityPlan::day(CarbonImmutable::parse($latest)->addMonthsNoOverflow(self::OPEN_MONTHS)->subDay()->toDateString());
    }
}
