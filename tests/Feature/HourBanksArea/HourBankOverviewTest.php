<?php

use App\Domain\HourBanks\Events\HourBankOverageRecorded;
use App\Domain\HourBanks\Events\HourBankThresholdReached;
use App\Models\Client;
use App\Models\Department;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\Setting;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Vista global de bolsas (/bolsas, SPEC §8, D-035): las abiertas de todos los clientes ordenadas
| por % de consumo; responsables y admins ven todas y un gestor solo las de sus proyectos; filtros
| de cliente, departamento, estado y «próximas a agotarse»; sin N+1.
*/

beforeEach(function () {
    Event::fake([HourBankThresholdReached::class, HourBankOverageRecorded::class]);

    /** Crea una bolsa con $consumed minutos imputados sobre $total horas. */
    $this->bank = function (string $name, int $hours, int $consumed, array $attributes = []): HourBank {
        $bank = HourBank::factory()->hours($hours)->create(['name' => $name, ...$attributes]);

        if ($consumed > 0) {
            TimeEntry::factory()->forTask(Task::factory()->inBank($bank)->create())->minutes($consumed)->create();
        }

        return $bank->fresh() ?? $bank;
    };

    $this->names = function (User $viewer, array $query = []): array {
        $names = [];

        $this->actingAs($viewer)
            ->get('/bolsas?'.http_build_query($query))
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$names) {
                $page->component('hour-banks/index');
                $names = array_column($page->toArray()['props']['banks']['data'], 'name');
            });

        return $names;
    };
});

test('ordena las abiertas por % de consumo, de más a menos, e incluye las agotadas', function () {
    ($this->bank)('Media', 10, 5 * 60);
    ($this->bank)('Agotada', 10, 11 * 60);
    ($this->bank)('Nueva', 20, 0);
    ($this->bank)('Casi', 4, 3 * 60 + 30);
    ($this->bank)('Cerrada', 10, 9 * 60, ['status' => 'closed']);
    ($this->bank)('Renovada', 10, 9 * 60 + 30, ['status' => 'renewed']);

    expect(($this->names)(userWithRole('admin')))->toBe(['Agotada', 'Casi', 'Media', 'Nueva']);
});

test('con el filtro de estado: una en concreto o todas (también cerradas y renovadas)', function () {
    ($this->bank)('Activa', 10, 60);
    ($this->bank)('Cerrada', 10, 9 * 60, ['status' => 'closed']);
    ($this->bank)('Renovada', 10, 9 * 60 + 30, ['status' => 'renewed']);

    $admin = userWithRole('admin');

    expect(($this->names)($admin, ['estado' => 'closed']))->toBe(['Cerrada'])
        ->and(($this->names)($admin, ['estado' => 'todas']))->toBe(['Renovada', 'Cerrada', 'Activa']);
});

test('filtra por cliente y por departamento', function () {
    $acme = Client::factory()->create();
    $design = Department::factory()->create();

    ($this->bank)('De Acme', 10, 60, ['project_id' => Project::factory()->hourBank()->create(['client_id' => $acme->id])->id]);
    ($this->bank)('De Diseño', 10, 60, ['department_id' => $design->id]);
    ($this->bank)('Otra', 10, 60);

    $admin = userWithRole('admin');

    expect(($this->names)($admin, ['cliente' => $acme->id]))->toBe(['De Acme'])
        ->and(($this->names)($admin, ['departamento' => $design->id]))->toBe(['De Diseño']);
});

test('«próximas a agotarse»: desde el primer umbral configurado (D-035)', function () {
    ($this->bank)('74 %', 100, 74 * 60);
    ($this->bank)('75 %', 100, 75 * 60);
    ($this->bank)('Agotada', 10, 10 * 60);

    $admin = userWithRole('admin');

    expect(($this->names)($admin, ['proximas' => 1]))->toBe(['Agotada', '75 %']);

    Setting::set('hour_bank_alert_thresholds', [70, 90, 100]);

    expect(($this->names)($admin, ['proximas' => 1]))->toBe(['Agotada', '75 %', '74 %']);
});

