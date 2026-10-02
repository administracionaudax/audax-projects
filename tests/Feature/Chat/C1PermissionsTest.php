<?php

use App\Domain\Chat\ConversationDirectory;
use App\Domain\Chat\MessageWriter;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/*
| Matriz de permisos del chat (D-071) en TODAS las rutas de C1, para cada tipo de conversación:
| - participante: lee y escribe; no edita, borra ni modera lo ajeno,
| - admin que no participa: ve y modera las de proyecto y grupo, sin escribir; nunca las directas,
| - responsable de departamento y empleado que no participan: nada,
| - cliente: nada (403 en JSON; en la página, a su portal), invitado: 401 (o al login).
| Cada ruta se prueba en un orden en el que ninguna afecta a las siguientes (moderar, al final).
*/

beforeEach(function () {
    Storage::fake('local');
    $this->directory = app(ConversationDirectory::class);
    $this->writer = app(MessageWriter::class);
    $this->author = User::factory()->employee()->create(['name' => 'Autora']);
    $this->participant = User::factory()->employee()->create(['name' => 'Participante']);
    $this->actors = [
        'participant' => $this->participant,
        'admin' => User::factory()->admin()->create(),
        'manager' => User::factory()->departmentManager()->create(),
        'outsider' => User::factory()->employee()->create(),
        'client' => User::factory()->client()->create(),
        'guest' => null,
    ];

    $this->conversation = function (string $type): Conversation {
        return match ($type) {
            'project' => (function (): Conversation {
                $project = Project::factory()->create(['owner_user_id' => $this->author->id]);
                $project->addMember($this->participant);

                return $this->directory->forProject($project);
            })(),
            'group' => $this->directory->group($this->author, 'Diseño', [$this->participant->id]),
            default => $this->directory->direct($this->author, $this->participant),
        };
    };

    /**
     * Estado de cada ruta para un actor, en orden. Devuelve [ruta => estado].
     *
     * @return array<string, int>
     */
    $this->statuses = function (?User $actor, Conversation $conversation, Message $message): array {
        $c = $conversation->id;
        $m = $message->id;
        $routes = [
            'page' => ['GET', "/chat/{$c}", [], false],
            'messages' => ['GET', "/chat/{$c}/mensajes", [], true],
            'poll' => ['GET', "/chat/{$c}/novedades?despues={$m}&desde={$m}", [], true],
            'pinned' => ['GET', "/chat/{$c}/fijados", [], true],
            'show' => ['GET', "/chat/mensajes/{$m}", [], true],
            'task_options' => ['GET', "/chat/mensajes/{$m}/tarea", [], true],
            'post' => ['POST', "/chat/{$c}/mensajes", ['body' => 'Hola'], true],
            'read' => ['POST', "/chat/{$c}/leido", ['message_id' => $m], true],
            'mute' => ['PATCH', "/chat/{$c}/silencio", ['muted' => true], true],
            'react' => ['POST', "/chat/mensajes/{$m}/reacciones", ['emoji' => '👍'], true],
            'pin' => ['PATCH', "/chat/mensajes/{$m}/fijado", ['pinned' => true], true],
            'edit_other' => ['PATCH', "/chat/mensajes/{$m}", ['body' => 'Cambio'], true],
            'delete_other' => ['DELETE', "/chat/mensajes/{$m}", [], true],
            'moderate' => ['PATCH', "/chat/mensajes/{$m}/moderacion", ['hidden' => true], true],
        ];
        $result = [];

        foreach ($routes as $name => [$method, $uri, $data, $json]) {
            if ($actor !== null) {
                $this->actingAs($actor);
            } else {
                auth()->logout();
            }

            $response = $json ? $this->json($method, $uri, $data) : $this->call($method, $uri, $data);
            $result[$name] = $response->getStatusCode();
        }

        return $result;
    };

    $this->expect = function (string $type, string $actorName, array $expected): void {
        $conversation = ($this->conversation)($type);
        $message = $this->writer->post($this->author, $conversation, 'Mensaje de la autora');

        expect(($this->statuses)($this->actors[$actorName], $conversation, $message))->toBe($expected);
    };
});

