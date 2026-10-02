<?php

use App\Domain\Chat\ConversationDirectory;
use App\Domain\Chat\MessageWriter;
use App\Models\Project;
use App\Models\User;
use App\Notifications\Chat\ChatMentionNotification;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Storage;

/*
| La campana no conserva el texto de un mensaje del chat ocultado o borrado (D-115): cada aviso del
| chat guarda su mensaje (notifications.chat_message_id) y, al ocultarlo o borrarlo, su extracto
| se vacía. Un aviso que la cola aún no ha enviado, ya no se envía.
*/

beforeEach(function () {
    Storage::fake('local');
    $this->writer = app(MessageWriter::class);
    $this->ana = User::factory()->employee()->create(['name' => 'Ana']);
    $this->luis = User::factory()->employee()->create(['name' => 'Luis']);
    $this->admin = User::factory()->admin()->create();
    $project = Project::factory()->create();
    $project->addMember($this->ana);
    $project->addMember($this->luis);
    $this->chat = app(ConversationDirectory::class)->forProject($project);
    $this->bell = fn (User $user): string => (string) json_encode(
        $this->actingAs($user)->getJson('/notificaciones/recientes')->assertOk()->json(),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
    );
});

it('cada aviso del chat guarda el mensaje del que habla', function () {
    $message = $this->writer->post($this->ana, $this->chat, "Hola <@{$this->luis->id}>");

    $notification = DatabaseNotification::query()->where('notifiable_id', $this->luis->id)->sole();
    expect($notification->chat_message_id)->toBe($message->id)
        ->and($notification->data['body'])->toBe('Hola @Luis');
});

it('al ocultar un mensaje, la campana deja de enseñar su texto (y no lo recupera al mostrarlo)', function () {
    $message = $this->writer->post($this->ana, $this->chat, "secreto ofensivo <@{$this->luis->id}>");
    // Como mucho un aviso por conversación cada 5 minutos (D-112).
    $this->travel(6)->minutes();
    $other = $this->writer->post($this->ana, $this->chat, "otro aviso <@{$this->luis->id}>");
    expect(($this->bell)($this->luis))->toContain('secreto ofensivo');

    $this->actingAs($this->admin)->patchJson("/chat/mensajes/{$message->id}/moderacion", ['hidden' => true])->assertOk();

    $bell = ($this->bell)($this->luis);
    expect($bell)->not->toContain('secreto ofensivo')
        ->and($bell)->toContain('/tiempo-real/conversaciones/'.$this->chat->id.'/abrir');

    $this->actingAs($this->admin)->patchJson("/chat/mensajes/{$message->id}/moderacion", ['hidden' => false])->assertOk();
    expect(($this->bell)($this->luis))->not->toContain('secreto ofensivo');

    // Los avisos de otros mensajes no se tocan.
    expect(DatabaseNotification::query()->where('chat_message_id', $other->id)->sole()->data['body'])->toBe('otro aviso @Luis');
});

it('al borrar un mensaje, la campana deja de enseñar su texto', function () {
    $message = $this->writer->post($this->ana, $this->chat, "me arrepiento <@{$this->luis->id}>");

    $this->actingAs($this->ana)->deleteJson("/chat/mensajes/{$message->id}")->assertOk();

    expect(($this->bell)($this->luis))->not->toContain('me arrepiento');
});

it('un aviso que la cola envía después de ocultar o borrar el mensaje ya no se envía', function () {
    $hidden = $this->writer->post($this->ana, $this->chat, 'Uno');
    $deleted = $this->writer->post($this->ana, $this->chat, 'Dos');
    $this->writer->setHidden($this->admin, $hidden, true);
    $this->writer->delete($this->ana, $deleted);

    foreach ([$hidden, $deleted] as $message) {
        $this->luis->notify(new ChatMentionNotification($this->chat->id, $message->id, 'Proyecto', 'Ana', 'Texto que no debe quedar'));
    }

    expect(DatabaseNotification::query()->where('notifiable_id', $this->luis->id)->count())->toBe(0);
});
