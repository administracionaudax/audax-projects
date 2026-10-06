<?php

namespace App\Domain\Forecast;

use App\Domain\Time\Capacity;
use App\Domain\Time\CapacityPlan;
use App\Models\Holiday;
use Carbon\CarbonImmutable;

/**
 * Capacidad por día de las personas y los departamentos que necesita la previsión
 * (docs/PLAN-CARGAS.md §6.2 y §6.3, D-282):
 *
 * - **Persona**: Capacity tal cual (jornada vigente − festivos − ausencias aprobadas), calculada por
 *   tramos (CapacityPlan) con una consulta de horarios, una de festivos y una de ausencias en total.
 * - **Más allá de un año** (D-051): la jornada semanal vigente en el tope, sin festivos ni ausencias.
 * - **Departamento**: la suma de sus personas de plantilla activas (admin, responsables y empleados;
 *   nunca colaboradores externos, D-134), con la plantilla de hoy (R6). Un día es laborable para un
 *   departamento si alguna de ellas tiene capacidad. Un departamento sin nadie usa la jornada por
 *   defecto sin los festivos solo para saber qué días son laborables (su capacidad es 0).
 *
 * Los días son números de día desde el 01/01/1970 (CapacityPlan::day), para no crear fechas.
 */
final class CapacityCalendar
{
    /** @var array<string, list<int>> «u12» o «d3» → días laborables en orden (memoria) */
    private array $workdays = [];

    /** @var array<int, array<int, int>> departamento → día → minutos (memoria) */
    private array $departmentDays = [];

    /**
     * @param  array<int, array<int, int>>  $days  persona → día → minutos, de $from a $limit
     * @param  array<int, list<int>>  $weeks  persona → jornada (lunes primero) más allá de $limit
     * @param  array<int, list<int>>  $members  departamento → personas
     * @param  list<int>  $defaultWeek
     * @param  array<int, true>  $holidays  días festivos (para los departamentos sin nadie)
     */
    public function __construct(
        public readonly int $from,
        public readonly int $to,
        public readonly int $limit,
        private readonly array $days,
        private readonly array $weeks,
        private readonly array $members,
        private readonly array $defaultWeek,
        private readonly array $holidays,
    ) {}

    /**
     * Calcula la capacidad de $userIds y de los miembros de $departmentIds entre $from y $to (días).
     * Lo que pase de $limit se cuenta con la jornada vigente en $limit.
     *
     * @param  list<int>  $userIds
     * @param  list<int>  $departmentIds
     * @param  array<int, list<int>>|null  $members  departamento → personas (si ya se tienen)
     */
    public static function build(Capacity $capacity, array $userIds, array $departmentIds, int $from, int $to, int $limit, ?array $members = null): self
    {
        $members ??= self::membersOf($departmentIds);
        $members = array_intersect_key($members, array_flip($departmentIds)) + array_fill_keys($departmentIds, []);
        foreach ($members as $memberIds) {
            array_push($userIds, ...$memberIds);
        }
        $userIds = array_values(array_unique($userIds));

        $end = min($to, $limit);
        $days = [];
        if ($userIds !== [] && $end >= $from) {
            $fromDate = CarbonImmutable::parse(CapacityPlan::date($from));
            $toDate = CarbonImmutable::parse(CapacityPlan::date($end));
            $ranges = array_map(fn (int $id): array => ['user_id' => $id, 'from' => $fromDate, 'to' => $toDate], $userIds);

            foreach ($capacity->plansForRanges($ranges) as $index => $plan) {
                $days[$userIds[$index]] = self::expand($plan);
            }
        }

        $weeks = [];
        if ($userIds !== [] && $to > $limit) {
            $weeks = $capacity->weeksOn($userIds, CarbonImmutable::parse(CapacityPlan::date($limit)));
        }

        $holidays = [];
        if (array_filter($members, fn (array $ids): bool => $ids === []) !== []) {
            foreach (Holiday::query()->whereBetween('date', [CapacityPlan::date($from), CapacityPlan::date($to)])->pluck('date') as $date) {
                $holidays[CapacityPlan::day(CarbonImmutable::parse($date)->toDateString())] = true;
            }
        }

        return new self($from, $to, $limit, $days, $weeks, $members, Capacity::defaultWeek(), $holidays);
    }

