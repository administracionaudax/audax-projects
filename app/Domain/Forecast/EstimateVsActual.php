<?php

namespace App\Domain\Forecast;

use App\Domain\Time\CapacityPlan;
use App\Enums\ProjectStatus;
use App\Models\Department;
use App\Models\ForecastProject;
use App\Models\TimeEntry;
use App\Models\User;
use App\Support\LocalTime;

/**
 * Estimado frente a real de un previsto vinculado (docs/PLAN-CARGAS.md §6.7, D-287):
 *
 * - **Estimado**: la línea base congelada al vincular (ForecastBaseline), nunca las asignaciones
 *   vivas ni las horas estimadas de las tareas (P6).
 * - **Real**: todas las horas imputadas en el proyecto vinculado, sea cual sea su estado (el mismo
 *   criterio que el consumo y el «Estimado frente a real» del proyecto, D-081 y D-084).
 * - **Por persona**: su user_id. **Por departamento**: el estimado, con el departamento de la foto;
 *   el real, con el departamento de la persona hoy (v1). **Por mes**: el estimado de la foto y el
 *   real por la fecha de la entrada, con los acumulados para la curva.
 * - **Previsión al cerrar** (D-297, P6): lo real más lo que **queda asignado** en el proyecto real
 *   de hoy en adelante (sus asignaciones vivas, con el restante del modo total), por persona, por
 *   departamento (el de la persona hoy o el del hueco) y por mes (`projected`).
 * - **Fechas**: inicio real = primera entrada; fin real = la última entrada si el proyecto está
 *   completado; si sigue en curso, el fin previsto es el último día con algo asignado (D-297); sin
 *   nada asignado, al ritmo de las últimas 4 semanas (días naturales).
 * - **Desviación** = (real − estimado) / estimado, en %, con un decimal; null sin estimado.
 *
 * Tres consultas: las horas por persona y día, las personas y los departamentos.
 *
 * @phpstan-type Row array{estimated: int, actual: int, projected: int, deviation_percent: float|null, projected_deviation_percent: float|null}
 * @phpstan-type Comparison array{
 *     project: array{id: int, code: string, name: string, status: string},
 *     taken_at: string|null,
 *     totals: Row,
 *     dates: array{estimated_start: string|null, estimated_end: string|null, actual_start: string|null, actual_end: string|null, projected_end: string|null},
 *     by_department: list<array{department_id: int|null, name: string|null, estimated: int, actual: int, projected: int, deviation_percent: float|null, projected_deviation_percent: float|null}>,
 *     by_user: list<array{user_id: int, name: string, department_id: int|null, estimated: int, actual: int, projected: int, deviation_percent: float|null, projected_deviation_percent: float|null}>,
 *     by_month: list<array{month: string, estimated: int, actual: int, remaining: int, cumulative_estimated: int, cumulative_actual: int, cumulative_projected: int}>,
 *     by_department_month: list<array{department_id: int|null, months: list<array{month: string, estimated: int, actual: int}>}>
 * }
 */
final class EstimateVsActual
{
    /** Días del ritmo con el que se proyecta el fin de un proyecto en curso. */
    public const int PACE_DAYS = 28;

    public function __construct(private readonly AllocationPlanner $planner) {}

