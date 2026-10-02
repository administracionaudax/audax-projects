<?php

use App\Domain\Chat\ConversationDirectory;
use App\Domain\Chat\MessageWriter;
use App\Enums\TranscriptionStatus;
use App\Events\Chat\ConversationRead;
use App\Events\Chat\MessagePosted;
use App\Events\Chat\MessageUpdated;
use App\Models\Attachment;
use App\Models\AudioTranscription;
use App\Models\Message;
use App\Models\MessageMention;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

/*
| Chat (C1): publicar, editar, borrar, responder en hilo, reaccionar, fijar, moderar y leer, con
| paginación por cursor (hacia atrás, hacia delante y alrededor) y la consulta periódica sin
| tiempo real. Todo pasa por MessageWriter (D-069) y dispara sus eventos.
*/

beforeEach(function () {
    Storage::fake('local');
    $this->directory = app(ConversationDirectory::class);
    $this->writer = app(MessageWriter::class);
    $this->ana = User::factory()->employee()->create(['name' => 'Ana']);
    $this->luis = User::factory()->employee()->create(['name' => 'Luis']);
    $this->admin = User::factory()->admin()->create(['name' => 'Admin']);
    $this->project = Project::factory()->create();
    $this->project->addMember($this->ana);
    $this->project->addMember($this->luis);
    $this->chat = $this->directory->forProject($this->project);
    $this->dm = $this->directory->direct($this->ana, $this->luis);
});

it('publica un mensaje (201) con menciones y dispara MessagePosted', function () {
    Event::fake([MessagePosted::class]);

    $response = $this->actingAs($this->ana)
        ->postJson("/chat/{$this->chat->id}/mensajes", ['body' => "Hola <@{$this->luis->id}> y @todos"])
        ->assertCreated()
        ->assertJsonPath('message.body', "Hola <@{$this->luis->id}> y @todos")
        ->assertJsonPath('message.author.name', 'Ana')
        ->assertJsonPath('message.type', 'text')
        ->assertJsonPath('message.can.edit', true)
        ->assertJsonPath('message.can.delete', true);

    $names = collect($response->json('users'))->pluck('name');
    expect($names->all())->toContain('Luis')
        ->and(MessageMention::query()->where('user_id', $this->luis->id)->exists())->toBeTrue();
    Event::assertDispatched(MessagePosted::class);
});

it('valida el cuerpo: obligatorio y como mucho 10.000 caracteres', function () {
    $this->actingAs($this->ana)->postJson("/chat/{$this->chat->id}/mensajes", ['body' => ''])->assertJsonValidationErrors('body');
    $this->actingAs($this->ana)->postJson("/chat/{$this->chat->id}/mensajes", ['body' => str_repeat('a', 10_001)])->assertJsonValidationErrors('body');

    expect(Message::query()->count())->toBe(0);
});

it('responde en hilo solo a mensajes de la misma conversación', function () {
    $root = $this->writer->post($this->luis, $this->chat, '¿Quién revisa?');
    $foreign = $this->writer->post($this->luis, $this->dm, 'De la directa');

    $this->actingAs($this->ana)
        ->postJson("/chat/{$this->chat->id}/mensajes", ['body' => 'Yo', 'parent_id' => $root->id])
        ->assertCreated()
        ->assertJsonPath('message.parent.id', $root->id)
        ->assertJsonPath('message.parent.excerpt', '¿Quién revisa?');

    $this->actingAs($this->ana)
        ->postJson("/chat/{$this->chat->id}/mensajes", ['body' => 'Yo', 'parent_id' => $foreign->id])
        ->assertJsonValidationErrors('parent_id');
});

it('edita lo propio (queda «editado» y se rehacen las menciones) y no lo ajeno', function () {
    $message = $this->writer->post($this->ana, $this->chat, "Para <@{$this->luis->id}>");

    $this->actingAs($this->luis)->patchJson("/chat/mensajes/{$message->id}", ['body' => 'No es mío'])->assertForbidden();

    $this->actingAs($this->ana)
        ->patchJson("/chat/mensajes/{$message->id}", ['body' => 'Corregido'])
        ->assertOk()
        ->assertJsonPath('message.body', 'Corregido')
        ->assertJsonPath('message.edited_at', fn ($value) => is_string($value));

    expect(MessageMention::query()->where('message_id', $message->id)->exists())->toBeFalse();
});

