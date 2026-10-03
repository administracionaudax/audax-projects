<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Notifications\Tasks\TaskCommentedNotification;
use App\Notifications\Tasks\TaskMentionedNotification;
use App\Notifications\Tasks\TaskStatusChangedNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

/*
| Avisos de tareas y un colaborador externo (D-134): ningún aviso de una tarea le llega si no es
| miembro de su proyecto, ni por una mención en la descripción ni como seguidor que se ha quedado
| atrás; él solo menciona a miembros; y el aviso diario de tareas que vencen solo habla de las
| tareas de sus proyectos. "Hoy" es el viernes 25/09/2026 en Madrid.
*/

beforeEach(function () {
    TaskStatus::ensureDefaults();
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-09-25 10:00:00', 'Europe/Madrid'));

    $this->sara = User::factory()->collaborator()->create(['name' => 'Sara Colaboradora']);
    $this->ana = User::factory()->employee()->create(['name' => 'Ana Plantilla']);
    $this->outsider = User::factory()->employee()->create(['name' => 'Óscar Fuera']);

    $this->own = Project::factory()->withMembers([$this->sara, $this->ana])->create();
    $this->foreign = Project::factory()->withMembers([$this->ana])->create();

    $this->mention = fn (User $user): string => sprintf('<span data-type="mention" data-id="%d" data-label="%s">@%s</span>', $user->id, e($user->name), e($user->name));
});

it('una mención en la descripción de una tarea ajena no le avisa; en una de sus proyectos, sí', function () {
    $this->actingAs($this->ana)
        ->post("/proyectos/{$this->foreign->id}/tareas", ['title' => 'Ajena', 'description' => '<p>'.($this->mention)($this->sara).'</p>'])
        ->assertSessionHasNoErrors();
    Notification::assertNotSentTo($this->sara, TaskMentionedNotification::class);

    // Al editar la descripción, tampoco.
    $foreignTask = Task::query()->where('project_id', $this->foreign->id)->sole();
    $this->actingAs($this->ana)
        ->patch("/tareas/{$foreignTask->id}", ['description' => '<p>Otra vez '.($this->mention)($this->sara).'</p>'])
        ->assertSessionHasNoErrors();
    Notification::assertNotSentTo($this->sara, TaskMentionedNotification::class);

    $this->actingAs($this->ana)
        ->post("/proyectos/{$this->own->id}/tareas", ['title' => 'Suya', 'description' => '<p>'.($this->mention)($this->sara).'</p>'])
        ->assertSessionHasNoErrors();
    Notification::assertSentToTimes($this->sara, TaskMentionedNotification::class, 1);
});

it('desde la descripción él solo menciona a miembros del proyecto', function () {
    $this->actingAs($this->sara)
        ->post("/proyectos/{$this->own->id}/tareas", ['title' => 'Mía', 'description' => '<p>'.($this->mention)($this->outsider).' '.($this->mention)($this->ana).'</p>'])
        ->assertSessionHasNoErrors();

    Notification::assertNotSentTo($this->outsider, TaskMentionedNotification::class);
    Notification::assertSentTo($this->ana, TaskMentionedNotification::class);

    $task = Task::query()->where('project_id', $this->own->id)->sole();
    $this->actingAs($this->sara)
        ->patch("/tareas/{$task->id}", ['description' => '<p>Y ahora '.($this->mention)($this->outsider).'</p>'])
        ->assertSessionHasNoErrors();
    Notification::assertNotSentTo($this->outsider, TaskMentionedNotification::class);
});

it('red de seguridad: aunque siga como seguidor de una tarea ajena, no recibe sus comentarios ni sus cambios de estado', function () {
    $task = Task::factory()->create(['project_id' => $this->foreign->id]);
    $task->watchers()->attach([$this->sara->id, $this->ana->id]);
    $other = User::factory()->employee()->create();
    $this->foreign->addMember($other);
    $task->watchers()->attach($other->id);

    $this->actingAs($this->ana)->post("/tareas/{$task->id}/comentarios", ['body' => '<p>Hola</p>'])->assertSessionHasNoErrors();
    $done = TaskStatus::query()->where('id', '!=', $task->status_id)->orderBy('position')->firstOrFail();
    $this->actingAs($this->ana)->patch("/tareas/{$task->id}", ['status_id' => $done->id])->assertSessionHasNoErrors();

    Notification::assertNotSentTo($this->sara, TaskCommentedNotification::class);
    Notification::assertNotSentTo($this->sara, TaskStatusChangedNotification::class);
    Notification::assertSentTo($other, TaskCommentedNotification::class);
    Notification::assertSentTo($other, TaskStatusChangedNotification::class);
});
