<?php

use App\Domain\Tasks\TaskWriter;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Registrado de una tarea padre = lo suyo + lo de sus subtareas (D-170) y estimación propia del
| padre que se conserva (D-171): lista, kanban, panel y Mis tareas.
*/

beforeEach(function () {
    TaskStatus::ensureDefaults();
    $this->travelTo(CarbonImmutable::parse('2026-09-23 10:00:00', 'Europe/Madrid'));

    $this->user = userWithRole('employee');
    $this->project = Project::factory()->create();
    $this->project->addMember($this->user);
    $this->url = "/proyectos/{$this->project->id}/tareas";

    // «Desarrollo web» (60 h, importada de ClickUp) con dos subtareas sin estimar.
    $this->parent = Task::factory()->assignedTo($this->user)->create(['project_id' => $this->project->id, 'title' => 'Desarrollo web', 'estimated_minutes' => 3600]);
    $this->childA = Task::factory()->subtaskOf($this->parent)->create(['title' => 'Maquetación']);
    $this->childB = Task::factory()->subtaskOf($this->parent)->create(['title' => 'Formularios']);
    TimeEntry::factory()->forTask($this->parent)->on('2026-09-21')->minutes(60)->create(['user_id' => $this->user->id]);
    TimeEntry::factory()->forTask($this->childA)->on('2026-09-21')->minutes(300)->create();
    TimeEntry::factory()->forTask($this->childB)->on('2026-09-22')->minutes(240)->create();
});

it('la lista y el kanban envían lo imputado en las subtareas de cada tarea raíz', function (string $query) {
    $this->actingAs($this->user)
        ->get($this->url.$query)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('tasks.0.title', 'Desarrollo web')
            ->where('tasks.0.logged_minutes', 60)
            ->where('tasks.0.subtasks_logged_minutes', 540)
            // Sin subtareas estimadas, manda la estimación propia del padre (D-171).
            ->where('tasks.0.estimate_from_subtasks', false)
            ->where('tasks.0.effective_estimated_minutes', 3600)
            ->missing('tasks.0.subtasks.0.subtasks_logged_minutes'));
})->with(['lista' => '', 'kanban' => '?vista=kanban']);

it('el panel lleva el desglose propio y de subtareas, y has_time cuenta las de las subtareas', function () {
    $this->actingAs($this->user)
        ->get($this->url."?tarea={$this->parent->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('panel.task.logged_minutes', 60)
            ->where('panel.task.subtasks_logged_minutes', 540)
            ->where('panel.task.estimated_minutes', 3600)
            ->where('panel.has_time', true));

    // Una tarea sin horas propias pero con horas en sus subtareas también «tiene horas».
    $empty = Task::factory()->create(['project_id' => $this->project->id]);
    $child = Task::factory()->subtaskOf($empty)->create();
    TimeEntry::factory()->forTask($child)->on('2026-09-22')->minutes(15)->create();

    $this->actingAs($this->user)
        ->get($this->url."?tarea={$empty->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('panel.task.logged_minutes', 0)
            ->where('panel.task.subtasks_logged_minutes', 15)
            ->where('panel.has_time', true));
});

it('el panel de una subtarea no lleva desglose', function () {
    $this->actingAs($this->user)
        ->get($this->url."?tarea={$this->childA->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('panel.task.logged_minutes', 300)
            ->missing('panel.task.subtasks_logged_minutes'));
});

it('Mis tareas suma en el padre lo imputado en sus subtareas, en la misma consulta', function () {
    $props = $this->actingAs($this->user)->get('/mis-tareas')->assertOk()->viewData('page')['props'];
    $row = collect($props['tasks'])->firstWhere('id', $this->parent->id);

    expect($row['logged_minutes'])->toBe(60)
        ->and($row['subtasks_logged_minutes'])->toBe(540);
});

it('no cuenta las horas de subtareas borradas', function () {
    $gone = Task::factory()->subtaskOf($this->parent)->create();
    TimeEntry::factory()->forTask($gone)->on('2026-09-22')->minutes(30)->create();
    $gone->delete();

    $props = $this->actingAs($this->user)->get('/mis-tareas')->assertOk()->viewData('page')['props'];

    expect(collect($props['tasks'])->firstWhere('id', $this->parent->id)['subtasks_logged_minutes'])->toBe(540);
});

it('a un colaborador externo no le llegan las horas de las subtareas (D-134)', function () {
    $sara = User::factory()->collaborator()->create();
    $this->project->addMember($sara);
    $this->parent->update(['assignee_user_id' => $sara->id]);

    $this->actingAs($sara)
        ->get($this->url."?tarea={$this->parent->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('tasks.0.logged_minutes', null)
            ->where('tasks.0.subtasks_logged_minutes', null)
            ->where('panel.task.subtasks_logged_minutes', null));

    $props = $this->actingAs($sara)->get('/mis-tareas')->assertOk()->viewData('page')['props'];

    expect(collect($props['tasks'])->firstWhere('id', $this->parent->id)['subtasks_logged_minutes'])->toBeNull();
});

it('la estimación propia del padre se conserva al estimar sus subtareas y vuelve a mandar al quitarlas (D-171)', function () {
    /** @var TaskWriter $writer */
    $writer = app(TaskWriter::class);
    $admin = userWithRole('admin');

    $writer->update($admin, $this->childA, ['estimated_minutes' => 600]);

    expect($this->parent->fresh()->estimated_minutes)->toBe(3600)
        ->and($this->parent->fresh()->effectiveEstimatedMinutes())->toBe(600);

    $writer->create($admin, $this->project, ['title' => 'Pruebas', 'parent_task_id' => $this->parent->id, 'estimated_minutes' => 120]);

    expect($this->parent->fresh()->estimated_minutes)->toBe(3600)
        ->and($this->parent->fresh()->effectiveEstimatedMinutes())->toBe(720);

    $this->parent->subtasks()->update(['estimated_minutes' => null]);

    expect($this->parent->fresh()->effectiveEstimatedMinutes())->toBe(3600);
});
