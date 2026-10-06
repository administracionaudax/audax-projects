<?php

use App\Domain\Weeklies\AppModules;
use App\Enums\AppModule;
use App\Enums\Permission;
use App\Models\Allocation;
use App\Models\Client;
use App\Models\Department;
use App\Models\ForecastProject;
use App\Models\Project;
use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Permisos de la previsión (docs/PLAN-CARGAS.md §8 con P4 a y P8 b; D-284): matriz por rol de las
| páginas y de las escrituras, el módulo `forecast` (apagado por defecto, modo de prueba) y los
| colaboradores externos y los clientes, siempre fuera.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 09:00:00', 'Europe/Madrid'));
    enableForecast();

    $this->department = Department::factory()->create();
    $this->admin = userWithRole('admin');
    $this->manager = userWithRole('department_manager', ['department_id' => $this->department->id]);
    $this->department->managers()->attach($this->manager->id);
    $this->employee = userWithRole('employee', ['department_id' => $this->department->id]);
    $this->projectManager = userWithRole('employee', ['department_id' => $this->department->id]);
    $this->collaborator = User::factory()->collaborator()->create();
    $this->client = User::factory()->portalOf(Client::factory()->create())->create();

    $this->project = Project::factory()->create(['owner_user_id' => $this->projectManager->id]);
    $this->project->addMember($this->collaborator);
    $this->forecast = ForecastProject::factory()->create(['owner_user_id' => $this->manager->id]);

    $this->actors = fn (): array => [
        'admin' => $this->admin,
        'responsable' => $this->manager,
        'empleado' => $this->employee,
        'gestor del proyecto' => $this->projectManager,
        'colaborador' => $this->collaborator,
        'cliente' => $this->client,
    ];
});

dataset('páginas', [
    //                                    admin resp. empl. gestor colab. cliente
    'previsión' => ['/prevision', [200, 200, 403, 403, 403, 302]],
    'mi carga' => ['/prevision/mi-carga', [200, 200, 200, 200, 403, 302]],
    'previstos' => ['/prevision/proyectos', [200, 200, 403, 403, 403, 302]],
    'ficha del previsto' => ['/prevision/proyectos/{forecast}', [200, 200, 403, 403, 403, 302]],
    'planificación' => ['/proyectos/{project}/planificacion', [200, 200, 403, 200, 403, 302]],
]);

it('matriz de las páginas por rol', function (string $uri, array $expected) {
    $uri = str_replace(['{forecast}', '{project}'], [(string) $this->forecast->id, (string) $this->project->id], $uri);

    foreach (array_values(($this->actors)()) as $index => $actor) {
        $status = $this->actingAs($actor)->get($uri)->getStatusCode();

        expect($status)->toBe($expected[$index], "{$uri} como ".array_keys(($this->actors)())[$index]);
    }
})->with('páginas');

it('sin sesión, al login', function () {
    $this->get('/prevision')->assertRedirect(route('login'));
});

it('con el módulo apagado, 404 para todos salvo los admins en modo de prueba', function () {
    enableForecast(false);

    foreach (['/prevision', '/prevision/mi-carga', '/prevision/proyectos', "/proyectos/{$this->project->id}/planificacion"] as $uri) {
        $this->actingAs($this->admin)->get($uri)->assertNotFound();
        $this->actingAs($this->manager)->get($uri)->assertNotFound();
    }

    Setting::set('modules_preview', true);
    $this->actingAs($this->admin)->get('/prevision')->assertOk();
    $this->actingAs($this->manager)->get('/prevision')->assertNotFound();
    expect(AppModules::enabled(AppModule::Forecast))->toBeFalse();
});

it('el módulo viene apagado por defecto en una instalación nueva', function () {
    Setting::query()->where('key', 'modules')->delete();

    expect(AppModules::enabled(AppModule::Forecast))->toBeFalse()
        ->and(AppModules::enabled(AppModule::DayPlan))->toBeTrue();
});

it('la migración lo deja apagado donde ya había módulos guardados, y no toca el resto', function () {
    $migration = require database_path('migrations/2026_10_08_090002_add_forecast_off_to_stored_modules.php');

    Setting::set('modules', ['weeklies' => false, 'day_plan' => true]);
    $migration->up();
    expect(Setting::get('modules'))->toBe(['weeklies' => false, 'day_plan' => true, 'forecast' => false]);

    Setting::set('modules', ['weeklies' => true, 'forecast' => true]);
    $migration->up();
    expect(AppModules::enabled(AppModule::Forecast))->toBeTrue();
});

