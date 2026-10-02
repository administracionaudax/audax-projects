<?php

use App\Domain\Chat\ConversationDirectory;
use App\Domain\Chat\MessageWriter;
use App\Enums\ProjectStatus;
use App\Events\Chat\MessageUpdated;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Crear una tarea desde un mensaje (SPEC §12): solo en chats de proyecto y si se pueden crear
| tareas en él (TaskPolicy::create); la tarea sale de TaskWriter con el texto del mensaje y el
| mensaje queda enlazado (message.task_id). El panel de la tarea enlaza al mensaje.
*/

beforeEach(function () {
    Storage::fake('local');
    Notification::fake();
    TaskStatus::ensureDefaults();
    $this->directory = app(ConversationDirectory::class);
    $this->writer = app(MessageWriter::class);
    $this->ana = User::factory()->employee()->create(['name' => 'Ana']);
    $this->luis = User::factory()->employee()->create(['name' => 'Luis']);
    $this->project = Project::factory()->hourBank()->create();
    $this->project->addMember($this->ana);
    $this->project->addMember($this->luis);
    $this->bank = HourBank::factory()->create(['project_id' => $this->project->id, 'name' => 'Bolsa Diseño 40h']);
    $this->closedBank = HourBank::factory()->closed()->create(['project_id' => $this->project->id, 'name' => 'Bolsa vieja']);
    $this->chat = $this->directory->forProject($this->project);
    $this->message = $this->writer->post($this->luis, $this->chat, "Hay que **revisar** el menú del móvil\nEn iOS se corta. <@{$this->ana->id}> ¿lo miras? https://audaxstudio.com <script>alert(1)</script>");
});

it('prepara el diálogo: título sugerido, bolsas abiertas y personas (primero los miembros)', function () {
    $response = $this->actingAs($this->ana)
        ->getJson("/chat/mensajes/{$this->message->id}/tarea")
        ->assertOk()
        ->assertJsonPath('title', 'Hay que revisar el menú del móvil')
        ->assertJsonPath('project.id', $this->project->id)
        ->assertJsonPath('project.uses_hour_banks', true)
        ->assertJsonCount(1, 'banks')
        ->assertJsonPath('banks.0.name', 'Bolsa Diseño 40h');

    $people = collect($response->json('people'));
    expect($people->firstWhere('name', 'Ana')['is_member'])->toBeTrue()
        ->and($people->takeWhile(fn (array $person): bool => $person['is_member'])->count())->toBe(3);
});

it('crea la tarea con TaskWriter, la enlaza al mensaje y avisa del cambio', function () {
    Event::fake([MessageUpdated::class]);

    $response = $this->actingAs($this->ana)
        ->postJson("/chat/mensajes/{$this->message->id}/tarea", [
            'title' => 'Revisar el menú en iOS',
            'hour_bank_id' => $this->bank->id,
            'assignee_user_id' => $this->ana->id,
            'due_date' => now()->addWeek()->toDateString(),
        ])
        ->assertCreated()
        ->assertJsonPath('task.title', 'Revisar el menú en iOS')
        ->assertJsonPath('message.task.title', 'Revisar el menú en iOS')
        ->assertJsonPath('message.can.create_task', false);

    $task = Task::query()->findOrFail($response->json('task.id'));
    expect($response->json('task.url'))->toBe("/tareas/{$task->id}")
        ->and($task->project_id)->toBe($this->project->id)
        ->and($task->hour_bank_id)->toBe($this->bank->id)
        ->and($task->assignee_user_id)->toBe($this->ana->id)
        ->and($task->created_by)->toBe($this->ana->id)
        ->and($this->message->fresh()->task_id)->toBe($task->id)
        ->and($task->description)
        ->toContain('<strong>revisar</strong>')
        ->toContain('href="https://audaxstudio.com"')
        ->toContain('Creada desde un mensaje de Luis')
        ->not->toContain('<script')
        ->not->toContain('data-type="mention"')
        ->and(html_entity_decode($task->description))->toContain('@Ana ¿lo miras?');

    Event::assertDispatched(MessageUpdated::class);
});

it('aplica las reglas de TaskWriter: la bolsa es obligatoria y tiene que estar abierta', function () {
    $this->actingAs($this->ana)
        ->postJson("/chat/mensajes/{$this->message->id}/tarea", ['title' => 'Sin bolsa'])
        ->assertJsonValidationErrors('hour_bank_id');

    $this->actingAs($this->ana)
        ->postJson("/chat/mensajes/{$this->message->id}/tarea", ['title' => 'Bolsa cerrada', 'hour_bank_id' => $this->closedBank->id])
        ->assertJsonValidationErrors('hour_bank_id');

    $this->actingAs($this->ana)
        ->postJson("/chat/mensajes/{$this->message->id}/tarea", ['title' => '', 'hour_bank_id' => $this->bank->id])
        ->assertJsonValidationErrors('title');

    expect(Task::query()->count())->toBe(0)
        ->and($this->message->fresh()->task_id)->toBeNull();
});

