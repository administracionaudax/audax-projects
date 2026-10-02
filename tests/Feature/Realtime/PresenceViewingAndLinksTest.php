<?php

use App\Broadcasting\ConversationViewers;
use App\Domain\Chat\ConversationDirectory;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Route;

/*
| Presencia sin tiempo real (latidos cada minuto), «tengo la conversación abierta» (no avisar a
| quien ya la lee) y el enlace estable de los avisos a la conversación.
*/

beforeEach(function () {
    $directory = app(ConversationDirectory::class);
    $this->ana = User::factory()->employee()->create(['name' => 'Ana']);
    $this->luis = User::factory()->employee()->create(['name' => 'Luis']);
    $this->project = Project::factory()->create();
    $this->project->addMember($this->ana);
    $this->chat = $directory->forProject($this->project);
    $this->dm = $directory->direct($this->ana, $this->luis);
    $this->beat = fn (User $user, string $status = 'online') => $this->actingAs($user)->postJson('/tiempo-real/presencia', ['status' => $status]);
});

it('presencia sin tiempo real: en línea, ausente y desconectado', function () {
    ($this->beat)($this->ana)->assertOk()->assertExactJson(['users' => [(string) $this->ana->id => 'online']]);

    $response = ($this->beat)($this->luis, 'away')->assertOk();
    expect($response->json('users'))->toBe([(string) $this->ana->id => 'online', (string) $this->luis->id => 'away']);

    // Sin latidos «en línea» en 90 s, ausente; sin ningún latido en 150 s, desconectado.
    $this->travel(100)->seconds();
    ($this->beat)($this->luis, 'away');
    expect(($this->beat)($this->luis, 'away')->json('users'))->toBe([(string) $this->ana->id => 'away', (string) $this->luis->id => 'away']);

    $this->travel(60)->seconds();
    expect(($this->beat)($this->luis, 'away')->json('users'))->toBe([(string) $this->luis->id => 'away']);
});

it('con dos pestañas, una activa y otra inactiva, gana la activa', function () {
    ($this->beat)($this->ana, 'online');
    ($this->beat)($this->ana, 'away');

    expect(($this->beat)($this->luis)->json('users')[(string) $this->ana->id])->toBe('online');
});

it('la presencia solo muestra a internos activos y valida el estado', function () {
    ($this->beat)($this->ana);
    $this->ana->update(['is_active' => false]);
    cache()->forget('presence.user-ids');

    expect(($this->beat)($this->luis)->json('users'))->toBe([(string) $this->luis->id => 'online']);
    ($this->beat)($this->luis, 'invisible')->assertUnprocessable()->assertJsonValidationErrors('status');
    $this->actingAs(User::factory()->client()->create())->postJson('/tiempo-real/presencia', ['status' => 'online'])->assertForbidden();
});

it('«tengo la conversación abierta» exige poder verla y caduca en un minuto', function () {
    $viewers = app(ConversationViewers::class);
    $admin = User::factory()->admin()->create();
    $outsider = User::factory()->employee()->create();

    $this->actingAs($this->luis)->postJson("/tiempo-real/conversaciones/{$this->dm->id}/viendo")->assertNoContent();
    expect($viewers->isViewing($this->dm->id, $this->luis->id))->toBeTrue();

    $this->travel(61)->seconds();
    expect($viewers->isViewing($this->dm->id, $this->luis->id))->toBeFalse();

    $this->actingAs($outsider)->postJson("/tiempo-real/conversaciones/{$this->dm->id}/viendo")->assertForbidden();
    $this->actingAs($admin)->postJson("/tiempo-real/conversaciones/{$this->dm->id}/viendo")->assertForbidden();
    $this->actingAs($admin)->postJson("/tiempo-real/conversaciones/{$this->chat->id}/viendo")->assertNoContent();
    expect($viewers->viewing($this->dm->id, [$outsider->id, $admin->id]))->toBe([]);
});

it('cerrar la conversación retira la marca al momento', function () {
    $viewers = app(ConversationViewers::class);
    $this->actingAs($this->ana)->postJson("/tiempo-real/conversaciones/{$this->chat->id}/viendo")->assertNoContent();

    $this->actingAs($this->ana)->deleteJson("/tiempo-real/conversaciones/{$this->chat->id}/viendo")->assertNoContent();

    expect($viewers->viewing($this->chat->id, [$this->ana->id]))->toBe([]);
});

it('el enlace de los avisos lleva a la conversación y al mensaje en el chat', function () {
    $this->actingAs($this->luis)->get("/tiempo-real/conversaciones/{$this->dm->id}/abrir?mensaje=15")
        ->assertRedirect("/chat?conversacion={$this->dm->id}&mensaje=15");
    $this->actingAs($this->luis)->get("/tiempo-real/conversaciones/{$this->dm->id}/abrir")
        ->assertRedirect("/chat?conversacion={$this->dm->id}");
});

it('si el chat define la ruta de la conversación (chat.show), el enlace la usa', function () {
    Route::middleware('web')->get('chat/conversaciones/{conversation}', fn () => 'ok')->name('chat.show');
    Route::getRoutes()->refreshNameLookups();

    $this->actingAs($this->ana)->get("/tiempo-real/conversaciones/{$this->chat->id}/abrir?mensaje=3")
        ->assertRedirect("/chat/conversaciones/{$this->chat->id}?mensaje=3");
});

it('el enlace comprueba que aún puedes ver la conversación (D-071)', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs(User::factory()->employee()->create())->get("/tiempo-real/conversaciones/{$this->dm->id}/abrir")->assertForbidden();
    $this->actingAs($admin)->get("/tiempo-real/conversaciones/{$this->dm->id}/abrir")->assertForbidden();
    $this->actingAs($admin)->get("/tiempo-real/conversaciones/{$this->chat->id}/abrir")->assertRedirect();
    $this->actingAs(User::factory()->client()->create())->get("/tiempo-real/conversaciones/{$this->chat->id}/abrir")->assertRedirect('/portal');
});
