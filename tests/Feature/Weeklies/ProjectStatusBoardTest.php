<?php

use App\Domain\HourBanks\HourBankLedger;
use App\Domain\Weeklies\ProjectStatus\ProjectKindCode;
use App\Domain\Weeklies\ProjectStatus\ProjectStatusBoard;
use App\Enums\BillingType;
use App\Enums\ProjectStatus;
use App\Models\Client;
use App\Models\Holiday;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\Setting;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/*
| «Estado de proyectos» con datos reales (10.4, F-064 y F-119 a F-121, D-148): la cartera con las
| bolsas, los fees (lo esperado por días laborables y festivos) y las horas, contra el fixture
| compartido con Vitest (tests/fixtures/weeklies/project-status-board.json).
*/

/**
 * @return array<string, mixed>
 */
function projectStatusFixture(): array
{
    return json_decode((string) file_get_contents(base_path('tests/fixtures/weeklies/project-status-board.json')), true);
}

/**
 * Monta los clientes, proyectos, bolsas, horas y festivos del fixture.
 *
 * @param  array<string, mixed>  $fixture
 * @return array<string, Client>
 */
function seedProjectStatusFixture(array $fixture): array
{
    foreach ($fixture['holidays'] as $date) {
        Holiday::factory()->create(['date' => $date]);
    }

    $clients = [];

    foreach ($fixture['clients'] as $row) {
        $clients[$row['key']] = Client::factory()->create(['name' => $row['name'], 'icon' => $row['icon'], 'is_active' => $row['active']]);
    }

    foreach ($fixture['projects'] as $row) {
        $project = Project::factory()->create([
            'client_id' => $clients[$row['client']]->id,
            'code' => $row['code'],
            'name' => $row['name'],
            'billing_type' => BillingType::from($row['billing_type']),
            'status' => ProjectStatus::from($row['status']),
            'budget_minutes' => $row['budget_minutes'],
            'description' => $row['description'],
        ]);
        $bank = $row['bank'] === null ? null : HourBank::factory()->create([
            'project_id' => $project->id,
            'name' => $row['bank']['name'],
            'total_minutes' => $row['bank']['total_minutes'],
            'start_date' => $row['bank']['start_date'],
        ]);
        $task = Task::factory()->create(['project_id' => $project->id, 'hour_bank_id' => $bank?->id]);

        foreach ($row['entries'] as [$date, $minutes]) {
            TimeEntry::factory()->forTask($task)->minutes($minutes)->on($date)->create();
        }

        if ($bank !== null) {
            app(HourBankLedger::class)->recalculate($bank);
        }
    }

    return $clients;
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08 12:00', 'Europe/Madrid'));
});

it('calcula la cartera como el fixture: bolsas, fees con lo esperado, presupuesto, sin presupuesto, tipos e insignias', function () {
    $fixture = projectStatusFixture();
    seedProjectStatusFixture($fixture);

    $board = app(ProjectStatusBoard::class)->build(CarbonImmutable::parse($fixture['today']));

    expect(array_map(fn (array $group): string => $group['client']['name'], $board))->toBe(array_column($fixture['expected'], 'client'));

    foreach ($fixture['expected'] as $index => $expected) {
        expect($board[$index]['badges'])->toBe($expected['badges'])
            ->and(array_column($board[$index]['projects'], 'code'))->toBe(array_column($expected['projects'], 'code'));

        foreach ($expected['projects'] as $position => $project) {
            $row = $board[$index]['projects'][$position];

            foreach (['name', 'kind_code', 'billing_type', 'budget_minutes', 'consumed_minutes', 'expected_minutes', 'deviation_minutes', 'week_minutes'] as $key) {
                expect($row[$key])->toBe($project[$key], "{$project['code']}.{$key}");
            }
        }
    }
});

it('el tipo de WeeklySync sale del código de ClickUp o, si no, del tipo de proyecto', function (string $code, string $kind, string $prefix) {
    expect(ProjectKindCode::for($code, $kind))->toBe($prefix);
})->with([
    'bolsa' => ['ACME-BH2', 'hour_bank', 'BH'],
    'fee' => ['ACME-FE1', 'monthly_fee', 'FE'],
    'web' => ['ACME-WE', 'fixed_price', 'WE'],
    'auditoría técnica' => ['ACME-AT3', 'fixed_price', 'AT'],
    'minúsculas' => ['acme-ec1', 'fixed_price', 'EC'],
    'código propio de bolsa' => ['ACME-SOPORTE', 'hour_bank', 'BH'],
    'código propio de fee' => ['ACME-MANT', 'monthly_fee', 'FE'],
    'BD no es de WeeklySync' => ['ACME-BD1', 'fixed_price', 'GE'],
    'sin guion' => ['WE1', 'fixed_price', 'WE'],
]);

it('las insignias cuentan por grupo y en el orden de WeeklySync (las tres auditorías juntas)', function () {
    expect(ProjectKindCode::badges(['BH', 'AT', 'AD', 'PR', 'FE', 'BH']))->toBe([
        ['tag' => 'product', 'count' => 1],
        ['tag' => 'audit', 'count' => 2],
        ['tag' => 'monthly_fee', 'count' => 1],
        ['tag' => 'hour_bank', 'count' => 2],
    ]);
});

it('la página la ve la plantilla con la pestaña de las weeklies; un colaborador externo no', function () {
    $fixture = projectStatusFixture();
    seedProjectStatusFixture($fixture);

    $this->actingAs(userWithRole('employee'))
        ->get('/weeklies/estado-proyectos')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('weeklies/project-status')
            ->where('reference_date', '2026-10-08')
            ->has('clients', 2)
            ->where('clients.0.client.name', 'Acme')
            ->where('clients.0.client.icon', '🍷')
            ->where('clients.0.projects.1.code', 'ACME-FE1')
            ->where('clients.0.projects.1.expected_minutes', 343)
            ->missing('clients.0.projects.0.fixed_price_amount'));

    $this->actingAs(User::factory()->collaborator()->create())->get('/weeklies/estado-proyectos')->assertForbidden();
});

it('con el módulo apagado da 404', function () {
    Setting::set('modules', ['project_status' => false]);

    $this->actingAs(userWithRole('admin'))->get('/weeklies/estado-proyectos')->assertNotFound();
});

it('no crece con la cartera: cinco consultas propias como mucho', function () {
    $admin = userWithRole('admin');
    $measure = function () use ($admin): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($admin)->get('/weeklies/estado-proyectos')->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };
    $grow = function (int $clients): void {
        Holiday::query()->firstOrCreate(['date' => '2026-10-12'], ['name' => 'Fiesta Nacional', 'scope' => 'company']);

        foreach (range(1, $clients) as $i) {
            $client = Client::factory()->create();
            $bank = Project::factory()->hourBank()->create(['client_id' => $client->id]);
            HourBank::factory()->create(['project_id' => $bank->id]);
            $fee = Project::factory()->create(['client_id' => $client->id, 'description' => 'Fee mensual de 10 h.']);
            TimeEntry::factory()->forTask(Task::factory()->create(['project_id' => $fee->id]))->minutes(60)->on('2026-10-06')->create();
            Project::factory()->fixedPrice()->create(['client_id' => $client->id, 'budget_minutes' => 600]);
        }
    };

    $grow(2);
    $measure();
    $small = $measure();
    $grow(10);
    $large = $measure();

    expect($large)->toBeLessThanOrEqual(16)
        ->and($large - $small)->toBeLessThanOrEqual(0);
});
