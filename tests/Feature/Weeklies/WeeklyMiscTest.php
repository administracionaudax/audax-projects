<?php

use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use App\Models\WeeklyCycle;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/*
| Piezas menores de 10.2: «Unirme a proyectos» y «Dejar proyecto» (F-034 y F-133, D-156), «Mis
| clientes» (F-033), el puesto en el alta (F-026), la versión de la interfaz (F-013) y la bienvenida
| al entrar (F-012).
*/

beforeEach(function () {
    $this->me = userWithRole('employee');
});

it('me uno a varios proyectos abiertos de clientes activos, como miembro (nunca gestor)', function () {
    $a = Project::factory()->create();
    $b = Project::factory()->create();
    Project::factory()->withMembers([$this->me])->create();

    $this->actingAs($this->me)
        ->post('/mi-espacio/proyectos', ['project_ids' => [$a->id, $b->id]])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', trans_choice('weeklies.flash.joined', 2, ['count' => 2]));

    expect($a->hasMember($this->me))->toBeTrue()
        ->and($b->hasMember($this->me))->toBeTrue()
        ->and((bool) $a->members()->whereKey($this->me->id)->first()?->membership?->is_manager)->toBeFalse();

    $this->actingAs($this->me)
        ->post('/mi-espacio/proyectos', ['project_ids' => [$a->id]])
        ->assertInertiaFlash('toast.message', trans_choice('weeklies.flash.joined', 0, ['count' => 0]));
});

it('no me uno a proyectos archivados, de clientes inactivos, internos o inexistentes', function (Closure $project) {
    $this->actingAs($this->me)
        ->postJson('/mi-espacio/proyectos', ['project_ids' => [$project()]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['project_ids' => __('weeklies.validation.projects')]);
})->with([
    'archivado' => [fn () => Project::factory()->archived()->create()->id],
    'cliente inactivo' => [fn () => Project::factory()->create(['client_id' => Client::factory()->create(['is_active' => false])->id])->id],
    'interno' => [fn () => Project::factory()->internal()->create()->id],
    'inexistente' => [fn () => 999999],
]);

it('dejo un proyecto del que soy miembro; no uno que gestiono', function () {
    $member = Project::factory()->withMembers([$this->me])->create(['code' => 'BH42']);
    $owned = Project::factory()->create(['owner_user_id' => $this->me->id]);

    $this->actingAs($this->me)
        ->delete("/mi-espacio/proyectos/{$member->id}")
        ->assertInertiaFlash('toast.message', __('weeklies.flash.left', ['project' => 'BH42']));

    expect($member->hasMember($this->me))->toBeFalse();

    $this->actingAs($this->me)
        ->deleteJson("/mi-espacio/proyectos/{$owned->id}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['project' => __('weeklies.validation.manager_cannot_leave')]);

    $this->actingAs($this->me)->deleteJson('/mi-espacio/proyectos/'.Project::factory()->create()->id)->assertNotFound();
});

it('unirse y dejar quedan en la auditoría del proyecto', function () {
    $project = Project::factory()->create();

    $this->actingAs($this->me)->post('/mi-espacio/proyectos', ['project_ids' => [$project->id]]);
    $this->actingAs($this->me)->delete("/mi-espacio/proyectos/{$project->id}");

    expect(Activity::query()->where('subject_type', $project->getMorphClass())->where('subject_id', $project->id)->where('causer_id', $this->me->id)->count())->toBe(2);
});

it('un colaborador externo no se une a proyectos desde la Weekly', function () {
    $this->actingAs(User::factory()->collaborator()->create())
        ->postJson('/mi-espacio/proyectos', ['project_ids' => [Project::factory()->create()->id]])
        ->assertForbidden();
});

it('«Mis clientes»: los que gestiono y en los que colaboro; «Unirme» llega solo al pedirlo', function () {
    WeeklyCycle::factory()->active()->create();
    $owned = Project::factory()->create(['owner_user_id' => $this->me->id, 'client_id' => Client::factory()->create(['name' => 'Gestiono'])->id]);
    $member = Project::factory()->withMembers([$this->me])->create(['client_id' => Client::factory()->create(['name' => 'Colaboro'])->id]);
    $free = Project::factory()->create(['client_id' => Client::factory()->create(['name' => 'Libre'])->id]);

    $this->actingAs($this->me)->get('/weeklies')->assertInertia(fn (Assert $page) => $page
        ->where('my_clients.owned.0.name', 'Gestiono')
        ->where('my_clients.owned.0.projects.0.id', $owned->id)
        ->where('my_clients.owned.0.projects.0.can_leave', false)
        ->where('my_clients.member.0.name', 'Colaboro')
        ->where('my_clients.member.0.projects.0.id', $member->id)
        ->where('my_clients.member.0.projects.0.can_leave', true)
        ->missing('joinable_projects')
        ->reloadOnly('joinable_projects', fn (Assert $reload) => $reload
            ->has('joinable_projects', 1)
            ->where('joinable_projects.0.id', $free->id)
            ->where('joinable_projects.0.client.name', 'Libre')));
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