    /**
     * @return Comparison|null null si el previsto no está vinculado o no tiene línea base
     */
    public function for(ForecastProject $forecast): ?array
    {
        $baseline = $forecast->baseline;
        $project = $forecast->project;

        if ($baseline === null || $project === null) {
            return null;
        }

        /** @var array<int, array<string, int>> $actualByUserMonth */
        $actualByUserMonth = [];
        $firstDate = null;
        $lastDate = null;
        $recent = 0;
        $paceFrom = LocalTime::today()->subDays(self::PACE_DAYS - 1)->toDateString();

        $rows = TimeEntry::query()
            ->where('project_id', $project->id)
            ->toBase()
            ->selectRaw('user_id, date, SUM(minutes) as minutes')
            ->groupBy('user_id', 'date')
            ->get();

        foreach ($rows as $row) {
            $date = substr((string) $row->date, 0, 10);
            $month = substr($date, 0, 7);
            $minutes = (int) $row->minutes;
            $actualByUserMonth[(int) $row->user_id][$month] = ($actualByUserMonth[(int) $row->user_id][$month] ?? 0) + $minutes;
            $firstDate = $firstDate === null ? $date : min($firstDate, $date);
            $lastDate = $lastDate === null ? $date : max($lastDate, $date);

            if ($date >= $paceFrom) {
                $recent += $minutes;
            }
        }

        $estimatedByUser = [];
        foreach ($baseline['by_user'] ?? [] as $row) {
            $estimatedByUser[(int) $row['user_id']] = (int) $row['minutes'];
        }

        // Lo que queda asignado en el proyecto real, de hoy en adelante (D-297).
        $completed = $project->status === ProjectStatus::Completed || $project->status === ProjectStatus::Archived;
        $remainingByUser = [];
        $remainingByGap = [];
        $remainingByMonth = [];
        $lastPlanned = null;
        if (! $completed) {
            $allocations = $project->allocations()->get();
            $allocations->each(fn ($allocation) => $allocation->setRelation('project', $project));
            $today = CapacityPlan::day(LocalTime::todayString());
            $horizon = ForecastBaseline::horizon($project->due_date?->toDateString(), $project->start_date?->toDateString(), $allocations);
            $forward = $this->planner->plan($allocations->all(), max($horizon, $today), $today, AllocationPlanner::logged($allocations));

            foreach ($allocations as $allocation) {
                foreach ($forward->days[$allocation->id] ?? [] as $day => $minutes) {
                    if ($minutes <= 0) {
                        continue;
                    }

                    $month = substr(CapacityPlan::date($day), 0, 7);
                    $remainingByMonth[$month] = ($remainingByMonth[$month] ?? 0) + $minutes;
                    $lastPlanned = $lastPlanned === null ? $day : max($lastPlanned, $day);

                    if ($allocation->user_id !== null) {
                        $remainingByUser[$allocation->user_id] = ($remainingByUser[$allocation->user_id] ?? 0) + $minutes;
                    } else {
                        $key = (string) ($allocation->department_id ?? '');
                        $remainingByGap[$key] = ($remainingByGap[$key] ?? 0) + $minutes;
                    }
                }
            }
        }

        $users = User::query()
            ->whereKey(array_unique([...array_keys($actualByUserMonth), ...array_keys($estimatedByUser), ...array_keys($remainingByUser)]))
            ->get(['id', 'name', 'department_id'])
            ->keyBy('id');

        // Lo que queda, por departamento (el de la persona hoy o el del hueco).
        $remainingByDepartment = $remainingByGap;
        foreach ($remainingByUser as $userId => $minutes) {
            $key = (string) ($users->get($userId)->department_id ?? '');
            $remainingByDepartment[$key] = ($remainingByDepartment[$key] ?? 0) + $minutes;
        }

        // Real por departamento (el de hoy) y por mes.
        $actualByDepartment = [];
        $actualByMonth = [];
        $actualByDepartmentMonth = [];
        foreach ($actualByUserMonth as $userId => $months) {
            $key = (string) ($users->get($userId)->department_id ?? '');

            foreach ($months as $month => $minutes) {
                $actualByDepartment[$key] = ($actualByDepartment[$key] ?? 0) + $minutes;
                $actualByMonth[$month] = ($actualByMonth[$month] ?? 0) + $minutes;
                $actualByDepartmentMonth[$key][$month] = ($actualByDepartmentMonth[$key][$month] ?? 0) + $minutes;
            }
        }

        $estimatedByDepartment = [];
        foreach ($baseline['by_department'] ?? [] as $row) {
            $estimatedByDepartment[(string) ($row['department_id'] ?? '')] = (int) $row['minutes'];
        }
        $estimatedByDepartmentMonth = [];
        foreach ($baseline['by_department_month'] ?? [] as $row) {
            $estimatedByDepartmentMonth[(string) ($row['department_id'] ?? '')] = array_map('intval', $row['months']);
        }
        /** @var array<string, int> $estimatedByMonth */
        $estimatedByMonth = array_map('intval', $baseline['by_month'] ?? []);

        $departmentKeys = array_values(array_unique(array_map('strval', [...array_keys($estimatedByDepartment), ...array_keys($actualByDepartment), ...array_keys($remainingByDepartment)])));
        $departments = Department::withTrashed()
            ->whereKey(array_map('intval', array_filter($departmentKeys, fn (string $key): bool => $key !== '')))
            ->pluck('name', 'id');

        $estimated = (int) ($baseline['allocated_minutes'] ?? 0);
        $actual = array_sum($actualByMonth);
        $projected = $actual + array_sum($remainingByMonth);

        $months = array_values(array_unique([...array_keys($estimatedByMonth), ...array_keys($actualByMonth), ...array_keys($remainingByMonth)]));
        sort($months);
        $byMonth = [];
        $cumulativeEstimated = 0;
        $cumulativeActual = 0;
        $cumulativeProjected = 0;
        foreach ($months as $month) {
            $cumulativeEstimated += $estimatedByMonth[$month] ?? 0;
            $cumulativeActual += $actualByMonth[$month] ?? 0;
            $cumulativeProjected += ($actualByMonth[$month] ?? 0) + ($remainingByMonth[$month] ?? 0);
            $byMonth[] = [
                'month' => $month,
                'estimated' => $estimatedByMonth[$month] ?? 0,
                'actual' => $actualByMonth[$month] ?? 0,
                'remaining' => $remainingByMonth[$month] ?? 0,
                'cumulative_estimated' => $cumulativeEstimated,
                'cumulative_actual' => $cumulativeActual,
                'cumulative_projected' => $cumulativeProjected,
            ];
        }

        $pace = $recent / self::PACE_DAYS;
        $remaining = $estimated - $actual;

        return [
            'project' => ['id' => $project->id, 'code' => $project->code, 'name' => $project->name, 'status' => $project->status->value],
            'taken_at' => $baseline['taken_at'] ?? null,
            'totals' => self::row($estimated, $actual, $projected),
            'dates' => [
                'estimated_start' => $baseline['planned_start'] ?? $baseline['start_date'] ?? null,
                'estimated_end' => $baseline['planned_end'] ?? $baseline['end_date'] ?? null,
                'actual_start' => $firstDate,
                'actual_end' => $completed ? $lastDate : null,
                'projected_end' => match (true) {
                    $completed => null,
                    $lastPlanned !== null => CapacityPlan::date($lastPlanned),
                    $remaining > 0 && $pace > 0 => LocalTime::today()->addDays((int) ceil($remaining / $pace))->toDateString(),
                    default => null,
                },
            ],
            'by_department' => array_map(fn (string $key): array => [
                'department_id' => $key === '' ? null : (int) $key,
                'name' => $key === '' ? null : ($departments[(int) $key] ?? null),
                ...self::row($estimatedByDepartment[$key] ?? 0, $actualByDepartment[$key] ?? 0, ($actualByDepartment[$key] ?? 0) + ($remainingByDepartment[$key] ?? 0)),
            ], $departmentKeys),
            'by_user' => array_values($users->map(fn (User $user): array => [
                'user_id' => $user->id,
                'name' => $user->name,
                'department_id' => $user->department_id,
                ...self::row($estimatedByUser[$user->id] ?? 0, array_sum($actualByUserMonth[$user->id] ?? []), array_sum($actualByUserMonth[$user->id] ?? []) + ($remainingByUser[$user->id] ?? 0)),
            ])->sortBy('name')->values()->all()),
            'by_month' => $byMonth,
            'by_department_month' => array_map(fn (string $key): array => [
                'department_id' => $key === '' ? null : (int) $key,
                'months' => array_map(fn (string $month): array => [
                    'month' => $month,
                    'estimated' => $estimatedByDepartmentMonth[$key][$month] ?? 0,
                    'actual' => $actualByDepartmentMonth[$key][$month] ?? 0,
                ], $months),
            ], $departmentKeys),
        ];
    }

    /**
     * @return Row
     */
    private static function row(int $estimated, int $actual, int $projected): array
    {
        return [
            'estimated' => $estimated,
            'actual' => $actual,
            'projected' => $projected,
            'deviation_percent' => self::deviation($estimated, $actual),
            'projected_deviation_percent' => self::deviation($estimated, $projected),
        ];
    }

    /**
     * Desviación en % con un decimal: (real − estimado) / estimado. Sin estimado, null. La interfaz
     * dice «Igual que lo estimado» por debajo de medio punto (como D-079; tests/fixtures/forecast-deviation.json).
     */
    public static function deviation(int $estimated, int $actual): ?float
    {
        if ($estimated <= 0) {
            return null;
        }

        return round(($actual - $estimated) / $estimated * 100, 1);
    }
}
