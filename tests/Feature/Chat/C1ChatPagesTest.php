<?php

use App\Domain\Chat\ConversationDirectory;
use App\Domain\Chat\MessageWriter;
use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Chat (C1): /chat con la lista de conversaciones (nombre, vista previa sin markdown, hora y no
| leídos), la conversación abierta, la lista en JSON, el total de la navegación, las personas para
| directas y grupos, y la pestaña Chat del proyecto (SPEC §12, D-071).
*/

beforeEach(function () {
    Storage::fake('local');
    $this->directory = app(ConversationDirectory::class);
    $this->writer = app(MessageWriter::class);
    $this->ana = User::factory()->employee()->create(['name' => 'Ana Pérez']);
    $this->luis = User::factory()->employee()->create(['name' => 'Luis Gil']);
    $this->marta = User::factory()->departmentManager()->create(['name' => 'Marta Ruiz']);
    $this->admin = User::factory()->admin()->create(['name' => 'Admin Audax']);
    $this->project = Project::factory()->create(['name' => 'Web corporativa', 'code' => 'ARR-WEB', 'owner_user_id' => $this->marta->id]);
    $this->project->addMember($this->ana);
    $this->project->addMember($this->luis);
    $this->projectChat = $this->directory->forProject($this->project);
    $this->dm = $this->directory->direct($this->ana, $this->luis);
});

it('lista las conversaciones de quien mira con vista previa sin markdown, no leídos y la más reciente primero', function () {
    $this->writer->post($this->luis, $this->projectChat, 'Revisad **la maqueta**');
    $this->travel(1)->minutes();
    $this->writer->post($this->luis, $this->dm, "Hola <@{$this->ana->id}>, mira [esto](https://audaxstudio.com) y `código`");
    $this->writer->post($this->luis, $this->dm, '¿Lo ves?');

    $this->actingAs($this->ana)
        ->get('/chat')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('chat/index', false)
            ->where('conversation', null)
            ->has('conversations', 2)
            ->where('conversations.0.id', $this->dm->id)
            ->where('conversations.0.type', 'direct')
            ->where('conversations.0.title', 'Luis Gil')
            ->where('conversations.0.other_user.name', 'Luis Gil')
            ->where('conversations.0.unread', 2)
            ->where('conversations.0.last_message.preview', '¿Lo ves?')
            ->where('conversations.0.last_message.author', 'Luis Gil')
            ->where('conversations.1.id', $this->projectChat->id)
            ->where('conversations.1.type', 'project')
            ->where('conversations.1.title', 'Web corporativa')
            ->where('conversations.1.subtitle', 'ARR-WEB')
            ->where('conversations.1.unread', 1)
            ->where('conversations.1.members_count', 3)
            ->where('conversations.1.last_message.preview', 'Revisad la maqueta')
            ->where('chat.unread', 3));
});

it('la vista previa resuelve las menciones y quita el formato', function () {
    $this->writer->post($this->luis, $this->dm, "Hola <@{$this->ana->id}>, mira [esto](https://audaxstudio.com) y `código` *ya*");

    $this->actingAs($this->ana)
        ->getJson('/chat/conversaciones')
        ->assertOk()
        ->assertJsonPath('conversations.0.last_message.preview', 'Hola @Ana Pérez, mira esto y código ya')
        ->assertJsonPath('unread_total', 1);
});

it('los mensajes propios, los borrados y los ocultados no cuentan como no leídos', function () {
    $this->writer->post($this->ana, $this->projectChat, 'Mío');
    $deleted = $this->writer->post($this->luis, $this->projectChat, 'Borrado');
    $this->writer->delete($this->luis, $deleted);
    $hidden = $this->writer->post($this->luis, $this->projectChat, 'Oculto');
    $this->writer->setHidden($this->admin, $hidden, true);
    $this->writer->post($this->luis, $this->projectChat, 'Este sí');

    $this->actingAs($this->ana)->getJson('/tiempo-real/no-leidos')->assertOk()->assertJsonPath('total', 1);
    expect($this->directory->unreadCounts($this->ana))->toBe([$this->projectChat->id => 1]);
});

it('una conversación silenciada no suma en el total de la navegación pero sí en su fila', function () {
    $this->writer->post($this->luis, $this->dm, 'Uno');
    $this->travel(1)->minutes();
    $this->writer->post($this->luis, $this->projectChat, 'Dos');
    $this->directory->mute($this->ana, $this->dm, true);

    $this->actingAs($this->ana)
        ->getJson('/chat/conversaciones')
        ->assertJsonPath('unread_total', 1)
        ->assertJsonPath('conversations.0.id', $this->projectChat->id)
        ->assertJsonPath('conversations.1.muted', true)
        ->assertJsonPath('conversations.1.unread', 1);
});

it('al volver a un proyecto, lo anterior cuenta como leído', function () {
    $this->project->members()->detach($this->ana->id);
    $this->writer->post($this->luis, $this->projectChat, 'Mientras no estabas');
    $this->project->addMember($this->ana);

    expect($this->directory->unreadTotal($this->ana))->toBe(0);
});

