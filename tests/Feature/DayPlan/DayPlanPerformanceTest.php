<?php

use App\Domain\DayPlan\DayPlanReminders;
use App\Domain\DayPlan\DayPlanTargets;
use App\Domain\DayPlan\HomeDayPlanCard;
use App\Models\ActiveTimer;
use App\Models\DayPlanComment;
use App\Models\DayPlanItem;
use App\Models\Department;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/*
| Presupuesto de consultas del plan del día (D-046, R7): Mi día, Equipo hoy, la semana, Inicio y el
| recordatorio no crecen con la plantilla ni con el número de líneas (sin N+1).
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00:00', 'Europe/Madrid'));
    $this->department = Department::factory()->create();
    $this->manager = userWithRole('department_manager', ['department_id' => $this->department->id]);
    $this->department->managers()->attach($this->manager->id);
    $this->project = Project::factory()->create();

    $this->grow = function (int $people): void {
        foreach (range(1, $people) as $i) {
            $user = userWithRole('employee', ['department_id' => $this->department->id]);
            $this->project->addMember($user);
            $task = Task::factory()->create(['project_id' => $this->project->id]);

            foreach (['2026-10-05', '2026-10-06', '2026-10-07'] as $date) {
                $items = DayPlanItem::factory()->count(3)->sequence(fn ($sequence) => ['position' => $sequence->index])
                    ->create(['user_id' => $user->id, 'date' => $date, 'project_id' => $this->project->id, 'task_id' => $task->id, 'planned_minutes' => 60, 'carry_count' => $i % 2]);
                DayPlanComment::query()->create(['day_plan_item_id' => $items[0]->id, 'user_id' => $this->manager->id, 'body' => 'Ojo']);
                TimeEntry::factory()->create(['user_id' => $user->id, 'task_id' => $task->id, 'project_id' => $this->project->id, 'date' => $date, 'minutes' => 30, 'day_plan_item_id' => $items[0]->id]);
            }

            if ($i % 3 === 0) {
                ActiveTimer::query()->create(['user_id' => $user->id, 'task_id' => $task->id, 'started_at' => now()->subMinutes(5), 'day_plan_item_id' => DayPlanItem::query()->where('user_id', $user->id)->where('date', '2026-10-07')->value('id')]);
            }
        }

        // Mis propias líneas, para Mi día e Inicio.
        DayPlanItem::factory()->count(2)->create(['user_id' => $this->manager->id, 'date' => '2026-10-07']);
        DayPlanItem::factory()->create(['user_id' => $this->manager->id, 'date' => '2026-10-06']);
    };

    $this->measure = function (string $uri, array $headers = []): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->manager)->get($uri, $headers)->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };
});

it('las páginas del plan del día no crecen con la plantilla ni con las líneas', function (string $uri, int $budget) {
    ($this->grow)(3);
    ($this->measure)($uri);
    $small = ($this->measure)($uri);

    ($this->grow)(12);
    $large = ($this->measure)($uri);

    expect($large)->toBeLessThanOrEqual($budget)
        ->and($large - $small)->toBeLessThanOrEqual(1);
})->with([
    'Mi día' => ['/dia', 30],
    'Equipo hoy' => ['/dia/equipo', 32],
    'Semana' => ['/dia/semana', 30],
]);

it('la tarjeta «Mi día» de Inicio y el catálogo de la línea no crecen', function () {
    $count = function (Closure $run): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $run();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };
    $card = fn () => app(HomeDayPlanCard::class)->for($this->manager);
    $targets = fn () => app(DayPlanTargets::class)->for($this->manager);

    ($this->grow)(3);
    $card();
    [$smallCard, $smallTargets] = [$count($card), $count($targets)];

    ($this->grow)(12);
    Project::factory()->count(10)->create();

    expect($count($card))->toBe($smallCard)->toBeLessThanOrEqual(14)
        ->and($count($targets))->toBe($smallTargets)->toBeLessThanOrEqual(4);
});

it('el recordatorio hace las mismas consultas con 5 personas que con 25', function () {
    Notification::fake();
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->travelTo(CarbonImmutable::parse('2026-10-08 08:30:00', 'Europe/Madrid'));
        app(DayPlanReminders::class)->sendDue();
        $queries = collect(DB::getQueryLog())->reject(fn (array $query): bool => str_contains($query['query'], 'day_plans') || str_contains($query['query'], 'notifications'))->count();
        DB::disableQueryLog();
        DB::table('day_plans')->update(['reminded_at' => null]);

        return $queries;
    };

    ($this->grow)(5);
    $small = $count();
    ($this->grow)(20);
    $large = $count();

    expect($large - $small)->toBeLessThanOrEqual(2);
});
