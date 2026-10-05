<?php

use App\Models\ActiveTimer;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\TaskType;
use App\Models\TimeEntry;
use App\Models\User;

/*
| Alta, edición y borrado de tareas con las reglas del SPEC §6 y §8.2 y D-037.
*/

beforeEach(function () {
    TaskStatus::ensureDefaults();

    $this->user = userWithRole('employee');
    $this->project = Project::factory()->create();
    $this->project->addMember($this->user);
    $this->store = fn (array $data, ?Project $project = null) => $this->actingAs($this->user)
        ->from('/proyectos/'.($project ?? $this->project)->id.'/tareas')
        ->post('/proyectos/'.($project ?? $this->project)->id.'/tareas', $data);
    $this->update = fn (Task $task, array $data) => $this->actingAs($this->user)
        ->from("/proyectos/{$task->project_id}/tareas?tarea={$task->id}")
        ->patch("/tareas/{$task->id}", $data);
});

it('crea una tarea con el estado por defecto, al final de su columna, y el creador la sigue', function () {
    Task::factory()->create(['project_id' => $this->project->id, 'position' => 4]);

    ($this->store)(['title' => '  Maquetar la home  '])
        ->assertRedirect("/proyectos/{$this->project->id}/tareas")
        ->assertSessionHasNoErrors();

    $task = Task::query()->where('title', 'Maquetar la home')->firstOrFail();

    expect($task->status_id)->toBe(TaskStatus::defaultStatus()->id)
        ->and($task->position)->toBe(5)
        ->and($task->created_by)->toBe($this->user->id)
        ->and($task->priority->value)->toBe('normal')
        ->and($task->watchers()->pluck('users.id')->all())->toBe([$this->user->id]);
});

it('crea la tarea en la columna pedida (creación rápida en el kanban)', function () {
    $inProgress = TaskStatus::query()->where('category', 'in_progress')->orderBy('position')->firstOrFail();

    ($this->store)(['title' => 'En curso', 'status_id' => $inProgress->id])->assertSessionHasNoErrors();

    expect(Task::query()->where('title', 'En curso')->value('status_id'))->toBe($inProgress->id);
});

it('exige título', function () {
    ($this->store)(['title' => ''])->assertSessionHasErrors('title');
});

it('en un proyecto de bolsas la bolsa es obligatoria, del proyecto y abierta (SPEC §8.2)', function () {
    $project = Project::factory()->hourBank()->create();
    $project->addMember($this->user);
    $open = HourBank::factory()->create(['project_id' => $project->id]);
    $closed = HourBank::factory()->closed()->create(['project_id' => $project->id]);
    $foreign = HourBank::factory()->create();

    ($this->store)(['title' => 'Sin bolsa'], $project)->assertSessionHasErrors(['hour_bank_id' => __('tasks.errors.bank_required')]);
    ($this->store)(['title' => 'Ajena', 'hour_bank_id' => $foreign->id], $project)->assertSessionHasErrors('hour_bank_id');
    ($this->store)(['title' => 'Cerrada', 'hour_bank_id' => $closed->id], $project)->assertSessionHasErrors('hour_bank_id');
    ($this->store)(['title' => 'Bien', 'hour_bank_id' => $open->id], $project)->assertSessionHasNoErrors();

    expect(Task::query()->where('project_id', $project->id)->pluck('title')->all())->toBe(['Bien'])
        ->and(Task::query()->where('title', 'Bien')->value('hour_bank_id'))->toBe($open->id);
});

it('una bolsa agotada sigue admitiendo tareas', function () {
    $project = Project::factory()->hourBank()->create();
    $project->addMember($this->user);
    $bank = HourBank::factory()->hours(1)->create(['project_id' => $project->id]);
    $task = Task::factory()->inBank($bank)->create();
    TimeEntry::factory()->forTask($task)->minutes(120)->create();

    expect($bank->fresh()->status->value)->toBe('exhausted');

    ($this->store)(['title' => 'Más trabajo', 'hour_bank_id' => $bank->id], $project)->assertSessionHasNoErrors();
});