it('abre una conversación con la cabecera, los participantes, los mensajes y los fijados', function () {
    $first = $this->writer->post($this->luis, $this->projectChat, 'Primero');
    $this->writer->setPinned($this->luis, $first, true);
    $this->writer->post($this->ana, $this->projectChat, 'Segundo', $first->id);

    $this->actingAs($this->ana)
        ->get("/chat/{$this->projectChat->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('chat/index', false)
            ->has('conversations', 2)
            ->where('conversation.id', $this->projectChat->id)
            ->where('conversation.title', 'Web corporativa')
            ->where('conversation.project.code', 'ARR-WEB')
            ->where('conversation.is_participant', true)
            ->where('conversation.can.post', true)
            ->where('conversation.can.moderate', false)
            ->where('conversation.can.create_task', true)
            ->where('conversation.read_only_reason', null)
            ->has('conversation.participants', 3)
            ->where('conversation.participants.0.name', 'Ana Pérez')
            ->has('messages.messages', 2)
            ->where('messages.messages.0.body', 'Primero')
            ->where('messages.messages.0.pinned', true)
            ->where('messages.messages.1.parent.id', $first->id)
            ->where('messages.messages.1.parent.excerpt', 'Primero')
            ->where('messages.messages.1.parent.author', 'Luis Gil')
            ->where('messages.messages.1.can.edit', true)
            ->where('messages.messages.0.can.edit', false)
            ->where('messages.has_older', false)
            ->where('messages.has_newer', false)
            ->has('pinned', 1)
            ->where('pinned.0.id', $first->id)
            ->where('pinned.0.pinned_by', 'Luis Gil')
            ->where('focus', null));
});

it('?mensaje= abre la conversación alrededor de ese mensaje', function () {
    $messages = collect(range(1, 50))->map(fn (int $i) => $this->writer->post($this->luis, $this->dm, "Mensaje {$i}"));
    $target = $messages[9];

    $this->actingAs($this->ana)
        ->get("/chat/{$this->dm->id}?mensaje={$target->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('focus', $target->id)
            ->where('messages.messages.0.body', 'Mensaje 1')
            ->where('messages.has_older', false)
            ->where('messages.has_newer', true)
            ->has('messages.messages', 25));

    // Un mensaje de otra conversación no sirve de foco: se abre en los últimos.
    $foreign = $this->writer->post($this->ana, $this->projectChat, 'Ajeno');
    $this->actingAs($this->ana)
        ->get("/chat/{$this->dm->id}?mensaje={$foreign->id}")
        ->assertInertia(fn (Assert $page) => $page->where('focus', null)->where('messages.has_older', true));
});

it('la lista de personas para directas y grupos: internas activas y sin quien mira', function () {
    $inactive = User::factory()->employee()->inactive()->create(['name' => 'Inactiva']);
    $client = User::factory()->client()->create(['name' => 'Cliente']);

    $names = collect($this->actingAs($this->ana)->getJson('/chat/personas')->assertOk()->json('people'))->pluck('name');

    expect($names->all())->toContain('Luis Gil', 'Marta Ruiz', 'Admin Audax')
        ->not->toContain('Ana Pérez', 'Inactiva', 'Cliente');
});

it('la pestaña Chat del proyecto abre su conversación para sus miembros', function () {
    $this->writer->post($this->luis, $this->projectChat, 'Hola equipo');

    $this->actingAs($this->ana)
        ->get("/proyectos/{$this->project->id}/chat")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('chat/project', false)
            ->where('project.id', $this->project->id)
            ->where('conversation.id', $this->projectChat->id)
            ->where('conversation.can.post', true)
            ->where('messages.messages.0.body', 'Hola equipo'));
});

it('la pestaña Chat crea la conversación del proyecto con sus miembros la primera vez', function () {
    $other = Project::factory()->create(['owner_user_id' => $this->marta->id]);
    $other->addMember($this->ana);

    $this->actingAs($this->ana)
        ->get("/proyectos/{$other->id}/chat")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('conversation.type', 'project')
            ->has('conversation.participants', 2)
            ->has('messages.messages', 0));
});

it('quien no es miembro ve la ficha del proyecto pero no su chat; el admin lo ve sin escribir', function () {
    $outsider = User::factory()->employee()->create();

    $this->actingAs($outsider)
        ->get("/proyectos/{$this->project->id}/chat")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('conversation', null)->where('messages', null));

    $this->actingAs($this->admin)
        ->get("/proyectos/{$this->project->id}/chat")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('conversation.id', $this->projectChat->id)
            ->where('conversation.is_participant', false)
            ->where('conversation.can.post', false)
            ->where('conversation.can.moderate', true)
            ->where('conversation.read_only_reason', 'not_participant'));
});

it('en un proyecto archivado el chat se lee pero no se escribe', function () {
    $this->project->update(['status' => ProjectStatus::Archived]);

    $this->actingAs($this->ana)
        ->get("/chat/{$this->projectChat->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('conversation.can.post', false)
            ->where('conversation.can.create_task', false)
            ->where('conversation.read_only_reason', 'archived'));

    $this->actingAs($this->ana)
        ->getJson('/chat/conversaciones')
        ->assertJsonPath('conversations.1.read_only', true);

    $this->actingAs($this->ana)
        ->postJson("/chat/{$this->projectChat->id}/mensajes", ['body' => 'Hola'])
        ->assertForbidden();
});

it('las props compartidas llevan el total sin leer del chat en cualquier página', function () {
    $this->writer->post($this->luis, $this->dm, 'Hola');

    $this->actingAs($this->ana)
        ->get('/')
        ->assertInertia(fn (Assert $page) => $page->where('chat.unread', 1));
});

it('el portal de cliente no recibe el total del chat', function () {
    $this->actingAs(User::factory()->client()->create())
        ->get('/portal')
        ->assertInertia(fn (Assert $page) => $page->missing('chat'));
});
