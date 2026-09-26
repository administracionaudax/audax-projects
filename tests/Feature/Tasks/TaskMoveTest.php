<?php

use App\Domain\Time\TimerService;
use App\Models\ActiveTimer;
use App\Models\Attachment;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\TaskStatus;
use App\Models\TimeEntry;

/*
| Mover una tarea a otro proyecto (SPEC §6): con sus subtareas y la bolsa elegida de nuevo;
| las horas ya imputadas se quedan en su proyecto y su bolsa.
*/

beforeEach(function () {
    TaskStatus::ensureDefaults();

    $this->user = userWithRole('employee');
    $this->source = Project::factory()->hourBank()->create();
    $this->source->addMember($this->user);
    $this->sourceBank = HourBank::factory()->create(['project_id' => $this->source->id]);
    $this->target = Project::factory()->hourBank()->create();
    $this->target->addMember($this->user);
    $this->targetBank = HourBank::factory()->create(['project_id' => $this->target->id]);
    $this->task = Task::factory()->inBank($this->sourceBank)->create(['title' => 'Viajera']);
    $this->move = fn (array $data, ?Task $task = null) => $this->actingAs($this->user)
        ->from("/proyectos/{$this->source->id}/tareas?tarea=".($task ?? $this->task)->id)
        ->post('/tareas/'.($task ?? $this->task)->id.'/mover', $data);
});

it('mueve la tarea y sus subtareas, pide la bolsa del destino y deja las horas donde estaban', function () {
    $child = Task::factory()->subtaskOf($this->task)->create();
    $entry = TimeEntry::factory()->forTask($this->task)->minutes(120)->create();
    $childEntry = TimeEntry::factory()->forTask($child)->minutes(30)->create();
    $attachment = Attachment::factory()->create(['attachable_id' => $this->task->id, 'project_id' => $this->source->id]);
    $comment = TaskComment::factory()->create(['task_id' => $child->id]);
    $commentFile = Attachment::factory()->create(['attachable_type' => TaskComment::class, 'attachable_id' => $comment->id, 'project_id' => $this->source->id]);

    ($this->move)(['project_id' => $this->target->id, 'hour_bank_id' => $this->targetBank->id])
        ->assertRedirect("/proyectos/{$this->target->id}/tareas?tarea={$this->task->id}")
        ->assertSessionHasNoErrors();

    expect($this->task->fresh()->project_id)->toBe($this->target->id)
        ->and($this->task->fresh()->hour_bank_id)->toBe($this->targetBank->id)
        ->and($child->fresh()->project_id)->toBe($this->target->id)
        ->and($child->fresh()->hour_bank_id)->toBe($this->targetBank->id)
        // Las horas no se mueven (SPEC §6).
        ->and($entry->fresh()->project_id)->toBe($this->source->id)
        ->and($entry->fresh()->hour_bank_id)->toBe($this->sourceBank->id)
        ->and($childEntry->fresh()->hour_bank_id)->toBe($this->sourceBank->id)
        ->and($this->sourceBank->fresh()->consumed_minutes)->toBe(150)
        ->and($this->targetBank->fresh()->consumed_minutes)->toBe(0)
        // Los adjuntos pasan a la pestaña Archivos del destino.
        ->and($attachment->fresh()->project_id)->toBe($this->target->id)
        ->and($commentFile->fresh()->project_id)->toBe($this->target->id);
});

it('exige la bolsa si el destino usa bolsas, y que sea suya y esté abierta', function () {
    $closed = HourBank::factory()->closed()->create(['project_id' => $this->target->id]);

    ($this->move)(['project_id' => $this->target->id])->assertSessionHasErrors('hour_bank_id');
    ($this->move)(['project_id' => $this->target->id, 'hour_bank_id' => $this->sourceBank->id])->assertSessionHasErrors('hour_bank_id');
    ($this->move)(['project_id' => $this->target->id, 'hour_bank_id' => $closed->id])->assertSessionHasErrors('hour_bank_id');

    expect($this->task->fresh()->project_id)->toBe($this->source->id);
});

it('a un proyecto sin bolsas la tarea queda sin bolsa', function () {
    $plain = Project::factory()->create();
    $plain->addMember($this->user);

    ($this->move)(['project_id' => $plain->id])->assertSessionHasNoErrors();

    expect($this->task->fresh()->project_id)->toBe($plain->id)
        ->and($this->task->fresh()->hour_bank_id)->toBeNull();
});

it('solo a proyectos donde puede crear tareas y nunca al mismo', function () {
    $foreign = Project::factory()->create();
    $archived = Project::factory()->archived()->create();
    $archived->addMember($this->user);

    ($this->move)(['project_id' => $foreign->id])->assertSessionHasErrors(['project_id' => __('tasks.errors.move_forbidden')]);
    ($this->move)(['project_id' => $archived->id])->assertSessionHasErrors('project_id');
    ($this->move)(['project_id' => $this->source->id, 'hour_bank_id' => $this->sourceBank->id])
        ->assertSessionHasErrors(['project_id' => __('tasks.errors.move_same_project')]);
});

it('no se mueve con un temporizador en marcha en la tarea o en una subtarea', function (string $where) {
    $child = Task::factory()->subtaskOf($this->task)->create();
    $colleague = userWithRole('employee');
    $this->source->addMember($colleague);
    $this->travelTo(now()->subHours(3));
    app(TimerService::class)->start($colleague, $where === 'tarea' ? $this->task : $child);
    $this->travelBack();

    ($this->move)(['project_id' => $this->target->id, 'hour_bank_id' => $this->targetBank->id])
        ->assertSessionHasErrors(['project_id' => __('tasks.errors.move_timer_running')]);

    expect($this->task->fresh()->project_id)->toBe($this->source->id)
        ->and($child->fresh()->project_id)->toBe($this->source->id);

    // Parado el temporizador, sus horas se imputan al origen y ya se puede mover sin llevárselas.
    app(TimerService::class)->stop($colleague);
    expect(ActiveTimer::query()->count())->toBe(0);

    ($this->move)(['project_id' => $this->target->id, 'hour_bank_id' => $this->targetBank->id])->assertSessionHasNoErrors();

    expect($this->task->fresh()->project_id)->toBe($this->target->id)
        ->and(TimeEntry::query()->where('user_id', $colleague->id)->sum('minutes'))->toBeGreaterThanOrEqual(179)
        ->and(TimeEntry::query()->where('user_id', $colleague->id)->where('project_id', $this->source->id)->where('hour_bank_id', $this->sourceBank->id)->count())
        ->toBe(TimeEntry::query()->where('user_id', $colleague->id)->count())
        ->and($this->targetBank->fresh()->consumed_minutes)->toBe(0);
})->with(['tarea', 'subtarea']);

it('las subtareas no se mueven solas', function () {
    $child = Task::factory()->subtaskOf($this->task)->create();

    ($this->move)(['project_id' => $this->target->id, 'hour_bank_id' => $this->targetBank->id], $child)
        ->assertSessionHasErrors(['project_id' => __('tasks.errors.move_subtask')]);
});

it('hace falta poder editar la tarea', function () {
    $outsider = userWithRole('employee');
    $this->target->addMember($outsider);

    $this->actingAs($outsider)
        ->post("/tareas/{$this->task->id}/mover", ['project_id' => $this->target->id, 'hour_bank_id' => $this->targetBank->id])
        ->assertForbidden();
});