test('un gestor que no es responsable ni admin solo ve las de sus proyectos (D-035)', function () {
    $manager = userWithRole('employee');
    $mine = Project::factory()->hourBank()->create(['owner_user_id' => $manager->id]);
    $coManaged = Project::factory()->hourBank()->create();
    $coManaged->addMember($manager, true);
    $onlyMember = Project::factory()->hourBank()->create();
    $onlyMember->addMember($manager);

    ($this->bank)('Mía', 10, 60, ['project_id' => $mine->id]);
    ($this->bank)('Cogestionada', 10, 120, ['project_id' => $coManaged->id]);
    ($this->bank)('Solo miembro', 10, 180, ['project_id' => $onlyMember->id]);
    ($this->bank)('Ajena', 10, 240);

    expect(($this->names)($manager))->toBe(['Cogestionada', 'Mía'])
        ->and(($this->names)(userWithRole('department_manager')))->toBe(['Ajena', 'Solo miembro', 'Cogestionada', 'Mía']);

    $this->actingAs($manager)->get('/bolsas')->assertInertia(fn (Assert $page) => $page->where('scope', 'managed'));
});

test('un empleado que no gestiona nada no entra; un cliente va a su portal', function () {
    $this->actingAs(userWithRole('employee'))->get('/bolsas')->assertForbidden();
    $this->actingAs(userWithRole('client'))->get('/bolsas')->assertRedirect(route('portal.home'));
});

test('cada fila lleva proyecto, cliente, departamento, exceso y comprometidas; sin datos económicos', function () {
    $client = Client::factory()->create(['name' => 'Acme']);
    $project = Project::factory()->hourBank()->create(['client_id' => $client->id, 'code' => 'ACME-WEB']);
    $department = Department::factory()->create(['name' => 'Desarrollo']);
    $bank = ($this->bank)('Bolsa', 10, 11 * 60, [
        'project_id' => $project->id,
        'department_id' => $department->id,
        'price_amount' => '900.00',
    ]);
    Task::factory()->inBank($bank)->create(['estimated_minutes' => 90]);

    $this->actingAs(userWithRole('department_manager'))
        ->get('/bolsas')
        ->assertInertia(fn (Assert $page) => $page
            ->where('banks.data.0.project.code', 'ACME-WEB')
            ->where('banks.data.0.project.client.name', 'Acme')
            ->where('banks.data.0.department.name', 'Desarrollo')
            ->where('banks.data.0.overage_minutes', 60)
            ->where('banks.data.0.committed_minutes', 90)
            ->where('banks.data.0.status', 'exhausted')
            ->missing('banks.data.0.price_amount')
            ->missing('banks.data.0.hourly_rate'));
});

test('el resumen cuenta las abiertas, las agotadas y las que pasan del primer umbral', function () {
    ($this->bank)('A', 10, 60);
    ($this->bank)('B', 10, 8 * 60);
    ($this->bank)('C', 10, 10 * 60);
    ($this->bank)('D', 10, 60, ['status' => 'closed']);

    $this->actingAs(userWithRole('admin'))
        ->get('/bolsas')
        ->assertInertia(fn (Assert $page) => $page
            ->where('stats.open', 3)
            ->where('stats.exhausted', 1)
            ->where('stats.near', 1)
            ->where('threshold', 75));
});

test('pagina de 25 en 25', function () {
    foreach (range(1, 27) as $i) {
        ($this->bank)("Bolsa {$i}", 10, 0);
    }

    $this->actingAs(userWithRole('admin'))
        ->get('/bolsas?pagina=2')
        ->assertInertia(fn (Assert $page) => $page
            ->has('banks.data', 2)
            ->where('banks.meta.total', 27));
});

test('sin N+1: las consultas no crecen con las bolsas', function () {
    $admin = userWithRole('admin');
    $queries = function () use ($admin): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($admin)->get('/bolsas')->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    $seed = function (): void {
        $bank = ($this->bank)('X', 10, 60, ['department_id' => Department::factory()->create()->id]);
        $parent = Task::factory()->inBank($bank)->create(['estimated_minutes' => 60]);
        Task::factory()->subtaskOf($parent)->create(['estimated_minutes' => 30]);
    };

    $seed();
    $seed();
    $queries();
    $few = $queries();

    $seed();
    $seed();
    $seed();
    $many = $queries();

    expect($many)->toBe($few);
});

