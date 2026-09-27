<?php

use App\Models\Client;
use App\Models\Department;
use App\Models\Project;
use App\Models\User;
use App\Providers\ReportsServiceProvider;
use Illuminate\Support\Facades\Route;

/*
| SEC-02: las exportaciones ?formato= de los informes tienen un límite con nombre (30 por minuto y
| usuario) que solo cuenta las peticiones con formato: ver los dashboards no gasta el cupo.
*/

it('las rutas de los informes con exportación llevan el limitador de exportaciones', function (string $name) {
    $route = Route::getRoutes()->getByName($name);

    expect($route)->not->toBeNull()
        ->and($route->gatherMiddleware())->toContain('throttle:'.ReportsServiceProvider::EXPORT_LIMITER);
})->with([
    'reports.direction', 'reports.department', 'reports.person', 'reports.client',
    'reports.project', 'reports.billing', 'reports.detail',
]);

it('limita a 30 exportaciones por minuto y usuario, sin contar las visitas a la página', function () {
    $admin = User::factory()->admin()->create();
    $other = User::factory()->admin()->create();

    // Las páginas no cuentan.
    foreach (range(1, 35) as $ignored) {
        $this->actingAs($admin)->get('/informes/detalle')->assertOk();
    }

    foreach (range(1, 30) as $ignored) {
        $this->actingAs($admin)->get('/informes/detalle?formato=csv')->assertOk();
    }

    $this->actingAs($admin)->get('/informes/detalle?formato=csv')->assertStatus(429);
    // La página sigue abierta y otro usuario tiene su propio cupo.
    $this->actingAs($admin)->get('/informes/detalle')->assertOk();
    $this->actingAs($other)->get('/informes/detalle?formato=xlsx')->assertOk();

    // Pasado el minuto, vuelve a poder exportar.
    $this->travel(61)->seconds();
    $this->actingAs($admin)->get('/informes/detalle?formato=csv')->assertOk();
});

it('el cupo es común a todos los informes', function () {
    $admin = User::factory()->admin()->create();
    $department = Department::factory()->create();
    $client = Client::factory()->create();
    $project = Project::factory()->create(['client_id' => $client->id]);

    $urls = [
        '/informes/direccion?formato=csv',
        "/informes/departamentos/{$department->id}?formato=csv",
        "/informes/personas/{$admin->id}?formato=csv",
        "/informes/clientes/{$client->id}?formato=csv",
        "/informes/proyectos/{$project->id}?formato=csv",
        "/informes/facturacion?formato=csv&cliente[]={$client->id}",
    ];

    foreach (range(0, 29) as $n) {
        $this->actingAs($admin)->get($urls[$n % count($urls)])->assertOk();
    }

    $this->actingAs($admin)->get('/informes/detalle?formato=csv')->assertStatus(429);
});
