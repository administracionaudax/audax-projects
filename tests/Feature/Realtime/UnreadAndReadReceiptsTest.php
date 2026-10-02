<?php

use App\Domain\Chat\ConversationDirectory;
use App\Domain\Chat\MessageWriter;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/*
| Contadores de no leídos (por conversación y total) y «leído por» (SPEC §12), con sus permisos
| (D-071) y sin N+1: las consultas no crecen con las conversaciones ni con los mensajes.
*/

beforeEach(function () {
    Storage::fake('local');
    Notification::fake();
    $this->directory = app(ConversationDirectory::class);
    $this->writer = app(MessageWriter::class);
    $this->ana = User::factory()->employee()->create(['name' => 'Ana']);
    $this->luis = User::factory()->employee()->create(['name' => 'Luis']);
    $this->project = Project::factory()->create();
    $this->project->addMember($this->ana);
    $this->project->addMember($this->luis);
    $this->chat = $this->directory->forProject($this->project);
    $this->dm = $this->directory->direct($this->ana, $this->luis);
    $this->queries = function (callable $callback): int {
        $count = 0;
        DB::listen(function (QueryExecuted $query) use (&$count): void {
            $count++;
        });
        $callback();

        return $count;
    };
});

it('cuenta los mensajes sin leer de otros, por conversación y en total', function () {
    // Escribir marca como leído todo lo anterior (MessageWriter): lo suyo nunca cuenta.
    $this->writer->post($this->luis, $this->chat, 'Mío: no cuenta para Luis');
    $this->writer->post($this->ana, $this->chat, 'Uno');
    $this->writer->post($this->ana, $this->chat, 'Dos');
    $this->writer->system($this->chat, 'hour_bank.threshold', ['threshold' => 90]);
    $this->writer->post($this->ana, $this->dm, 'Directo');

    $this->actingAs($this->luis)->getJson('/tiempo-real/no-leidos')
        ->assertOk()
        ->assertExactJson([
            'total' => 4,
            'conversations' => [(string) $this->chat->id => 3, (string) $this->dm->id => 1],
            'muted' => [],
            'latest_message_id' => Message::withTrashed()->max('id'),
        ]);

    // A Ana solo le queda el mensaje de sistema, posterior a lo último que escribió.
    $this->actingAs($this->ana)->getJson('/tiempo-real/no-leidos')
        ->assertOk()
        ->assertExactJson(['total' => 1, 'conversations' => [(string) $this->chat->id => 1], 'muted' => [], 'latest_message_id' => Message::withTrashed()->max('id')]);
});

it('leer, borrar u ocultar un mensaje lo quita del recuento; las silenciadas no suman al total', function () {
    $admin = User::factory()->admin()->create();
    $one = $this->writer->post($this->ana, $this->chat, 'Uno');
    $two = $this->writer->post($this->ana, $this->chat, 'Dos');
    $three = $this->writer->post($this->ana, $this->chat, 'Tres');
    $this->writer->post($this->ana, $this->dm, 'Directo');

    $this->writer->markRead($this->luis, $this->chat, $one->id);
    $this->writer->delete($this->ana, $two);
    $this->writer->setHidden($admin, $three, true);
    ConversationParticipant::query()->where(['conversation_id' => $this->dm->id, 'user_id' => $this->luis->id])->update(['muted' => true]);

    $this->actingAs($this->luis)->getJson('/tiempo-real/no-leidos')
        ->assertOk()
        ->assertExactJson(['total' => 0, 'conversations' => [(string) $this->dm->id => 1], 'muted' => [$this->dm->id], 'latest_message_id' => Message::withTrashed()->max('id')]);
});

it('sin nada pendiente devuelve un objeto vacío, no una lista', function () {
    $response = $this->actingAs($this->luis)->getJson('/tiempo-real/no-leidos')->assertOk();

    expect($response->getContent())->toBe('{"total":0,"conversations":{},"muted":[],"latest_message_id":null}');
});

it('devuelve todas las conversaciones silenciadas, tengan o no mensajes pendientes', function () {
    ConversationParticipant::query()->where('user_id', $this->luis->id)->update(['muted' => true]);

    $this->actingAs($this->luis)->getJson('/tiempo-real/no-leidos')
        ->assertExactJson(['total' => 0, 'conversations' => [], 'muted' => collect([$this->chat->id, $this->dm->id])->sort()->values()->all(), 'latest_message_id' => null]);
});

it('no cuenta las conversaciones de las que ya no participa', function () {
    $this->writer->post($this->ana, $this->chat, 'Uno');
    $this->project->members()->detach($this->luis->id);

    $this->actingAs($this->luis)->getJson('/tiempo-real/no-leidos')->assertJson(['total' => 0]);
});