    /**
     * Personas de plantilla activas de cada departamento (la plantilla de hoy, R6). Una consulta.
     *
     * @param  list<int>|null  $departmentIds  null: todos
     * @return array<int, list<int>>
     */
    public static function membersOf(?array $departmentIds = null): array
    {
        $members = [];

        foreach (ForecastPeople::staff()
            ->whereNotNull('department_id')
            ->when($departmentIds !== null, fn ($query) => $query->whereIn('department_id', $departmentIds ?? []))
            ->orderBy('id')
            ->get(['id', 'department_id']) as $user) {
            $members[(int) $user->department_id][] = $user->id;
        }

        return $members;
    }

    /**
     * Minutos de capacidad de una persona un día (0 fuera del calendario calculado).
     */
    public function forUser(int $userId, int $day): int
    {
        if ($day > $this->limit) {
            $week = $this->weeks[$userId] ?? null;

            return $week === null ? 0 : $week[CapacityPlan::weekday($day) - 1];
        }

        return $this->days[$userId][$day] ?? 0;
    }

    /**
     * Minutos de capacidad de un departamento un día: la suma de sus personas.
     */
    public function forDepartment(int $departmentId, int $day): int
    {
        if ($day <= $this->limit) {
            $this->departmentDays[$departmentId] ??= $this->departmentDays($departmentId);

            return $this->departmentDays[$departmentId][$day] ?? 0;
        }

        $total = 0;
        foreach ($this->members[$departmentId] ?? [] as $userId) {
            $total += $this->forUser($userId, $day);
        }

        return $total;
    }

    /** ¿Es laborable el día para la persona (capacidad > 0)? */
    public function userWorks(int $userId, int $day): bool
    {
        return $this->forUser($userId, $day) > 0;
    }

    /**
     * ¿Es laborable el día para el departamento? Si alguna de sus personas tiene capacidad; sin
     * nadie, según la jornada por defecto y los festivos.
     */
    public function departmentWorks(int $departmentId, int $day): bool
    {
        if (($this->members[$departmentId] ?? []) === []) {
            return ! isset($this->holidays[$day]) && $this->defaultWeek[CapacityPlan::weekday($day) - 1] > 0;
        }

        return $this->forDepartment($departmentId, $day) > 0;
    }

    /**
     * Días laborables (en orden) de una persona o de un departamento en todo el calendario, una vez
     * por cada una (rendimiento: el reparto los recorre por tramos).
     *
     * @return list<int>
     */
    public function workdays(?int $userId, ?int $departmentId): array
    {
        $key = $userId !== null ? "u{$userId}" : "d{$departmentId}";

        if (! isset($this->workdays[$key])) {
            $days = [];

            for ($day = $this->from; $day <= $this->to; $day++) {
                if ($userId !== null ? $this->userWorks($userId, $day) : $this->departmentWorks((int) $departmentId, $day)) {
                    $days[] = $day;
                }
            }

            $this->workdays[$key] = $days;
        }

        return $this->workdays[$key];
    }

    /** Minutos de la jornada por defecto ese día de la semana (el «% de una persona» de un hueco). */
    public function defaultMinutes(int $day): int
    {
        return $this->defaultWeek[CapacityPlan::weekday($day) - 1];
    }

    /**
     * @return list<int>
     */
    public function membersOfDepartment(int $departmentId): array
    {
        return $this->members[$departmentId] ?? [];
    }

    /**
     * @return array<int, int>
     */
    private function departmentDays(int $departmentId): array
    {
        $total = [];

        foreach ($this->members[$departmentId] ?? [] as $userId) {
            foreach ($this->days[$userId] ?? [] as $day => $minutes) {
                $total[$day] = ($total[$day] ?? 0) + $minutes;
            }
        }

        return $total;
    }

    /**
     * Los tramos de un CapacityPlan como día → minutos, sin crear fechas (rendimiento).
     *
     * @return array<int, int>
     */
    private static function expand(CapacityPlan $plan): array
    {
        $days = [];

        foreach ($plan->segments as $segment) {
            $week = $segment['week'];
            $weekday = CapacityPlan::weekday($segment['from']) - 1;

            for ($day = $segment['from']; $day <= $segment['to']; $day++) {
                $days[$day] = $plan->overrides[$day] ?? $week[$weekday];
                $weekday = $weekday === 6 ? 0 : $weekday + 1;
            }
        }

        return $days;
    }
}