it('las subtareas son de un solo nivel, del mismo proyecto y con la bolsa del padre (D-037)', function () {
    $project = Project::factory()->hourBank()->create();
    $project->addMember($this->user);
    $bank = HourBank::factory()->create(['project_id' => $project->id]);
    $other = HourBank::factory()->create(['project_id' => $project->id]);
    $parent = Task::factory()->inBank($bank)->create();

    ($this->store)(['title' => 'Hija', 'parent_task_id' => $parent->id, 'hour_bank_id' => $other->id], $project)->assertSessionHasNoErrors();
    $child = Task::query()->where('title', 'Hija')->firstOrFail();

    expect($child->parent_task_id)->toBe($parent->id)
        ->and($child->hour_bank_id)->toBe($bank->id)
        ->and($child->project_id)->toBe($project->id);

    ($this->store)(['title' => 'Nieta', 'parent_task_id' => $child->id], $project)
        ->assertSessionHasErrors(['parent_task_id' => __('tasks.errors.single_level')]);

    $foreign = Task::factory()->create();
    ($this->store)(['title' => 'De otro proyecto', 'parent_task_id' => $foreign->id], $project)
        ->assertSessionHasErrors('parent_task_id');
});

it('crea una subtarea con todos los datos del diálogo (D-173) y la estimación del padre no cambia', function () {
    $project = Project::factory()->hourBank()->create();
    $project->addMember($this->user);
    $bank = HourBank::factory()->create(['project_id' => $project->id]);
    $parent = Task::factory()->inBank($bank)->create(['estimated_minutes' => 3600]);
    $type = TaskType::factory()->create();
    $doing = TaskStatus::query()->where('category', 'in_progress')->firstOrFail();

    ($this->store)([
        'title' => 'Formularios',
        'parent_task_id' => $parent->id,
        'assignee_user_id' => $this->user->id,
        'status_id' => $doing->id,
        'priority' => 'high',
        'task_type_id' => $type->id,
        'start_date' => '2026-10-05',
        'due_date' => '2026-10-09',
        'estimated_minutes' => 90,
    ], $project)->assertSessionHasNoErrors();

    $child = Task::query()->where('title', 'Formularios')->firstOrFail();

    expect($child->parent_task_id)->toBe($parent->id)
        ->and($child->hour_bank_id)->toBe($bank->id)
        ->and($child->assignee_user_id)->toBe($this->user->id)
        ->and($child->status_id)->toBe($doing->id)
        ->and($child->priority->value)->toBe('high')
        ->and($child->task_type_id)->toBe($type->id)
        ->and($child->start_date?->toDateString())->toBe('2026-10-05')
        ->and($child->due_date?->toDateString())->toBe('2026-10-09')
        ->and($child->estimated_minutes)->toBe(90)
        ->and($parent->fresh()->estimated_minutes)->toBe(3600);

    ($this->store)(['title' => 'Al revés', 'parent_task_id' => $parent->id, 'start_date' => '2026-10-09', 'due_date' => '2026-10-05'], $project)
        ->assertSessionHasErrors('due_date');
});

it('no se añaden subtareas a una tarea que se ha quedado en una bolsa cerrada o renovada (BRN-07)', function (string $status, string $label) {
    $project = Project::factory()->hourBank()->create();
    $project->addMember($this->user);
    $bank = HourBank::factory()->create(['project_id' => $project->id]);
    // Un padre terminado se queda en su bolsa al renovarla (solo se mueven las abiertas).
    $parent = Task::factory()->inBank($bank)->completed()->create();
    $bank->update(['status' => $status]);

    ($this->store)(['title' => 'Hija', 'parent_task_id' => $parent->id], $project)
        ->assertSessionHasErrors(['parent_task_id' => "La tarea está en la bolsa «{$bank->name}», que está {$label}: muévela antes a una bolsa abierta para añadirle subtareas."]);

    expect(Task::query()->where('title', 'Hija')->exists())->toBeFalse();
})->with([
    'renovada' => ['renewed', 'renovada'],
    'cerrada' => ['closed', 'cerrada'],
]);

it('las subtareas se colocan al final de sus hermanas', function () {
    $parent = Task::factory()->create(['project_id' => $this->project->id]);
    Task::factory()->subtaskOf($parent)->create(['position' => 3]);

    ($this->store)(['title' => 'Última', 'parent_task_id' => $parent->id])->assertSessionHasNoErrors();

    expect(Task::query()->where('title', 'Última')->value('position'))->toBe(4);
});