it('manage-forecast: admins y responsables por defecto; sin él, el responsable ve pero no crea; con él, un empleado ve y crea', function () {
    $data = ['name' => 'Previsto', 'prospect_name' => 'Cliente nuevo'];

    $this->actingAs($this->admin)->post('/prevision/proyectos', $data)->assertRedirect();
    $this->actingAs($this->manager)->post('/prevision/proyectos', $data)->assertRedirect();
    $this->actingAs($this->employee)->post('/prevision/proyectos', $data)->assertForbidden();
    $this->actingAs($this->collaborator)->post('/prevision/proyectos', $data)->assertForbidden();

    $this->manager->revokePermissionTo(Permission::ManageForecast->value);
    $this->manager->roles->each->revokePermissionTo(Permission::ManageForecast->value);
    $this->manager->refresh();
    $this->actingAs($this->manager)->get('/prevision')->assertOk();
    $this->actingAs($this->manager)->post('/prevision/proyectos', $data)->assertForbidden();

    $this->employee->givePermissionTo(Permission::ManageForecast->value);
    $this->actingAs($this->employee)->get('/prevision')->assertOk();
    $this->actingAs($this->employee)->post('/prevision/proyectos', $data)->assertRedirect();

    expect(ForecastProject::query()->count())->toBe(4);
});

it('las asignaciones de un proyecto real: quien lo gestiona; nunca un empleado ni en un proyecto archivado', function () {
    $data = ['user_id' => $this->employee->id, 'mode' => 'total', 'minutes' => 600, 'start_date' => '2026-11-02', 'end_date' => '2026-11-06'];
    $uri = "/proyectos/{$this->project->id}/asignaciones";

    $this->actingAs($this->projectManager)->post($uri, $data)->assertRedirect()->assertSessionHasNoErrors();
    $this->actingAs($this->manager)->post($uri, $data)->assertRedirect()->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post($uri, $data)->assertRedirect()->assertSessionHasNoErrors();
    $this->actingAs($this->employee)->post($uri, $data)->assertForbidden();
    $this->actingAs($this->collaborator)->post($uri, $data)->assertForbidden();

    $archived = Project::factory()->archived()->create(['owner_user_id' => $this->projectManager->id]);
    $this->actingAs($this->projectManager)->post("/proyectos/{$archived->id}/asignaciones", $data)->assertForbidden();

    $allocation = Allocation::query()->where('project_id', $this->project->id)->firstOrFail();
    $this->actingAs($this->projectManager)->put("/prevision/asignaciones/{$allocation->id}", [...$data, 'minutes' => 900])->assertRedirect();
    expect($allocation->fresh()->minutes)->toBe(900);
    $this->actingAs($this->employee)->put("/prevision/asignaciones/{$allocation->id}", $data)->assertForbidden();
    $this->actingAs($this->employee)->delete("/prevision/asignaciones/{$allocation->id}")->assertForbidden();
    $this->actingAs($this->projectManager)->delete("/prevision/asignaciones/{$allocation->id}")->assertRedirect();
    expect(Allocation::query()->whereKey($allocation->id)->exists())->toBeFalse();
});

it('las asignaciones de un previsto: manage-forecast; un gestor de proyecto, no', function () {
    $data = ['department_id' => $this->department->id, 'mode' => 'percent', 'percent' => 50, 'start_date' => '2026-11-02', 'end_date' => '2026-11-30'];
    $uri = "/prevision/proyectos/{$this->forecast->id}/asignaciones";

    $this->actingAs($this->manager)->post($uri, $data)->assertRedirect()->assertSessionHasNoErrors();
    $this->actingAs($this->projectManager)->post($uri, $data)->assertForbidden();

    $gap = Allocation::query()->where('forecast_project_id', $this->forecast->id)->firstOrFail();
    $this->actingAs($this->projectManager)->post("/prevision/asignaciones/{$gap->id}/asignar", ['user_id' => $this->employee->id])->assertForbidden();
    $this->actingAs($this->manager)->post("/prevision/asignaciones/{$gap->id}/asignar", ['user_id' => $this->employee->id])->assertRedirect();
    expect($gap->fresh()->user_id)->toBe($this->employee->id);

    // Ya no es un hueco: no se vuelve a «asignar».
    $this->actingAs($this->manager)->post("/prevision/asignaciones/{$gap->id}/asignar", ['user_id' => $this->manager->id])->assertForbidden();
});

