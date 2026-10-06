<?php

use App\Domain\Forecast\AllocationPlanner;
use App\Domain\Time\CapacityPlan;
use App\Enums\AbsenceStatus;
use App\Models\Absence;
use App\Models\Allocation;
use App\Models\Department;
use App\Models\ForecastProject;
use App\Models\Holiday;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\WorkSchedule;
use Carbon\CarbonImmutable;

/*
| Reparto de las asignaciones (docs/PLAN-CARGAS.md §6.2, D-282): los casos de
| tests/fixtures/allocations.json (cada modo con festivos, ausencias, huecos y meses partidos) y los
| que dependen de horas imputadas o de la fecha de hoy (restante, vencidas, plan fijo y más de un año).
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 09:00:00', 'Europe/Madrid'));
    $this->planner = app(AllocationPlanner::class);

    /** Días → fechas, para comparar. */
    $this->dates = fn (array $days): array => collect($days)->mapWithKeys(fn (int $minutes, int $day): array => [CapacityPlan::date($day) => $minutes])->sortKeys()->all();
});

$cases = json_decode((string) file_get_contents(__DIR__.'/../../fixtures/allocations.json'), true)['cases'];

dataset('reparto', array_combine(array_column($cases, 'name'), array_map(fn (array $case): array => [$case], $cases)));

it('reparte cada modo como dice el caso compartido', function (array $case) {
    foreach ($case['holidays'] ?? [] as $date) {
        Holiday::factory()->create(['date' => $date]);
    }

    $absence = function (User $user, array $data): void {
        Absence::factory()->between($data['from'], $data['to'])->create([
            'user_id' => $user->id,
            'partial_minutes' => $data['partial'],
            'status' => AbsenceStatus::from($data['status']),
        ]);
    };

    $data = $case['allocation'];
    $factory = Allocation::factory()->forForecast()->between($data['start'], $data['end']);
    $factory = match ($data['mode']) {
        'total' => $factory->total($data['minutes']),
        'per_day' => $factory->perDay($data['minutes']),
        'percent' => $factory->percent($data['percent']),
        'monthly' => $factory->monthly($data['minutes']),
    };

    if ($data['gap'] ?? false) {
        $department = Department::factory()->create();
        $members = User::factory()->employee()->count($data['members'])->create(['department_id' => $department->id]);
        foreach ($case['member_absences'] ?? [] as $item) {
            $absence($members->first(), $item);
        }
        $allocation = $factory->gap($department)->create();
    } else {
        $user = User::factory()->employee()->create();
        if (isset($case['schedule'])) {
            WorkSchedule::factory()->create(['user_id' => $user->id, ...array_combine(
                ['mon_minutes', 'tue_minutes', 'wed_minutes', 'thu_minutes', 'fri_minutes', 'sat_minutes', 'sun_minutes'],
                $case['schedule'],
            )]);
        }
        foreach ($case['absences'] ?? [] as $item) {
            $absence($user, $item);
        }
        $allocation = $factory->forUser($user)->create();
    }

    $plan = $this->planner->plan([$allocation->fresh()], CapacityPlan::day($case['horizon'] ?? '2026-12-31'));
    $days = ($this->dates)($plan->days[$allocation->id]);

    if (isset($case['expected'])) {
        expect($days)->toBe($case['expected']);
    }

    if (isset($case['expected_total'])) {
        expect(array_sum($days))->toBe($case['expected_total'])
            ->and(count($days))->toBe($case['expected_days']);
    }

    foreach ($case['expected_first'] ?? [] as $date => $minutes) {
        expect($days[$date])->toBe($minutes);
    }

    expect(in_array($allocation->id, $plan->unscheduled, true))->toBe($case['unscheduled'] ?? false);
})->with('reparto');

