<?php

use App\Domain\Tasks\TaskActivityFeed;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;

/*
| Actividad de la tarea en el panel (SPEC §4.6): quién, qué, antes y después, con los ids
| traducidos a nombres y los valores en español.
*/

beforeEach(function () {
    TaskStatus::ensureDefaults();

    $this->user = userWithRole('employee');
    $this->project = Project::factory()->create();
    $this->project->addMember($this->user);
});

it('muestra los cambios con nombres y valores legibles, lo más reciente primero', function () {
    $marta = User::factory()->employee()->create(['name' => 'Marta']);
    $task = Task::factory()->create(['project_id' => $this->project->id, 'title' => 'Maquetar']);
    $doing = TaskStatus::query()->where('category', 'in_progress')->orderBy('position')->firstOrFail();

    $this->actingAs($this->user)->patch("/tareas/{$task->id}", [
        'status_id' => $doing->id,
        'assignee_user_id' => $marta->id,
        'due_date' => '2026-10-05',
        'estimated_minutes' => 150,
        'is_billable' => false,
        'priority' => 'urgent',
        'description' => '<p>Nueva</p>',
    ])->assertSessionHasNoErrors();

    $feed = app(TaskActivityFeed::class)->for($task->fresh());
    $changes = collect($feed[0]['changes'])->keyBy('field');

    expect($feed[0]['event'])->toBe('updated')
        ->and($feed[0]['causer'])->toBe($this->user->name)
        ->and($changes['status_id'])->toBe(['field' => 'status_id', 'from' => 'Por hacer', 'to' => $doing->name])
        ->and($changes['assignee_user_id']['to'])->toBe('Marta')
        ->and($changes['due_date']['to'])->toBe('05/10/2026')
        ->and($changes['estimated_minutes']['to'])->toBe('2:30')
        ->and($changes['is_billable'])->toBe(['field' => 'is_billable', 'from' => 'Sí', 'to' => 'No'])
        ->and($changes['priority'])->toBe(['field' => 'priority', 'from' => 'Normal', 'to' => 'Urgente'])
        ->and($changes['description'])->toBe(['field' => 'description', 'from' => null, 'to' => null])
        ->and($feed[1]['event'])->toBe('created')
        ->and($feed[1]['changes'])->toBe([]);
});

it('no registra en la actividad los cambios de orden del kanban', function () {
    $task = Task::factory()->create(['project_id' => $this->project->id]);
    $other = Task::factory()->create(['project_id' => $this->project->id, 'position' => 1]);

    $this->actingAs($this->user)
        ->patch("/tareas/{$task->id}/posicion", ['status_id' => $task->status_id, 'after_id' => $other->id])
        ->assertSessionHasNoErrors();

    expect($task->activitiesAsSubject()->count())->toBe(1)
        ->and($other->activitiesAsSubject()->count())->toBe(1);
});
