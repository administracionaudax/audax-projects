<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\TaskDependency;
use App\Models\TaskStatus;
use App\Models\User;

/*
| Rutas del contrato de planificación (D-056, D-057): dependencias y reprogramar con propuesta.
*/

beforeEach(function () {
    TaskStatus::ensureDefaults();
    $this->project = Project::factory()->create();
    $this->member = User::factory()->employee()->create();
    $this->project->addMember($this->member);
    $this->make = fn (string $title, ?string $start, ?string $due) => Task::factory()->create([
        'project_id' => $this->project->id, 'title' => $title, 'start_date' => $start, 'due_date' => $due,
    ]);
});

it('un miembro enlaza dos tareas de su proyecto', function () {
    $a = ($this->make)('A', '2026-10-05', '2026-10-07');
    $b = ($this->make)('B', '2026-10-08', '2026-10-09');

    $this->actingAs($this->member)->from('/proyectos/'.$this->project->id.'/gantt')
        ->post("/proyectos/{$this->project->id}/dependencias", ['predecessor_task_id' => $a->id, 'successor_task_id' => $b->id])
        ->assertRedirect('/proyectos/'.$this->project->id.'/gantt')
        ->assertSessionHasNoErrors();

    expect(TaskDependency::query()->where('predecessor_task_id', $a->id)->where('successor_task_id', $b->id)->exists())->toBeTrue();
});

it('un ciclo se rechaza con un error en successor_task_id', function () {
    $a = ($this->make)('A', null, '2026-10-07');
    $b = ($this->make)('B', null, '2026-10-09');
    TaskDependency::query()->create(['predecessor_task_id' => $a->id, 'successor_task_id' => $b->id]);

    $this->actingAs($this->member)
        ->post("/proyectos/{$this->project->id}/dependencias", ['predecessor_task_id' => $b->id, 'successor_task_id' => $a->id])
        ->assertSessionHasErrors('successor_task_id');

    expect(TaskDependency::query()->count())->toBe(1);
});

it('una tarea de otro proyecto no se encuentra y quien no es miembro no enlaza', function () {
    $a = ($this->make)('A', null, '2026-10-07');
    $other = Task::factory()->create();

    $this->actingAs($this->member)
        ->post("/proyectos/{$this->project->id}/dependencias", ['predecessor_task_id' => $a->id, 'successor_task_id' => $other->id])
        ->assertNotFound();

    $b = ($this->make)('B', null, '2026-10-09');
    $this->actingAs(User::factory()->employee()->create())
        ->post("/proyectos/{$this->project->id}/dependencias", ['predecessor_task_id' => $a->id, 'successor_task_id' => $b->id])
        ->assertForbidden();
});

it('quita una dependencia', function () {
    $a = ($this->make)('A', null, '2026-10-07');
    $b = ($this->make)('B', null, '2026-10-09');
    $dependency = TaskDependency::query()->create(['predecessor_task_id' => $a->id, 'successor_task_id' => $b->id]);

    $this->actingAs(User::factory()->employee()->create())->delete("/dependencias/{$dependency->id}")->assertForbidden();
    $this->actingAs($this->member)->delete("/dependencias/{$dependency->id}")->assertRedirect();

    expect(TaskDependency::query()->count())->toBe(0);
});

it('la propuesta enseña las sucesoras en conflicto sin cambiar nada', function () {
    $a = ($this->make)('A', '2026-10-05', '2026-10-07');
    $b = ($this->make)('B', '2026-10-08', '2026-10-09');
    TaskDependency::query()->create(['predecessor_task_id' => $a->id, 'successor_task_id' => $b->id]);

    $this->actingAs($this->member)
        ->postJson("/tareas/{$a->id}/reprogramar/propuesta", ['start_date' => '2026-10-05', 'due_date' => '2026-10-12'])
        ->assertOk()
        ->assertJsonPath('proposals.0.task_id', $b->id)
        ->assertJsonPath('proposals.0.new_start_date', '2026-10-13')
        ->assertJsonPath('proposals.0.new_due_date', '2026-10-14');

    expect($a->fresh()->due_date->toDateString())->toBe('2026-10-07');
});

it('reprogramar sin confirmar no mueve las sucesoras; confirmando, sí', function () {
    $a = ($this->make)('A', '2026-10-05', '2026-10-07');
    $b = ($this->make)('B', '2026-10-08', '2026-10-09');
    TaskDependency::query()->create(['predecessor_task_id' => $a->id, 'successor_task_id' => $b->id]);

    $this->actingAs($this->member)
        ->post("/tareas/{$a->id}/reprogramar", ['start_date' => '2026-10-05', 'due_date' => '2026-10-12'])
        ->assertSessionHasNoErrors();
    expect($a->fresh()->due_date->toDateString())->toBe('2026-10-12')
        ->and($b->fresh()->start_date->toDateString())->toBe('2026-10-08');

    $this->actingAs($this->member)
        ->post("/tareas/{$a->id}/reprogramar", ['start_date' => '2026-10-06', 'due_date' => '2026-10-13', 'shift_successors' => true])
        ->assertSessionHasNoErrors();
    expect($b->fresh()->start_date->toDateString())->toBe('2026-10-14')
        ->and($b->fresh()->due_date->toDateString())->toBe('2026-10-15');
});

it('valida las fechas y exige poder editar la tarea', function () {
    $a = ($this->make)('A', '2026-10-05', '2026-10-07');

    $this->actingAs($this->member)
        ->post("/tareas/{$a->id}/reprogramar", ['start_date' => '2026-10-09', 'due_date' => '2026-10-07'])
        ->assertSessionHasErrors('due_date');

    $this->actingAs(User::factory()->employee()->create())
        ->postJson("/tareas/{$a->id}/reprogramar/propuesta", ['start_date' => '2026-10-05', 'due_date' => '2026-10-08'])
        ->assertForbidden();

    $client = User::factory()->client()->create();
    $this->actingAs($client)->post("/tareas/{$a->id}/reprogramar", ['start_date' => null, 'due_date' => null])->assertRedirect('/portal');
});

it('una dependencia con una tarea en la papelera no se puede tocar', function () {
    $a = ($this->make)('A', null, '2026-10-07');
    $b = ($this->make)('B', null, '2026-10-09');
    $dependency = TaskDependency::query()->create(['predecessor_task_id' => $a->id, 'successor_task_id' => $b->id]);
    $b->delete();

    $this->actingAs($this->member)->delete("/dependencias/{$dependency->id}")->assertNotFound();
});
