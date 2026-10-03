<?php

use App\Domain\Chat\ConversationDirectory;
use App\Domain\Import\ClickUp\ImportReport;
use App\Domain\Import\ClickUp\PeopleFile;
use App\Domain\Import\ClickUp\PeopleImporter;
use App\Domain\Import\ClickUp\PersonSpec;
use App\Enums\Role;
use App\Models\ActiveTimer;
use App\Models\ConversationParticipant;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

/*
| Lo que deja atrás un colaborador externo al dejar de ver un proyecto (D-134): al sacarlo del
| proyecto, al mover una tarea a uno del que no es miembro y al pasar a colaborador, deja de
| seguir sus tareas, se quedan sin responsable y su temporizador en ellas se descarta sin imputar.
| Al pasar a colaborador, además, sale de sus directas y grupos.
*/

beforeEach(function () {
    TaskStatus::ensureDefaults();
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-09-25 10:00:00', 'Europe/Madrid'));

    $this->admin = User::factory()->admin()->create();
    $this->sara = User::factory()->collaborator()->create(['name' => 'Sara Colaboradora']);
    $this->ana = User::factory()->employee()->create(['name' => 'Ana Plantilla']);

    $this->own = Project::factory()->withMembers([$this->sara, $this->ana])->create();
    $this->other = Project::factory()->withMembers([$this->sara, $this->ana])->create();

    $this->task = Task::factory()->create(['project_id' => $this->own->id, 'assignee_user_id' => $this->sara->id]);
    $this->subtask = Task::factory()->create(['project_id' => $this->own->id, 'parent_task_id' => $this->task->id, 'assignee_user_id' => $this->sara->id]);
    $this->task->watchers()->attach([$this->sara->id, $this->ana->id]);
    $this->subtask->watchers()->attach([$this->sara->id]);

    $this->kept = Task::factory()->create(['project_id' => $this->other->id, 'assignee_user_id' => $this->sara->id]);
    $this->kept->watchers()->attach([$this->sara->id]);

    $this->watcherIds = fn (Task $task): array => $task->watchers()->pluck('users.id')->sort()->values()->all();
});

it('al sacarlo de un proyecto deja de seguir sus tareas, se quedan sin responsable y su temporizador se descarta', function () {
    ActiveTimer::query()->create(['user_id' => $this->sara->id, 'task_id' => $this->task->id, 'started_at' => now()->subHour()]);

    $this->actingAs($this->admin)->delete("/proyectos/{$this->own->id}/miembros/{$this->sara->id}")->assertSessionHasNoErrors();

    expect(($this->watcherIds)($this->task))->toBe([$this->ana->id])
        ->and(($this->watcherIds)($this->subtask))->toBe([])
        ->and($this->task->fresh()->assignee_user_id)->toBeNull()
        ->and($this->subtask->fresh()->assignee_user_id)->toBeNull()
        ->and(ActiveTimer::query()->whereKey($this->sara->id)->exists())->toBeFalse()
        ->and(TimeEntry::query()->count())->toBe(0)
        // Lo de los proyectos en los que sigue no cambia.
        ->and(($this->watcherIds)($this->kept))->toBe([$this->sara->id])
        ->and($this->kept->fresh()->assignee_user_id)->toBe($this->sara->id);
});

it('el temporizador en una tarea de otro proyecto sigue en marcha al sacarlo de uno', function () {
    ActiveTimer::query()->create(['user_id' => $this->sara->id, 'task_id' => $this->kept->id, 'started_at' => now()->subHour()]);

    $this->actingAs($this->admin)->delete("/proyectos/{$this->own->id}/miembros/{$this->sara->id}")->assertSessionHasNoErrors();

    expect(ActiveTimer::query()->whereKey($this->sara->id)->value('task_id'))->toBe($this->kept->id);
});

it('a una persona de la plantilla que sale de un proyecto no se le quita nada (sigue viéndolo)', function () {
    $this->task->update(['assignee_user_id' => $this->ana->id]);

    $this->actingAs($this->admin)->delete("/proyectos/{$this->own->id}/miembros/{$this->ana->id}")->assertSessionHasNoErrors();

    expect(($this->watcherIds)($this->task))->toBe(collect([$this->sara->id, $this->ana->id])->sort()->values()->all())
        ->and($this->task->fresh()->assignee_user_id)->toBe($this->ana->id);
});