it('borra lo propio: queda «eliminado» en su sitio, sin cuerpo, reacciones ni adjuntos', function () {
    $message = $this->writer->post($this->ana, $this->chat, 'Me equivoqué');
    $this->writer->toggleReaction($this->luis, $message, '👍');

    $this->actingAs($this->luis)->deleteJson("/chat/mensajes/{$message->id}")->assertForbidden();

    $this->actingAs($this->ana)
        ->deleteJson("/chat/mensajes/{$message->id}")
        ->assertOk()
        ->assertJsonPath('message.deleted', true)
        ->assertJsonPath('message.body', null)
        ->assertJsonPath('message.reactions', [])
        ->assertJsonPath('message.can.edit', false)
        ->assertJsonPath('message.can.react', false);

    // Se sigue pudiendo pedir (p. ej. tras un aviso del tiempo real) y sale como eliminado.
    $this->actingAs($this->luis)->getJson("/chat/mensajes/{$message->id}")->assertOk()->assertJsonPath('message.deleted', true);
});

it('reacciona y quita la reacción (agrupadas con recuento y quién), y el mensaje cambia', function () {
    $message = $this->writer->post($this->ana, $this->chat, 'Entregado');
    $before = $message->fresh()->updated_at;
    $this->travel(5)->seconds();

    $this->actingAs($this->luis)
        ->postJson("/chat/mensajes/{$message->id}/reacciones", ['emoji' => '🎉'])
        ->assertOk()
        ->assertJsonPath('message.reactions.0.emoji', '🎉')
        ->assertJsonPath('message.reactions.0.count', 1)
        ->assertJsonPath('message.reactions.0.reacted', true)
        ->assertJsonPath('message.reactions.0.users', ['Luis']);

    expect($message->fresh()->updated_at->greaterThan($before))->toBeTrue();

    $this->actingAs($this->ana)
        ->postJson("/chat/mensajes/{$message->id}/reacciones", ['emoji' => '🎉'])
        ->assertJsonPath('message.reactions.0.count', 2)
        ->assertJsonPath('message.reactions.0.users', ['Luis', 'Ana']);

    $this->actingAs($this->luis)
        ->postJson("/chat/mensajes/{$message->id}/reacciones", ['emoji' => '🎉'])
        ->assertJsonPath('message.reactions.0.count', 1)
        ->assertJsonPath('message.reactions.0.reacted', false);

    $this->actingAs($this->luis)
        ->postJson("/chat/mensajes/{$message->id}/reacciones", ['emoji' => 'hola'])
        ->assertJsonValidationErrors('emoji');
});

it('fija y desfija (barra de fijados)', function () {
    $message = $this->writer->post($this->ana, $this->chat, 'Acta de la reunión');

    $this->actingAs($this->luis)
        ->patchJson("/chat/mensajes/{$message->id}/fijado", ['pinned' => true])
        ->assertOk()
        ->assertJsonPath('message.pinned', true)
        ->assertJsonPath('message.pinned_by', 'Luis')
        ->assertJsonPath('pinned.0.id', $message->id)
        ->assertJsonPath('pinned.0.excerpt', 'Acta de la reunión');

    $this->actingAs($this->ana)->getJson("/chat/{$this->chat->id}/fijados")->assertJsonCount(1, 'pinned');

    $this->actingAs($this->ana)
        ->patchJson("/chat/mensajes/{$message->id}/fijado", ['pinned' => false])
        ->assertJsonPath('message.pinned', false)
        ->assertJsonPath('pinned', []);
});

