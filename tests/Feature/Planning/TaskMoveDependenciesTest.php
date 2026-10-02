<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\TaskDependency;
use App\Models\TaskStatus;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Mover una tarea a otro proyecto (SPEC §6) y sus dependencias (D-056): solo unen tareas del mismo
| proyecto, así que se quitan las que tenía con tareas que se quedan en el proyecto de origen, y
| se conservan las que hay entre la tarea y sus subtareas, que se mueven juntas.
*/

beforeEach(function () {
    TaskStatus::ensureDefaults();

    $this->user = userWithRole('employee');
    $this->source = Project::factory()->create();
    $this->source->addMember($this->user);
    $this->target = Project::factory()->create();
    $this->target->addMember($this->user);

    $this->task = Task::factory()->create(['project_id' => $this->source->id, 'title' => 'Viajera']);
    $this->before = Task::factory()->create(['project_id' => $this->source->id, 'title' => 'Antes']);
    $this->after = Task::factory()->create(['project_id' => $this->source->id, 'title' => 'Después']);
    $this->link = fn (Task $predecessor, Task $successor): TaskDependency => TaskDependency::query()->create([
        'predecessor_task_id' => $predecessor->id,
        'successor_task_id' => $successor->id,
    ]);
    $this->move = fn () => $this->actingAs($this->user)
        ->from("/proyectos/{$this->source->id}/tareas?tarea={$this->task->id}")
        ->post("/tareas/{$this->task->id}/mover", ['project_id' => $this->target->id]);
});

it('quita las dependencias con las tareas que se quedan y conserva las de sus subtareas', function () {
    $child = Task::factory()->subtaskOf($this->task)->create(['title' => 'Hija']);
    $otherChild = Task::factory()->subtaskOf($this->task)->create(['title' => 'Otra hija']);
    $kept = ($this->link)($this->task, $child);
    $keptBetweenChildren = ($this->link)($child, $otherChild);
    ($this->link)($this->before, $this->task);
    ($this->link)($this->task, $this->after);
    ($this->link)($child, $this->after);
    ($this->link)($this->before, $otherChild);
    $untouched = ($this->link)($this->before, $this->after);

    ($this->move)()->assertSessionHasNoErrors();

    expect($this->task->fresh()->project_id)->toBe($this->target->id)
        ->and(TaskDependency::query()->orderBy('id')->pluck('id')->all())
        ->toBe([$kept->id, $keptBetweenChildren->id, $untouched->id]);
});

it('tras moverla, el panel de la tarea que se queda ya no la enseña (no quedan filas ocultas)', function () {
    ($this->link)($this->task, $this->after);

    ($this->move)()->assertSessionHasNoErrors();

    $this->actingAs($this->user)
        ->get("/proyectos/{$this->source->id}/tareas?tarea={$this->after->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('panel.dependencies.predecessors', [])
            ->where('panel.dependencies.successors', []));

    expect(TaskDependency::query()->count())->toBe(0);
});

it('si no se puede mover, las dependencias siguen donde estaban', function () {
    ($this->link)($this->before, $this->task);

    $this->actingAs($this->user)
        ->from("/proyectos/{$this->source->id}/tareas?tarea={$this->task->id}")
        ->post("/tareas/{$this->task->id}/mover", ['project_id' => $this->source->id])
        ->assertSessionHasErrors('project_id');

    expect(TaskDependency::query()->count())->toBe(1);
});
