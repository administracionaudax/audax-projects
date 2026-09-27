<?php

use App\Domain\Reports\ReportFilters;
use App\Domain\Reports\ReportScope;
use App\Domain\Reports\RevenueCalculator;
use App\Enums\TimeEntryStatus;
use App\Http\Controllers\Reports\HoursExportController;
use App\Models\Task;
use App\Models\TaskType;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Tests\Feature\Reports\R3Scenario;

/*
| Exportación de horas (SPEC §10, D-045): /informes/horas/exportar con los filtros globales y el
| alcance de cada uno (D-044), y la de la pestaña Horas del proyecto (D-021: el empleado solo las
| suyas). Tarifas, instantáneas e importes solo con view-financials. Cifras del escenario a mano.
*/

beforeEach(function () {
    $this->s = R3Scenario::build($this);
    $this->readXlsx = function (string $content): array {
        $path = tempnam(sys_get_temp_dir(), 'r3').'.xlsx';
        file_put_contents($path, $content);
        $reader = new XlsxReader;
        $reader->open($path);
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
        }
        $reader->close();
        unlink($path);

        return $rows;
    };
    $this->export = fn (User $user, array $query = []) => $this->actingAs($user)
        ->get('/informes/horas/exportar?'.http_build_query(R3Scenario::week(['formato' => 'xlsx', ...$query])));
    // Filas por encabezado para comparar sin depender de la posición de cada columna.
    $this->table = function ($response): array {
        $rows = ($this->readXlsx)($response->assertOk()->streamedContent());
        $headers = array_shift($rows);

        return array_map(fn (array $row): array => array_combine($headers, array_pad($row, count($headers), '')), $rows);
    };
});

it('exporta cada entrada con horas, dentro, exceso e importes como a mano (admin)', function () {
    $s = $this->s;
    $rows = ($this->table)(($this->export)($s->admin, ['departamento' => [$s->design->id]]));

    expect($rows)->toHaveCount(6)
        ->and(array_column($rows, 'Fecha'))->toBe(['2026-09-22', '2026-09-22', '2026-09-23', '2026-09-24', '2026-09-24', '2026-09-25']);

    $byKey = collect($rows)->keyBy(fn (array $row): string => $row['Fecha'].' '.$row['Persona'].' '.$row['Horas']);

    // Aprobada con instantáneas: 300 × 55 / 60 = 275,00 €; coste 300 × 20 / 60 = 100,00 €.
    expect($byKey['2026-09-22 Ana 5'])->toMatchArray([
        'Cliente' => 'Cliente Uno', 'Proyecto' => 'TM · Por horas', 'Tarea' => 'Maquetación', 'Bolsa' => '',
        'Facturable' => true, 'Estado' => 'Aprobada', 'Descripción' => 'Primera versión',
        'Tarifa (€/h)' => 55, 'Tarifa congelada (€/h)' => 55, 'Coste congelado (€/h)' => 20,
        'Ingreso estimado (€)' => 275, 'Coste (€)' => 100,
    ])
        // Bolsa con precio: 1000 × 500 / 600 = 833,33 €; coste 500 × 30 / 60 = 250,00 €.
        ->and($byKey['2026-09-22 Luis 8.33'])->toMatchArray([
            'Bolsa' => 'Bolsa anual', 'Dentro de bolsa (horas)' => 8.33, 'Exceso (horas)' => 0,
            'Tarifa (€/h)' => 70, 'Tarifa congelada (€/h)' => '', 'Ingreso estimado (€)' => 833.33, 'Coste (€)' => 250,
        ])
        // Borrador: tarifa vigente del cliente (60 €/h): 120 × 60 / 60 = 120,00 €.
        ->and($byKey['2026-09-23 Ana 2'])->toMatchArray(['Estado' => 'Borrador', 'Tarifa (€/h)' => 60, 'Ingreso estimado (€)' => 120, 'Coste (€)' => 40])
        // 100 min dentro y 100 de exceso: 1000 × 100 / 600 + 100 × 70 / 60 = 283,33 €.
        ->and($byKey['2026-09-24 Luis 3.33'])->toMatchArray(['Dentro de bolsa (horas)' => 1.67, 'Exceso (horas)' => 1.67, 'Ingreso estimado (€)' => 283.33, 'Coste (€)' => 100])
        // Precio cerrado: 3000 × 240 / 1200 = 600,00 €, sin tarifa por hora.
        ->and($byKey['2026-09-24 Ana 4'])->toMatchArray(['Tarifa (€/h)' => '', 'Ingreso estimado (€)' => 600, 'Coste (€)' => 80])
        // Interno y no facturable: sin ingreso.
        ->and($byKey['2026-09-25 Luis 1'])->toMatchArray(['Cliente' => '', 'Facturable' => false, 'Tarifa (€/h)' => '', 'Ingreso estimado (€)' => 0, 'Coste (€)' => 30]);

    // La suma por entrada coincide con RevenueCalculator salvo el redondeo de cada una (1116,67 vs 1116,66).
    expect(round(array_sum(array_column($rows, 'Ingreso estimado (€)')), 2))->toBe(2111.66)
        ->and(round(array_sum(array_column($rows, 'Coste (€)')), 2))->toBe(600.0);
});

