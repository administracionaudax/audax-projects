<?php

use App\Domain\HourBanks\Events\HourBankOverageRecorded;
use App\Domain\HourBanks\Events\HourBankThresholdReached;
use App\Enums\TimesheetStatus;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\TimesheetPeriod;
use App\Models\User;
use App\Models\WorkSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Inicio (SPEC §5.1, D-021, D-037): solo las cosas de quien mira. "Hoy" es el jueves 24/09/2026.
*/

beforeEach(function () {
    Event::fake([HourBankThresholdReached::class, HourBankOverageRecorded::class]);
    $this->travelTo(CarbonImmutable::parse('2026-09-24 10:00:00', 'Europe/Madrid'));

    $this->user = User::factory()->employee()->create(['created_at' => '2026-01-01 08:00:00']);
    $this->colleague = User::factory()->employee()->create(['created_at' => '2026-01-01 08:00:00']);
    $this->project = Project::factory()->create(['code' => 'ACME-WEB']);
    $this->project->addMember($this->user);
    $this->project->addMember($this->colleague);

    $this->task = fn (array $attributes, ?User $assignee = null): Task => Task::factory()
        ->assignedTo($assignee ?? $this->user)
        ->create(['project_id' => $this->project->id, ...$attributes]);

    $this->log = fn (string $date, int $minutes, ?User $user = null): TimeEntry => TimeEntry::factory()
        ->forTask(Task::factory()->create(['project_id' => $this->project->id]))
        ->on($date)->minutes($minutes)->create(['user_id' => ($user ?? $this->user)->id]);
});

it('agrupa mis tareas abiertas en vencidas, de hoy y de esta semana', function () {
    ($this->task)(['title' => 'Vencida', 'due_date' => '2026-09-22']);
    ($this->task)(['title' => 'Vence hoy', 'due_date' => '2026-09-24']);
    ($this->task)(['title' => 'Empieza hoy', 'start_date' => '2026-09-24', 'due_date' => '2026-10-02']);
    ($this->task)(['title' => 'Vence el domingo', 'due_date' => '2026-09-27']);
    // Fuera: la semana que viene, sin fecha, completada, de otra persona o de un proyecto archivado.
    ($this->task)(['title' => 'Próxima', 'due_date' => '2026-09-28']);
    ($this->task)(['title' => 'Sin fecha']);
    Task::factory()->completed()->assignedTo($this->user)->create(['project_id' => $this->project->id, 'title' => 'Hecha', 'due_date' => '2026-09-23']);
    ($this->task)(['title' => 'De otro', 'due_date' => '2026-09-24'], $this->colleague);
    $archived = Project::factory()->archived()->create();
    Task::factory()->assignedTo($this->user)->create(['project_id' => $archived->id, 'title' => 'Archivada', 'due_date' => '2026-09-24']);

    $this->actingAs($this->user)
        ->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('home', false)
            ->has('tasks.overdue', 1)
            ->where('tasks.overdue.0.title', 'Vencida')
            ->where('tasks.overdue.0.project.code', 'ACME-WEB')
            ->has('tasks.today', 2)
            ->where('tasks.today.0.title', 'Vence hoy')
            ->where('tasks.today.1.title', 'Empieza hoy')
            ->has('tasks.week', 1)
            ->where('tasks.week.0.title', 'Vence el domingo'));
});

it('muestra mis horas de hoy y de la semana frente a mi capacidad', function () {
    WorkSchedule::factory()->for($this->user)->create(['valid_from' => '2026-01-01', 'thu_minutes' => 420]);
    ($this->log)('2026-09-24', 90);
    ($this->log)('2026-09-24', 30);
    ($this->log)('2026-09-21', 480);
    ($this->log)('2026-09-20', 60); // semana anterior
    ($this->log)('2026-09-24', 240, $this->colleague); // de otra persona

    $this->actingAs($this->user)
        ->get('/')
        ->assertInertia(fn (Assert $page) => $page
            ->where('hours.today', 120)
            ->where('hours.week', 600)
            ->where('hours.capacity_today', 420)
            ->where('hours.capacity_week', 480 * 4 + 420)
            ->where('week.iso', '2026-W39')
            ->where('week.period.status', 'open'));
});

it('muestra el estado de mi semana y el comentario si me la han devuelto', function () {
    TimesheetPeriod::factory()->for($this->user)->week('2026-09-24')->status(TimesheetStatus::Returned)
        ->create(['review_comment' => 'Falta el martes', 'reviewed_by' => $this->colleague->id]);

    $this->actingAs($this->user)
        ->get('/')
        ->assertInertia(fn (Assert $page) => $page
            ->where('week.period.status', 'returned')
            ->where('week.period.review_comment', 'Falta el martes')
            ->where('week.period.reviewer.id', $this->colleague->id));
});

it('lista los días laborables sin imputar de las dos últimas semanas (hasta ayer)', function () {
    // Del 10 al 23 de septiembre: laborables 10, 11, 14-18, 21-23. Con horas: 10, 14, 15, 16, 17, 21.
    foreach (['2026-09-10', '2026-09-14', '2026-09-15', '2026-09-16', '2026-09-17', '2026-09-21'] as $date) {
        ($this->log)($date, 60);
    }
    ($this->log)('2026-09-22', 60, $this->colleague); // las horas de otro no cuentan

    $this->actingAs($this->user)
        ->get('/')
        ->assertInertia(fn (Assert $page) => $page
            ->where('unlogged_days', [
                ['date' => '2026-09-11', 'capacity' => 480, 'week' => '2026-W37'],
                ['date' => '2026-09-18', 'capacity' => 480, 'week' => '2026-W38'],
                ['date' => '2026-09-22', 'capacity' => 480, 'week' => '2026-W39'],
                ['date' => '2026-09-23', 'capacity' => 480, 'week' => '2026-W39'],
            ]));
});

it('no cuenta como sin imputar los días anteriores al alta', function () {
    $newcomer = User::factory()->employee()->create(['created_at' => '2026-09-22 09:00:00']);

    $this->actingAs($newcomer)
        ->get('/')
        ->assertInertia(fn (Assert $page) => $page->where('unlogged_days', [
            ['date' => '2026-09-22', 'capacity' => 480, 'week' => '2026-W39'],
            ['date' => '2026-09-23', 'capacity' => 480, 'week' => '2026-W39'],
        ]));
});

it('carga muchas tareas sin consultas perezosas (N+1)', function () {
    foreach (range(1, 8) as $day) {
        ($this->task)(['due_date' => '2026-09-'.str_pad((string) (19 + $day), 2, '0', STR_PAD_LEFT)]);
    }

    $this->actingAs($this->user)->get('/')->assertOk();
});
