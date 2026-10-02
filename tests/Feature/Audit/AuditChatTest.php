<?php

use App\Domain\Chat\ConversationDirectory;
use App\Domain\Chat\MessageWriter;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/*
| El chat de la Fase 6 en la auditoría visible (D-074): la moderación del admin (ocultar y volver
| a mostrar un mensaje) y los cambios en los grupos (D-119) salen con la entidad «Chat», su
| detalle legible y el enlace al mensaje o a la conversación.
*/

beforeEach(function () {
    Storage::fake('local');
    Notification::fake();

    $this->admin = User::factory()->admin()->create(['name' => 'Ana Admin']);
    $this->elena = User::factory()->employee()->create(['name' => 'Elena Empleada']);
    $this->luis = User::factory()->employee()->create(['name' => 'Luis López']);
    $this->directory = app(ConversationDirectory::class);
    $this->writer = app(MessageWriter::class);

    $this->page = fn (string $query = ''): array => $this->actingAs($this->admin)
        ->get('/admin/auditoria'.($query === '' ? '' : "?{$query}"))
        ->assertOk()
        ->viewData('page')['props'];
});

test('la moderación de un mensaje sale como «Chat» con el texto que tenía y el enlace al mensaje', function () {
    $project = Project::factory()->create(['code' => 'WEB', 'name' => 'Web corporativa']);
    $project->addMember($this->elena);
    $chat = $this->directory->forProject($project);
    $message = $this->writer->post($this->elena, $chat, 'Texto fuera de lugar');

    $this->writer->setHidden($this->admin, $message, true);
    $this->writer->setHidden($this->admin, $message->refresh(), false);

    $props = ($this->page)('entidad=chat&accion=message_hidden');
    $entries = collect($props['entries']);
    $hidden = $entries->sole();
    $changes = collect($hidden['changes'])->keyBy('field');

    expect(collect($props['options']['entities'])->pluck('value'))->toContain('chat')
        ->and($hidden['entity'])->toMatchArray(['key' => 'chat', 'label' => 'Chat'])
        ->and($hidden['event_label'])->toBe('Mensaje ocultado')
        ->and($hidden['causer']['name'])->toBe('Ana Admin')
        ->and($hidden['subject'])->toBe([
            'label' => 'Mensaje de Elena Empleada en Chat de WEB · Web corporativa',
            'url' => "/chat/{$chat->id}?mensaje={$message->id}",
            'deleted' => false,
        ])
        ->and($changes['conversation_id']['to'])->toBe('Chat de WEB · Web corporativa')
        ->and($changes['body']['to'])->toBe('Texto fuera de lugar')
        ->and(collect(($this->page)('entidad=chat&accion=message_unhidden')['entries'])->sole()['event_label'])->toBe('Mensaje visible de nuevo');
});

test('crear, renombrar y cambiar las personas de un grupo queda en la auditoría', function () {
    $group = $this->directory->group($this->elena, 'Diseño web', [$this->luis->id]);
    $this->directory->renameGroup($this->elena, $group, 'Diseño y web');
    $this->directory->addToGroup($this->admin, $group, [$this->admin->id]);
    $this->directory->removeFromGroup($this->elena, $group, $this->luis);
    $this->directory->leaveGroup($this->admin, $group);

    $entries = collect(($this->page)('entidad=chat&accion=group_changed')['entries'])->keyBy('event');

    expect($entries->keys()->sort()->values()->all())->toBe(['group_created', 'group_left', 'group_member_removed', 'group_members_added', 'group_renamed'])
        ->and($entries['group_created']['causer']['name'])->toBe('Elena Empleada')
        ->and($entries['group_created']['subject'])->toBe(['label' => 'Grupo «Diseño y web»', 'url' => "/chat/{$group->id}", 'deleted' => false])
        ->and(collect($entries['group_created']['changes'])->keyBy('field')['participants']['to'])->toBe('Elena Empleada, Luis López')
        ->and($entries['group_renamed']['changes'])->toBe([['field' => 'name', 'label' => 'Nombre', 'from' => 'Diseño web', 'to' => 'Diseño y web']])
        ->and(collect($entries['group_members_added']['changes'])->sole()['to'])->toBe('Ana Admin')
        ->and(collect($entries['group_member_removed']['changes'])->sole()['from'])->toBe('Luis López')
        ->and($entries['group_left']['event_label'])->toBe('Salida del grupo');
});

test('las conversaciones directas no se auditan ni se nombran por sus personas', function () {
    $direct = $this->directory->direct($this->elena, $this->luis);
    $this->writer->post($this->elena, $direct, 'Hola');

    expect(($this->page)('entidad=chat')['entries'])->toBe([]);
});
