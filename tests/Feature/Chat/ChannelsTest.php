<?php

use App\Domain\Access\CollaboratorOffboarding;
use App\Domain\Chat\ChannelMembership;
use App\Domain\Chat\ConversationDirectory;
use App\Domain\Chat\MessageWriter;
use App\Enums\ConversationType;
use App\Enums\MessageType;
use App\Models\Client;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Project;
use App\Models\User;
use App\Notifications\Chat\ChatEveryoneNotification;
use App\Notifications\Chat\ChatMentionNotification;
use App\Search\Sources\MessageSource;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/*
| Canales del chat (D-270 a D-273):
| - de equipo: los crean, renombran y archivan los admins; participa toda la plantilla interna; un
|   colaborador externo solo si un admin lo añade,
| - de cliente: uno por cliente, creado al abrirlo; participan los miembros de sus proyectos
|   activos; lo ve toda la plantilla y los colaboradores con proyectos de ese cliente,
| - de los canales se entra y se sale libremente; quien escribe o es mencionado pasa a participar,
| - la lista del chat trae todos los canales que se ven, con su cliente.
*/

beforeEach(function () {
    Storage::fake('local');
    Notification::fake();
    $this->directory = app(ConversationDirectory::class);
    $this->writer = app(MessageWriter::class);
    $this->admin = User::factory()->admin()->create(['name' => 'Admin']);
    $this->ana = User::factory()->employee()->create(['name' => 'Ana']);
    $this->luis = User::factory()->employee()->create(['name' => 'Luis']);
    $this->amparo = User::factory()->collaborator()->create(['name' => 'Amparo']);
    $this->participants = fn (Conversation $conversation): array => ConversationParticipant::query()
        ->where('conversation_id', $conversation->id)->whereNull('left_at')->orderBy('user_id')->pluck('user_id')->all();
});

it('el admin crea un canal de equipo con toda la plantilla dentro y sin colaboradores', function () {
    $response = $this->actingAs($this->admin)
        ->postJson('/chat/canales', ['name' => '  Daily  ', 'icon' => '🔹'])
        ->assertCreated();

    $channel = Conversation::query()->findOrFail($response->json('id'));

    expect($channel->type)->toBe(ConversationType::Team)
        ->and($channel->name)->toBe('Daily')
        ->and($channel->icon)->toBe('🔹')
        ->and(($this->participants)($channel))->toBe([$this->admin->id, $this->ana->id, $this->luis->id]);

    // Nadie más que un admin los crea, y el emoji tiene que ser uno del selector.
    $this->actingAs($this->ana)->postJson('/chat/canales', ['name' => 'Otro'])->assertForbidden();
    $this->actingAs($this->admin)->postJson('/chat/canales', ['name' => 'Otro', 'icon' => 'xx'])->assertUnprocessable();

    expect(Activity::query()->where('log_name', 'chat')->where('event', 'channel_created')->count())->toBe(1);
});

it('toda la plantilla ve y escribe en un canal de equipo; un colaborador, solo si lo añade un admin', function () {
    $channel = $this->directory->createTeam($this->admin, 'Diseño', '🎨');

    $this->actingAs($this->luis)->get("/chat/{$channel->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('conversation.type', 'team')
            ->where('conversation.icon', '🎨')
            ->where('conversation.can.post', true)
            ->where('conversation.can.leave', true)
            ->where('conversation.can.manage', false));
    $this->actingAs($this->luis)->postJson("/chat/{$channel->id}/mensajes", ['body' => 'Hola @todos'])->assertCreated();
    Notification::assertSentTo([$this->ana, $this->admin], ChatEveryoneNotification::class);
    Notification::assertNotSentTo($this->amparo, ChatEveryoneNotification::class);

    $this->actingAs($this->amparo)->get("/chat/{$channel->id}")->assertForbidden();

    $this->actingAs($this->admin)
        ->postJson("/chat/{$channel->id}/participantes", ['user_ids' => [$this->amparo->id]])
        ->assertOk();
    $this->actingAs($this->amparo)->get("/chat/{$channel->id}")->assertOk();
    $this->actingAs($this->amparo)->postJson("/chat/{$channel->id}/mensajes", ['body' => 'Gracias'])->assertCreated();

    // Y el admin la vuelve a quitar.
    $this->actingAs($this->admin)->deleteJson("/chat/{$channel->id}/participantes/{$this->amparo->id}")->assertOk();
    $this->actingAs($this->amparo)->get("/chat/{$channel->id}")->assertForbidden();
});

