<?php

use App\Models\Attachment;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\TaskStatus;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/*
| Matriz de permisos de las tareas (D-031, TaskPolicy):
| - ver: todos los internos; clientes, siempre fuera (a su portal),
| - crear, editar, mover y borrar: miembros del proyecto y quienes lo gestionan (gestores,
|   responsables y admins),
| - comentar: cualquier interno.
*/

beforeEach(function () {
    TaskStatus::ensureDefaults();
    Storage::fake('local');

    $this->project = Project::factory()->create();
    $this->task = Task::factory()->create(['project_id' => $this->project->id, 'title' => 'Maquetar la home']);

    $this->actors = [
        'admin' => userWithRole('admin'),
        'department_manager' => userWithRole('department_manager'),
        'member' => userWithRole('employee'),
        'manager' => userWithRole('employee'),
        'outsider' => userWithRole('employee'),
        'client' => userWithRole('client'),
    ];
    $this->project->addMember($this->actors['member']);
    $this->project->addMember($this->actors['manager'], isManager: true);

    $this->as = function (string $actor) {
        if ($actor !== 'guest') {
            $this->actingAs($this->actors[$actor]);
        }

        return $this;
    };
});

dataset('actores', ['guest', 'admin', 'department_manager', 'member', 'manager', 'outsider', 'client']);

test('ver la pestaña Tareas, Mis tareas y Archivos: todos los internos; invitados al login y clientes al portal', function (string $actor) {
    ($this->as)($actor);

    foreach ([
        "/proyectos/{$this->project->id}/tareas",
        "/proyectos/{$this->project->id}/tareas?tarea={$this->task->id}",
        "/proyectos/{$this->project->id}/archivos",
        '/mis-tareas',
    ] as $url) {
        $response = $this->get($url);

        match ($actor) {
            'guest' => $response->assertRedirect(route('login')),
            'client' => $response->assertRedirect(route('portal.home')),
            default => $response->assertOk(),
        };
    }
})->with('actores');

test('/tareas/{id} lleva al panel de la tarea en su proyecto', function (string $actor) {
    ($this->as)($actor);

    $response = $this->get("/tareas/{$this->task->id}");

    match ($actor) {
        'guest' => $response->assertRedirect(route('login')),
        'client' => $response->assertRedirect(route('portal.home')),
        default => $response->assertRedirect("/proyectos/{$this->project->id}/tareas?tarea={$this->task->id}"),
    };
})->with('actores');

test('crear tareas: miembros y quienes gestionan el proyecto', function (string $actor) {
    ($this->as)($actor);

    $response = $this->post("/proyectos/{$this->project->id}/tareas", ['title' => 'Nueva']);

    match ($actor) {
        'guest' => $response->assertRedirect(route('login')),
        'client' => $response->assertRedirect(route('portal.home')),
        'outsider' => $response->assertForbidden(),
        default => $response->assertRedirect(),
    };

    expect(Task::query()->where('title', 'Nueva')->exists())
        ->toBe(in_array($actor, ['admin', 'department_manager', 'member', 'manager'], true));
})->with('actores');

test('editar, reordenar y seguir la edición: miembros y gestores; los demás, 403', function (string $actor) {
    ($this->as)($actor);
    $allowed = in_array($actor, ['admin', 'department_manager', 'member', 'manager'], true);

    $response = $this->patch("/tareas/{$this->task->id}", ['title' => 'Cambiada']);
    $position = $this->patch("/tareas/{$this->task->id}/posicion", ['status_id' => $this->task->status_id]);

    foreach ([$response, $position] as $result) {
        match (true) {
            $actor === 'guest' => $result->assertRedirect(route('login')),
            $actor === 'client' => $result->assertRedirect(route('portal.home')),
            $allowed => $result->assertRedirect(),
            default => $result->assertForbidden(),
        };
    }

    expect($this->task->fresh()->title)->toBe($allowed ? 'Cambiada' : 'Maquetar la home');
})->with('actores');

