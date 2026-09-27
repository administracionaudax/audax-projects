<?php

use App\Enums\PortalEntryVisibility;
use App\Enums\PortalPersonDisplay;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Ajustes del portal (SPEC §11, D-064, D-065):
| - del cliente, en su ficha: cómo se nombra a las personas, qué horas ve y los avisos de bolsa
|   por email. Son datos del cliente: admin y responsables (ClientPolicy::update),
| - de cada proyecto, en sus ajustes: abrir la vista del proyecto, las horas por tarea y el Gantt.
|   Quien gestiona el proyecto (ProjectPolicy::update).
*/

beforeEach(function () {
    $this->client = Client::factory()->create();
    $this->admin = userWithRole('admin');
    $this->manager = userWithRole('employee');
    $this->project = Project::factory()->create(['client_id' => $this->client->id, 'owner_user_id' => $this->manager->id]);
    $this->settings = [
        'portal_person_display' => 'initials',
        'portal_entry_visibility' => 'submitted',
        'portal_notify_thresholds' => true,
    ];
    $this->projectSettings = [
        'portal_project_visible' => true,
        'portal_show_task_hours' => true,
        'portal_gantt_visible' => true,
    ];
});

test('el admin cambia los ajustes del portal del cliente y queda en su auditoría', function () {
    $this->actingAs($this->admin)
        ->put("/clientes/{$this->client->id}/portal/ajustes", $this->settings)
        ->assertRedirect()
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', __('portal.access.settings_saved'));

    $client = $this->client->fresh();
    expect($client->portal_person_display)->toBe(PortalPersonDisplay::Initials)
        ->and($client->portal_entry_visibility)->toBe(PortalEntryVisibility::Submitted)
        ->and($client->portal_notify_thresholds)->toBeTrue();

    $this->assertDatabaseHas('activity_log', ['subject_id' => $this->client->id, 'log_name' => 'clients', 'event' => 'updated']);
});

test('los ajustes del cliente se validan: nunca borradores ni valores desconocidos', function (array $payload, string $field) {
    $this->actingAs($this->admin)
        ->put("/clientes/{$this->client->id}/portal/ajustes", [...$this->settings, ...$payload])
        ->assertSessionHasErrors($field);

    expect($this->client->fresh()->portal_entry_visibility)->toBe(PortalEntryVisibility::Approved);
})->with([
    'borradores' => [['portal_entry_visibility' => 'draft'], 'portal_entry_visibility'],
    'estado desconocido' => [['portal_entry_visibility' => 'all'], 'portal_entry_visibility'],
    'persona desconocida' => [['portal_person_display' => 'email'], 'portal_person_display'],
    'sin persona' => [['portal_person_display' => null], 'portal_person_display'],
    'aviso no booleano' => [['portal_notify_thresholds' => 'quizá'], 'portal_notify_thresholds'],
]);

test('los ajustes del cliente los cambian admin y responsables; ni gestores ni empleados', function (string $actor, int $status) {
    $user = match ($actor) {
        'admin' => $this->admin,
        'responsable' => userWithRole('department_manager'),
        'gestor' => $this->manager,
        'empleado' => userWithRole('employee'),
        'cliente' => User::factory()->portalOf($this->client)->create(),
    };

    $response = $this->actingAs($user)->put("/clientes/{$this->client->id}/portal/ajustes", $this->settings);

    if ($actor === 'cliente') {
        $response->assertRedirect(route('portal.home'));
    } else {
        $response->assertStatus($status);
    }

    expect($this->client->fresh()->portal_notify_thresholds)->toBe($status === 302 && $actor !== 'cliente');
})->with([
    'admin' => ['admin', 302],
    'responsable' => ['responsable', 302],
    'gestor de un proyecto del cliente' => ['gestor', 403],
    'empleado' => ['empleado', 403],
    'cliente' => ['cliente', 302],
]);

test('quien gestiona el proyecto lo abre al portal con sus horas por tarea y su Gantt', function (string $actor) {
    $user = match ($actor) {
        'admin' => $this->admin,
        'responsable' => userWithRole('department_manager'),
        'gestor' => $this->manager,
    };

    $this->actingAs($user)
        ->put("/proyectos/{$this->project->id}/portal", $this->projectSettings)
        ->assertRedirect()
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', __('portal.access.project_saved'));

    $project = $this->project->fresh();
    expect($project->portal_project_visible)->toBeTrue()
        ->and($project->portal_show_task_hours)->toBeTrue()
        ->and($project->portal_gantt_visible)->toBeTrue();

    $this->assertDatabaseHas('activity_log', ['subject_id' => $this->project->id, 'log_name' => 'projects', 'event' => 'updated']);
})->with(['admin', 'responsable', 'gestor']);