it('RevenueCalculator::perEntry valora cada entrada con los criterios de compute()', function () {
    $s = $this->s;
    $scope = new ReportScope($s->admin, ReportFilters::fromQuery(R3Scenario::week(['proyecto' => [$s->bank->project_id]])));
    $perEntry = app(RevenueCalculator::class)->perEntry($scope->entries());
    $total = app(RevenueCalculator::class)->compute($scope->entries())['all'];

    expect(collect($perEntry)->pluck('income')->all())->toEqualCanonicalizing(['833.33', '283.33'])
        ->and(collect($perEntry)->pluck('cost')->all())->toEqualCanonicalizing(['250.00', '100.00'])
        ->and($total['income'])->toBe('1116.67')
        ->and($total['cost'])->toBe('350.00');
});

it('sin view-financials no exporta tarifas, instantáneas ni importes', function () {
    $s = $this->s;
    $rows = ($this->readXlsx)(($this->export)($s->head)->assertOk()->streamedContent());

    expect($rows[0])->toBe(['Fecha', 'Persona', 'Cliente', 'Proyecto', 'Bolsa', 'Tarea', 'Tipo de tarea', 'Horas', 'Dentro de bolsa (horas)', 'Exceso (horas)', 'Facturable', 'Estado', 'Descripción'])
        ->and($rows)->toHaveCount(7);
});

it('cada uno exporta solo lo que ve (D-044)', function (string $who, array $expected) {
    $s = $this->s;
    $user = match ($who) {
        'admin' => $s->admin,
        'responsable' => $s->head,
        'gestor' => $s->manager,
        'empleada' => $s->ana,
    };

    $rows = ($this->table)(($this->export)($user));

    expect(collect($rows)->map(fn (array $row): string => $row['Persona'].' '.$row['Horas'])->sort()->values()->all())->toBe($expected);
})->with([
    'admin' => ['admin', ['Ana 2', 'Ana 4', 'Ana 5', 'Luis 1', 'Luis 3.33', 'Luis 8.33']],
    'responsable: su equipo' => ['responsable', ['Ana 2', 'Ana 4', 'Ana 5', 'Luis 1', 'Luis 3.33', 'Luis 8.33']],
    'gestor: su proyecto' => ['gestor', ['Luis 3.33', 'Luis 8.33']],
    'empleada: las suyas' => ['empleada', ['Ana 2', 'Ana 4', 'Ana 5']],
]);