it('el admin renombra y archiva un canal de equipo: queda de solo lectura, con mensajes de sistema y auditoría', function () {
    $channel = $this->directory->createTeam($this->admin, 'Marketing', null);

    $this->actingAs($this->ana)->patchJson("/chat/{$channel->id}/canal", ['name' => 'Nombre'])->assertForbidden();

    $this->actingAs($this->admin)
        ->patchJson("/chat/{$channel->id}/canal", ['name' => 'Marketing y contenidos', 'icon' => '🟠', 'archived' => true])
        ->assertOk()
        ->assertJsonPath('conversation.title', 'Marketing y contenidos')
        ->assertJsonPath('conversation.archived', true)
        ->assertJsonPath('conversation.can.post', false)
        ->assertJsonPath('conversation.read_only_reason', 'channel_archived');

    $this->actingAs($this->ana)->postJson("/chat/{$channel->id}/mensajes", ['body' => 'Hola'])->assertForbidden();
    expect($channel->messages()->where('type', MessageType::System)->orderBy('id')->pluck('system_key')->all())
        ->toBe(['channel.renamed', 'channel.archived'])
        ->and(Activity::query()->where('event', 'channel_updated')->sole()->properties['old']['name'])->toBe('Marketing');

    $this->actingAs($this->admin)->patchJson("/chat/{$channel->id}/canal", ['name' => 'Marketing y contenidos', 'icon' => '🟠', 'archived' => false])->assertOk();
    $this->actingAs($this->ana)->postJson("/chat/{$channel->id}/mensajes", ['body' => 'Hola'])->assertCreated();
});

it('el canal de un cliente se crea al abrirlo con los miembros de sus proyectos activos', function () {
    $client = Client::factory()->create(['name' => 'Naranjas', 'icon' => '🍊']);
    $web = Project::factory()->create(['client_id' => $client->id]);
    $web->addMember($this->ana);
    $web->addMember($this->amparo);
    $old = Project::factory()->archived()->create(['client_id' => $client->id]);
    $old->addMember($this->luis);

    $this->actingAs($this->luis)->get("/chat/clientes/{$client->id}")->assertRedirect();
    $channel = Conversation::query()->where('client_id', $client->id)->sole();

    expect($channel->type)->toBe(ConversationType::Client)
        ->and(($this->participants)($channel))->toBe(collect([$web->owner_user_id, $this->ana->id, $this->amparo->id])->filter()->unique()->sort()->values()->all());

    // Luis (plantilla, sin proyectos activos del cliente) lo ve sin participar y, al escribir, entra.
    $this->actingAs($this->luis)->get("/chat/{$channel->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('conversation.title', 'Naranjas')
            ->where('conversation.icon', '🍊')
            ->where('conversation.client.name', 'Naranjas')
            ->where('conversation.is_participant', false)
            ->where('conversation.can.post', true)
            ->where('conversation.can.join', true));
    $this->actingAs($this->luis)->postJson("/chat/{$channel->id}/mensajes", ['body' => 'Hola'])->assertCreated();
    expect($channel->hasParticipant($this->luis->fresh()))->toBeTrue();

    // La colaboradora lo ve porque tiene un proyecto del cliente; otra, no.
    $this->actingAs($this->amparo)->get("/chat/{$channel->id}")->assertOk();
    $other = User::factory()->collaborator()->create();
    $this->actingAs($other)->get("/chat/{$channel->id}")->assertForbidden();
    $this->actingAs($other)->get("/chat/clientes/{$client->id}")->assertNotFound();

    // Un cliente desactivado deja su canal en solo lectura.
    $client->update(['is_active' => false]);
    $this->actingAs($this->ana)->postJson("/chat/{$channel->id}/mensajes", ['body' => 'Hola'])->assertForbidden();
});

it('al entrar en un proyecto activo se participa en el canal de su cliente', function () {
    $client = Client::factory()->create();
    $channel = $this->directory->forClient($client);
    $project = Project::factory()->create(['client_id' => $client->id]);

    $project->addMember($this->luis);

    expect($channel->hasParticipant($this->luis))->toBeTrue();
});

