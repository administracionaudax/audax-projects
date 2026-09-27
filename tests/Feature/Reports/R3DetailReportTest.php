<?php

use App\Domain\Reports\Dimension;
use App\Domain\Reports\PivotReport;
use App\Domain\Reports\ReportCache;
use App\Domain\Reports\ReportFilters;
use App\Domain\Reports\ReportScope;
use App\Models\Department;
use App\Models\Task;
use App\Models\TaskType;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Tests\Feature\Reports\R3Scenario;

/*
| Informe detallado /informes/detalle (SPEC §10.6, D-044): tabla dinámica con subtotales, cada rol
| con su alcance, cifras frente al escenario calculado a mano (R3Scenario) y exportación tal cual.
*/

beforeEach(function () {
    $this->s = R3Scenario::build($this);
    $this->url = fn (array $query = []): string => '/informes/detalle?'.http_build_query(R3Scenario::week($query));
    // Lee un XLSX descargado (StreamedResponse) como filas de valores.
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
});

it('cruza persona × proyecto con subtotales por fila y columna, como a mano (admin)', function () {
    $s = $this->s;

    $this->actingAs($s->admin)
        ->get(($this->url)(['filas' => 'persona', 'columnas' => 'proyecto', 'departamento' => [$s->design->id]]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('reports/detail')
            ->where('layout', ['filas' => 'persona', 'columnas' => 'proyecto', 'medida' => 'imputadas'])
            ->where('pivot.total', 1420)
            ->where('pivot.rows.0.name', 'Luis')
            ->where('pivot.rows.1.name', 'Ana')
            ->where('pivot.row_totals.'.$s->luis->id, 760)
            ->where('pivot.row_totals.'.$s->ana->id, 660)
            ->where('pivot.columns.0.name', 'BOL · Bolsa')
            ->where('pivot.column_totals.'.$s->bank->project_id, 700)
            ->where('pivot.column_totals.'.$s->tm->id, 420)
            ->where('pivot.column_totals.'.$s->fixed->id, 240)
            ->where('pivot.column_totals.'.$s->internal->id, 60)
            ->where('pivot.cells.'.$s->luis->id.'.'.$s->bank->project_id, 700)
            ->where('pivot.cells.'.$s->ana->id.'.'.$s->tm->id, 420)
            ->where('pivot.truncated', false)
            ->where('summary', [
                'logged_minutes' => 1420,
                'billable_minutes' => 1360,
                'in_bank_minutes' => 1320,
                'overage_minutes' => 100,
                'billability' => 0.9577,
            ])
            ->where('comparison', null)
            ->where('dimensions', ['persona', 'departamento', 'cliente', 'proyecto', 'bolsa', 'tipo', 'tarea', 'semana', 'mes'])
            ->where('measures', ['imputadas', 'facturables', 'dentro', 'exceso'])
            // Las elecciones de la tabla viajan con los filtros (anterior, siguiente y la propia query).
            ->where('filters.query.filas', 'persona')
            ->where('filters.previous.fecha', '2026-09-14')
            ->where('filters.previous.columnas', 'proyecto')
            ->where('filters.next.medida', 'imputadas'));
});

it('calcula cada medida: facturables, dentro de bolsa y exceso', function (string $measure, int $total, int $luis, ?int $ana, int $columns) {
    $s = $this->s;

    $this->actingAs($s->admin)
        ->get(($this->url)(['filas' => 'persona', 'columnas' => 'proyecto', 'medida' => $measure, 'departamento' => [$s->design->id]]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('layout.medida', $measure)
            ->where('pivot.total', $total)
            ->where('pivot.row_totals.'.$s->luis->id, $luis)
            // Sin las filas ni las columnas a 0 (PivotReport withoutEmpty): quien no tiene exceso no sale.
            ->when($ana === null, fn (Assert $page) => $page->missing('pivot.row_totals.'.$s->ana->id)->has('pivot.rows', 1))
            ->when($ana !== null, fn (Assert $page) => $page->where('pivot.row_totals.'.$s->ana->id, $ana))
            ->has('pivot.columns', $columns));
})->with([
    // El proyecto interno (60 min no facturables) no sale como columna.
    'facturables (el interno no cuenta)' => ['facturables', 1360, 700, 660, 3],
    'dentro de bolsa' => ['dentro', 1320, 660, 660, 4],
    'exceso (solo la bolsa, solo Luis)' => ['exceso', 100, 100, null, 1],
]);

it('PivotReport::run con withoutEmpty quita las celdas a 0 sin cambiar los totales', function () {
    $s = $this->s;
    $scope = new ReportScope($s->admin, ReportFilters::fromQuery(R3Scenario::week(['departamento' => [$s->design->id]])));
    $pivot = app(PivotReport::class);

    // Por defecto (sin cambios): todas las personas y proyectos, con 0 donde no hay exceso.
    $all = $pivot->run($scope, Dimension::Person, Dimension::Project, 'overage');
    // Sin vacíos: solo Luis en la bolsa (100 min de exceso el 24).
    $nonEmpty = $pivot->run($scope, Dimension::Person, Dimension::Project, 'overage', withoutEmpty: true);

    expect($all['row_totals'])->toBe([(string) $s->luis->id => 100, (string) $s->ana->id => 0])
        ->and($all['columns'])->toHaveCount(4)
        ->and($nonEmpty['rows'])->toBe([['key' => (string) $s->luis->id, 'name' => 'Luis']])
        ->and($nonEmpty['columns'])->toBe([['key' => (string) $s->bank->project_id, 'name' => 'BOL · Bolsa']])
        ->and($nonEmpty['cells'])->toBe([(string) $s->luis->id => [(string) $s->bank->project_id => 100]])
        ->and($nonEmpty['row_totals'])->toBe([(string) $s->luis->id => 100])
        ->and($nonEmpty['column_totals'])->toBe([(string) $s->bank->project_id => 100])
        ->and($nonEmpty['total'])->toBe($all['total'])
        ->and($nonEmpty['total'])->toBe(100)
        // Con las horas imputadas no hay celdas a 0: el mismo resultado.
        ->and($pivot->run($scope, Dimension::Person, Dimension::Project, 'logged', withoutEmpty: true))
        ->toBe($pivot->run($scope, Dimension::Person, Dimension::Project));
});

it('muestra el estado vacío, no una tabla de ceros, si la medida no tiene horas', function (string $measure, string $project) {
    $s = $this->s;
    $query = ['filas' => 'persona', 'columnas' => 'semana', 'medida' => $measure, 'proyecto' => [$s->{$project}->id]];

    $this->actingAs($s->admin)
        ->get(($this->url)($query))
        ->assertInertia(fn (Assert $page) => $page
            ->where('pivot.rows', [])
            ->where('pivot.columns', [])
            ->where('pivot.cells', [])
            ->where('pivot.total', 0)
            ->where('pivot.truncated', false)
            // Las horas imputadas siguen en los KPIs.
            ->where('summary.logged_minutes', $project === 'tm' ? 420 : 60));

    // La exportación, igual: solo la cabecera y la fila de totales.
    $rows = ($this->readXlsx)($this->actingAs($s->admin)->get(($this->url)([...$query, 'formato' => 'xlsx']))->assertOk()->streamedContent());

    expect($rows)->toBe([['Persona / Semana (horas)', 'Total'], ['Total', 0]]);
})->with([
    'exceso en un proyecto sin bolsa' => ['exceso', 'tm'],
    'facturables en el proyecto interno' => ['facturables', 'internal'],
]);

it('agrupa por mes en columnas con los meses ordenados', function () {
    $s = $this->s;

    $this->actingAs($s->admin)
        ->get('/informes/detalle?'.http_build_query(['periodo' => 'trimestre', 'fecha' => '2026-07-01', 'filas' => 'proyecto', 'columnas' => 'mes', 'departamento' => [$s->design->id]]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('pivot.columns.0.key', '2026-08-01')
            ->where('pivot.columns.1.key', '2026-09-01')
            ->where('pivot.column_totals.2026-08-01', 60)
            ->where('pivot.column_totals.2026-09-01', 1420)
            ->where('pivot.row_totals.'.$s->fixed->id, 300)
            ->where('pivot.total', 1480));
});

it('agrupa por bolsa × tipo de tarea y por cliente, con «Sin bolsa», «Sin tipo» e «Interno»', function () {
    $s = $this->s;
    $type = TaskType::factory()->create(['name' => 'Diseño web']);
    $s->tmTask->update(['task_type_id' => $type->id]);

    // Bolsa anual: 700 min, sin tipo. Sin bolsa: 420 de «Diseño web» (TM) + 240 (FIX) + 60 (INT) sin tipo.
    $this->actingAs($s->admin)
        ->get(($this->url)(['filas' => 'bolsa', 'columnas' => 'tipo', 'departamento' => [$s->design->id]]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('pivot.rows', [
                ['key' => null, 'name' => 'Sin bolsa'],
                ['key' => (string) $s->bank->id, 'name' => 'BOL · Bolsa anual'],
            ])
            ->where('pivot.columns', [
                ['key' => null, 'name' => 'Sin tipo'],
                ['key' => (string) $type->id, 'name' => 'Diseño web'],
            ])
            ->where('pivot.cells', [
                (string) $s->bank->id => ['' => 700],
                '' => ['' => 300, (string) $type->id => 420],
            ])
            ->where('pivot.row_totals', ['' => 720, (string) $s->bank->id => 700])
            ->where('pivot.column_totals', ['' => 1000, (string) $type->id => 420])
            ->where('pivot.total', 1420));

    $this->actingAs($s->admin)
        ->get(($this->url)(['filas' => 'cliente', 'columnas' => 'mes', 'departamento' => [$s->design->id]]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('pivot.rows.0.name', $s->bank->project->client->name)
            ->where('pivot.row_totals', fn ($totals) => $totals[''] === 60 && $totals[$s->tm->client_id] === 420)
            ->where('pivot.rows.3', ['key' => null, 'name' => 'Interno (sin cliente)']));
});

it('con semanas en las filas, van en orden de fecha (en la página y en la exportación)', function () {
    $s = $this->s;
    // Agosto (60 min) tiene menos horas que septiembre (1420): por horas iría detrás.
    $query = ['periodo' => 'trimestre', 'fecha' => '2026-07-01', 'filas' => 'semana', 'columnas' => 'persona', 'departamento' => [$s->design->id]];

    $this->actingAs($s->admin)
        ->get('/informes/detalle?'.http_build_query($query))
        ->assertInertia(fn (Assert $page) => $page
            ->where('pivot.rows.0.key', '2026-08-10')
            ->where('pivot.rows.1.key', '2026-09-21')
            ->where('pivot.row_totals.2026-08-10', 60)
            ->where('pivot.row_totals.2026-09-21', 1420));

    $csv = $this->actingAs($s->admin)->get('/informes/detalle?'.http_build_query([...$query, 'formato' => 'csv']))->streamedContent();

    expect($csv)->toContain('"Sem. 10/08/2026";')
        ->and(strpos($csv, 'Sem. 10/08/2026'))->toBeLessThan(strpos($csv, 'Sem. 21/09/2026'));

    $months = $this->actingAs($s->admin)->get('/informes/detalle?'.http_build_query([...$query, 'filas' => 'mes', 'formato' => 'csv']))->streamedContent();

    expect($months)->toContain('"Agosto 2026";')
        ->and(strpos($months, 'Agosto 2026'))->toBeLessThan(strpos($months, 'Septiembre 2026'));
});

it('cada rol ve lo suyo (D-044)', function (string $who, int $total, bool $person, bool $team) {
    $s = $this->s;
    $user = match ($who) {
        'admin' => $s->admin,
        'responsable' => $s->head,
        'gestor' => $s->manager,
        'empleada' => $s->ana,
    };

    $this->actingAs($user)
        ->get(($this->url)(['filas' => 'persona', 'columnas' => 'proyecto']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('pivot.total', $total)
            ->where('summary.logged_minutes', $total)
            // Sin la dimensión persona, las filas vuelven a proyecto.
            ->where('layout.filas', $person ? 'persona' : 'proyecto')
            ->where('dimensions', fn ($dimensions) => collect($dimensions)->contains('persona') === $person)
            // Filtros de persona y departamento, solo con equipo (son los que tienen opciones).
            ->where('filterKeys', $team
                ? ['persona', 'departamento', 'cliente', 'proyecto', 'bolsa', 'tipo', 'facturable']
                : ['cliente', 'proyecto', 'bolsa', 'tipo', 'facturable']));
})->with([
    'admin: toda la agencia' => ['admin', 1420, true, true],
    'responsable: su equipo' => ['responsable', 1420, true, true],
    'gestor: las horas de su proyecto' => ['gestor', 700, true, false],
    'empleada: solo las suyas' => ['empleada', 660, false, false],
]);

it('la empleada no ve las horas de otra persona aunque la filtre', function () {
    $s = $this->s;

    $this->actingAs($s->ana)
        ->get(($this->url)(['persona' => [$s->luis->id]]))
        ->assertInertia(fn (Assert $page) => $page->where('pivot.total', 0)->where('pivot.rows', []));
});

it('un responsable no ve las horas de otro departamento', function () {
    $s = $this->s;
    $other = Department::factory()->create();
    $outsider = User::factory()->employee()->create(['department_id' => $other->id]);
    TimeEntry::factory()->forTask($s->tmTask)->on('2026-09-22')->minutes(45)->create(['user_id' => $outsider->id]);

    $this->actingAs($s->head)
        ->get(($this->url)())
        ->assertInertia(fn (Assert $page) => $page->where('pivot.total', 1420));

    $this->actingAs($s->admin)
        ->get(($this->url)())
        ->assertInertia(fn (Assert $page) => $page->where('pivot.total', 1465));
});

it('los clientes no entran (van a su portal) y hace falta iniciar sesión', function () {
    $client = $this->s->client;

    $this->actingAs($client)->get(($this->url)())->assertRedirect(route('portal.home'));
    $this->actingAs($client)->get(($this->url)(['formato' => 'xlsx']))->assertRedirect(route('portal.home'));
    $this->actingAs($client)->getJson(($this->url)())->assertForbidden();

    // Además de la ruta interna, la política lo niega (defensa en profundidad).
    expect(Gate::forUser($client)->allows('viewDetailReport', TimeEntry::class))->toBeFalse()
        ->and(Gate::forUser($this->s->ana)->allows('viewDetailReport', TimeEntry::class))->toBeTrue();

    auth()->logout();
    $this->get(($this->url)())->assertRedirect('/login');
});

it('ignora las elecciones no válidas y nunca repite la dimensión de filas en columnas', function () {
    $this->actingAs($this->s->admin)
        ->get(($this->url)(['filas' => 'semana', 'columnas' => 'semana', 'medida' => 'euros']))
        ->assertInertia(fn (Assert $page) => $page->where('layout', ['filas' => 'semana', 'columnas' => 'proyecto', 'medida' => 'imputadas']));

    $this->actingAs($this->s->admin)
        ->get(($this->url)(['filas' => 'dia', 'columnas' => ['x'], 'medida' => ['y']]))
        ->assertInertia(fn (Assert $page) => $page->where('layout', ['filas' => 'proyecto', 'columnas' => 'semana', 'medida' => 'imputadas']));
});

it('compara con el periodo anterior', function () {
    $s = $this->s;
    TimeEntry::factory()->forTask($s->tmTask)->on('2026-09-15')->minutes(90)->create(['user_id' => $s->ana->id]);

    $this->actingAs($s->admin)
        ->get(($this->url)(['comparar' => '1', 'departamento' => [$s->design->id]]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.logged_minutes', 1420)
            ->where('comparison.logged_minutes', 90)
            ->where('filters.comparison', ['from' => '2026-09-14', 'to' => '2026-09-20']));
});

it('no manda datos económicos: solo horas', function () {
    $this->actingAs($this->s->admin)
        ->get(($this->url)())
        ->assertInertia(fn (Assert $page) => $page
            ->missing('summary.income')
            ->missing('summary.cost')
            ->missing('summary.margin'));
});

it('usa la caché de informes y se invalida al imputar', function () {
    $s = $this->s;
    $this->actingAs($s->admin)->get(($this->url)())->assertInertia(fn (Assert $page) => $page->where('pivot.total', 1420));

    // Un cambio sin eventos (como la aprobación masiva) no se ve hasta invalidar la caché.
    TimeEntry::query()->where('minutes', 60)->update(['minutes' => 61]);
    $this->actingAs($s->admin)->get(($this->url)())->assertInertia(fn (Assert $page) => $page->where('pivot.total', 1420));

    ReportCache::bump();
    $this->actingAs($s->admin)->get(($this->url)())->assertInertia(fn (Assert $page) => $page->where('pivot.total', 1421));
});

it('exporta la tabla tal cual a XLSX, con subtotales y la fila de totales', function () {
    $s = $this->s;

    $response = $this->actingAs($s->admin)
        ->get(($this->url)(['filas' => 'persona', 'columnas' => 'proyecto', 'departamento' => [$s->design->id], 'formato' => 'xlsx']))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    expect($response->headers->get('Content-Disposition'))->toContain('horas-imputadas-por-persona-y-proyecto-del-2026-09-21-al-2026-09-27');

    $rows = ($this->readXlsx)($response->streamedContent());

    expect($rows[0])->toBe(['Persona / Proyecto (horas)', 'BOL · Bolsa', 'TM · Por horas', 'FIX · Precio cerrado', 'INT · Interno', 'Total'])
        ->and($rows[1][0])->toBe('Luis')
        ->and($rows[1][1])->toBe(11.67)
        ->and($rows[1][4])->toBe(1)
        ->and($rows[1][5])->toBe(12.67)
        ->and($rows[2][0])->toBe('Ana')
        ->and($rows[2][2])->toBe(7)
        ->and($rows[2][3])->toBe(4)
        ->and($rows[2][5])->toBe(11)
        ->and($rows[3])->toBe(['Total', 11.67, 7, 4, 1, 23.67])
        ->and($rows)->toHaveCount(4);
});

it('exporta a CSV con las semanas por su lunes y lo que ve cada uno', function () {
    $s = $this->s;

    $response = $this->actingAs($s->ana)
        ->get(($this->url)(['filas' => 'proyecto', 'columnas' => 'semana', 'formato' => 'csv']))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

    $csv = $response->streamedContent();

    expect($csv)->toStartWith("\xEF\xBB\xBF")
        ->and($csv)->toContain('"Proyecto / Semana (horas)";"Sem. 21/09/2026";Total')
        ->and($csv)->toContain('"TM · Por horas";7,00;7,00')
        ->and($csv)->toContain('"FIX · Precio cerrado";4,00;4,00')
        ->and($csv)->toContain('Total;11,00;11,00')
        ->and($csv)->not->toContain('Bolsa');
});

it('avisa si la tabla está recortada (más de 60 columnas), en la página y en la exportación', function () {
    $s = $this->s;
    // 61 tareas con horas: más columnas que PivotReport::MAX_COLUMNS (60).
    foreach (range(1, 61) as $n) {
        $task = Task::factory()->create(['project_id' => $s->tm->id, 'title' => "Tarea {$n}"]);
        TimeEntry::factory()->forTask($task)->on('2026-09-21')->minutes($n)->create(['user_id' => $s->ana->id]);
    }

    $query = ['filas' => 'persona', 'columnas' => 'tarea'];
    $this->actingAs($s->admin)->get(($this->url)($query))
        ->assertInertia(fn (Assert $page) => $page
            ->where('pivot.truncated', true)
            ->has('pivot.columns', 60)
            // Los totales incluyen también la columna que no se ve.
            ->where('pivot.total', 1420 + 61 * 31));

    $csv = $this->actingAs($s->admin)->get(($this->url)([...$query, 'formato' => 'csv']))->streamedContent();

    expect($csv)->toContain('Tabla recortada: se muestran las 200 filas y las 60 columnas con más horas');
});
