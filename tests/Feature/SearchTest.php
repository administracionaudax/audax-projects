<?php

use App\Models\Department;
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

    expect($pages)->toHaveCount(14);

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
