<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Notifications\Tasks\TaskStatusChangedNotification;
use Illuminate\Support\Facades\Notification;

/*
| Kanban (SPEC §6): soltar una tarjeta cambia su estado y su posición; las posiciones de cada
| columna quedan consecutivas y en el orden que ve el usuario.
*/

beforeEach(function () {
    TaskStatus::ensureDefaults();

    $this->user = userWithRole('employee');
    $this->project = Project::factory()->create();
    $this->project->addMember($this->user);
    $this->todo = TaskStatus::defaultStatus();
    $this->doing = TaskStatus::query()->where('category', 'in_progress')->orderBy('position')->firstOrFail();
    $this->done = TaskStatus::query()->where('category', 'done')->firstOrFail();

    $this->column = fn (TaskStatus $status): array => Task::query()
        ->where('project_id', $this->project->id)
        ->where('status_id', $status->id)
        ->whereNull('parent_task_id')
        ->orderBy('position')
        ->get(['title', 'position'])
        ->map(fn (Task $task): string => "{$task->position}:{$task->title}")
        ->all();

    $this->make = function (TaskStatus $status, array $titles): array {
        $tasks = [];
        foreach ($titles as $position => $title) {
            $tasks[$title] = Task::factory()->create([
                'project_id' => $this->project->id,
                'status_id' => $status->id,
                'title' => $title,
                'position' => $position * 10,
            ]);
        }

        return $tasks;
    };

    $this->drop = fn (Task $task, TaskStatus $status, array $neighbour = []) => $this->actingAs($this->user)
        ->from("/proyectos/{$this->project->id}/tareas?vista=kanban")
        ->patch("/tareas/{$task->id}/posicion", ['status_id' => $status->id, ...$neighbour]);
});

it('reordena dentro de la misma columna y deja las posiciones consecutivas', function () {
    $tasks = ($this->make)($this->todo, ['A', 'B', 'C', 'D']);

    ($this->drop)($tasks['D'], $this->todo, ['before_id' => $tasks['B']->id])
        ->assertRedirect("/proyectos/{$this->project->id}/tareas?vista=kanban")
        ->assertSessionHasNoErrors();

    expect(($this->column)($this->todo))->toBe(['0:A', '1:D', '2:B', '3:C']);

    ($this->drop)($tasks['A'], $this->todo, ['after_id' => $tasks['C']->id])->assertSessionHasNoErrors();

    expect(($this->column)($this->todo))->toBe(['0:D', '1:B', '2:C', '3:A']);
});

it('mueve a otra columna: cambia el estado, la coloca en su sitio y renumera las dos columnas', function () {
    $todo = ($this->make)($this->todo, ['A', 'B', 'C']);
    $doing = ($this->make)($this->doing, ['X', 'Y']);

    ($this->drop)($todo['B'], $this->doing, ['before_id' => $doing['Y']->id])->assertSessionHasNoErrors();

    expect($todo['B']->fresh()->status_id)->toBe($this->doing->id)
        ->and(($this->column)($this->doing))->toBe(['0:X', '1:B', '2:Y'])
        // La columna de origen puede quedar con un hueco: se renumera al soltar en ella.
        ->and(array_map(fn (string $item) => substr($item, strpos($item, ':') + 1), ($this->column)($this->todo)))->toBe(['A', 'C']);
});

it('sin vecina la coloca al final; en una columna vacía, la primera', function () {
    $todo = ($this->make)($this->todo, ['A', 'B']);

    ($this->drop)($todo['A'], $this->doing)->assertSessionHasNoErrors();
    expect(($this->column)($this->doing))->toBe(['0:A']);

    ($this->drop)($todo['B'], $this->doing)->assertSessionHasNoErrors();
    expect(($this->column)($this->doing))->toBe(['0:A', '1:B']);
});

it('al soltar en una columna «done» se completa y avisa a los seguidores', function () {
    Notification::fake();
    $watcher = User::factory()->employee()->create();
    $todo = ($this->make)($this->todo, ['A']);
    $todo['A']->watchers()->attach([$watcher->id, $this->user->id]);

    ($this->drop)($todo['A'], $this->done)->assertSessionHasNoErrors();

    expect($todo['A']->fresh()->completed_at)->not->toBeNull();
    Notification::assertSentTo($watcher, TaskStatusChangedNotification::class);
    Notification::assertNotSentTo($this->user, TaskStatusChangedNotification::class);
});

it('reordenar en la misma columna no avisa a nadie', function () {
    Notification::fake();
    $watcher = User::factory()->employee()->create();
    $tasks = ($this->make)($this->todo, ['A', 'B']);
    $tasks['A']->watchers()->attach($watcher->id);

    ($this->drop)($tasks['A'], $this->todo, ['after_id' => $tasks['B']->id])->assertSessionHasNoErrors();

    Notification::assertNothingSent();
});

it('rechaza una vecina que no está en la columna de destino', function () {
    $todo = ($this->make)($this->todo, ['A', 'B']);
    $doing = ($this->make)($this->doing, ['X']);

    ($this->drop)($todo['A'], $this->todo, ['before_id' => $doing['X']->id])->assertSessionHasErrors('before_id');

    expect($todo['A']->fresh()->position)->toBe(0);
});

it('las subtareas no se mueven en el kanban', function () {
    $parent = Task::factory()->create(['project_id' => $this->project->id]);
    $child = Task::factory()->subtaskOf($parent)->create();

    ($this->drop)($child, $this->doing)->assertSessionHasErrors('status_id');
});

it('no cuenta ni mueve tareas de otros proyectos ni subtareas al renumerar', function () {
    $tasks = ($this->make)($this->todo, ['A', 'B']);
    $foreign = Task::factory()->create(['status_id' => $this->todo->id, 'position' => 0]);
    $child = Task::factory()->subtaskOf($tasks['A'])->create(['status_id' => $this->todo->id, 'position' => 0]);

    ($this->drop)($tasks['B'], $this->todo, ['before_id' => $tasks['A']->id])->assertSessionHasNoErrors();

    expect(($this->column)($this->todo))->toBe(['0:B', '1:A'])
        ->and($foreign->fresh()->position)->toBe(0)
        ->and($child->fresh()->position)->toBe(0);
});