it('el recuento dice hasta qué mensaje ha contado y no cuenta los posteriores (D-121)', function () {
    $first = $this->writer->post($this->ana, $this->chat, 'Uno');
    $second = $this->writer->post($this->ana, $this->chat, 'Dos');

    // Lo que llegó después del mensaje «Uno» no entra en un recuento hecho hasta él: el navegador
    // suma por su cuenta los avisos en vivo de los mensajes posteriores.
    expect($this->directory->unreadCounts($this->luis, null, $first->id))->toBe([$this->chat->id => 1])
        ->and($this->directory->unreadCounts($this->luis, null, $second->id))->toBe([$this->chat->id => 2]);

    $this->actingAs($this->luis)->getJson('/tiempo-real/no-leidos')
        ->assertJsonPath('latest_message_id', $second->id)
        ->assertJsonPath('total', 2);
});

it('el recuento es una sola consulta, crezcan lo que crezcan las conversaciones y los mensajes', function () {
    $measure = function (): int {
        $this->actingAs($this->luis)->getJson('/tiempo-real/no-leidos')->assertOk();

        return ($this->queries)(fn () => $this->actingAs($this->luis)->getJson('/tiempo-real/no-leidos')->assertOk());
    };
    $this->writer->post($this->ana, $this->chat, 'Uno');
    $before = $measure();

    foreach (range(1, 6) as $i) {
        $other = User::factory()->employee()->create();
        $dm = $this->directory->direct($other, $this->luis);
        foreach (range(1, 3) as $j) {
            $this->writer->post($other, $dm, "Mensaje {$j}");
        }
        $group = $this->directory->group($other, "Grupo {$i}", [$this->luis->id]);
        $this->writer->post($other, $group, 'Hola');
    }

    expect($measure())->toBe($before)->and($before)->toBeLessThanOrEqual(8);
});

it('«leído por»: hasta dónde ha leído cada participante activo', function () {
    $message = $this->writer->post($this->ana, $this->chat, 'Hola');
    $this->writer->markRead($this->luis, $this->chat, $message->id);
    $gone = User::factory()->employee()->create();
    $this->project->addMember($gone);
    $this->project->members()->detach($gone->id);

    $response = $this->actingAs($this->ana)->getJson("/tiempo-real/conversaciones/{$this->chat->id}/leidos")->assertOk();

    $participants = collect($response->json('participants'))->keyBy('id');
    expect($participants->keys()->sort()->values()->all())->toEqual(collect([$this->ana->id, $this->luis->id, $this->project->owner_user_id])->unique()->sort()->values()->all())
        ->and($participants[$this->luis->id])->toBe(['id' => $this->luis->id, 'name' => 'Luis', 'avatar' => null, 'is_active' => true, 'last_read_message_id' => $message->id])
        ->and($participants[$this->ana->id]['last_read_message_id'])->toBe($message->id)
        ->and($participants->has($gone->id))->toBeFalse();
});

it('«leído por» sigue los permisos de la conversación (D-071)', function () {
    $admin = User::factory()->admin()->create();
    $outsider = User::factory()->employee()->create();
    $client = User::factory()->client()->create();
    $manager = User::factory()->departmentManager()->create();
    $group = $this->directory->group($this->ana, 'Diseño', [$this->luis->id]);
    $url = fn ($conversation): string => "/tiempo-real/conversaciones/{$conversation->id}/leidos";

    $this->getJson($url($this->chat))->assertUnauthorized();
    $this->actingAs($this->luis)->getJson($url($this->chat))->assertOk();
    $this->actingAs($this->luis)->getJson($url($this->dm))->assertOk();
    $this->actingAs($this->luis)->getJson($url($group))->assertOk();
    $this->actingAs($outsider)->getJson($url($this->chat))->assertForbidden();
    $this->actingAs($manager)->getJson($url($this->chat))->assertForbidden();
    $this->actingAs($outsider)->getJson($url($this->dm))->assertForbidden();
    $this->actingAs($admin)->getJson($url($this->chat))->assertOk();
    $this->actingAs($admin)->getJson($url($group))->assertOk();
    $this->actingAs($admin)->getJson($url($this->dm))->assertForbidden();
    $this->actingAs($client)->getJson($url($this->chat))->assertForbidden();
    $this->actingAs($this->luis)->getJson('/tiempo-real/conversaciones/999999/leidos')->assertNotFound();
});

it('«leído por» hace las mismas consultas con 3 que con 30 participantes', function () {
    $measure = function (): int {
        $this->actingAs($this->ana)->getJson("/tiempo-real/conversaciones/{$this->chat->id}/leidos")->assertOk();

        return ($this->queries)(fn () => $this->actingAs($this->ana)->getJson("/tiempo-real/conversaciones/{$this->chat->id}/leidos")->assertOk());
    };
    $before = $measure();

    foreach (User::factory()->employee()->count(27)->create() as $member) {
        $this->project->addMember($member);
    }

    expect($measure())->toBe($before);
});