test('ni un empleado, ni un gestor de otro proyecto, ni un cliente abren un proyecto al portal', function () {
    $other = userWithRole('employee');
    Project::factory()->create(['client_id' => $this->client->id, 'owner_user_id' => $other->id]);

    $this->actingAs(userWithRole('employee'))->put("/proyectos/{$this->project->id}/portal", $this->projectSettings)->assertForbidden();
    $this->actingAs($other)->put("/proyectos/{$this->project->id}/portal", $this->projectSettings)->assertForbidden();
    $this->actingAs(User::factory()->portalOf($this->client)->create())
        ->put("/proyectos/{$this->project->id}/portal", $this->projectSettings)
        ->assertRedirect(route('portal.home'));

    expect($this->project->fresh()->portal_project_visible)->toBeFalse();
});

test('las horas por tarea solo quedan activas con la vista del proyecto abierta; el Gantt se abre aparte', function () {
    $this->actingAs($this->admin)
        ->put("/proyectos/{$this->project->id}/portal", ['portal_project_visible' => false, 'portal_show_task_hours' => true, 'portal_gantt_visible' => true])
        ->assertSessionHasNoErrors();

    $project = $this->project->fresh();
    expect($project->portal_project_visible)->toBeFalse()
        ->and($project->portal_show_task_hours)->toBeFalse()
        ->and($project->portal_gantt_visible)->toBeTrue();
});

test('un proyecto sin cliente no se abre al portal y los valores se validan', function () {
    $internal = Project::factory()->internal()->create(['owner_user_id' => $this->manager->id]);

    $this->actingAs($this->admin)
        ->put("/proyectos/{$internal->id}/portal", $this->projectSettings)
        ->assertSessionHasErrors(['portal_project_visible' => __('portal.access.errors.no_client')]);

    $this->actingAs($this->admin)
        ->put("/proyectos/{$internal->id}/portal", ['portal_project_visible' => false, 'portal_show_task_hours' => false, 'portal_gantt_visible' => false])
        ->assertSessionHasNoErrors();

    $this->actingAs($this->admin)
        ->put("/proyectos/{$this->project->id}/portal", ['portal_project_visible' => 'sí'])
        ->assertSessionHasErrors(['portal_project_visible', 'portal_show_task_hours', 'portal_gantt_visible']);

    expect($internal->fresh()->portal_project_visible)->toBeFalse();
});

test('los ajustes del proyecto llevan la sección del portal con su cliente y sus usuarios activos', function () {
    User::factory()->portalOf($this->client)->count(2)->create();
    User::factory()->portalOf($this->client)->create(['is_active' => false]);
    $this->project->update(['portal_project_visible' => true, 'portal_gantt_visible' => true]);

    $this->actingAs($this->manager)
        ->get("/proyectos/{$this->project->id}/ajustes")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/settings')
            ->where('portal', [
                'project_visible' => true,
                'show_task_hours' => false,
                'gantt_visible' => true,
                'client' => ['id' => $this->client->id, 'name' => $this->client->name, 'is_active' => true],
                'active_users' => 2,
            ]));

    $internal = Project::factory()->internal()->create(['owner_user_id' => $this->manager->id]);
    $this->actingAs($this->manager)
        ->get("/proyectos/{$internal->id}/ajustes")
        ->assertInertia(fn (Assert $page) => $page->where('portal.client', null)->where('portal.active_users', 0));
});

test('la ficha del cliente resume los proyectos abiertos al portal', function () {
    $this->project->update(['portal_project_visible' => true, 'portal_show_task_hours' => true]);
    $gantt = Project::factory()->create(['client_id' => $this->client->id, 'name' => 'Aaa Gantt', 'portal_gantt_visible' => true]);
    Project::factory()->create(['client_id' => $this->client->id, 'name' => 'Cerrado']);
    Project::factory()->create(['portal_project_visible' => true]);

    $this->actingAs($this->admin)
        ->get("/clientes/{$this->client->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->has('portal.projects', 2)
            ->where('portal.projects.0.id', $gantt->id)
            ->where('portal.projects.0.project_visible', false)
            ->where('portal.projects.0.gantt_visible', true)
            ->where('portal.projects.1.id', $this->project->id)
            ->where('portal.projects.1.show_task_hours', true)
            ->where('portal.options.person_display', ['name', 'initials', 'team'])
            ->where('portal.options.entry_visibility', ['approved', 'submitted']));
});
