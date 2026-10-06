<?php

use App\Models\Client;
use App\Models\Department;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Search\Sources\PageSource;
use Illuminate\Support\Facades\DB;

/**
 * Empleado que busca, con nombre y correo fijos: con los aleatorios de Faker (es_ES) podía llamarse
 * «Lucas» o «Lucía» y aparecer en sus propias búsquedas de personas.
 */
function searcher(): User
{
    return userWithRole('employee', [
        'name' => 'Zoe Buscadora',
        'email' => 'zoe-'.bin2hex(random_bytes(4)).'@example.com',
    ]);
}

/**
 * @return list<string>
 */
function searchTitles(string $query, ?User $user = null): array
{
    $response = test()->actingAs($user ?? searcher())
        ->getJson('/buscar?q='.urlencode($query))
        ->assertOk();

    return array_column($response->json('results'), 'title');
}

test('la búsqueda exige haber iniciado sesión', function () {
    $this->getJson('/buscar?q=pro')->assertUnauthorized();
    $this->get('/buscar?q=pro')->assertRedirect(route('login'));
});

test('un cliente no puede usar la búsqueda interna', function () {
    $this->actingAs(userWithRole('client'))->getJson('/buscar?q=pro')->assertForbidden();
});

test('hacen falta al menos 2 caracteres', function (string $query) {
    $this->actingAs(userWithRole('admin'))
        ->getJson('/buscar?q='.urlencode($query))
        ->assertOk()
        ->assertExactJson(['results' => []]);
})->with(['', 'a', ' p ']);

test('los resultados siguen el contrato {type, id, title, subtitle, url}', function () {
    $this->actingAs(userWithRole('admin'))
        ->getJson('/buscar?q=proyectos')
        ->assertOk()
        ->assertJsonPath('results.0', [
            'type' => 'page',
            'id' => 'projects.index',
            'title' => 'Proyectos',
            'subtitle' => 'Proyectos y tareas',
            'url' => '/proyectos',
        ]);
});

test('un empleado no ve Bolsas ni Administración', function () {
    $employee = userWithRole('employee');

    expect(searchTitles('bolsas', $employee))->not->toContain('Bolsas')
        ->and(searchTitles('administ', $employee))->not->toContain('Administración')
        ->and(searchTitles('horas', $employee))->toContain('Horas');
});

test('un responsable ve Bolsas pero no Administración', function () {
    $manager = userWithRole('department_manager');

    expect(searchTitles('bolsas', $manager))->toContain('Bolsas')
        ->and(searchTitles('administ', $manager))->not->toContain('Administración');
});

test('el admin ve Bolsas y Administración, sin importar acentos ni mayúsculas', function () {
    $admin = userWithRole('admin');

    expect(searchTitles('BOLSAS', $admin))->toContain('Bolsas')
        ->and(searchTitles('administracion', $admin))->toContain('Administración');
});

test('las ausencias salen para todos; las del equipo, para quien las aprueba; los festivos, con manage-settings', function (string $role, array $visible, array $hidden) {
    $user = userWithRole($role);
    $titles = [...searchTitles('ausencias', $user), ...searchTitles('festivos', $user)];

    expect($titles)->toContain(...$visible);

    foreach ($hidden as $title) {
        expect($titles)->not->toContain($title);
    }
})->with([
    'empleado' => ['employee', ['Ausencias'], ['Ausencias del equipo', 'Festivos']],
    'responsable' => ['department_manager', ['Ausencias', 'Ausencias del equipo'], ['Festivos']],
    'admin' => ['admin', ['Ausencias', 'Ausencias del equipo', 'Festivos'], []],
]);

test('cada sección lleva a su página y «ausencias» ya no es una palabra clave de Carga', function () {
    $response = $this->actingAs(userWithRole('admin'))
        ->getJson('/buscar?q=ausencias')
        ->assertOk();

    $pages = collect($response->json('results'))->where('type', 'page')->pluck('url', 'title')->all();

    expect($pages)->toBe(['Ausencias' => '/ausencias', 'Ausencias del equipo' => '/ausencias/equipo'])
        ->and(searchTitles('vacaciones'))->toContain('Ausencias')
        ->and(collect($this->actingAs(userWithRole('admin'))->getJson('/buscar?q=festivos')->json('results'))->firstWhere('title', 'Festivos')['url'])->toBe('/admin/festivos');
});

test('devuelve personas internas activas por nombre o correo', function () {
    $design = Department::factory()->create(['name' => 'Diseño']);
    User::factory()->employee()->inDepartment($design)->create(['name' => 'Lucía Martín', 'email' => 'lucia@audaxstudio.com']);
    User::factory()->employee()->inactive()->create(['name' => 'Lucía Inactiva']);
    User::factory()->client()->create(['name' => 'Lucía Cliente']);

    $response = $this->actingAs(searcher())
        ->getJson('/buscar?q=luc')
        ->assertOk();

    $people = collect($response->json('results'))->where('type', 'person')->values();

    expect($people)->toHaveCount(1)
        ->and($people[0]['title'])->toBe('Lucía Martín')
        ->and($people[0]['subtitle'])->toBe('Diseño · lucia@audaxstudio.com')
        ->and(searchTitles('LUCIA@AUDAX'))->toContain('Lucía Martín');
});