$none = fn (int $status): array => array_fill_keys(['page', 'messages', 'poll', 'pinned', 'show', 'task_options', 'post', 'read', 'mute', 'react', 'pin', 'edit_other', 'delete_other', 'moderate'], $status);

dataset('conversation_types', ['project', 'group', 'direct']);

it('un participante lee y escribe, pero no edita, borra ni modera lo ajeno', function (string $type) {
    ($this->expect)($type, 'participant', [
        'page' => 200, 'messages' => 200, 'poll' => 200, 'pinned' => 200, 'show' => 200,
        'task_options' => $type === 'project' ? 200 : 422,
        'post' => 201, 'read' => 200, 'mute' => 200, 'react' => 200, 'pin' => 200,
        'edit_other' => 403, 'delete_other' => 403, 'moderate' => 403,
    ]);
})->with('conversation_types');

it('el admin que no participa ve y modera las de proyecto y grupo sin escribir', function (string $type) {
    ($this->expect)($type, 'admin', [
        'page' => 200, 'messages' => 200, 'poll' => 200, 'pinned' => 200, 'show' => 200,
        'task_options' => $type === 'project' ? 200 : 422,
        'post' => 403, 'read' => 200, 'mute' => 403, 'react' => 403, 'pin' => 403,
        'edit_other' => 403, 'delete_other' => 403, 'moderate' => 200,
    ]);
})->with(['project', 'group']);

it('el admin nunca ve las conversaciones directas de los demás', function () use ($none) {
    ($this->expect)('direct', 'admin', $none(403));
});

it('el responsable de departamento y el empleado que no participan no ven nada', function (string $type, string $actor) use ($none) {
    ($this->expect)($type, $actor, $none(403));
})->with('conversation_types')->with(['manager', 'outsider']);

it('un cliente nunca entra: 403 en JSON y a su portal en la página', function (string $type) use ($none) {
    ($this->expect)($type, 'client', ['page' => 302, ...array_diff_key($none(403), ['page' => true])]);
})->with('conversation_types');

it('un invitado va al login (401 en JSON)', function (string $type) use ($none) {
    ($this->expect)($type, 'guest', ['page' => 302, ...array_diff_key($none(401), ['page' => true])]);
})->with('conversation_types');

it('en su propia directa el admin es un participante más, pero tampoco la modera', function () {
    $admin = $this->actors['admin'];
    $conversation = $this->directory->direct($this->author, $admin);
    $message = $this->writer->post($this->author, $conversation, 'Hola, admin');

    expect(($this->statuses)($admin, $conversation, $message))->toBe([
        'page' => 200, 'messages' => 200, 'poll' => 200, 'pinned' => 200, 'show' => 200, 'task_options' => 422,
        'post' => 201, 'read' => 200, 'mute' => 200, 'react' => 200, 'pin' => 200,
        'edit_other' => 403, 'delete_other' => 403, 'moderate' => 403,
    ]);
});

it('quien sale del proyecto deja de ver su chat, pero sus mensajes siguen en el histórico', function () {
    $conversation = ($this->conversation)('project');
    $message = $this->writer->post($this->participant, $conversation, 'Me voy del proyecto');
    $conversation->project?->members()->detach($this->participant->id);

    $this->actingAs($this->participant)->get("/chat/{$conversation->id}")->assertForbidden();
    $this->actingAs($this->author)
        ->getJson("/chat/mensajes/{$message->id}")
        ->assertOk()
        ->assertJsonPath('message.author.name', 'Participante');
});

it('una persona desactivada sigue en el histórico, marcada como inactiva, y ya no entra', function () {
    $conversation = ($this->conversation)('direct');
    $message = $this->writer->post($this->participant, $conversation, 'Mi último mensaje');
    $this->participant->forceFill(['is_active' => false])->save();

    $this->actingAs($this->author)
        ->getJson("/chat/mensajes/{$message->id}")
        ->assertJsonPath('message.author.is_active', false);

    // El middleware active cierra su sesión (401 en JSON).
    $this->actingAs($this->participant)->getJson("/chat/{$conversation->id}/mensajes")->assertUnauthorized();
    expect(Gate::forUser($this->participant->fresh())->allows('view', $conversation))->toBeFalse();
});
