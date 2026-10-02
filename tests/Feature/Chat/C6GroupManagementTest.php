<?php

use App\Domain\Chat\ConversationDirectory;
use App\Domain\Chat\MessageWriter;
use App\Enums\MessageType;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Grupos y moderación del admin (D-071, D-119):
| - el admin lista los chats de proyecto y los grupos en los que no participa (para moderarlos),
|   nunca las directas,
| - quien creó un grupo (mientras siga en él) o el admin lo renombran y añaden o quitan personas;
|   cualquiera de sus participantes puede salir; cada cambio deja un mensaje de sistema.
*/

beforeEach(function () {
    Storage::fake('local');
    Notification::fake();
    $this->directory = app(ConversationDirectory::class);
    $this->writer = app(MessageWriter::class);
    $this->ana = User::factory()->employee()->create(['name' => 'Ana']);
    $this->luis = User::factory()->employee()->create(['name' => 'Luis']);
    $this->eva = User::factory()->employee()->create(['name' => 'Eva']);
    $this->admin = User::factory()->admin()->create(['name' => 'Admin']);
    $this->group = $this->directory->group($this->ana, 'Diseño', [$this->luis->id]);
    $this->systemKeys = fn (): array => $this->group->messages()->where('type', MessageType::System)->orderBy('id')->pluck('system_key')->all();
});

it('el admin lista los proyectos y grupos que puede moderar sin participar, nunca las directas', function () {
    $project = Project::factory()->create(['name' => 'Web']);
    $project->addMember($this->ana);
    $projectChat = $this->directory->forProject($project);
    $this->directory->direct($this->ana, $this->luis);
    $ownGroup = $this->directory->group($this->admin, 'Dirección', [$this->ana->id]);

    $response = $this->actingAs($this->admin)->getJson('/chat/moderar')->assertOk();

    expect(collect($response->json('conversations'))->pluck('id')->sort()->values()->all())
        ->toBe(collect([$projectChat->id, $this->group->id])->sort()->values()->all())
        ->and(collect($response->json('conversations'))->firstWhere('id', $projectChat->id)['title'])->toBe('Web')
        ->and(collect($response->json('conversations'))->firstWhere('id', $this->group->id)['members_count'])->toBe(2)
        ->and(collect($response->json('conversations'))->pluck('id'))->not->toContain($ownGroup->id);

    // Y abre cualquiera de ellas en modo moderación.
    $this->actingAs($this->admin)
        ->get("/chat/{$this->group->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('conversation.is_participant', false)
            ->where('conversation.can.moderate', true)
            ->where('conversation.can.manage', true)
            ->where('conversation.can.leave', false));

    // Nadie más puede pedir la lista.
    $this->actingAs($this->ana)->getJson('/chat/moderar')->assertForbidden();
});

it('quien creó el grupo lo renombra, añade y quita personas, con un mensaje de sistema por cambio', function () {
    $this->actingAs($this->ana)
        ->patchJson("/chat/{$this->group->id}/grupo", ['name' => '  Diseño web  '])
        ->assertOk()
        ->assertJsonPath('conversation.title', 'Diseño web')
        ->assertJsonPath('conversation.can.manage', true);

    $this->actingAs($this->ana)
        ->postJson("/chat/{$this->group->id}/participantes", ['user_ids' => [$this->eva->id, $this->luis->id]])
        ->assertOk()
        ->assertJsonCount(3, 'conversation.participants');

    $this->actingAs($this->ana)
        ->deleteJson("/chat/{$this->group->id}/participantes/{$this->luis->id}")
        ->assertOk()
        ->assertJsonCount(2, 'conversation.participants');

    expect($this->group->fresh()->name)->toBe('Diseño web')
        ->and($this->group->hasParticipant($this->eva))->toBeTrue()
        ->and($this->group->hasParticipant($this->luis))->toBeFalse()
        ->and(($this->systemKeys)())->toBe(['group.renamed', 'group.added', 'group.removed']);

    $added = $this->group->messages()->where('system_key', 'group.added')->sole();
    expect($added->system_payload)->toBe(['by' => 'Ana', 'users' => ['Eva']]);

    // Luis ya no ve el grupo; Eva, sí: lo anterior a su entrada cuenta como leído, pero no los
    // avisos de que ha entrado ella y de que ha salido Luis.
    $this->actingAs($this->luis)->get("/chat/{$this->group->id}")->assertForbidden();
    expect($this->directory->unreadCounts($this->eva))->toBe([$this->group->id => 2]);
});