it('al elegir un tipo la tarea hereda si es facturable; en un proyecto interno, nunca', function () {
    $type = TaskType::factory()->create(['is_billable_default' => false]);

    ($this->store)(['title' => 'Con tipo', 'task_type_id' => $type->id])->assertSessionHasNoErrors();
    expect(Task::query()->where('title', 'Con tipo')->value('is_billable'))->toBeFalse();

    $billable = TaskType::factory()->create(['is_billable_default' => true]);
    $task = Task::query()->where('title', 'Con tipo')->firstOrFail();
    ($this->update)($task, ['task_type_id' => $billable->id])->assertSessionHasNoErrors();
    expect($task->fresh()->is_billable)->toBeTrue();

    ($this->update)($task, ['task_type_id' => $type->id, 'is_billable' => true])->assertSessionHasNoErrors();
    expect($task->fresh()->is_billable)->toBeTrue();

    $internal = Project::factory()->internal()->create();
    $internal->addMember($this->user);
    ($this->store)(['title' => 'Interna', 'task_type_id' => $billable->id], $internal)->assertSessionHasNoErrors();
    expect(Task::query()->where('title', 'Interna')->value('is_billable'))->toBeFalse();
});

it('no admite tipos desactivados ni responsables inactivos o clientes', function () {
    $inactiveType = TaskType::factory()->create(['is_active' => false]);
    $inactive = User::factory()->employee()->inactive()->create();
    $client = User::factory()->client()->create();

    ($this->store)(['title' => 'X', 'task_type_id' => $inactiveType->id])->assertSessionHasErrors('task_type_id');
    ($this->store)(['title' => 'X', 'assignee_user_id' => $inactive->id])->assertSessionHasErrors('assignee_user_id');
    ($this->store)(['title' => 'X', 'assignee_user_id' => $client->id])->assertSessionHasErrors('assignee_user_id');
});

it('el vencimiento no puede ser anterior al inicio', function () {
    ($this->store)(['title' => 'Fechas', 'start_date' => '2026-10-10', 'due_date' => '2026-10-01'])
        ->assertSessionHasErrors('due_date');

    $task = Task::factory()->create(['project_id' => $this->project->id, 'start_date' => '2026-10-10']);
    ($this->update)($task, ['due_date' => '2026-10-01'])->assertSessionHasErrors('due_date');
    ($this->update)($task, ['due_date' => '2026-10-20'])->assertSessionHasNoErrors();

    expect($task->fresh()->due_date->toDateString())->toBe('2026-10-20');
});

it('edita solo los campos enviados y registra la auditoría', function () {
    $task = Task::factory()->create(['project_id' => $this->project->id, 'title' => 'Antes', 'priority' => 'low']);

    ($this->update)($task, ['title' => 'Después'])->assertSessionHasNoErrors();

    $task->refresh();
    expect($task->title)->toBe('Después')
        ->and($task->priority->value)->toBe('low')
        ->and($task->activitiesAsSubject()->latest('id')->first()->attribute_changes['attributes'])->toBe(['title' => 'Después']);
});

it('sanea la descripción en el servidor (XSS)', function () {
    $task = Task::factory()->create(['project_id' => $this->project->id]);

    ($this->update)($task, ['description' => '<p onclick="alert(1)">Hola <script>alert(1)</script><a href="javascript:alert(1)">x</a><img src=x onerror=alert(1)></p>'])
        ->assertSessionHasNoErrors();

    $description = (string) $task->fresh()->description;

    expect($description)->not->toContain('<script')
        ->not->toContain('onclick')
        ->not->toContain('javascript:')
        ->not->toContain('<img')
        ->toContain('Hola');
});

it('la estimación del padre es la suma de sus subtareas y no se edita a mano (SPEC §6)', function () {
    $parent = Task::factory()->create(['project_id' => $this->project->id, 'estimated_minutes' => 60]);
    Task::factory()->subtaskOf($parent)->create(['estimated_minutes' => 30]);
    Task::factory()->subtaskOf($parent)->create(['estimated_minutes' => 45]);
    Task::factory()->subtaskOf($parent)->create(['estimated_minutes' => null]);

    ($this->update)($parent, ['estimated_minutes' => 600])
        ->assertSessionHasErrors(['estimated_minutes' => __('tasks.errors.estimate_from_subtasks')]);

    expect($parent->fresh()->effectiveEstimatedMinutes())->toBe(75);

    // Sin subtareas con estimación, la del padre se edita con normalidad.
    $alone = Task::factory()->create(['project_id' => $this->project->id]);
    Task::factory()->subtaskOf($alone)->create(['estimated_minutes' => null]);
    ($this->update)($alone, ['estimated_minutes' => 120])->assertSessionHasNoErrors();
    expect($alone->fresh()->estimated_minutes)->toBe(120);
});

