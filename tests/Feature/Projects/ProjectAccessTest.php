<?php

use App\Enums\ProjectStatus;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Permisos de proyectos (D-021, D-022, D-031, D-032): ver, todos los internos; crear, admins y
| responsables; gestionar, admins, responsables y gestores del proyecto; un empleado miembro no
| gestiona; un cliente nunca entra en la app interna; un invitado va al login.
*/

beforeEach(function () {
    $this->owner = userWithRole('employee');
    $this->project = Project::factory()->create(['owner_user_id' => $this->owner->id]);

    $this->actors = [
        'admin' => userWithRole('admin'),
        'department_manager' => userWithRole('department_manager'),
        'employee' => userWithRole('employee'),
        'member' => userWithRole('employee'),
        'manager' => $this->owner,
        'client' => userWithRole('client'),
    ];

    $this->project->addMember($this->actors['member']);
});

dataset('project_actors', [
    //                         ver   crear  gestionar
    'invitado' => ['guest', 302, 302, 302],
    'admin' => ['admin', 200, 200, 200],
    'responsable' => ['department_manager', 200, 200, 200],
    'empleado' => ['employee', 200, 403, 403],
    'empleado miembro' => ['member', 200, 403, 403],
    'gestor del proyecto' => ['manager', 200, 403, 200],
    'cliente' => ['client', 302, 302, 302],
]);

test('matriz de acceso a las páginas de proyectos', function (string $actor, int $view, int $create, int $manage) {
    if ($actor !== 'guest') {
        $this->actingAs($this->actors[$actor]);
    }

    $this->get('/proyectos')->assertStatus($view);
    $this->get("/proyectos/{$this->project->id}")->assertStatus($view);
    $this->get('/proyectos/nuevo')->assertStatus($create);
    $this->get("/proyectos/{$this->project->id}/ajustes")->assertStatus($manage);
})->with('project_actors');

test('un cliente siempre acaba en su portal y un invitado en el login', function () {
    $this->get('/proyectos')->assertRedirect(route('login'));

    $this->actingAs($this->actors['client'])
        ->get("/proyectos/{$this->project->id}")
        ->assertRedirect(route('portal.home'));
});

test('crear: solo admins y responsables (D-022)', function (string $actor, bool $allowed) {
    $client = Client::factory()->create();

    $response = $this->actingAs($this->actors[$actor])->post('/proyectos', [
        'name' => 'Web corporativa',
        'client_id' => $client->id,
        'billing_type' => 'time_and_materials',
        'status' => 'active',
        'color' => '#0171FF',
    ]);

    if ($allowed) {
        $response->assertRedirect()->assertSessionHasNoErrors();
        expect(Project::query()->where('name', 'Web corporativa')->exists())->toBeTrue();
    } else {
        $response->assertForbidden();
        expect(Project::query()->where('name', 'Web corporativa')->exists())->toBeFalse();
    }
})->with([
    'admin' => ['admin', true],
    'responsable' => ['department_manager', true],
    'empleado' => ['employee', false],
    'gestor de otro proyecto' => ['manager', false],
]);

test('gestionar (editar, miembros, archivar): admin, responsables y gestores; un empleado miembro no', function (string $actor, bool $allowed) {
    $this->actingAs($this->actors[$actor]);
    $status = $allowed ? 302 : 403;
    $newcomer = userWithRole('employee');

    $this->put("/proyectos/{$this->project->id}", [
        'name' => 'Nombre nuevo',
        'code' => $this->project->code,
        'client_id' => $this->project->client_id,
        'billing_type' => $this->project->billing_type->value,
        'status' => 'active',
        'color' => $this->project->color,
    ])->assertStatus($status);

    $this->post("/proyectos/{$this->project->id}/miembros", ['user_id' => $newcomer->id])->assertStatus($status);
    $this->post("/proyectos/{$this->project->id}/archivar")->assertStatus($status);

    expect($this->project->fresh()->name === 'Nombre nuevo')->toBe($allowed)
        ->and($this->project->hasMember($newcomer))->toBe($allowed)
        ->and($this->project->fresh()->status === ProjectStatus::Archived)->toBe($allowed);
})->with([
    'admin' => ['admin', true],
    'responsable' => ['department_manager', true],
    'gestor del proyecto' => ['manager', true],
    'empleado miembro' => ['member', false],
    'empleado' => ['employee', false],
]);

test('los clientes no pueden actuar sobre proyectos ni con peticiones directas', function () {
    $this->actingAs($this->actors['client']);

    $this->post("/proyectos/{$this->project->id}/archivar")->assertRedirect(route('portal.home'));
    $this->post('/proyectos', ['name' => 'X'])->assertRedirect(route('portal.home'));

    expect($this->project->fresh()->status)->toBe(ProjectStatus::Active);
});

test('la ficha dice a quién se le deja gestionar (pestaña Ajustes)', function (string $actor, bool $canManage) {
    $this->actingAs($this->actors[$actor])
        ->get("/proyectos/{$this->project->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/show')
            ->where('canManage', $canManage));
})->with([
    'admin' => ['admin', true],
    'gestor' => ['manager', true],
    'miembro' => ['member', false],
]);

test('un usuario desactivado pierde el acceso', function () {
    $user = User::factory()->employee()->inactive()->create();

    $this->actingAs($user)->get('/proyectos')->assertRedirect();
});
