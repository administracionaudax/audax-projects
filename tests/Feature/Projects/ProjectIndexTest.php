<?php

use App\Enums\ProjectStatus;
use App\Models\Client;
use App\Models\Department;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskType;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Listado de proyectos (SPEC §6, D-032, D-037): filtros por cliente, estado (por defecto sin
| archivados), tipo, responsable (owner), departamento implicado, búsqueda y «mis proyectos»;
| consumo agregado de las bolsas abiertas; paginación y sin N+1.
*/

beforeEach(function () {
    $this->viewer = userWithRole('employee');

    /** Códigos de los proyectos de la página, en orden. */
    $this->codes = function (array $query = []): array {
        $codes = [];

        $this->actingAs($this->viewer)
            ->get('/proyectos?'.http_build_query($query))
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$codes) {
                $page->component('projects/index');
                $codes = array_column($page->toArray()['props']['projects']['data'], 'code');
            });

        sort($codes);

        return $codes;
    };
});

test('por defecto no muestra los archivados; con «todos» sí, y se puede pedir un estado', function () {
    Project::factory()->create(['code' => 'ACTIVO']);
    Project::factory()->create(['code' => 'PAUSA', 'status' => ProjectStatus::OnHold]);
    Project::factory()->archived()->create(['code' => 'VIEJO']);

    expect(($this->codes)())->toBe(['ACTIVO', 'PAUSA'])
        ->and(($this->codes)(['estado' => 'todos']))->toBe(['ACTIVO', 'PAUSA', 'VIEJO'])
        ->and(($this->codes)(['estado' => 'archived']))->toBe(['VIEJO'])
        ->and(($this->codes)(['estado' => 'on_hold']))->toBe(['PAUSA'])
        ->and(($this->codes)(['estado' => 'inventado']))->toBe(['ACTIVO', 'PAUSA']);
});

test('filtra por cliente, tipo y responsable (el gestor principal, D-032)', function () {
    $acme = Client::factory()->create();
    $owner = userWithRole('employee');

    Project::factory()->hourBank()->create(['code' => 'ACME-WEB', 'client_id' => $acme->id, 'owner_user_id' => $owner->id]);
    Project::factory()->create(['code' => 'ACME-APP', 'client_id' => $acme->id]);
    Project::factory()->internal()->create(['code' => 'INTERNO']);

    expect(($this->codes)(['cliente' => $acme->id]))->toBe(['ACME-APP', 'ACME-WEB'])
        ->and(($this->codes)(['tipo' => 'internal']))->toBe(['INTERNO'])
        ->and(($this->codes)(['tipo' => 'hour_bank']))->toBe(['ACME-WEB'])
        ->and(($this->codes)(['responsable' => $owner->id]))->toBe(['ACME-WEB']);
});

test('departamento implicado: un miembro, una bolsa o un tipo de tarea de ese departamento (D-037)', function () {
    $design = Department::factory()->create(['name' => 'Diseño']);
    $marketing = Department::factory()->create(['name' => 'Marketing']);

    $byMember = Project::factory()->create(['code' => 'MIEMBRO']);
    $byMember->addMember(User::factory()->employee()->inDepartment($design)->create());

    $byBank = Project::factory()->hourBank()->create(['code' => 'BOLSA']);
    HourBank::factory()->forDepartment($design)->create(['project_id' => $byBank->id]);

    $byTaskType = Project::factory()->create(['code' => 'TIPO']);
    $type = TaskType::factory()->create(['department_id' => $design->id]);
    Task::factory()->create(['project_id' => $byTaskType->id, 'task_type_id' => $type->id]);

    $other = Project::factory()->create(['code' => 'OTRO']);
    $other->addMember(User::factory()->employee()->inDepartment($marketing)->create());

    expect(($this->codes)(['departamento' => $design->id]))->toBe(['BOLSA', 'MIEMBRO', 'TIPO'])
        ->and(($this->codes)(['departamento' => $marketing->id]))->toBe(['OTRO']);
});

test('busca por nombre o código sin distinguir mayúsculas', function () {
    Project::factory()->create(['code' => 'ACME-WEB', 'name' => 'Web corporativa']);
    Project::factory()->create(['code' => 'LUR-SEO', 'name' => 'Posicionamiento Bodegas']);

    expect(($this->codes)(['buscar' => 'corpora']))->toBe(['ACME-WEB'])
        ->and(($this->codes)(['buscar' => 'lur-']))->toBe(['LUR-SEO'])
        ->and(($this->codes)(['buscar' => 'BODEGAS']))->toBe(['LUR-SEO'])
        ->and(($this->codes)(['buscar' => '100%_']))->toBe([]);
});