describe('carga desde hoy', function () {
    beforeEach(function () {
        $this->user = User::factory()->employee()->create();
        $this->project = Project::factory()->create();
        $this->project->addMember($this->user);
        $this->task = Task::factory()->create(['project_id' => $this->project->id]);
        $this->today = CapacityPlan::day('2026-11-04');
    });

    it('en un proyecto real, el modo total de una persona cuenta su restante desde hoy', function () {
        $allocation = Allocation::factory()->forProject($this->project)->forUser($this->user)
            ->total(2400)->between('2026-11-02', '2026-11-13')->create();
        // Imputado dentro del rango (cuenta) y fuera (no cuenta) y de otra persona (no cuenta).
        TimeEntry::factory()->forTask($this->task)->on('2026-11-02')->minutes(600)->create(['user_id' => $this->user->id]);
        TimeEntry::factory()->forTask($this->task)->on('2026-10-30')->minutes(300)->create(['user_id' => $this->user->id]);
        TimeEntry::factory()->forTask($this->task)->on('2026-11-03')->minutes(300)->create();

        $logged = AllocationPlanner::logged([$allocation]);
        expect($logged)->toBe([$allocation->id => 600]);

        $plan = $this->planner->plan([$allocation->fresh()], CapacityPlan::day('2026-12-31'), $this->today, $logged);
        $days = ($this->dates)($plan->days[$allocation->id]);

        // 1.800 min entre los 8 días laborables del 4 al 13 de noviembre.
        expect($days)->toHaveCount(8)
            ->and(array_sum($days))->toBe(1800)
            ->and($days['2026-11-04'])->toBe(225)
            ->and(array_key_exists('2026-11-03', $days))->toBeFalse();
    });

    it('sin restante, no carga nada', function () {
        $allocation = Allocation::factory()->forProject($this->project)->forUser($this->user)
            ->total(600)->between('2026-11-02', '2026-11-13')->create();
        TimeEntry::factory()->forTask($this->task)->on('2026-11-02')->minutes(700)->create(['user_id' => $this->user->id]);

        $plan = $this->planner->plan([$allocation->fresh()], CapacityPlan::day('2026-12-31'), $this->today, AllocationPlanner::logged([$allocation]));

        expect($plan->days[$allocation->id])->toBe([]);
    });

    it('con el fin ya pasado y restante, todo va a hoy y se marca vencida', function () {
        $allocation = Allocation::factory()->forProject($this->project)->forUser($this->user)
            ->total(900)->between('2026-10-26', '2026-10-30')->create();
        TimeEntry::factory()->forTask($this->task)->on('2026-10-27')->minutes(300)->create(['user_id' => $this->user->id]);

        $plan = $this->planner->plan([$allocation->fresh()], CapacityPlan::day('2026-12-31'), $this->today, AllocationPlanner::logged([$allocation]));

        expect(($this->dates)($plan->days[$allocation->id]))->toBe(['2026-11-04' => 600])
            ->and($plan->overdue)->toBe([$allocation->id]);
    });

    it('en un previsto, el modo total es plan fijo: solo cuenta lo que cae de hoy en adelante', function () {
        $forecast = ForecastProject::factory()->create();
        $allocation = Allocation::factory()->forForecast($forecast)->forUser($this->user)
            ->total(2400)->between('2026-11-02', '2026-11-06')->create();

        $plan = $this->planner->plan([$allocation->fresh()], CapacityPlan::day('2026-12-31'), $this->today);

        expect(($this->dates)($plan->days[$allocation->id]))->toBe(['2026-11-04' => 480, '2026-11-05' => 480, '2026-11-06' => 480]);
    });

    it('los modos fijos de un proyecto real no restan lo imputado', function () {
        $allocation = Allocation::factory()->forProject($this->project)->forUser($this->user)
            ->perDay(120)->between('2026-11-02', '2026-11-06')->create();
        TimeEntry::factory()->forTask($this->task)->on('2026-11-02')->minutes(600)->create(['user_id' => $this->user->id]);

        $plan = $this->planner->plan([$allocation->fresh()], CapacityPlan::day('2026-12-31'), $this->today, AllocationPlanner::logged([$allocation]));

        expect(array_sum($plan->days[$allocation->id]))->toBe(360);
    });

    it('un hueco en un proyecto real es plan fijo (no tiene horas de nadie)', function () {
        $department = Department::factory()->create();
        User::factory()->employee()->create(['department_id' => $department->id]);
        $allocation = Allocation::factory()->forProject($this->project)->gap($department)
            ->total(2400)->between('2026-11-02', '2026-11-06')->create();

        expect(AllocationPlanner::countsRemaining($allocation))->toBeFalse();

        $plan = $this->planner->plan([$allocation->fresh()], CapacityPlan::day('2026-12-31'), $this->today);

        expect(array_sum($plan->days[$allocation->id]))->toBe(1440);
    });

    it('más allá de un año reparte con la jornada vigente en el tope, sin festivos ni ausencias', function () {
        WorkSchedule::factory()->create(['user_id' => $this->user->id, 'valid_from' => '2020-01-01', 'fri_minutes' => 0]);
        Holiday::factory()->create(['date' => '2028-01-04']);
        $allocation = Allocation::factory()->forForecast()->forUser($this->user)
            ->perDay(60)->between('2028-01-03', '2028-01-09')->create();

        $plan = $this->planner->plan([$allocation->fresh()], CapacityPlan::day('2028-12-31'), $this->today);

        // Lunes a jueves (sin viernes por la jornada); el festivo del 4 no cuenta tan lejos.
        expect(($this->dates)($plan->days[$allocation->id]))->toBe([
            '2028-01-03' => 60, '2028-01-04' => 60, '2028-01-05' => 60, '2028-01-06' => 60,
        ]);
    });

    it('un total que cruza el tope de un año reparte entre todos sus días laborables', function () {
        $allocation = Allocation::factory()->forForecast()->forUser($this->user)
            ->total(480 * 10)->between('2027-10-25', '2027-11-12')->create();

        $plan = $this->planner->plan([$allocation->fresh()], CapacityPlan::day('2027-12-31'), $this->today);

        // 15 días laborables, a ambos lados del tope (05/11/2027).
        expect($plan->days[$allocation->id])->toHaveCount(15)
            ->and(array_sum($plan->days[$allocation->id]))->toBe(4800);
    });
});