it('el admin oculta un mensaje (auditado): los demás ven el aviso y él, el contenido', function () {
    $message = $this->writer->post($this->ana, $this->chat, 'Algo inapropiado');

    $this->actingAs($this->luis)->patchJson("/chat/mensajes/{$message->id}/moderacion", ['hidden' => true])->assertForbidden();

    $this->actingAs($this->admin)
        ->patchJson("/chat/mensajes/{$message->id}/moderacion", ['hidden' => true])
        ->assertOk()
        ->assertJsonPath('message.hidden', true)
        ->assertJsonPath('message.body', 'Algo inapropiado')
        ->assertJsonPath('message.hidden_by', 'Admin')
        ->assertJsonPath('message.can.moderate', true);

    expect(Activity::query()->where('log_name', 'chat')->where('event', 'hidden')->where('subject_id', $message->id)->exists())->toBeTrue();

    $this->actingAs($this->luis)
        ->getJson("/chat/mensajes/{$message->id}")
        ->assertJsonPath('message.hidden', true)
        ->assertJsonPath('message.body', null)
        ->assertJsonPath('message.hidden_by', null)
        ->assertJsonPath('message.can.react', false);

    // La autora tampoco lo edita mientras está oculto.
    $this->actingAs($this->ana)->patchJson("/chat/mensajes/{$message->id}", ['body' => 'Otra cosa'])->assertForbidden();

    $this->actingAs($this->admin)
        ->patchJson("/chat/mensajes/{$message->id}/moderacion", ['hidden' => false])
        ->assertJsonPath('message.hidden', false);
});

it('el admin nunca modera las conversaciones directas', function () {
    $message = $this->writer->post($this->ana, $this->dm, 'Privado');

    $this->actingAs($this->admin)->patchJson("/chat/mensajes/{$message->id}/moderacion", ['hidden' => true])->assertForbidden();
    $this->actingAs($this->admin)->getJson("/chat/mensajes/{$message->id}")->assertForbidden();
});

it('pagina hacia atrás por cursor, sin huecos ni repetidos', function () {
    $messages = collect(range(1, 70))->map(fn (int $i) => $this->writer->post($i % 2 ? $this->ana : $this->luis, $this->dm, "M{$i}"));

    $first = $this->actingAs($this->ana)->getJson("/chat/{$this->dm->id}/mensajes")->assertOk();
    expect($first->json('messages'))->toHaveCount(30)
        ->and($first->json('messages.0.body'))->toBe('M41')
        ->and($first->json('messages.29.body'))->toBe('M70')
        ->and($first->json('has_older'))->toBeTrue()
        ->and($first->json('has_newer'))->toBeFalse();

    $second = $this->actingAs($this->ana)->getJson("/chat/{$this->dm->id}/mensajes?antes={$first->json('messages.0.id')}");
    expect($second->json('messages.0.body'))->toBe('M11')
        ->and($second->json('messages.29.body'))->toBe('M40')
        ->and($second->json('has_older'))->toBeTrue();

    $third = $this->actingAs($this->ana)->getJson("/chat/{$this->dm->id}/mensajes?antes={$second->json('messages.0.id')}");
    expect($third->json('messages'))->toHaveCount(10)
        ->and($third->json('messages.0.body'))->toBe('M1')
        ->and($third->json('has_older'))->toBeFalse();

    // Alrededor de un mensaje y hacia delante desde ahí.
    $around = $this->actingAs($this->ana)->getJson("/chat/{$this->dm->id}/mensajes?alrededor={$messages[34]->id}");
    expect($around->json('messages.0.body'))->toBe('M20')
        ->and($around->json('messages.30.body'))->toBe('M50')
        ->and($around->json('has_older'))->toBeTrue()
        ->and($around->json('has_newer'))->toBeTrue();

    $after = $this->actingAs($this->ana)->getJson("/chat/{$this->dm->id}/mensajes?despues={$messages[49]->id}");
    expect($after->json('messages.0.body'))->toBe('M51')
        ->and($after->json('messages'))->toHaveCount(20)
        ->and($after->json('has_newer'))->toBeFalse();

    // Un mensaje de otra conversación no sirve de ancla.
    $foreign = $this->writer->post($this->ana, $this->chat, 'Ajeno');
    $this->actingAs($this->ana)->getJson("/chat/{$this->dm->id}/mensajes?alrededor={$foreign->id}")->assertNotFound();
});