it('limita la estimación a 999 horas', function () {
    $task = Task::factory()->create(['project_id' => $this->project->id]);

    ($this->update)($task, ['estimated_minutes' => 999 * 60 + 1])->assertSessionHasErrors('estimated_minutes');
    ($this->update)($task, ['estimated_minutes' => 999 * 60])->assertSessionHasNoErrors();
});

it('completed_at se fija al pasar a un estado «done» y se borra al reabrir', function () {
    $task = Task::factory()->create(['project_id' => $this->project->id]);
    $done = TaskStatus::query()->where('category', 'done')->firstOrFail();

    ($this->update)($task, ['status_id' => $done->id])->assertSessionHasNoErrors();
    expect($task->fresh()->completed_at)->not->toBeNull();

    ($this->update)($task, ['status_id' => TaskStatus::defaultStatus()->id])->assertSessionHasNoErrors();
    expect($task->fresh()->completed_at)->toBeNull();
});

it('al cambiar de estado la tarea va al final de la nueva columna', function () {
    $done = TaskStatus::query()->where('category', 'done')->firstOrFail();
    Task::factory()->create(['project_id' => $this->project->id, 'status_id' => $done->id, 'position' => 7]);
    $task = Task::factory()->create(['project_id' => $this->project->id, 'position' => 0]);

    ($this->update)($task, ['status_id' => $done->id])->assertSessionHasNoErrors();

    expect($task->fresh()->position)->toBe(8);
});

it('un hito no lleva estimación ni puede serlo si ya tiene horas', function () {
    $task = Task::factory()->create(['project_id' => $this->project->id, 'estimated_minutes' => 60]);

    ($this->update)($task, ['is_milestone' => true])->assertSessionHasNoErrors();
    expect($task->fresh()->is_milestone)->toBeTrue()
        ->and($task->fresh()->estimated_minutes)->toBeNull();

    $withTime = Task::factory()->create(['project_id' => $this->project->id]);
    TimeEntry::factory()->forTask($withTime)->create();
    ($this->update)($withTime, ['is_milestone' => true])
        ->assertSessionHasErrors(['is_milestone' => __('tasks.errors.milestone_with_time')]);
});

it('cambiar la bolsa de la tarea mueve la de sus subtareas, pero no las horas ya imputadas', function () {
    $project = Project::factory()->hourBank()->create();
    $project->addMember($this->user);
    $from = HourBank::factory()->create(['project_id' => $project->id]);
    $to = HourBank::factory()->create(['project_id' => $project->id]);
    $parent = Task::factory()->inBank($from)->create();
    $child = Task::factory()->subtaskOf($parent)->create();
    $entry = TimeEntry::factory()->forTask($parent)->minutes(90)->create();

    ($this->update)($parent, ['hour_bank_id' => $to->id])->assertSessionHasNoErrors();

    expect($parent->fresh()->hour_bank_id)->toBe($to->id)
        ->and($child->fresh()->hour_bank_id)->toBe($to->id)
        ->and($entry->fresh()->hour_bank_id)->toBe($from->id)
        ->and($from->fresh()->consumed_minutes)->toBe(90)
        ->and($to->fresh()->consumed_minutes)->toBe(0);

    ($this->update)($child, ['hour_bank_id' => $from->id])
        ->assertSessionHasErrors(['hour_bank_id' => __('tasks.errors.subtask_bank')]);

    ($this->update)($parent, ['hour_bank_id' => null])
        ->assertSessionHasErrors(['hour_bank_id' => __('tasks.errors.bank_required')]);
});