it('de un canal se sale y se vuelve a entrar; quien sale no vuelve a entrar solo', function () {
    $channel = $this->directory->createTeam($this->admin, 'Daily', null);

    $this->actingAs($this->ana)->postJson("/chat/{$channel->id}/dejar")->assertOk()
        ->assertJsonPath('conversation.is_participant', false)
        ->assertJsonPath('conversation.can.join', true);

    // La lista lo sigue enseñando (sin participar) y no la vuelve a meter.
    $this->actingAs($this->ana)->getJson('/chat/conversaciones')->assertOk()
        ->assertJsonPath('conversations.0.id', $channel->id)
        ->assertJsonPath('conversations.0.is_participant', false);
    app(ChannelMembership::class)->ensureFor($this->ana);
    expect($channel->fresh()->hasParticipant($this->ana))->toBeFalse();

    $this->actingAs($this->ana)->postJson("/chat/{$channel->id}/unirme")->assertOk()
        ->assertJsonPath('conversation.is_participant', true);

    // Salir de un canal no es salir de un grupo (sin mensaje de sistema).
    $this->actingAs($this->ana)->postJson("/chat/{$channel->id}/salir")->assertForbidden();
    expect($channel->messages()->count())->toBe(0);
});

it('quien se suma a la plantilla entra en los canales de equipo al abrir el chat', function () {
    $channel = $this->directory->createTeam($this->admin, 'Daily', null);
    $this->writer->post($this->ana, $channel, 'Antes de llegar');
    $new = User::factory()->employee()->create();

    $this->actingAs($new)->getJson('/chat/conversaciones')->assertOk()
        ->assertJsonPath('conversations.0.id', $channel->id)
        ->assertJsonPath('conversations.0.is_participant', true)
        // Lo anterior a su llegada cuenta como leído.
        ->assertJsonPath('conversations.0.unread', 0);
});

it('mencionar en un canal a quien lo ve sin participar le hace entrar y le avisa', function () {
    $client = Client::factory()->create();
    $channel = $this->directory->forClient($client);

    $this->actingAs($this->ana)->postJson("/chat/{$channel->id}/mensajes", ['body' => "Mira esto <@{$this->luis->id}>"])->assertCreated();

    expect($channel->fresh()->hasParticipant($this->luis))->toBeTrue();
    Notification::assertSentTo($this->luis, ChatMentionNotification::class);
});

it('la lista del chat trae los canales que se ven y los proyectos con su cliente', function () {
    $client = Client::factory()->create(['name' => 'H&N', 'icon' => '🐓']);
    $project = Project::factory()->create(['client_id' => $client->id, 'name' => 'Web']);
    $project->addMember($this->ana);
    $projectChat = $this->directory->forProject($project);
    $clientChat = $this->directory->forClient($client);
    $team = $this->directory->createTeam($this->admin, 'Daily', '🔹');
    $hidden = $this->directory->createTeam($this->admin, 'Interno', null);

    $items = collect($this->actingAs($this->ana)->getJson('/chat/conversaciones')->assertOk()->json('conversations'))->keyBy('id');

    expect($items->keys()->sort()->values()->all())->toBe(collect([$projectChat->id, $clientChat->id, $team->id, $hidden->id])->sort()->values()->all())
        ->and($items[$projectChat->id]['client'])->toMatchArray(['id' => $client->id, 'name' => 'H&N', 'icon' => '🐓', 'is_active' => true])
        ->and($items[$clientChat->id]['type'])->toBe('client')
        ->and($items[$clientChat->id]['title'])->toBe('H&N')
        ->and($items[$clientChat->id]['icon'])->toBe('🐓')
        ->and($items[$team->id]['icon'])->toBe('🔹');

    // La colaboradora del proyecto ve su proyecto y el canal del cliente, pero no los de equipo.
    $project->addMember($this->amparo);
    $collaborator = collect($this->actingAs($this->amparo)->getJson('/chat/conversaciones')->assertOk()->json('conversations'))->pluck('id')->sort()->values()->all();
    expect($collaborator)->toBe(collect([$projectChat->id, $clientChat->id])->sort()->values()->all());
});

it('la búsqueda del chat encuentra los mensajes de los canales que se ven', function () {
    $client = Client::factory()->create(['name' => 'Kora']);
    $channel = $this->directory->forClient($client);
    $this->writer->post($this->admin, $channel, 'Presupuesto del sprint');

    $hits = app(MessageSource::class)->find($this->luis, 'sprint', 10);
    expect(collect($hits)->pluck('conversation.label')->all())->toBe(['Kora'])
        ->and(collect($hits)->pluck('conversation.type')->all())->toBe(['client']);

    expect(app(MessageSource::class)->find($this->amparo, 'sprint', 10))->toBe([]);
});

it('quien pasa a colaborador sale de los canales de equipo', function () {
    $channel = $this->directory->createTeam($this->admin, 'Daily', null);
    expect($channel->hasParticipant($this->luis))->toBeTrue();

    $this->luis->syncRoles(['collaborator']);
    app(CollaboratorOffboarding::class)->becameCollaborator($this->luis->fresh());

    $this->actingAs($this->luis->fresh())->get("/chat/{$channel->id}")->assertForbidden();
});