it('los errores de una asignación vuelven como errores de validación', function () {
    $this->actingAs($this->manager)
        ->post("/prevision/proyectos/{$this->forecast->id}/asignaciones", ['user_id' => User::factory()->client()->create()->id, 'mode' => 'total', 'minutes' => 60, 'start_date' => '2026-11-02', 'end_date' => '2026-11-02'])
        ->assertSessionHasErrors('user_id');

    $this->actingAs($this->manager)
        ->post("/prevision/proyectos/{$this->forecast->id}/asignaciones", ['mode' => 'percent', 'start_date' => '2026-11-02'])
        ->assertSessionHasErrors(['user_id', 'percent']);
});

it('confirmar, perdido, reabrir y borrar, con manage-forecast; desvincular, solo un admin', function () {
    $base = "/prevision/proyectos/{$this->forecast->id}";

    $this->actingAs($this->employee)->post("{$base}/confirmar")->assertForbidden();
    $this->actingAs($this->manager)->post("{$base}/confirmar")->assertRedirect();
    $this->actingAs($this->manager)->post("{$base}/confirmar")->assertForbidden();
    $this->actingAs($this->manager)->post("{$base}/perdido", ['reason' => 'Precio'])->assertRedirect();
    expect($this->forecast->fresh()->lost_reason)->toBe('Precio');
    $this->actingAs($this->manager)->post("{$base}/reabrir")->assertRedirect();

    $this->actingAs($this->manager)->post("{$base}/vincular", ['project_id' => $this->project->id])->assertRedirect()->assertSessionHasNoErrors();
    expect($this->forecast->fresh()->project_id)->toBe($this->project->id);

    $this->actingAs($this->manager)->delete("{$base}/vinculo")->assertForbidden();
    $this->actingAs($this->manager)->delete($base)->assertForbidden();
    $this->actingAs($this->admin)->delete("{$base}/vinculo")->assertRedirect();
    $this->actingAs($this->manager)->delete($base)->assertRedirect(route('forecast.projects.index'));
    expect(ForecastProject::query()->whereKey($this->forecast->id)->exists())->toBeFalse();
});

it('vincular exige gestionar el proyecto real', function () {
    $other = Project::factory()->create();
    $this->employee->givePermissionTo(Permission::ManageForecast->value);

    $this->actingAs($this->employee)
        ->post("/prevision/proyectos/{$this->forecast->id}/vincular", ['project_id' => $other->id])
        ->assertSessionHasErrors('project_id');
});

it('crear el proyecto real desde el previsto: admins y responsables, con las reglas del alta', function () {
    $client = Client::factory()->create();
    Allocation::factory()->forForecast($this->forecast)->forUser($this->employee)->create();
    $data = ['client_id' => $client->id, 'name' => 'Hotel Mar Azul', 'color' => '#0171FF', 'billing_type' => 'time_and_materials', 'status' => 'planned', 'copy_allocations' => true];
    $uri = "/prevision/proyectos/{$this->forecast->id}/crear-proyecto";

    $this->employee->givePermissionTo(Permission::ManageForecast->value);
    $this->actingAs($this->employee)->post($uri, $data)->assertForbidden();
    $this->actingAs($this->manager)->post($uri, [...$data, 'client_id' => null])->assertSessionHasErrors('client_id');

    $response = $this->actingAs($this->manager)->post($uri, $data);
    $project = Project::query()->where('name', 'Hotel Mar Azul')->firstOrFail();

    $response->assertRedirect(route('projects.show', $project));
    expect($project->hasMember($this->employee))->toBeTrue()
        ->and($this->forecast->fresh()->project_id)->toBe($project->id)
        ->and(Allocation::query()->where('project_id', $project->id)->count())->toBe(1);
});

it('el importe estimado solo llega a quien tiene view-financials', function () {
    $this->forecast->update(['estimated_amount' => '18000.00']);

    $this->actingAs($this->admin)->get("/prevision/proyectos/{$this->forecast->id}")
        ->assertInertia(fn (Assert $page) => $page->where('forecast.estimated_amount', '18000.00'));
    $this->actingAs($this->manager)->get("/prevision/proyectos/{$this->forecast->id}")
        ->assertInertia(fn (Assert $page) => $page->where('forecast.estimated_amount', null));
});