it('no cambia de bolsa con un temporizador en marcha en la tarea o en una subtarea', function (string $where) {
    $project = Project::factory()->hourBank()->create();
    $project->addMember($this->user);
    $from = HourBank::factory()->create(['project_id' => $project->id]);
    $to = HourBank::factory()->create(['project_id' => $project->id]);
    $parent = Task::factory()->inBank($from)->create();
    $child = Task::factory()->subtaskOf($parent)->create();
    ActiveTimer::query()->create(['user_id' => $this->user->id, 'task_id' => $where === 'tarea' ? $parent->id : $child->id, 'started_at' => now()->subHour()]);

    ($this->update)($parent, ['hour_bank_id' => $to->id])
        ->assertSessionHasErrors(['hour_bank_id' => __('tasks.errors.bank_timer_running')]);

    expect($parent->fresh()->hour_bank_id)->toBe($from->id)
        ->and($child->fresh()->hour_bank_id)->toBe($from->id);

    // El resto de cambios sí se admiten con el temporizador en marcha.
    ($this->update)($parent, ['title' => 'Otro título', 'hour_bank_id' => $from->id])->assertSessionHasNoErrors();
    expect($parent->fresh()->title)->toBe('Otro título');
})->with(['tarea', 'subtarea']);

it('borra (papelera) una tarea sin horas, con sus subtareas', function () {
    $task = Task::factory()->create(['project_id' => $this->project->id]);
    $child = Task::factory()->subtaskOf($task)->create();

    $this->actingAs($this->user)
        ->from("/proyectos/{$this->project->id}/tareas?tarea={$task->id}&vista=kanban")
        ->delete("/tareas/{$task->id}")
        ->assertRedirect("/proyectos/{$this->project->id}/tareas?vista=kanban");

    expect(Task::query()->find($task->id))->toBeNull()
        ->and(Task::withTrashed()->find($task->id)?->trashed())->toBeTrue()
        ->and(Task::query()->find($child->id))->toBeNull();
});

it('al borrar una subtarea vuelve al panel de su padre', function () {
    $task = Task::factory()->create(['project_id' => $this->project->id]);
    $child = Task::factory()->subtaskOf($task)->create();

    $this->actingAs($this->user)
        ->from("/proyectos/{$this->project->id}/tareas?tarea={$child->id}")
        ->delete("/tareas/{$child->id}")
        ->assertRedirect("/proyectos/{$this->project->id}/tareas?tarea={$task->id}");
});

it('no borra una tarea con horas, con subtareas con horas o con un temporizador en marcha', function (string $case) {
    $task = Task::factory()->create(['project_id' => $this->project->id, 'title' => 'Protegida']);

    match ($case) {
        'has_time' => TimeEntry::factory()->forTask($task)->create(),
        'subtasks_have_time' => TimeEntry::factory()->forTask(Task::factory()->subtaskOf($task)->create())->create(),
        'timer_running' => ActiveTimer::query()->create(['user_id' => $this->user->id, 'task_id' => $task->id, 'started_at' => now()]),
    };

    $this->actingAs($this->user)
        ->from("/proyectos/{$this->project->id}/tareas")
        ->delete("/tareas/{$task->id}")
        ->assertSessionHasErrors(['task' => __("tasks.errors.{$case}", ['task' => 'Protegida'])]);

    expect(Task::query()->find($task->id))->not->toBeNull();
})->with(['has_time', 'subtasks_have_time', 'timer_running']);

it('no crea tareas en un proyecto archivado', function () {
    $archived = Project::factory()->archived()->create();
    $archived->addMember($this->user);

    ($this->store)(['title' => 'Nada'], $archived)->assertForbidden();
});

it('seguir y dejar de seguir una tarea', function () {
    $task = Task::factory()->create(['project_id' => $this->project->id]);
    $outsider = userWithRole('employee');

    $this->actingAs($outsider)->post("/tareas/{$task->id}/seguir")->assertRedirect();
    expect($task->watchers()->whereKey($outsider->id)->exists())->toBeTrue();

    $this->actingAs($outsider)->post("/tareas/{$task->id}/seguir")->assertRedirect();
    expect($task->watchers()->count())->toBe(1);

    $this->actingAs($outsider)->delete("/tareas/{$task->id}/seguir")->assertRedirect();
    expect($task->watchers()->whereKey($outsider->id)->exists())->toBeFalse();
});

it('asignar una tarea hace seguidor al responsable', function () {
    $task = Task::factory()->create(['project_id' => $this->project->id]);
    $colleague = User::factory()->employee()->create();

    ($this->update)($task, ['assignee_user_id' => $colleague->id])->assertSessionHasNoErrors();

    expect($task->fresh()->assignee_user_id)->toBe($colleague->id)
        ->and($task->watchers()->whereKey($colleague->id)->exists())->toBeTrue();
});
