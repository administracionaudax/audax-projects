<?php

use App\Domain\Chat\ConversationDirectory;
use App\Models\Project;
use App\Models\User;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Testing\TestResponse;

/*
| Tiempo real de un colaborador externo (D-134), con el broadcaster de Reverb de verdad (como
| ChannelAuthorizationTest): se suscribe al chat de sus proyectos y a sus notificaciones, pero no
| al de un proyecto ajeno ni a la presencia de toda la plantilla.
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

    $this->sara = User::factory()->collaborator()->create();
    $this->ana = User::factory()->employee()->create();
    $directory = app(ConversationDirectory::class);
    $this->ownChat = $directory->forProject(Project::factory()->withMembers([$this->sara, $this->ana])->create());
    $this->foreignChat = $directory->forProject(Project::factory()->withMembers([$this->ana])->create());

    $this->auth = fn (User $user, string $channel): TestResponse => $this->actingAs($user)
        ->postJson('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $channel]);
});

it('se suscribe al chat de su proyecto y a sus notificaciones', function () {
    ($this->auth)($this->sara, "private-conversation.{$this->ownChat->id}")->assertOk();
    ($this->auth)($this->sara, "private-App.Models.User.{$this->sara->id}")->assertOk();
});

it('no se suscribe al chat de un proyecto ajeno, aunque figure como participante', function () {
    ($this->auth)($this->sara, "private-conversation.{$this->foreignChat->id}")->assertForbidden();

    app(ConversationDirectory::class)->join($this->foreignChat, $this->sara->id);
    ($this->auth)($this->sara, "private-conversation.{$this->foreignChat->id}")->assertForbidden();
});

it('no entra en la presencia de la plantilla; el resto, sí', function () {
    ($this->auth)($this->sara, 'presence-online')->assertForbidden();
    ($this->auth)($this->ana, 'presence-online')->assertOk();
});

it('la presencia sin tiempo real solo le enseña a las personas de sus proyectos', function () {
    $outsider = User::factory()->employee()->create();

    foreach ([$this->ana, $outsider] as $user) {
        $this->actingAs($user)->postJson('/tiempo-real/presencia', ['status' => 'online'])->assertOk();
    }

    $seen = array_map('intval', array_keys($this->actingAs($this->sara)->postJson('/tiempo-real/presencia', ['status' => 'online'])->json('users')));

    expect($seen)->toContain($this->ana->id)
        ->and($seen)->toContain($this->sara->id)
        ->and($seen)->not->toContain($outsider->id);
});
