<?php

use App\Domain\Chat\ConversationDirectory;
use App\Models\Conversation;
use App\Models\Project;
use App\Models\User;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Testing\TestResponse;

/*
| Autorización de los canales de Echo (D-068 y D-071), con el broadcaster de Reverb de verdad
| (firma las suscripciones con la clave de la app): /broadcasting/auth solo firma lo que la
| política permite ver. En los tests el broadcaster por defecto es «null», que no comprueba nada.
*/

beforeEach(function () {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb' => [
            'driver' => 'reverb',
            'key' => 'clave-de-prueba',
            'secret' => 'secreto-de-prueba',
            'app_id' => 'audax-tests',
            'options' => ['host' => '127.0.0.1', 'port' => 18080, 'scheme' => 'http', 'useTLS' => false],
            'client_options' => [],
        ],
    ]);
    app(BroadcastManager::class)->purge('reverb');
    require base_path('routes/channels.php');

    $directory = app(ConversationDirectory::class);
    $this->ana = User::factory()->employee()->create(['name' => 'Ana']);
    $this->luis = User::factory()->employee()->create(['name' => 'Luis']);
    $this->project = Project::factory()->create();
    $this->project->addMember($this->ana);
    $this->projectChat = $directory->forProject($this->project);
    $this->direct = $directory->direct($this->ana, $this->luis);
    $this->group = $directory->group($this->ana, 'Diseño', [$this->luis->id]);

    $this->auth = fn (?User $user, string $channel): TestResponse => ($user === null ? $this : $this->actingAs($user))
        ->postJson('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $channel]);
    $this->conversation = fn (Conversation $conversation): string => "private-conversation.{$conversation->id}";
});

it('un participante se suscribe a su conversación y recibe la firma', function () {
    $response = ($this->auth)($this->ana, ($this->conversation)($this->projectChat))->assertOk();

    expect($response->json('auth'))->toStartWith('clave-de-prueba:');
    ($this->auth)($this->luis, ($this->conversation)($this->direct))->assertOk();
    ($this->auth)($this->luis, ($this->conversation)($this->group))->assertOk();
});

it('quien no participa no se suscribe, aunque sea interno', function () {
    $other = User::factory()->employee()->create();

    ($this->auth)($other, ($this->conversation)($this->projectChat))->assertForbidden();
    ($this->auth)($other, ($this->conversation)($this->direct))->assertForbidden();
    ($this->auth)($other, ($this->conversation)($this->group))->assertForbidden();
    ($this->auth)($this->luis, ($this->conversation)($this->projectChat))->assertForbidden();
});

it('quien sale del proyecto deja de poder escuchar su chat', function () {
    ($this->auth)($this->ana, ($this->conversation)($this->projectChat))->assertOk();

    $this->project->members()->detach($this->ana->id);

    ($this->auth)($this->ana, ($this->conversation)($this->projectChat))->assertForbidden();
});

it('el admin escucha los chats de proyecto y de grupo, pero nunca una conversación directa', function () {
    $admin = User::factory()->admin()->create();

    ($this->auth)($admin, ($this->conversation)($this->projectChat))->assertOk();
    ($this->auth)($admin, ($this->conversation)($this->group))->assertOk();
    ($this->auth)($admin, ($this->conversation)($this->direct))->assertForbidden();
});

it('sin sesión no se suscribe a nada', function () {
    foreach ([($this->conversation)($this->projectChat), "private-App.Models.User.{$this->ana->id}", 'presence-online'] as $channel) {
        ($this->auth)(null, $channel)->assertUnauthorized();
    }
});

it('un cliente o una persona desactivada no se suscriben a nada', function () {
    $client = User::factory()->client()->create();
    $inactive = User::factory()->employee()->create();
    $this->project->addMember($inactive);
    $inactive->update(['is_active' => false]);

    foreach ([($this->conversation)($this->projectChat), "private-App.Models.User.{$client->id}", 'presence-online'] as $channel) {
        ($this->auth)($client, $channel)->assertForbidden();
    }

    ($this->auth)($inactive->fresh(), ($this->conversation)($this->projectChat))->assertUnauthorized();
});

it('una conversación que no existe se rechaza', function () {
    ($this->auth)($this->ana, 'private-conversation.999999')->assertForbidden();
});

it('el canal personal solo lo escucha su dueño', function () {
    ($this->auth)($this->ana, "private-App.Models.User.{$this->ana->id}")->assertOk();
    ($this->auth)($this->ana, "private-App.Models.User.{$this->luis->id}")->assertForbidden();
});

it('la presencia «online» da a conocer id, nombre y avatar de cada persona interna', function () {
    $response = ($this->auth)($this->ana, 'presence-online')->assertOk();

    $data = json_decode((string) $response->json('channel_data'), true);
    // Pusher manda el user_id como texto; Echo entrega a here()/joining() el user_info (id numérico).
    expect($data['user_id'])->toBe((string) $this->ana->id)
        ->and($data['user_info'])->toBe(['id' => $this->ana->id, 'name' => 'Ana', 'avatar' => null]);
});