it('respeta los filtros globales (facturable, proyecto y periodo)', function () {
    $s = $this->s;

    expect(($this->table)(($this->export)($s->admin, ['facturable' => 'no'])))->toHaveCount(1)
        ->and(($this->table)(($this->export)($s->admin, ['proyecto' => [$s->fixed->id]])))->toHaveCount(1)
        ->and(($this->table)(($this->export)($s->admin, ['fecha' => '2026-08-10', 'proyecto' => [$s->fixed->id]])))->toHaveCount(1);
});

it('exporta a CSV para Excel en español', function () {
    $s = $this->s;
    $csv = $this->actingAs($s->ana)
        ->get('/informes/horas/exportar?'.http_build_query(R3Scenario::week(['formato' => 'csv'])))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
        ->streamedContent();

    expect($csv)->toStartWith("\xEF\xBB\xBF")
        ->and($csv)->toContain('Fecha;Persona;Cliente;Proyecto;Bolsa;Tarea;"Tipo de tarea";Horas;')
        ->and($csv)->toContain('2026-09-22;Ana;"Cliente Uno";"TM · Por horas";;Maquetación;')
        ->and($csv)->toContain(';5,00;;;Sí;Aprobada;"Primera versión"')
        ->and($csv)->not->toContain('Luis');
});

it('por bloques: las mismas filas e importes con bloques de 2 entradas', function () {
    $s = $this->s;
    $whole = ($this->table)(($this->export)($s->admin));

    app()->when(HoursExportController::class)->needs('$chunkSize')->give(2);
    $chunked = ($this->table)(($this->export)($s->admin));

    expect($chunked)->toBe($whole)->and($chunked)->toHaveCount(6);
});

it('si hay más filas que el máximo, la última avisa en lugar de cortar en silencio', function () {
    app()->when(HoursExportController::class)->needs('$maxRows')->give(4);
    app()->when(HoursExportController::class)->needs('$chunkSize')->give(2);

    $rows = ($this->readXlsx)(($this->export)($this->s->admin)->streamedContent());

    expect($rows)->toHaveCount(5)
        ->and($rows[4][0])->toBe('Exportación recortada a las primeras 3 entradas: acota el periodo o los filtros para exportar el resto.');
});

it('con justo las filas que caben, no hay aviso', function () {
    app()->when(HoursExportController::class)->needs('$maxRows')->give(7);
    app()->when(HoursExportController::class)->needs('$chunkSize')->give(4);

    $rows = ($this->readXlsx)(($this->export)($this->s->admin)->streamedContent());

    expect($rows)->toHaveCount(7)->and(end($rows)[0])->toBe('2026-09-25');
});

it('no repite consultas por entrada: las mismas con 30 que con 90 entradas más', function () {
    $s = $this->s;
    $grow = function (int $count) use ($s): void {
        foreach (range(1, $count) as $n) {
            $task = Task::factory()->create(['project_id' => $s->tm->id, 'task_type_id' => TaskType::factory()]);
            TimeEntry::factory()->forTask($task)->on('2026-09-23')->minutes($n)->create(['user_id' => $s->luis->id, 'description' => "Entrada {$n}"]);
        }
    };
    $count = function () use ($s): int {
        $queries = 0;
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries++;
        });
        $this->actingAs($s->admin)->get('/informes/horas/exportar?'.http_build_query(R3Scenario::week(['formato' => 'csv'])))->streamedContent();
        app('events')->forget(QueryExecuted::class);

        return $queries;
    };

    // La primera petición carga roles y permisos (se guardan en caché, como en producción).
    $count();
    $grow(30);
    $before = $count();
    $grow(60);

    expect($count())->toBe($before)->and($before)->toBeLessThanOrEqual(14);
});

// --- Pestaña Horas del proyecto (D-021) ---

