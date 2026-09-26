<?php

use App\Domain\HourBanks\Events\HourBankOverageRecorded;
use App\Domain\HourBanks\Events\HourBankThresholdReached;
use App\Domain\HourBanks\HourBankCommitment;
use App\Models\HourBank;
use App\Models\Task;
use App\Models\TimeEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Horas comprometidas (SPEC §8, UI): Σ max(estimación efectiva − imputado a esa tarea en esa
| bolsa, 0) de las tareas ABIERTAS, contando las subtareas y no el padre cuya estimación deriva
| de ellas. Aviso si consumido + comprometido supera el total.
*/

beforeEach(function () {
    Event::fake([HourBankThresholdReached::class, HourBankOverageRecorded::class]);

    $this->commitment = app(HourBankCommitment::class);
    $this->bank = HourBank::factory()->hours(10)->create();
});

test('suma lo que falta de las tareas abiertas; lo imputado de más no resta', function () {
    $a = Task::factory()->inBank($this->bank)->create(['estimated_minutes' => 300]);
    $b = Task::factory()->inBank($this->bank)->create(['estimated_minutes' => 60]);
    Task::factory()->inBank($this->bank)->create(['estimated_minutes' => null]);

    TimeEntry::factory()->forTask($a)->minutes(120)->create();
    TimeEntry::factory()->forTask($b)->minutes(90)->create();

    expect($this->commitment->committedFor($this->bank))->toBe(180);
});

test('las tareas completadas y los hitos no cuentan', function () {
    Task::factory()->inBank($this->bank)->completed()->create(['estimated_minutes' => 600]);
    Task::factory()->inBank($this->bank)->milestone()->create();
    Task::factory()->inBank($this->bank)->create(['estimated_minutes' => 30]);

    expect($this->commitment->committedFor($this->bank))->toBe(30);
});

test('cuentan las subtareas y no el padre cuya estimación deriva de ellas', function () {
    $parent = Task::factory()->inBank($this->bank)->create(['estimated_minutes' => 1000]);
    $sub1 = Task::factory()->subtaskOf($parent)->create(['estimated_minutes' => 120]);
    Task::factory()->subtaskOf($parent)->create(['estimated_minutes' => 60]);
    Task::factory()->subtaskOf($parent)->completed()->create(['estimated_minutes' => 500]);

    TimeEntry::factory()->forTask($sub1)->minutes(30)->create();
    // Las horas propias del padre no restan a sus subtareas.
    TimeEntry::factory()->forTask($parent)->minutes(600)->create();

    expect($this->commitment->committedFor($this->bank))->toBe(90 + 60);
});

test('un padre sin subtareas estimadas cuenta con su propia estimación', function () {
    $parent = Task::factory()->inBank($this->bank)->create(['estimated_minutes' => 240]);
    Task::factory()->subtaskOf($parent)->create(['estimated_minutes' => null]);
    TimeEntry::factory()->forTask($parent)->minutes(60)->create();

    expect($this->commitment->committedFor($this->bank))->toBe(180);
});

test('solo resta lo imputado a la tarea EN ESTA bolsa (tras moverla, sus horas se quedan en la otra)', function () {
    $other = HourBank::factory()->hours(10)->create(['project_id' => $this->bank->project_id]);
    $task = Task::factory()->inBank($other)->create(['estimated_minutes' => 300]);
    TimeEntry::factory()->forTask($task)->minutes(200)->create();

    $task->update(['hour_bank_id' => $this->bank->id]);

    expect($this->commitment->committedFor($this->bank))->toBe(300)
        ->and($this->commitment->committedFor($other))->toBe(0);
});

test('calcula varias bolsas a la vez con las mismas consultas', function () {
    $banks = HourBank::factory()->count(5)->create();

    foreach ($banks as $bank) {
        $parent = Task::factory()->inBank($bank)->create(['estimated_minutes' => 100]);
        Task::factory()->subtaskOf($parent)->create(['estimated_minutes' => 50]);
        Task::factory()->inBank($bank)->create(['estimated_minutes' => 40]);
    }

    DB::enableQueryLog();
    $figures = $this->commitment->forBanks($banks->modelKeys());
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($count)->toBe(3)
        ->and(array_column($figures, 'committed_minutes'))->toBe([90, 90, 90, 90, 90])
        ->and(array_column($figures, 'open_tasks_count'))->toBe([2, 2, 2, 2, 2])
        ->and($this->commitment->forBanks([]))->toBe([]);
});

test('el aviso: consumido + comprometido supera el total (lo pinta HourBankMeter)', function () {
    $task = Task::factory()->inBank($this->bank)->create(['estimated_minutes' => 9 * 60]);
    TimeEntry::factory()->forTask(Task::factory()->inBank($this->bank)->create())->minutes(3 * 60)->create();

    $this->actingAs(userWithRole('admin'))
        ->get("/proyectos/{$this->bank->project_id}/bolsas")
        ->assertInertia(fn (Assert $page) => $page
            ->where('banks.0.consumed_minutes', 180)
            ->where('banks.0.committed_minutes', 540)
            ->where('banks.0.total_minutes', 600));

    // 180 + 540 − 600 = 120 minutos por encima del saldo.
    expect($this->bank->fresh()->consumed_minutes + $this->commitment->committedFor($this->bank) - $this->bank->total_minutes)->toBe(120)
        ->and($task->exists)->toBeTrue();
});