test('con un cliente elegido, el histórico de renovaciones de todos sus proyectos (SPEC §8.8)', function () {
    $acme = Client::factory()->create();
    $web = Project::factory()->hourBank()->create(['client_id' => $acme->id, 'code' => 'ACME-WEB']);
    $seo = Project::factory()->hourBank()->create(['client_id' => $acme->id, 'code' => 'ACME-SEO']);

    $t1 = HourBank::factory()->renewed()->create(['project_id' => $web->id, 'name' => 'Web T1', 'start_date' => '2026-01-01']);
    HourBank::factory()->create(['project_id' => $web->id, 'name' => 'Web T2', 'start_date' => '2026-04-01', 'renewed_from_id' => $t1->id]);
    $s1 = HourBank::factory()->renewed()->create(['project_id' => $seo->id, 'name' => 'SEO 1', 'start_date' => '2026-02-01']);
    $s2 = HourBank::factory()->renewed()->create(['project_id' => $seo->id, 'name' => 'SEO 2', 'start_date' => '2026-05-01', 'renewed_from_id' => $s1->id]);
    HourBank::factory()->closed()->create(['project_id' => $seo->id, 'name' => 'SEO 3', 'start_date' => '2026-08-01', 'renewed_from_id' => $s2->id]);
    // Sin renovaciones, u otro cliente: no salen.
    HourBank::factory()->create(['project_id' => $web->id, 'name' => 'Suelta']);
    $other = HourBank::factory()->renewed()->create(['name' => 'Ajena']);
    HourBank::factory()->create(['project_id' => $other->project_id, 'renewed_from_id' => $other->id]);

    $admin = userWithRole('admin');

    $this->actingAs($admin)
        ->get("/bolsas?cliente={$acme->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->has('history.chains', 2)
            ->where('history.chains.0.0.name', 'Web T1')
            ->where('history.chains.0.0.project_id', $web->id)
            ->where('history.chains.0.1.name', 'Web T2')
            ->has('history.chains.1', 3)
            ->where('history.chains.1.0.name', 'SEO 1')
            ->where('history.chains.1.2.name', 'SEO 3')
            ->where('history.chains.1.2.status', 'closed')
            ->has('history.projects', 2)
            ->where('history.projects.0.code', 'ACME-SEO')
            ->where('history.projects.1.code', 'ACME-WEB'));

    // Sin cliente elegido no hay histórico.
    $this->actingAs($admin)->get('/bolsas')->assertInertia(fn (Assert $page) => $page->where('history', null));
});

test('el histórico del cliente solo lleva las bolsas que ve quien mira (D-035)', function () {
    $acme = Client::factory()->create();
    $manager = userWithRole('employee');
    $mine = Project::factory()->hourBank()->create(['client_id' => $acme->id, 'owner_user_id' => $manager->id]);
    $notMine = Project::factory()->hourBank()->create(['client_id' => $acme->id]);

    foreach ([$mine, $notMine] as $project) {
        $first = HourBank::factory()->renewed()->create(['project_id' => $project->id, 'name' => "Primera {$project->id}"]);
        HourBank::factory()->create(['project_id' => $project->id, 'renewed_from_id' => $first->id]);
    }

    $this->actingAs($manager)
        ->get("/bolsas?cliente={$acme->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->has('history.chains', 1)
            ->where('history.chains.0.0.name', "Primera {$mine->id}")
            ->has('history.projects', 1)
            ->where('history.projects.0.id', $mine->id));
});

test('sin N+1 en el histórico del cliente', function () {
    $acme = Client::factory()->create();
    $admin = userWithRole('admin');
    $queries = function () use ($admin, $acme): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($admin)->get("/bolsas?cliente={$acme->id}&estado=todas")->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    $seed = function () use ($acme): void {
        $project = Project::factory()->hourBank()->create(['client_id' => $acme->id]);
        $first = HourBank::factory()->renewed()->create(['project_id' => $project->id]);
        HourBank::factory()->create(['project_id' => $project->id, 'renewed_from_id' => $first->id]);
    };

    $seed();
    $seed();
    $queries();
    $few = $queries();

    $seed();
    $seed();
    $seed();
    $many = $queries();

    expect($many)->toBe($few);
});
