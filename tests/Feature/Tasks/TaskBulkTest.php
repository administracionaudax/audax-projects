<?php

use App\Models\HourBank;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\TimeEntry;
use App\Models\User;
use App\Notifications\Tasks\TaskAssignedNotification;
use Illuminate\Support\Facades\Notification;

/*
| Acciones masivas (SPEC §6): estado, responsable, fechas o bolsa de varias tareas; todo o nada.
*/

beforeEach(function () {
    TaskStatus::ensureDefaults();

    $this->user = userWithRole('employee');
    $this->project = Project::factory()->hourBank()->create();
    $this->project->addMember($this->user);
    $this->bankA = HourBank::factory()->create(['project_id' => $this->project->id]);
    $this->bankB = HourBank::factory()->create(['project_id' => $this->project->id]);
    $this->tasks = Task::factory()->count(3)->inBank($this->bankA)->create();
    $this->bulk = fn (array $data) => $this->actingAs($this->user)
        ->from("/proyectos/{$this->project->id}/tareas")
        ->patch("/proyectos/{$this->project->id}/tareas/masivo", $data);
});

it('cambia el estado de varias tareas', function () {
    $done = TaskStatus::query()->where('category', 'done')->firstOrFail();

    ($this->bulk)(['ids' => $this->tasks->modelKeys(), 'status_id' => $done->id])
        ->assertRedirect("/proyectos/{$this->project->id}/tareas")
        ->assertSessionHasNoErrors();

    foreach ($this->tasks as $task) {
        expect($task->fresh()->status_id)->toBe($done->id)
            ->and($task->fresh()->completed_at)->not->toBeNull();
    }
});

it('asigna o quita el responsable y avisa al nuevo', function () {
    Notification::fake();
    $colleague = User::factory()->employee()->create();

    ($this->bulk)(['ids' => $this->tasks->modelKeys(), 'assignee_user_id' => $colleague->id])->assertSessionHasNoErrors();

    expect(Task::query()->whereKey($this->tasks->modelKeys())->pluck('assignee_user_id')->unique()->all())->toBe([$colleague->id]);
    Notification::assertSentToTimes($colleague, TaskAssignedNotification::class, 3);

    ($this->bulk)(['ids' => $this->tasks->modelKeys(), 'assignee_user_id' => null])->assertSessionHasNoErrors();
    expect(Task::query()->whereKey($this->tasks->modelKeys())->whereNotNull('assignee_user_id')->count())->toBe(0);
});

it('cambia las fechas sin tocar el resto', function () {
    ($this->bulk)(['ids' => $this->tasks->modelKeys(), 'due_date' => '2026-10-30'])->assertSessionHasNoErrors();

    foreach ($this->tasks as $task) {
        expect($task->fresh()->due_date->toDateString())->toBe('2026-10-30')
            ->and($task->fresh()->start_date)->toBeNull();
    }
});

it('cambia la bolsa de las tareas raíz (y sus subtareas), no las horas imputadas', function () {
    $child = Task::factory()->subtaskOf($this->tasks[0])->create();
    $entry = TimeEntry::factory()->forTask($this->tasks[0])->minutes(60)->create();

    ($this->bulk)(['ids' => [...$this->tasks->modelKeys(), $child->id], 'hour_bank_id' => $this->bankB->id])
        ->assertSessionHasNoErrors();

    expect(Task::query()->whereKey($this->tasks->modelKeys())->pluck('hour_bank_id')->unique()->all())->toBe([$this->bankB->id])
        ->and($child->fresh()->hour_bank_id)->toBe($this->bankB->id)
        ->and($entry->fresh()->hour_bank_id)->toBe($this->bankA->id);
});

it('es todo o nada: si una tarea no admite el cambio, no cambia ninguna', function () {
    $this->tasks[1]->update(['start_date' => '2026-11-15']);

    ($this->bulk)(['ids' => $this->tasks->modelKeys(), 'due_date' => '2026-11-01'])
        ->assertSessionHasErrors('ids');

    expect(Task::query()->whereKey($this->tasks->modelKeys())->whereNotNull('due_date')->count())->toBe(0);
});

it('no sale ningún aviso si la acción masiva falla', function () {
    Notification::fake();
    $colleague = User::factory()->employee()->create();
    $closed = HourBank::factory()->closed()->create(['project_id' => $this->project->id]);

    ($this->bulk)(['ids' => $this->tasks->modelKeys(), 'assignee_user_id' => $colleague->id, 'hour_bank_id' => $closed->id])
        ->assertSessionHasErrors('ids');

    Notification::assertNothingSent();
    expect(Task::query()->whereNotNull('assignee_user_id')->count())->toBe(0);
});

it('rechaza tareas de otros proyectos y peticiones sin cambios', function () {
    $foreign = Task::factory()->create();

    ($this->bulk)(['ids' => [$this->tasks[0]->id, $foreign->id], 'due_date' => '2026-10-30'])->assertSessionHasErrors('ids');
    ($this->bulk)(['ids' => $this->tasks->modelKeys()])->assertSessionHasErrors('ids');

    expect($foreign->fresh()->due_date)->toBeNull();
});