it('la consulta periódica trae lo nuevo, lo cambiado en la ventana, los fijados y lo leído', function () {
    $old = $this->writer->post($this->ana, $this->chat, 'Viejo');
    $edited = $this->writer->post($this->ana, $this->chat, 'Antes');
    $this->travel(10)->seconds();
    $since = now()->toIso8601ZuluString();
    $this->travel(10)->seconds();

    $this->writer->edit($this->ana, $edited, 'Después');
    $this->writer->toggleReaction($this->luis, $old, '👍');
    $this->writer->setPinned($this->luis, $old, true);
    $new = $this->writer->post($this->luis, $this->chat, 'Nuevo');
    $this->writer->markRead($this->luis, $this->chat, $new->id);

    $response = $this->actingAs($this->ana)
        ->getJson("/chat/{$this->chat->id}/novedades?despues={$edited->id}&desde={$old->id}&cambios={$since}")
        ->assertOk()
        ->assertJsonPath('has_more', false)
        ->assertJsonPath('messages.0.id', $new->id)
        ->assertJsonCount(1, 'messages')
        ->assertJsonCount(2, 'updated')
        ->assertJsonPath('pinned.0.id', $old->id);

    $updated = collect($response->json('updated'))->keyBy('id');
    expect($updated[$edited->id]['body'])->toBe('Después')
        ->and($updated[$old->id]['reactions'][0]['emoji'])->toBe('👍')
        ->and(collect($response->json('read_state'))->firstWhere('user_id', $this->luis->id)['last_read_message_id'])->toBe($new->id)
        ->and($response->json('server_time'))->toBeString();

    // Sin cambios desde ahora: nada nuevo ni cambiado.
    $this->travel(10)->seconds();
    $this->actingAs($this->ana)
        ->getJson("/chat/{$this->chat->id}/novedades?despues={$new->id}&desde={$old->id}&cambios=".now()->toIso8601ZuluString())
        ->assertJsonCount(0, 'messages')
        ->assertJsonCount(0, 'updated');
});

it('la consulta periódica recoge el texto de un audio recién transcrito', function () {
    $message = Message::query()->create(['conversation_id' => $this->chat->id, 'user_id' => $this->ana->id, 'type' => 'audio']);
    $attachment = Attachment::factory()->create([
        'attachable_type' => Message::class,
        'attachable_id' => $message->id,
        'project_id' => $this->project->id,
        'mime' => 'audio/webm',
        'original_name' => 'nota.webm',
    ]);
    $transcription = AudioTranscription::query()->create(['message_id' => $message->id, 'attachment_id' => $attachment->id]);
    $this->travel(10)->seconds();
    $since = now()->toIso8601ZuluString();
    $this->travel(10)->seconds();
    $transcription->forceFill(['status' => TranscriptionStatus::Done, 'text' => 'Hola equipo'])->save();

    $this->actingAs($this->luis)
        ->getJson("/chat/{$this->chat->id}/novedades?despues={$message->id}&desde={$message->id}&cambios={$since}")
        ->assertJsonPath('updated.0.id', $message->id)
        ->assertJsonPath('updated.0.audio.transcription.status', 'done')
        ->assertJsonPath('updated.0.audio.transcription.text', 'Hola equipo');
});

it('si llegan demasiados mensajes de golpe, avisa para recargar los últimos', function () {
    $first = $this->writer->post($this->ana, $this->dm, 'Inicio');
    foreach (range(1, 55) as $i) {
        $this->writer->post($this->luis, $this->dm, "Ráfaga {$i}");
    }

    $this->actingAs($this->ana)
        ->getJson("/chat/{$this->dm->id}/novedades?despues={$first->id}&desde={$first->id}")
        ->assertJsonCount(50, 'messages')
        ->assertJsonPath('has_more', true);
});

it('marca como leído (nunca hacia atrás) y devuelve lo que queda', function () {
    Event::fake([ConversationRead::class]);
    $one = $this->writer->post($this->luis, $this->dm, 'Uno');
    $two = $this->writer->post($this->luis, $this->dm, 'Dos');
    $this->writer->post($this->luis, $this->chat, 'Del proyecto');

    $this->actingAs($this->ana)
        ->postJson("/chat/{$this->dm->id}/leido", ['message_id' => $one->id])
        ->assertOk()
        ->assertJsonPath('unread', 1)
        ->assertJsonPath('unread_total', 2);

    $this->actingAs($this->ana)
        ->postJson("/chat/{$this->dm->id}/leido", ['message_id' => $two->id])
        ->assertJsonPath('unread', 0)
        ->assertJsonPath('unread_total', 1);

    // Hacia atrás no cambia nada.
    $this->actingAs($this->ana)->postJson("/chat/{$this->dm->id}/leido", ['message_id' => $one->id])->assertJsonPath('unread', 0);
    Event::assertDispatchedTimes(ConversationRead::class, 2);

    // Un mensaje de otra conversación, no.
    $this->actingAs($this->ana)
        ->postJson("/chat/{$this->dm->id}/leido", ['message_id' => $this->chat->messages()->value('id')])
        ->assertJsonValidationErrors('message_id');
});

