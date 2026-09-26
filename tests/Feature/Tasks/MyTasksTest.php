<?php

use App\Domain\Tasks\MyTaskSections;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Mis tareas (SPEC §6, D-037): tareas abiertas asignadas a mí, en secciones según «hoy» en Madrid.
| «Hoy» es el miércoles 23/09/2026 (semana del lunes 21 al domingo 27).
*/

beforeEach(function () {
    TaskStatus::ensureDefaults();
    $this->travelTo(CarbonImmutable::parse('2026-09-23 10:00:00', 'Europe/Madrid'));

    $this->user = userWithRole('employee');
    $this->project = Project::factory()->create(['name' => 'Web Hoteles']);
    $this->make = fn (string $title, array $attributes = []): Task => Task::factory()->assignedTo($this->user)->create([
        'project_id' => $this->project->id,
        'title' => $title,
        ...$attributes,
    ]);
    $this->sections = function (): array {
        $sections = $this->actingAs($this->user)->get('/mis-tareas')->assertOk()->viewData('page')['props']['sections'];

        return collect($sections)->mapWithKeys(fn (array $section): array => [
            $section['key'] => array_column($section['tasks'], 'title'),
        ])->all();
    };
});

it('reparte las tareas abiertas en Vencidas, Hoy, Esta semana, Próximas y Sin fecha', function () {
    ($this->make)('Vencida', ['due_date' => '2026-09-22']);
    ($this->make)('Vence hoy', ['due_date' => '2026-09-23']);
    ($this->make)('Empieza hoy', ['start_date' => '2026-09-23', 'due_date' => '2026-10-10']);
    ($this->make)('Vence el domingo', ['due_date' => '2026-09-27']);
    ($this->make)('Vence el lunes que viene', ['due_date' => '2026-09-28']);
    ($this->make)('Solo con inicio futuro', ['start_date' => '2026-09-30']);
    ($this->make)('Sin fecha');

    expect(($this->sections)())->toBe([
        'overdue' => ['Vencida'],
        'today' => ['Vence hoy', 'Empieza hoy'],
        'this_week' => ['Vence el domingo'],
        'upcoming' => ['Vence el lunes que viene', 'Solo con inicio futuro'],
        'no_date' => ['Sin fecha'],
    ]);
});

it('usa «hoy» de Madrid aunque en UTC sea otro día', function () {
    // 00:30 del jueves 24 en Madrid = 22:30 del miércoles 23 en UTC.
    $this->travelTo(CarbonImmutable::parse('2026-09-24 00:30:00', 'Europe/Madrid'));
    ($this->make)('Vence el 23', ['due_date' => '2026-09-23']);
    ($this->make)('Vence el 24', ['due_date' => '2026-09-24']);

    expect(($this->sections)())->toMatchArray([
        'overdue' => ['Vence el 23'],
        'today' => ['Vence el 24'],
    ]);
});

it('solo muestra mis tareas abiertas de proyectos no archivados (también subtareas)', function () {
    $other = User::factory()->employee()->create();
    ($this->make)('Mía');
    Task::factory()->assignedTo($other)->create(['project_id' => $this->project->id, 'title' => 'De otra persona']);
    Task::factory()->completed()->assignedTo($this->user)->create(['project_id' => $this->project->id, 'title' => 'Completada']);
    Task::factory()->assignedTo($this->user)->create(['project_id' => Project::factory()->archived()->create()->id, 'title' => 'Archivada']);
    $parent = Task::factory()->create(['project_id' => $this->project->id, 'title' => 'Padre']);
    Task::factory()->subtaskOf($parent)->assignedTo($this->user)->create(['title' => 'Subtarea mía']);

    $sections = ($this->sections)();

    expect($sections['no_date'])->toBe(['Mía', 'Subtarea mía']);
});

it('ordena por vencimiento y prioridad e incluye proyecto, bolsa y tarea padre', function () {
    $project = Project::factory()->hourBank()->create(['code' => 'ACME']);
    $bank = HourBank::factory()->create(['project_id' => $project->id, 'name' => 'Bolsa Q4']);
    Task::factory()->inBank($bank)->assignedTo($this->user)->create(['title' => 'Normal', 'due_date' => '2026-10-15', 'priority' => 'normal']);
    Task::factory()->inBank($bank)->assignedTo($this->user)->create(['title' => 'Urgente', 'due_date' => '2026-10-15', 'priority' => 'urgent']);
    Task::factory()->inBank($bank)->assignedTo($this->user)->create(['title' => 'Antes', 'due_date' => '2026-10-01', 'priority' => 'low']);

    $this->actingAs($this->user)->get('/mis-tareas')
        ->assertInertia(fn (Assert $page) => $page
            ->component('my-tasks/index')
            ->where('today', '2026-09-23')
            ->where('sections.3.key', MyTaskSections::UPCOMING)
            ->where('sections.3.tasks.0.title', 'Antes')
            ->where('sections.3.tasks.1.title', 'Urgente')
            ->where('sections.3.tasks.2.title', 'Normal')
            ->where('sections.3.tasks.0.project.code', 'ACME')
            ->where('sections.3.tasks.0.hour_bank.name', 'Bolsa Q4')
            ->where('sections.3.tasks.0.parent', null)
            ->has('statuses', 5));
});

it('sin tareas muestra las secciones vacías', function () {
    expect(($this->sections)())->toBe([
        'overdue' => [],
        'today' => [],
        'this_week' => [],
        'upcoming' => [],
        'no_date' => [],
    ]);
});

it('no hace N+1', function () {
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->user)->get('/mis-tareas')->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };
    $seed = function (int $count): void {
        foreach (range(1, $count) as $i) {
            $bank = HourBank::factory()->create();
            $parent = Task::factory()->inBank($bank)->create();
            Task::factory()->subtaskOf($parent)->assignedTo($this->user)->create(['due_date' => '2026-09-2'.($i % 9)]);
        }
    };

    $count();
    $seed(2);
    $few = $count();
    $seed(6);

    expect($count())->toBe($few);
});