test('borrar: miembros y gestores, y solo si no tiene horas', function (string $actor) {
    ($this->as)($actor);
    $allowed = in_array($actor, ['admin', 'department_manager', 'member', 'manager'], true);

    $response = $this->delete("/tareas/{$this->task->id}");

    match (true) {
        $actor === 'guest' => $response->assertRedirect(route('login')),
        $actor === 'client' => $response->assertRedirect(route('portal.home')),
        $allowed => $response->assertRedirect(),
        default => $response->assertForbidden(),
    };

    expect(Task::query()->whereKey($this->task->id)->exists())->toBe(! $allowed);
})->with('actores');

test('comentar y reaccionar: cualquier interno', function (string $actor) {
    ($this->as)($actor);
    $internal = ! in_array($actor, ['guest', 'client'], true);

    $response = $this->post("/tareas/{$this->task->id}/comentarios", ['body' => '<p>Hola</p>']);

    match ($actor) {
        'guest' => $response->assertRedirect(route('login')),
        'client' => $response->assertRedirect(route('portal.home')),
        default => $response->assertRedirect(),
    };

    expect(TaskComment::query()->where('task_id', $this->task->id)->count())->toBe($internal ? 1 : 0);
})->with('actores');

test('seguir una tarea: cualquier interno', function (string $actor) {
    ($this->as)($actor);

    $response = $this->post("/tareas/{$this->task->id}/seguir");

    match ($actor) {
        'guest' => $response->assertRedirect(route('login')),
        'client' => $response->assertRedirect(route('portal.home')),
        default => $response->assertRedirect(),
    };
})->with('actores');

test('mover y acciones masivas: solo miembros y gestores', function (string $actor) {
    ($this->as)($actor);
    $allowed = in_array($actor, ['admin', 'department_manager', 'member', 'manager'], true);
    $status = TaskStatus::query()->where('category', 'in_progress')->firstOrFail();

    $bulk = $this->patch("/proyectos/{$this->project->id}/tareas/masivo", [
        'ids' => [$this->task->id],
        'status_id' => $status->id,
    ]);

    match (true) {
        $actor === 'guest' => $bulk->assertRedirect(route('login')),
        $actor === 'client' => $bulk->assertRedirect(route('portal.home')),
        $allowed => $bulk->assertRedirect(),
        default => $bulk->assertForbidden(),
    };

    expect($this->task->fresh()->status_id === $status->id)->toBe($allowed);
})->with('actores');

test('subir adjuntos a una tarea: quien puede editarla; descargar: cualquier interno con la URL firmada', function (string $actor) {
    ($this->as)($actor);
    $allowed = in_array($actor, ['admin', 'department_manager', 'member', 'manager'], true);

    $upload = $this->post("/tareas/{$this->task->id}/adjuntos", [
        'files' => [UploadedFile::fake()->create('acta.pdf', 12, 'application/pdf')],
    ]);

    match (true) {
        $actor === 'guest' => $upload->assertRedirect(route('login')),
        $actor === 'client' => $upload->assertRedirect(route('portal.home')),
        $allowed => $upload->assertRedirect(),
        default => $upload->assertForbidden(),
    };

    $attachment = Attachment::factory()->create([
        'attachable_type' => Task::class,
        'attachable_id' => $this->task->id,
        'project_id' => $this->project->id,
    ]);
    Storage::disk('local')->put($attachment->path, '%PDF-1.4');
    $url = URL::temporarySignedRoute('attachments.show', now()->addHour(), ['attachment' => $attachment->id], absolute: false);

    $download = $this->get($url);

    match ($actor) {
        'guest' => $download->assertRedirect(route('login')),
        'client' => $download->assertRedirect(route('portal.home')),
        default => $download->assertOk(),
    };
})->with('actores');

test('una tarea con horas no se puede borrar ni siquiera un admin (D-037)', function () {
    TimeEntry::factory()->forTask($this->task)->create();

    $this->actingAs($this->actors['admin'])
        ->delete("/tareas/{$this->task->id}")
        ->assertRedirect()
        ->assertSessionHasErrors('task');

    expect(Task::query()->whereKey($this->task->id)->exists())->toBeTrue();
});

test('un usuario desactivado no puede hacer nada', function () {
    $inactive = User::factory()->employee()->inactive()->create();
    $this->project->addMember($inactive);

    $this->actingAs($inactive)->get("/proyectos/{$this->project->id}/tareas")->assertRedirect();
    expect(Task::query()->count())->toBe(1);
});