it('silencia y reactiva una conversación', function () {
    $this->writer->post($this->luis, $this->dm, 'Uno');

    $this->actingAs($this->ana)
        ->patchJson("/chat/{$this->dm->id}/silencio", ['muted' => true])
        ->assertOk()
        ->assertJsonPath('muted', true)
        ->assertJsonPath('unread_total', 0);

    $this->actingAs($this->ana)
        ->patchJson("/chat/{$this->dm->id}/silencio", ['muted' => false])
        ->assertJsonPath('muted', false)
        ->assertJsonPath('unread_total', 1);

    // El admin modera el chat del proyecto, pero no participa: no lo silencia.
    $this->actingAs($this->admin)->patchJson("/chat/{$this->chat->id}/silencio", ['muted' => true])->assertForbidden();
});

it('abre una directa (la misma para la pareja) solo con internos activos', function () {
    $marta = User::factory()->employee()->create();

    $this->actingAs($this->ana)->post('/chat/directas', ['user_id' => $marta->id])->assertRedirect();
    $conversation = $this->directory->direct($this->ana, $marta);
    $this->actingAs($marta)->post('/chat/directas', ['user_id' => $this->ana->id])->assertRedirect("/chat/{$conversation->id}");

    $this->actingAs($this->ana)->postJson('/chat/directas', ['user_id' => $marta->id])->assertCreated()->assertJsonPath('id', $conversation->id);

    $this->actingAs($this->ana)->post('/chat/directas', ['user_id' => User::factory()->client()->create()->id])->assertSessionHasErrors('user_id');
    $this->actingAs($this->ana)->post('/chat/directas', ['user_id' => User::factory()->employee()->inactive()->create()->id])->assertSessionHasErrors('user_id');
    $this->actingAs($this->ana)->post('/chat/directas', ['user_id' => $this->ana->id])->assertSessionHasErrors('user_id');
    $this->actingAs($this->ana)->post('/chat/directas', ['user_id' => 999999])->assertSessionHasErrors('user_id');
});

it('crea un grupo con nombre y personas internas activas', function () {
    $marta = User::factory()->employee()->create();
    $client = User::factory()->client()->create();

    $this->actingAs($this->ana)
        ->post('/chat/grupos', ['name' => '  Diseño  ', 'user_ids' => [$this->luis->id, $marta->id, $client->id]])
        ->assertRedirect();

    $group = $this->directory->forUser($this->ana)->where('type', 'group')->sole();
    expect($group->name)->toBe('Diseño')
        ->and($group->activeParticipants()->pluck('user_id')->sort()->values()->all())
        ->toEqual(collect([$this->ana->id, $this->luis->id, $marta->id])->sort()->values()->all());

    $this->actingAs($this->ana)->post('/chat/grupos', ['name' => '', 'user_ids' => [$this->luis->id]])->assertSessionHasErrors('name');
    $this->actingAs($this->ana)->post('/chat/grupos', ['name' => 'Solo', 'user_ids' => [$client->id]])->assertSessionHasErrors('name');
    $this->actingAs($this->ana)->post('/chat/grupos', ['name' => 'Vacío', 'user_ids' => []])->assertSessionHasErrors('user_ids');
});

it('dispara MessageUpdated en cada cambio de un mensaje', function () {
    $message = $this->writer->post($this->ana, $this->chat, 'Hola');
    Event::fake([MessageUpdated::class]);

    $this->actingAs($this->ana)->patchJson("/chat/mensajes/{$message->id}", ['body' => 'Hola, equipo']);
    $this->actingAs($this->luis)->postJson("/chat/mensajes/{$message->id}/reacciones", ['emoji' => '👍']);
    $this->actingAs($this->luis)->patchJson("/chat/mensajes/{$message->id}/fijado", ['pinned' => true]);
    $this->actingAs($this->admin)->patchJson("/chat/mensajes/{$message->id}/moderacion", ['hidden' => true]);

    Event::assertDispatchedTimes(MessageUpdated::class, 4);
});