it('al mover una tarea a un proyecto del que no es miembro la suelta (con sus subtareas)', function () {
    $target = Project::factory()->withMembers([$this->ana])->create();

    $this->actingAs($this->admin)->post("/tareas/{$this->task->id}/mover", ['project_id' => $target->id])->assertSessionHasNoErrors();

    expect($this->task->fresh()->project_id)->toBe($target->id)
        ->and(($this->watcherIds)($this->task))->toBe([$this->ana->id])
        ->and(($this->watcherIds)($this->subtask))->toBe([])
        ->and($this->task->fresh()->assignee_user_id)->toBeNull()
        ->and($this->subtask->fresh()->assignee_user_id)->toBeNull();

    // Si es miembro del destino, la conserva.
    $this->actingAs($this->admin)->post("/tareas/{$this->kept->id}/mover", ['project_id' => $this->own->id])->assertSessionHasNoErrors();
    expect(($this->watcherIds)($this->kept))->toBe([$this->sara->id])
        ->and($this->kept->fresh()->assignee_user_id)->toBe($this->sara->id);
});

describe('al pasar a colaborador', function () {
    beforeEach(function () {
        // Ana es de la plantilla: responsable y seguidora de una tarea de un proyecto del que no
        // es miembro, con el temporizador en marcha en ella; y en una directa y un grupo.
        $this->foreign = Project::factory()->create();
        $this->foreignTask = Task::factory()->create(['project_id' => $this->foreign->id, 'assignee_user_id' => $this->ana->id]);
        $this->foreignTask->watchers()->attach([$this->ana->id]);
        ActiveTimer::query()->create(['user_id' => $this->ana->id, 'task_id' => $this->foreignTask->id, 'started_at' => now()->subHour()]);

        $directory = app(ConversationDirectory::class);
        $bea = User::factory()->employee()->create();
        $this->direct = $directory->direct($this->ana, $bea);
        $this->group = $directory->group($bea, 'Café', [$this->ana->id, User::factory()->employee()->create()->id]);
        $this->projectChat = $directory->forProject($this->own);

        $this->active = fn (int $conversationId): bool => ConversationParticipant::query()
            ->where('conversation_id', $conversationId)->where('user_id', $this->ana->id)->whereNull('left_at')->exists();
    });

    $assertReleased = function (): void {
        expect($this->foreignTask->fresh()->assignee_user_id)->toBeNull()
            ->and(($this->watcherIds)($this->foreignTask))->toBe([])
            ->and(ActiveTimer::query()->whereKey($this->ana->id)->exists())->toBeFalse()
            ->and(TimeEntry::query()->count())->toBe(0)
            // Lo de sus proyectos se queda.
            ->and(($this->watcherIds)($this->task))->toContain($this->ana->id)
            // Sale de la directa y del grupo, pero no del chat de su proyecto.
            ->and(($this->active)($this->direct->id))->toBeFalse()
            ->and(($this->active)($this->group->id))->toBeFalse()
            ->and(($this->active)($this->projectChat->id))->toBeTrue()
            // El histórico se conserva.
            ->and(ConversationParticipant::query()->where('conversation_id', $this->direct->id)->where('user_id', $this->ana->id)->exists())->toBeTrue();
    };

    it('desde la administración', function () use ($assertReleased) {
        $this->actingAs($this->admin)
            ->put("/admin/usuarios/{$this->ana->id}", ['name' => $this->ana->name, 'email' => $this->ana->email, 'role' => 'collaborator'])
            ->assertSessionHasNoErrors();

        $assertReleased->call($this);
    });

    it('desde la importación de ClickUp', function () use ($assertReleased) {
        $spec = new PersonSpec($this->ana->email, $this->ana->email, $this->ana->name, Role::Collaborator, null, false, true);

        app(PeopleImporter::class)->import(new PeopleFile([$spec], null), new ImportReport);

        expect($this->ana->fresh()->isCollaborator())->toBeTrue();
        $assertReleased->call($this);
    });
});
