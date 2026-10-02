<?php

use App\Domain\Time\Capacity;
use App\Domain\Workload\WorkloadPlanner;
use App\Models\Absence;
use App\Models\Holiday;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;

/*
|--------------------------------------------------------------------------
| WorkloadPlanner: las optimizaciones de rendimiento de la vista Carga (Fase 3, W2) no cambian el
| reparto. Se compara con el cálculo directo de D-051 (con CarbonPeriod, día a día) en un escenario
| con muchas tareas al azar (semilla fija): vencidas, sin inicio, más allá del año, con horas
| imputadas, festivos, ausencias completas y parciales y jornadas distintas. Hoy es miércoles a las
| 23:30 en Madrid (ya jueves en UTC) y el límite del año cae en viernes: se nota si el último día
| que se reparte no es el mismo. Más allá del año, los días laborables se cuentan con la jornada
| vigente en el tope (revisión global de la Fase 3).
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-21 23:30', 'Europe/Madrid'));
    mt_srand(20261023);

    $this->users = User::factory()->employee()->count(5)->create();
    WorkSchedule::factory()->for($this->users[1])->create(['valid_from' => '2026-01-01', 'fri_minutes' => 240, 'sat_minutes' => 180]);
    WorkSchedule::factory()->for($this->users[2])->create(['valid_from' => '2026-11-15', 'mon_minutes' => 0]);
    Holiday::factory()->create(['date' => '2026-11-02', 'name' => 'Todos los Santos (trasladado)']);
    Holiday::factory()->create(['date' => '2026-12-08', 'name' => 'Inmaculada']);
    Absence::factory()->approved()->between('2026-10-26', '2026-10-30')->create(['user_id' => $this->users[0]->id]);
    Absence::factory()->approved()->partial(120)->between('2026-11-04', '2026-11-04')->create(['user_id' => $this->users[3]->id, 'type' => 'training']);
    // Sin ningún día con capacidad en el rango de alguna de sus tareas.
    Absence::factory()->approved()->between('2026-11-09', '2026-11-20')->create(['user_id' => $this->users[4]->id]);

    $project = Project::factory()->create();
    $today = CarbonImmutable::parse('2026-10-21');

    foreach (range(1, 120) as $i) {
        $user = $this->users[mt_rand(0, 4)];
        $due = $today->addDays(mt_rand(-15, 420));
        $start = mt_rand(0, 3) === 0 ? null : $due->subDays(mt_rand(0, 60));
        $task = Task::factory()->create([
            'project_id' => $project->id,
            'assignee_user_id' => $user->id,
            'estimated_minutes' => mt_rand(0, 8) === 0 ? null : mt_rand(1, 60) * 30,
            'start_date' => $start?->toDateString(),
            'due_date' => mt_rand(0, 12) === 0 ? null : $due->toDateString(),
        ]);

        if (mt_rand(0, 2) === 0) {
            TimeEntry::factory()->forTask($task)->on('2026-10-20')->minutes(mt_rand(1, 20) * 15)->create(['user_id' => $user->id]);
        }
    }
});

it('reparte exactamente como el cálculo directo de D-051', function () {
    $ids = $this->users->pluck('id')->all();
    $from = CarbonImmutable::parse('2026-10-19');
    $to = CarbonImmutable::parse('2027-10-31');
    $plan = app(WorkloadPlanner::class)->plan($ids, $from, $to);

    // Referencia: el algoritmo de D-051 tal cual, con CarbonPeriod y la capacidad de Capacity.
    $today = LocalTime::today();
    $limit = $today->addDays(WorkloadPlanner::MAX_DAYS_AHEAD);
    $capacity = [];
    $weeks = [];
    foreach ($ids as $index => $id) {
        $capacity[$id] = app(Capacity::class)->forRange($this->users[$index], $from < $today ? $from : $today, $limit);
        // Jornada vigente en el tope, sin festivos ni ausencias (la de su horario o la de por defecto).
        $weeks[$id] = WorkSchedule::query()->where('user_id', $id)
            ->where('valid_from', '<=', $limit->toDateString())
            ->where(fn ($query) => $query->whereNull('valid_to')->orWhere('valid_to', '>=', $limit->toDateString()))
            ->orderByDesc('valid_from')
            ->first()?->weekMinutes() ?? Capacity::defaultWeek();
    }

    $load = [];
    $contributions = [];
    $overdue = [];
    $tasks = app(WorkloadPlanner::class)->openTasks()->whereIn('assignee_user_id', $ids)->get();

    foreach ($tasks as $task) {
        $remaining = max((int) $task->estimated_minutes - (int) ($task->time_entries_sum_minutes ?? 0), 0);

        if (! $task->estimated_minutes || $task->due_date === null || $remaining <= 0) {
            continue;
        }

        $due = CarbonImmutable::parse($task->due_date->toDateString());

        if ($due < $today) {
            $overdue[] = $task->id;
            $days = [$today->toDateString() => $remaining];
        } else {
            $start = $task->start_date !== null && $task->start_date->toDateString() > $today->toDateString()
                ? CarbonImmutable::parse($task->start_date->toDateString())
                : $today;
            $start = $start > $due ? $due : $start;
            $end = $due > $limit ? $limit : $due;
            $working = [];
            $later = 0;

            // Todos los días hasta la entrega: hasta el tope, con la capacidad real; después, con la
            // jornada vigente en el tope (sin festivos ni ausencias), solo para contar.
            foreach (CarbonPeriod::create($start, $due) as $day) {
                if ($day <= $end) {
                    if (($capacity[$task->assignee_user_id][$day->toDateString()] ?? 0) > 0) {
                        $working[] = $day->toDateString();
                    }
                } elseif ($weeks[$task->assignee_user_id][$day->dayOfWeekIso - 1] > 0) {
                    $later++;
                }
            }

            $count = count($working) + $later;

            if ($count === 0) {
                $days = [$start->toDateString() => $remaining];
            } else {
                $days = [];
                foreach ($working as $index => $date) {
                    $minutes = intdiv($remaining, $count) + ($index < $remaining % $count ? 1 : 0);
                    if ($minutes > 0) {
                        $days[$date] = $minutes;
                    }
                }
            }
        }

        foreach ($days as $date => $minutes) {
            if ($date >= $from->toDateString() && $date <= $to->toDateString()) {
                $load[$task->assignee_user_id][$date] = ($load[$task->assignee_user_id][$date] ?? 0) + $minutes;
                $contributions[$task->assignee_user_id][$date][$task->id] = $minutes;
            }
        }
    }

    $sort = function (array $byUser): array {
        ksort($byUser);
        foreach ($byUser as &$byDate) {
            ksort($byDate);
        }

        return $byUser;
    };

    expect($sort($plan->load))->toBe($sort($load))
        ->and($plan->contributions)->toEqual($contributions)
        ->and(collect($plan->overdue)->sort()->values()->all())->toBe(collect($overdue)->sort()->values()->all())
        ->and($load)->not->toBe([])
        ->and($overdue)->not->toBe([]);
});