test('«mis proyectos» muestra solo aquellos de los que soy miembro', function () {
    $mine = Project::factory()->create(['code' => 'MIO']);
    $mine->addMember($this->viewer);
    Project::factory()->create(['code' => 'AJENO']);

    expect(($this->codes)(['mios' => 1]))->toBe(['MIO'])
        ->and(($this->codes)())->toBe(['AJENO', 'MIO']);
});

test('cada fila lleva cliente, gestor principal y el consumo agregado de sus bolsas abiertas', function () {
    $project = Project::factory()->hourBank()->create(['code' => 'BOLSAS']);
    $open = HourBank::factory()->hours(10)->create(['project_id' => $project->id]);
    $closed = HourBank::factory()->hours(20)->closed()->create(['project_id' => $project->id]);
    TimeEntry::factory()->forTask(Task::factory()->inBank($open)->create())->minutes(150)->create();
    TimeEntry::factory()->forTask(Task::factory()->inBank($closed)->create())->minutes(60)->create();

    $this->actingAs($this->viewer)
        ->get('/proyectos')
        ->assertInertia(fn (Assert $page) => $page
            ->where('projects.data.0.code', 'BOLSAS')
            ->where('projects.data.0.client.name', $project->client?->name)
            ->where('projects.data.0.owner.id', $project->owner_user_id)
            ->where('projects.data.0.hour_banks.open_count', 1)
            ->where('projects.data.0.hour_banks.total_minutes', 600)
            ->where('projects.data.0.hour_banks.consumed_minutes', 150)
            ->where('projects.data.0.hour_banks.overage_minutes', 0)
            ->missing('projects.data.0.hourly_rate')
            ->missing('projects.data.0.fixed_price_amount'));
});

test('un proyecto que no es de bolsas no lleva consumo', function () {
    Project::factory()->fixedPrice()->create();

    $this->actingAs($this->viewer)
        ->get('/proyectos')
        ->assertInertia(fn (Assert $page) => $page->where('projects.data.0.hour_banks', null));
});

test('con view-financials llegan la tarifa y el importe cerrado', function () {
    Project::factory()->fixedPrice()->create(['hourly_rate' => '60.00']);

    $this->actingAs(userWithRole('admin'))
        ->get('/proyectos')
        ->assertInertia(fn (Assert $page) => $page
            ->where('projects.data.0.fixed_price_amount', '4800.00')
            ->where('projects.data.0.hourly_rate', '60.00'));
});

test('pagina de 25 en 25 conservando los filtros', function () {
    Project::factory()->count(27)->create();

    $this->actingAs($this->viewer)
        ->get('/proyectos?tipo=time_and_materials&pagina=2')
        ->assertInertia(fn (Assert $page) => $page
            ->has('projects.data', 2)
            ->where('projects.meta.current_page', 2)
            ->where('projects.meta.last_page', 2)
            ->where('projects.meta.total', 27)
            ->where('projects.links.next', null)
            ->where('projects.links.prev', fn (string $url) => str_contains($url, 'tipo=time_and_materials') && str_contains($url, 'pagina=1')));
});

test('sin N+1: el número de consultas no crece con las filas', function () {
    $queries = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->viewer)->get('/proyectos')->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    Project::factory()->hourBank()->count(2)->create()->each(
        fn (Project $project) => HourBank::factory()->create(['project_id' => $project->id]),
    );
    $queries(); // Calienta las cachés de permisos y ajustes.
    $few = $queries();

    Project::factory()->hourBank()->count(8)->create()->each(
        fn (Project $project) => HourBank::factory()->create(['project_id' => $project->id]),
    );
    $many = $queries();

    expect($many)->toBe($few);
});

test('las opciones de los filtros incluyen clientes, responsables y departamentos', function () {
    $client = Client::factory()->create(['name' => 'Acme']);
    $owner = userWithRole('employee', ['name' => 'Laura Gómez']);
    Project::factory()->create(['client_id' => $client->id, 'owner_user_id' => $owner->id]);
    Department::factory()->create(['name' => 'Diseño']);

    $this->actingAs($this->viewer)
        ->get('/proyectos?cliente='.$client->id.'&mios=1&buscar=web')
        ->assertInertia(fn (Assert $page) => $page
            ->where('options.clients.0.name', 'Acme')
            ->where('options.owners.0.name', 'Laura Gómez')
            ->where('options.departments.0.name', 'Diseño')
            ->where('filters.cliente', $client->id)
            ->where('filters.mios', true)
            ->where('filters.buscar', 'web')
            ->where('filters.estado', ''));
});