it('el admin gestiona cualquier grupo; los demás participantes, no', function () {
    $this->actingAs($this->luis)->patchJson("/chat/{$this->group->id}/grupo", ['name' => 'Mío'])->assertForbidden();
    $this->actingAs($this->luis)->postJson("/chat/{$this->group->id}/participantes", ['user_ids' => [$this->eva->id]])->assertForbidden();
    $this->actingAs($this->luis)->deleteJson("/chat/{$this->group->id}/participantes/{$this->ana->id}")->assertForbidden();
    $this->actingAs($this->eva)->patchJson("/chat/{$this->group->id}/grupo", ['name' => 'Ajeno'])->assertForbidden();

    $this->actingAs($this->admin)->patchJson("/chat/{$this->group->id}/grupo", ['name' => 'Moderado'])->assertOk();
    $this->actingAs($this->admin)->postJson("/chat/{$this->group->id}/participantes", ['user_ids' => [$this->eva->id]])->assertOk();
    $this->actingAs($this->admin)->deleteJson("/chat/{$this->group->id}/participantes/{$this->eva->id}")->assertOk();

    expect($this->group->fresh()->name)->toBe('Moderado')
        ->and($this->group->hasParticipant($this->admin))->toBeFalse();
});

it('quien creó el grupo deja de gestionarlo si sale de él', function () {
    $this->actingAs($this->ana)->postJson("/chat/{$this->group->id}/salir")->assertOk()->assertJsonPath('left', true);

    expect($this->group->hasParticipant($this->ana))->toBeFalse()
        ->and(($this->systemKeys)())->toBe(['group.left']);
    $this->actingAs($this->ana)->patchJson("/chat/{$this->group->id}/grupo", ['name' => 'Vuelvo'])->assertForbidden();
});

it('cualquier participante sale del grupo; quien no está, no', function () {
    $this->actingAs($this->luis)
        ->getJson('/chat/conversaciones')
        ->assertJsonPath('conversations.0.id', $this->group->id);

    $this->actingAs($this->luis)->postJson("/chat/{$this->group->id}/salir")->assertOk();
    $this->actingAs($this->luis)->getJson('/chat/conversaciones')->assertJsonCount(0, 'conversations');
    $this->actingAs($this->luis)->postJson("/chat/{$this->group->id}/salir")->assertForbidden();
    $this->actingAs($this->eva)->postJson("/chat/{$this->group->id}/salir")->assertForbidden();

    // El admin que modera sin participar tampoco «sale».
    $this->actingAs($this->admin)->postJson("/chat/{$this->group->id}/salir")->assertForbidden();
});

it('valida los cambios: nombre, personas internas activas que no estén y no quitarse a uno mismo', function () {
    $client = User::factory()->client()->create();
    $inactive = User::factory()->employee()->inactive()->create();

    $this->actingAs($this->ana)->patchJson("/chat/{$this->group->id}/grupo", ['name' => '   '])->assertJsonValidationErrors('name');
    $this->actingAs($this->ana)->postJson("/chat/{$this->group->id}/participantes", ['user_ids' => [$client->id, $inactive->id, $this->luis->id]])
        ->assertJsonValidationErrors('user_ids');
    $this->actingAs($this->ana)->deleteJson("/chat/{$this->group->id}/participantes/{$this->ana->id}")->assertJsonValidationErrors('user');
    $this->actingAs($this->ana)->deleteJson("/chat/{$this->group->id}/participantes/{$this->eva->id}")->assertJsonValidationErrors('user');

    expect(($this->systemKeys)())->toBe([]);
});

it('ni los proyectos ni las directas se gestionan como grupos', function () {
    $project = Project::factory()->create();
    $project->addMember($this->ana);
    $projectChat = $this->directory->forProject($project);
    $dm = $this->directory->direct($this->ana, $this->luis);

    $this->actingAs($this->admin)->patchJson("/chat/{$projectChat->id}/grupo", ['name' => 'X'])->assertForbidden();
    $this->actingAs($this->ana)->patchJson("/chat/{$projectChat->id}/grupo", ['name' => 'X'])->assertForbidden();
    $this->actingAs($this->ana)->patchJson("/chat/{$dm->id}/grupo", ['name' => 'X'])->assertForbidden();
    $this->actingAs($this->ana)->postJson("/chat/{$projectChat->id}/salir")->assertForbidden();
    $this->actingAs($this->ana)->postJson("/chat/{$dm->id}/salir")->assertForbidden();

    // El admin no distingue una directa ajena (403 antes de validar).
    $this->actingAs($this->admin)->postJson("/chat/{$dm->id}/participantes", [])->assertForbidden();
});