test('los textos de las secciones salen de lang/es/search.php', function () {
    $pages = (new class extends PageSource
    {
        /** @return list<array{route: string, title: string, subtitle: string, keywords: string, allowed: Closure(User): bool}> */
        public function all(): array
        {
            return $this->pages();
        }
    })->all();

    // 17 de las Fases 1 a 6, 5 de la Fase 7 (preferencias de notificación, auditoría, privacidad del admin, privacidad y mis datos)
    // el calendario del equipo (Fase 9, D-144), 5 de la Weekly (Fase 10, D-239), 3 del plan del día (D-250)
    // y 2 de la previsión (D-306).
    expect($pages)->toHaveCount(33);

    foreach ($pages as $page) {
        foreach (['title', 'subtitle', 'keywords'] as $field) {
            expect($page[$field])->not->toBe('')->not->toStartWith('search.');
        }
    }

    expect(collect($pages)->firstWhere('route', 'sessions.index')['title'])->toBe(__('search.pages.sessions.title'));
});

test('en PostgreSQL la búsqueda de personas ignora los acentos (extensión unaccent)', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Solo en PostgreSQL (tests del servidor).');
    }

    User::factory()->employee()->create(['name' => 'Lucía Martín', 'email' => 'l.martin@audaxstudio.com']);
    User::factory()->employee()->create(['name' => 'José Pérez', 'email' => 'jperez@audaxstudio.com']);

    expect(DB::table('pg_extension')->where('extname', 'unaccent')->exists())->toBeTrue()
        ->and(searchTitles('lucia'))->toContain('Lucía Martín')
        ->and(searchTitles('jose perez'))->toContain('José Pérez');
});

test('la búsqueda de personas trata % y _ como texto', function () {
    User::factory()->employee()->create(['name' => 'Pedro Pérez']);

    expect(searchTitles('%%'))->toBe([])
        ->and(searchTitles('__'))->toBe([]);
});

test('devuelve como máximo 20 resultados', function () {
    User::factory()->employee()->count(30)->sequence(fn ($sequence) => ['name' => 'Persona '.$sequence->index])->create();

    expect(searchTitles('persona'))->toHaveCount(20);

    $this->actingAs(userWithRole('employee'))->getJson('/buscar?q=persona&limit=50')->assertUnprocessable();
});

test('encuentra clientes, proyectos y tareas, sin distinguir mayúsculas, con su enlace (Fase 1)', function () {
    $client = Client::factory()->create(['name' => 'Bodegas Zarzalejo', 'contact_name' => 'Ana']);
    $project = Project::factory()->create(['client_id' => $client->id, 'name' => 'Web Zarzalejo', 'code' => 'ZAR-WEB']);
    $task = Task::factory()->create(['project_id' => $project->id, 'title' => 'Maquetar ficha Zarzalejo']);

    $results = collect($this->actingAs(searcher())->getJson('/buscar?q=zarzalejo')->assertOk()->json('results'));

    expect($results->firstWhere('type', 'client'))->toMatchArray([
        'id' => $client->id,
        'url' => '/clientes/'.$client->id,
        'subtitle' => '1 proyecto activo',
    ])
        ->and($results->firstWhere('type', 'project'))->toMatchArray([
            'id' => $project->id,
            'url' => '/proyectos/'.$project->id,
            'subtitle' => 'ZAR-WEB · Bodegas Zarzalejo',
        ])
        ->and($results->firstWhere('type', 'task'))->toMatchArray([
            'id' => $task->id,
            'url' => "/proyectos/{$project->id}/tareas?tarea={$task->id}",
        ]);
});

test('busca proyectos por código y deja los archivados al final', function () {
    Project::factory()->archived()->create(['name' => 'Alfa antiguo', 'code' => 'ALFA-OLD']);
    Project::factory()->create(['name' => 'Alfa nuevo', 'code' => 'ALFA-NEW']);

    $projects = collect($this->actingAs(searcher())->getJson('/buscar?q=alfa-')->json('results'))->where('type', 'project')->values();

    expect($projects->pluck('title')->all())->toBe(['Alfa nuevo', 'Alfa antiguo'])
        ->and($projects[1]['subtitle'])->toContain('Archivado');
});

test('primero las tareas abiertas y las mías', function () {
    $me = searcher();
    $project = Project::factory()->create();
    Task::factory()->completed()->create(['project_id' => $project->id, 'title' => 'Revisar textos A']);
    Task::factory()->create(['project_id' => $project->id, 'title' => 'Revisar textos B']);
    Task::factory()->assignedTo($me)->create(['project_id' => $project->id, 'title' => 'Revisar textos C']);

    $titles = collect($this->actingAs($me)->getJson('/buscar?q=revisar textos')->json('results'))
        ->where('type', 'task')->pluck('title')->all();

    expect($titles)->toBe(['Revisar textos C', 'Revisar textos B', 'Revisar textos A']);
});

test('ninguna fuente acapara los resultados y los huecos se rellenan', function () {
    $project = Project::factory()->create(['name' => 'Omega']);
    Task::factory()->count(30)->create(['project_id' => $project->id, 'title' => 'Omega tarea']);
    User::factory()->employee()->count(10)->sequence(fn ($sequence) => ['name' => 'Omega persona '.$sequence->index])->create();

    $results = collect($this->actingAs(searcher())->getJson('/buscar?q=omega')->json('results'));

    // Proyecto (1) + tareas (6 en la 1.ª ronda) + personas (4) y el resto, rellenado con tareas.
    expect($results)->toHaveCount(20)
        ->and($results->where('type', 'project'))->toHaveCount(1)
        ->and($results->where('type', 'person')->count())->toBeGreaterThanOrEqual(4)
        ->and($results->pluck('type')->unique()->values()->all())->toBe(['project', 'task', 'person']);
});

test('los escapes de LIKE no rompen la búsqueda de proyectos', function () {
    Project::factory()->create(['name' => 'Cien por cien', 'code' => 'C100']);

    expect(collect($this->actingAs(searcher())->getJson('/buscar?q='.urlencode('100%'))->json('results'))->where('type', 'project'))
        ->toHaveCount(0);
});
