<?php

use App\Domain\Chat\ConversationDirectory;
use App\Domain\Chat\MessageWriter;
use App\Domain\Weeklies\Insights\ClientInsights;
use App\Domain\Weeklies\Insights\PersonInsights;
use App\Domain\Weeklies\MyWeeklyClients;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\WeeklyCycle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Piezas menores de 10.2: «Unirme a clientes» y «Dejar cliente» (F-034 y F-133; D-221: una suscripción
| de la Weekly, nunca una membresía de proyecto), «Mis clientes» (F-033), el puesto en el alta (F-026),
| la versión de la interfaz (F-013) y la bienvenida al entrar (F-012).
*/

beforeEach(function () {
    $this->me = userWithRole('employee');
});

function weeklySubscriptions(): array
{
    return DB::table('weekly_client_subscriptions')->orderBy('client_id')->get(['client_id', 'user_id'])
        ->map(fn ($row): array => [(int) $row->client_id, (int) $row->user_id])->all();
}

it('me uno a varios clientes activos: una suscripción de la Weekly, idempotente', function () {
    $a = Client::factory()->create();
    $b = Client::factory()->create();

    $this->actingAs($this->me)
        ->post('/mi-espacio/clientes', ['client_ids' => [$a->id, $b->id]])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', trans_choice('weeklies.flash.joined', 2, ['count' => 2]));

    expect(weeklySubscriptions())->toBe([[$a->id, $this->me->id], [$b->id, $this->me->id]]);

    $this->actingAs($this->me)
        ->post('/mi-espacio/clientes', ['client_ids' => [$a->id]])
        ->assertInertiaFlash('toast.message', trans_choice('weeklies.flash.joined', 0, ['count' => 0]));

    expect(weeklySubscriptions())->toHaveCount(2);
});

it('unirme a un cliente NO me hace miembro de sus proyectos: ni chat, ni horas, ni tareas, ni bolsas (D-221)', function () {
    $owner = User::factory()->departmentManager()->create();
    $member = User::factory()->employee()->create();
    $client = Client::factory()->create();
    $project = Project::factory()->create(['client_id' => $client->id, 'owner_user_id' => $owner->id]);
    $project->addMember($member);
    $chat = app(ConversationDirectory::class)->forProject($project);
    app(MessageWriter::class)->post($member, $chat, 'Mensaje confidencial anterior');

    $this->actingAs($this->me)
        ->post('/mi-espacio/clientes', ['client_ids' => [$client->id]])
        ->assertSessionHasNoErrors();

    $this->me->refresh();
    expect($project->hasMember($this->me))->toBeFalse()
        ->and($chat->participants()->where('user_id', $this->me->id)->exists())->toBeFalse()
        ->and(Gate::forUser($this->me)->allows('logTime', $project))->toBeFalse()
        ->and(Gate::forUser($this->me)->allows('create', [Task::class, $project]))->toBeFalse()
        ->and(Gate::forUser($this->me)->allows('update', Task::factory()->create(['project_id' => $project->id])))->toBeFalse();

    $this->actingAs($this->me)->getJson("/chat/{$chat->id}/mensajes")->assertForbidden();
    // La ruta antigua de unirse a proyectos ya no existe.
    $this->actingAs($this->me)->postJson('/mi-espacio/proyectos', ['project_ids' => [$project->id]])->assertNotFound();
    expect($project->hasMember($this->me))->toBeFalse();
});