it('un mensaje solo se enlaza a una tarea', function () {
    $this->actingAs($this->ana)
        ->postJson("/chat/mensajes/{$this->message->id}/tarea", ['title' => 'Primera', 'hour_bank_id' => $this->bank->id])
        ->assertCreated();

    $this->actingAs($this->luis)
        ->postJson("/chat/mensajes/{$this->message->id}/tarea", ['title' => 'Segunda', 'hour_bank_id' => $this->bank->id])
        ->assertJsonValidationErrors('message');

    expect(Task::query()->count())->toBe(1);
});

it('no se crean tareas desde mensajes ocultados, borrados, de sistema o de chats sin proyecto', function () {
    $admin = User::factory()->admin()->create();
    $hidden = $this->writer->post($this->luis, $this->chat, 'Oculto');
    $this->writer->setHidden($admin, $hidden, true);
    $deleted = $this->writer->post($this->luis, $this->chat, 'Borrado');
    $this->writer->delete($this->luis, $deleted);
    $system = $this->writer->system($this->chat, 'hour_bank.threshold', ['bank' => 'X', 'threshold' => 90]);
    $direct = $this->writer->post($this->ana, $this->directory->direct($this->ana, $this->luis), 'Directo');
    $payload = ['title' => 'Tarea', 'hour_bank_id' => $this->bank->id];

    $this->actingAs($this->ana)->postJson("/chat/mensajes/{$hidden->id}/tarea", $payload)->assertJsonValidationErrors('message');
    $this->actingAs($this->ana)->postJson("/chat/mensajes/{$deleted->id}/tarea", $payload)->assertNotFound();
    $this->actingAs($this->ana)->postJson("/chat/mensajes/{$system->id}/tarea", $payload)->assertJsonValidationErrors('message');
    $this->actingAs($this->ana)->postJson("/chat/mensajes/{$direct->id}/tarea", $payload)->assertJsonValidationErrors('message');

    expect(Task::query()->count())->toBe(0);
});

it('hace falta poder crear tareas en el proyecto (miembros y quien lo gestiona; no en archivados)', function () {
    $payload = ['title' => 'Tarea', 'hour_bank_id' => $this->bank->id];
    $outsider = User::factory()->employee()->create();

    // Quien no participa ni siquiera ve la conversación.
    $this->actingAs($outsider)->postJson("/chat/mensajes/{$this->message->id}/tarea", $payload)->assertForbidden();

    $this->project->update(['status' => ProjectStatus::Archived]);
    $this->actingAs($this->ana)->postJson("/chat/mensajes/{$this->message->id}/tarea", $payload)->assertForbidden();

    expect(Task::query()->count())->toBe(0);
});

it('MessageWriter::linkTask comprueba el proyecto y la política', function () {
    $otherProject = Project::factory()->create();
    $foreignTask = Task::factory()->create(['project_id' => $otherProject->id]);
    $task = Task::factory()->inBank($this->bank)->create();

    expect(fn () => $this->writer->linkTask($this->ana, $this->message, $foreignTask))->toThrow(ValidationException::class);

    $this->writer->linkTask($this->ana, $this->message, $task);
    expect($this->message->fresh()->task_id)->toBe($task->id);
});

it('el panel de la tarea enlaza al mensaje para quien ve la conversación', function () {
    $taskId = $this->actingAs($this->ana)
        ->postJson("/chat/mensajes/{$this->message->id}/tarea", ['title' => 'Desde el chat', 'hour_bank_id' => $this->bank->id])
        ->json('task.id');

    $this->actingAs($this->luis)
        ->get("/proyectos/{$this->project->id}/tareas?tarea={$taskId}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('panel.source_message.conversation_id', $this->chat->id)
            ->where('panel.source_message.message_id', $this->message->id));

    // Quien no es miembro ve la tarea (D-021) pero no el chat: sin enlace.
    $this->actingAs(User::factory()->employee()->create())
        ->get("/proyectos/{$this->project->id}/tareas?tarea={$taskId}")
        ->assertInertia(fn (Assert $page) => $page->where('panel.source_message', null));

    // El admin modera el chat del proyecto: con enlace.
    $this->actingAs(User::factory()->admin()->create())
        ->get("/proyectos/{$this->project->id}/tareas?tarea={$taskId}")
        ->assertInertia(fn (Assert $page) => $page->where('panel.source_message.message_id', $this->message->id));
});
