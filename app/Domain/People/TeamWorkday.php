<?php

namespace App\Domain\People;

use App\Domain\Time\Week;
use App\Models\ClockCorrection;
use App\Models\User;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;

/**
 * «Jornada del equipo» (PLAN-FASE-11 §6.2 y §6.3; D-341): para el responsable (su departamento) y
 * RR. HH. (toda la plantilla), una tabla persona × día de la semana con lo trabajado frente a la
 * teórica y el semáforo de cada día, más cómo está cada persona ahora (W-023: trabajando, en
 * pausa, jornada cerrada, no ha fichado, ausente o no trabaja hoy) y las correcciones que esperan
 * su decisión.
 *
 * @phpstan-import-type Day from WorkdayCalculator
 */
final class TeamWorkday
{
    public function __construct(private readonly WorkdayCalculator $calculator) {}

    /**
     * @return array<string, mixed>
     */
    public function for(User $viewer, Week $week, ?int $departmentId): array
    {
        $now = CarbonImmutable::now();
        $today = LocalTime::dateOf($now);
        $from = $week->start->toDateString();
        $to = $week->end()->toDateString();

        $people = PeopleAccess::teamQuery($viewer, $departmentId)
            ->with(['department:id,name,color', 'employmentProfile'])
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'department_id', 'avatar_path', 'is_active']);

        $rangeFrom = min($from, $today);
        $rangeTo = max($to, $today);
        $days = $this->calculator->forUsers($people->all(), $rangeFrom, $rangeTo, $viewer, $now);

        $dates = [];
        for ($date = $week->start; $date->toDateString() <= $to; $date = $date->addDay()) {
            $dates[] = $date->toDateString();
        }

        $rows = [];
        foreach ($people as $person) {
            $own = $days[$person->id] ?? [];
            $weekDays = array_intersect_key($own, array_flip($dates));

            $rows[] = [
                'id' => $person->id,
                'name' => $person->name,
                'avatar' => $person->avatar_url,
                'department' => $person->department === null ? null : ['id' => $person->department->id, 'name' => $person->department->name, 'color' => $person->department->color],
                'now' => isset($own[$today]) ? self::now($own[$today]) : null,
                'days' => array_map(fn (array $day): array => [
                    'date' => $day['date'],
                    'worked_minutes' => $day['worked_minutes'],
                    'expected_minutes' => $day['expected_minutes'],
                    'difference_minutes' => $day['difference_minutes'],
                    'status' => $day['status'],
                    'incidents' => $day['incidents'],
                    'holiday' => $day['holiday'] !== null,
                    'absence' => $day['absence'] !== null && $day['absence']['partial_minutes'] === null,
                    'in_progress' => $day['in_progress'],
                ], array_values($weekDays)),
                'totals' => WorkdayCalculator::totals($weekDays),
            ];
        }

        return [
            'week' => $week->iso(),
            'previous_week' => Week::containing($week->start->subWeek())->iso(),
            'next_week' => $week->end()->toDateString() < $today ? Week::containing($week->start->addWeek())->iso() : null,
            'dates' => $dates,
            'today' => $today,
            'members' => $rows,
            'departments' => PeopleAccess::departments($viewer)->map(fn ($department): array => ['id' => $department->id, 'name' => $department->name, 'color' => $department->color])->values()->all(),
            'department_id' => $departmentId,
            'pending' => $this->pendingFor($viewer),
        ];
    }

    /**
     * Cuántas correcciones esperan la decisión de $viewer (las que proponen las personas de su
     * equipo, o de todos si es RR. HH.). Para el contador de «Pendientes».
     */
    public function pendingFor(User $viewer): int
    {
        return count($this->pendingCorrections($viewer));
    }

    /**
     * @return list<ClockCorrection>
     */
    public function pendingCorrections(User $viewer): array
    {
        $query = ClockCorrection::query()
            ->pending()
            ->where('user_id', '!=', $viewer->id)
            ->whereColumn('proposed_by', 'user_id')
            ->orderBy('date')
            ->orderBy('id');

        if (! PeopleAccess::managesAll($viewer)) {
            $ids = $viewer->managedDepartmentIds();
            $query->whereHas('user', fn ($user) => $user->whereIn('department_id', $ids ?: [0]));
        }

        /** @var list<ClockCorrection> */
        return array_values(array_filter(
            $query->with('user')->get()->all(),
            fn (ClockCorrection $correction): bool => PeopleAccess::decides($viewer, $correction, $correction->user),
        ));
    }

    /**
     * Cómo está ahora (W-023), según el diario de hoy.
     *
     * @param  Day  $day
     * @return array{state: string, since: string|null}
     */
    private static function now(array $day): array
    {
        $open = null;
        foreach ($day['workdays'] as $workday) {
            if ($workday['open'] && ! $workday['stale']) {
                $open = $workday;
            }
        }

        if ($open !== null) {
            $segment = $open['segments'][count($open['segments']) - 1];

            return ['state' => $segment['kind'] === 'pause' ? 'paused' : 'working', 'since' => $segment['from']];
        }

        if ($day['workdays'] !== []) {
            $last = $day['workdays'][count($day['workdays']) - 1];

            return ['state' => 'closed', 'since' => $last['clock_out']];
        }

        return match (true) {
            $day['absence'] !== null && $day['absence']['partial_minutes'] === null => ['state' => 'absent', 'since' => null],
            $day['capacity_minutes'] === 0 => ['state' => 'not_working', 'since' => null],
            default => ['state' => 'not_clocked', 'since' => null],
        };
    }
}