it('no me uno a clientes inactivos o inexistentes', function (Closure $client) {
    $this->actingAs($this->me)
        ->postJson('/mi-espacio/clientes', ['client_ids' => [$client()]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['client_ids' => __('weeklies.validation.clients')]);

    expect(weeklySubscriptions())->toBe([]);
})->with([
    'inactivo' => [fn () => Client::factory()->create(['is_active' => false])->id],
    'inexistente' => [fn () => 999999],
]);

it('dejo un cliente al que me uní; uno al que no me uní da 404 y no toca mis proyectos', function () {
    $client = Client::factory()->create(['name' => 'Manzanas']);
    $mine = Project::factory()->withMembers([$this->me])->create(['client_id' => $client->id]);
    DB::table('weekly_client_subscriptions')->insert(['client_id' => $client->id, 'user_id' => $this->me->id, 'created_at' => now(), 'updated_at' => now()]);

    $this->actingAs($this->me)
        ->delete("/mi-espacio/clientes/{$client->id}")
        ->assertInertiaFlash('toast.message', __('weeklies.flash.left', ['client' => 'Manzanas']));

    expect(weeklySubscriptions())->toBe([])
        ->and($mine->hasMember($this->me))->toBeTrue();

    $this->actingAs($this->me)->deleteJson("/mi-espacio/clientes/{$client->id}")->assertNotFound();
    expect($mine->hasMember($this->me))->toBeTrue();
});

it('un colaborador externo no se une a clientes desde la Weekly', function () {
    $this->actingAs(User::factory()->collaborator()->create())
        ->postJson('/mi-espacio/clientes', ['client_ids' => [Client::factory()->create()->id]])
        ->assertForbidden();

    expect(weeklySubscriptions())->toBe([]);
});

it('«Mis clientes»: los que gestiono y en los que colaboro (miembro o unido); «Unirme» llega solo al pedirlo', function () {
    WeeklyCycle::factory()->active()->create();
    $owned = Project::factory()->create(['owner_user_id' => $this->me->id, 'client_id' => Client::factory()->create(['name' => 'Gestiono'])->id]);
    $member = Project::factory()->withMembers([$this->me])->create(['client_id' => Client::factory()->create(['name' => 'Colaboro'])->id]);
    $followed = Client::factory()->create(['name' => 'Me uní']);
    $free = Project::factory()->create(['client_id' => Client::factory()->create(['name' => 'Libre'])->id, 'code' => 'LIB-WE1']);
    Client::factory()->create(['name' => 'Inactivo', 'is_active' => false]);
    DB::table('weekly_client_subscriptions')->insert(['client_id' => $followed->id, 'user_id' => $this->me->id, 'created_at' => now(), 'updated_at' => now()]);

    $this->actingAs($this->me)->get('/weeklies')->assertInertia(fn (Assert $page) => $page
        ->where('my_clients.owned.0.name', 'Gestiono')
        ->where('my_clients.owned.0.projects.0.id', $owned->id)
        ->where('my_clients.owned.0.subscribed', false)
        ->has('my_clients.member', 2)
        ->where('my_clients.member.0.name', 'Colaboro')
        ->where('my_clients.member.0.projects.0.id', $member->id)
        ->where('my_clients.member.0.subscribed', false)
        ->where('my_clients.member.1.name', 'Me uní')
        ->where('my_clients.member.1.projects', [])
        ->where('my_clients.member.1.subscribed', true)
        ->missing('joinable_clients')
        ->reloadOnly('joinable_clients', fn (Assert $reload) => $reload
            ->has('joinable_clients', 1)
            ->where('joinable_clients.0.name', 'Libre')
            ->where('joinable_clients.0.projects.0.code', 'LIB-WE1')));

    expect($free->hasMember($this->me))->toBeFalse();
});

it('un cliente al que me uno sale propuesto en «Mi weekly» y en el equipo del cliente de la Weekly', function () {
    $cycle = WeeklyCycle::factory()->active()->create();
    $client = Client::factory()->create(['name' => 'Manzanas']);
    DB::table('weekly_client_subscriptions')->insert(['client_id' => $client->id, 'user_id' => $this->me->id, 'created_at' => now(), 'updated_at' => now()]);

    expect(app(MyWeeklyClients::class)->for($this->me, $cycle)['proposed'])->toBe([$client->id]);

    $team = app(ClientInsights::class)->team($client);
    expect(array_column(array_column($team['members'], 'user'), 'id'))->toBe([$this->me->id])
        ->and($team['members'][0]['role'])->toBe('member')
        ->and($team['members'][0]['projects'])->toBe([])
        ->and(app(PersonInsights::class)->clients($this->me)['member'][0]['id'])->toBe($client->id);
});

it('el alta y la edición de personas guardan el puesto (F-026)', function () {
    $admin = userWithRole('admin');

    $this->actingAs($admin)
        ->post('/admin/usuarios', ['name' => 'Nueva', 'email' => 'nueva@audaxstudio.com', 'role' => 'employee', 'job_title' => '  Diseñadora UX  '])
        ->assertSessionHasNoErrors();

    expect(User::query()->where('email', 'nueva@audaxstudio.com')->value('job_title'))->toBe('Diseñadora UX');

    $this->actingAs($admin)
        ->postJson('/admin/usuarios', ['name' => 'Otra', 'email' => 'otra@audaxstudio.com', 'role' => 'employee', 'job_title' => str_repeat('a', 121)])
        ->assertJsonValidationErrors(['job_title']);
});

it('la versión de la interfaz es la de Inertia y no se guarda en caché (F-013)', function () {
    $this->actingAs($this->me)
        ->getJson('/version')
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonStructure(['version']);
});

it('la versión de la interfaz pide sesión', function () {
    $this->getJson('/version')->assertUnauthorized();
});

it('al entrar con el formulario se da la bienvenida (F-012)', function () {
    $user = userWithRole('employee', ['name' => 'Laura Gómez', 'password' => 'password-segura-123']);

    $this->post('/login', ['email' => $user->email, 'password' => 'password-segura-123'])
        ->assertInertiaFlash('toast.message', __('weeklies.welcome', ['name' => 'Laura']));
});