it('en la pestaña Horas, el empleado solo exporta las suyas aunque pida las de otro', function () {
    $s = $this->s;
    $url = fn (array $query = []) => "/proyectos/{$s->bank->project_id}/horas/exportar?".http_build_query(['formato' => 'xlsx', ...$query]);

    $luis = ($this->table)($this->actingAs($s->luis)->get($url()));
    expect(array_column($luis, 'Horas'))->toBe([8.33, 3.33]);

    $ana = ($this->table)($this->actingAs($s->ana)->get($url(['persona' => $s->luis->id])));
    expect($ana)->toBe([]);

    // Gestor del proyecto, responsable del equipo y admin: todas las del proyecto que ven.
    foreach ([$s->manager, $s->head, $s->admin] as $user) {
        expect(($this->table)($this->actingAs($user)->get($url())))->toHaveCount(2);
    }
});

it('la exportación de la pestaña Horas aplica sus filtros (fechas, estado, facturable, persona, bolsa)', function () {
    $s = $this->s;
    $url = fn (array $query) => "/proyectos/{$s->tm->id}/horas/exportar?".http_build_query(['formato' => 'xlsx', ...$query]);

    expect(($this->table)($this->actingAs($s->admin)->get($url([]))))->toHaveCount(2)
        ->and(array_column(($this->table)($this->actingAs($s->admin)->get($url(['estado' => 'draft']))), 'Horas'))->toBe([2])
        ->and(array_column(($this->table)($this->actingAs($s->admin)->get($url(['desde' => '2026-09-23']))), 'Horas'))->toBe([2])
        ->and(array_column(($this->table)($this->actingAs($s->admin)->get($url(['hasta' => '2026-09-22']))), 'Horas'))->toBe([5])
        ->and(($this->table)($this->actingAs($s->admin)->get($url(['facturable' => 'no']))))->toBe([])
        ->and(($this->table)($this->actingAs($s->admin)->get($url(['persona' => $s->luis->id]))))->toBe([])
        ->and(($this->table)($this->actingAs($s->admin)->get($url(['estado' => 'nada', 'desde' => '22-09-2026']))))->toHaveCount(2);

    $bankUrl = "/proyectos/{$s->bank->project_id}/horas/exportar?".http_build_query(['formato' => 'xlsx', 'bolsa' => $s->bank->id]);
    expect(($this->table)($this->actingAs($s->admin)->get($bankUrl)))->toHaveCount(2);
});

it('la exportación de la pestaña Horas lleva importes solo con view-financials', function () {
    $s = $this->s;
    $url = "/proyectos/{$s->tm->id}/horas/exportar?formato=xlsx";

    $admin = ($this->readXlsx)($this->actingAs($s->admin)->get($url)->streamedContent());
    $head = ($this->readXlsx)($this->actingAs($s->head)->get($url)->streamedContent());

    expect($admin[0])->toContain('Ingreso estimado (€)', 'Tarifa (€/h)')
        ->and($head[0])->not->toContain('Ingreso estimado (€)')
        ->and($head[0])->not->toContain('Tarifa (€/h)')
        ->and($this->actingAs($s->admin)->get($url)->headers->get('Content-Disposition'))->toContain('horas-tm-');
});

it('los clientes y los invitados no exportan', function () {
    $s = $this->s;

    $this->actingAs($s->client)->get('/informes/horas/exportar?formato=csv')->assertRedirect(route('portal.home'));
    $this->actingAs($s->client)->getJson("/proyectos/{$s->tm->id}/horas/exportar?formato=csv")->assertForbidden();
    expect(Gate::forUser($s->client)->allows('exportHours', TimeEntry::class))->toBeFalse();

    auth()->logout();
    $this->get('/informes/horas/exportar?formato=csv')->assertRedirect('/login');
    $this->get("/proyectos/{$s->tm->id}/horas/exportar")->assertRedirect('/login');
});

it('una entrada bloqueada o enviada sale con su estado', function () {
    $s = $this->s;
    TimeEntry::query()->where('minutes', 240)->update(['status' => TimeEntryStatus::Locked->value]);

    $rows = ($this->table)(($this->export)($s->admin, ['proyecto' => [$s->fixed->id]]));

    expect($rows[0]['Estado'])->toBe('Bloqueada');
});
