<?php

use App\Domain\HourBanks\Events\HourBankOverageRecorded;
use App\Domain\HourBanks\Events\HourBankThresholdReached;
use App\Domain\Reports\Dimension;
use App\Domain\Reports\Export\TableExporter;
use App\Domain\Reports\HourBanksAtRisk;
use App\Domain\Reports\Metrics;
use App\Domain\Reports\OverdueTasks;
use App\Domain\Reports\ReportFilters;
use App\Domain\Reports\ReportScope;
use App\Models\Client;
use App\Models\Department;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\Setting;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\TaskType;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\WorkSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/*
| R1 · Añadidos al contrato de la Fase 2 (app/Domain/Reports), con cifras calculadas a mano:
|  - Metrics::capacityByPerson: la capacidad de cada persona (capacityByDate es su suma),
|  - Metrics::elapsedCapacity(ByPerson): la capacidad transcurrida hasta ayer (dato informativo),
|  - Metrics::summaryFirstDays: los primeros días de un periodo frente a su capacidad completa
|    (la comparación «al mismo punto» de un periodo en curso),
|  - TableExporter: nunca escribe fórmulas (inyección de fórmulas en XLSX y CSV),
|  - ReportScope::withoutFinancials: el mismo alcance sin valorar ingreso ni coste,
|  - HourBanksAtRisk: bolsas abiertas desde el primer umbral, con los filtros y el alcance D-044,
|  - OverdueTasks: tareas abiertas con la fecha límite pasada, con los filtros y el alcance D-044.
| Semana del 21 al 27/09/2026; «hoy» es el viernes 25 (Madrid).
*/

beforeEach(function () {
    Event::fake([HourBankThresholdReached::class, HourBankOverageRecorded::class]);
    $this->travelTo(CarbonImmutable::parse('2026-09-25 12:00', 'Europe/Madrid'));
    TaskStatus::ensureDefaults();

    $this->design = Department::factory()->create(['name' => 'Diseño']);
    $this->marketing = Department::factory()->create(['name' => 'Marketing']);
    $this->admin = User::factory()->admin()->create();
    $this->head = User::factory()->departmentManager()->create();
    $this->design->managers()->attach($this->head);

    $this->week = fn (array $query = []): ReportFilters => ReportFilters::fromQuery(['periodo' => 'semana', 'fecha' => '2026-09-21', ...$query]);
    $this->queries = function (Closure $callback): int {
        $count = 0;
        DB::listen(function (QueryExecuted $query) use (&$count): void {
            $count++;
        });
        $callback();
        app('events')->forget(QueryExecuted::class);

        return $count;
    };
});

describe('Metrics::capacityByPerson', function () {
    beforeEach(function () {
        // Ana: 8 h de lunes a viernes; Luis: 4 h. Bea, de baja, imputó por última vez el martes
        // 22: su capacidad acaba ese día (2 × 480). Carlos, de baja y sin horas, no cuenta.
        $this->ana = User::factory()->employee()->create(['name' => 'Ana', 'department_id' => $this->design->id]);
        $this->luis = User::factory()->employee()->create(['name' => 'Luis', 'department_id' => $this->design->id]);
        $this->bea = User::factory()->employee()->create(['name' => 'Bea', 'department_id' => $this->design->id]);
        $carlos = User::factory()->employee()->create(['name' => 'Carlos', 'department_id' => $this->design->id, 'is_active' => false]);
        WorkSchedule::factory()->for($this->ana)->create(['valid_from' => '2026-01-01']);
        WorkSchedule::factory()->for($this->luis)->create(['valid_from' => '2026-01-01', 'mon_minutes' => 240, 'tue_minutes' => 240, 'wed_minutes' => 240, 'thu_minutes' => 240, 'fri_minutes' => 240]);
        WorkSchedule::factory()->for($this->bea)->create(['valid_from' => '2026-01-01']);
        WorkSchedule::factory()->for($carlos)->create(['valid_from' => '2026-01-01']);
        TimeEntry::factory()->on('2026-09-22')->minutes(60)->create(['user_id' => $this->bea->id]);
        $this->bea->update(['is_active' => false]);
    });

    it('da la capacidad de cada persona por día y su suma es capacityByDate', function () {
        $metrics = app(Metrics::class);
        $scope = new ReportScope($this->admin, ($this->week)(['departamento' => [$this->design->id]]));

        $byPerson = $metrics->capacityByPerson($scope);

        expect(array_keys($byPerson))->toEqualCanonicalizing([$this->ana->id, $this->luis->id, $this->bea->id])
            ->and(array_sum($byPerson[$this->ana->id]))->toBe(2400)
            ->and(array_sum($byPerson[$this->luis->id]))->toBe(1200)
            ->and($byPerson[$this->bea->id])->toBe(['2026-09-21' => 480, '2026-09-22' => 480])
            ->and($byPerson[$this->luis->id]['2026-09-26'])->toBe(0)
            ->and(array_sum($metrics->capacityByDate($scope)))->toBe(2400 + 1200 + 960)
            ->and($metrics->capacityByDate($scope)['2026-09-22'])->toBe(480 + 240 + 480);
    });

    it('calcula una vez por alcance y persona que mira en la misma instancia', function () {
        $metrics = app(Metrics::class);
        $scope = new ReportScope($this->admin, ($this->week)());

        $first = ($this->queries)(fn () => $metrics->capacityByPerson($scope));
        $again = ($this->queries)(fn () => $metrics->capacityByDate(new ReportScope($this->admin, ($this->week)())));

        expect($first)->toBeGreaterThan(0)->and($again)->toBe(0);

        // Otra persona que mira u otros filtros vuelven a calcular.
        expect(($this->queries)(fn () => $metrics->capacityByPerson(new ReportScope($this->head, ($this->week)()))))->toBeGreaterThan(0)
            ->and(($this->queries)(fn () => $metrics->capacityByPerson(new ReportScope($this->admin, ($this->week)(['persona' => [$this->ana->id]])))))->toBeGreaterThan(0);
    });

    it('un responsable solo tiene la capacidad de su equipo y la suya', function () {
        $outsider = User::factory()->employee()->create(['department_id' => $this->marketing->id]);
        WorkSchedule::factory()->for($outsider)->create(['valid_from' => '2026-01-01']);

        $byPerson = app(Metrics::class)->capacityByPerson(new ReportScope($this->head, ($this->week)()));

        expect(array_keys($byPerson))->toEqualCanonicalizing([$this->ana->id, $this->luis->id, $this->bea->id, $this->head->id]);
    });
});

describe('Metrics::elapsedCapacity y summaryFirstDays', function () {
    beforeEach(function () {
        // Ana: 8 h de lunes a viernes; Luis: 4 h. Ana imputa 480 min el lunes 21 y 240 el martes
        // 22 (facturables); Luis, 120 min no facturables el martes 22. El miércoles 23 Ana imputa
        // 360 min a una tarea estimada en 300 y la completa ese día.
        $this->ana = User::factory()->employee()->create(['department_id' => $this->design->id]);
        $this->luis = User::factory()->employee()->create(['department_id' => $this->design->id]);
        WorkSchedule::factory()->for($this->ana)->create(['valid_from' => '2026-01-01']);
        WorkSchedule::factory()->for($this->luis)->create(['valid_from' => '2026-01-01', 'mon_minutes' => 240, 'tue_minutes' => 240, 'wed_minutes' => 240, 'thu_minutes' => 240, 'fri_minutes' => 240]);
        TimeEntry::factory()->on('2026-09-21')->minutes(480)->create(['user_id' => $this->ana->id]);
        TimeEntry::factory()->on('2026-09-22')->minutes(240)->create(['user_id' => $this->ana->id]);
        TimeEntry::factory()->on('2026-09-22')->minutes(120)->create(['user_id' => $this->luis->id, 'is_billable' => false]);
        $done = Task::factory()->create(['estimated_minutes' => 300, 'assignee_user_id' => $this->ana->id]);
        TimeEntry::factory()->forTask($done)->on('2026-09-23')->minutes(360)->create(['user_id' => $this->ana->id]);
        $this->travelTo(CarbonImmutable::parse('2026-09-23 12:00', 'Europe/Madrid'));
        $done->update(['status_id' => TaskStatus::query()->where('category', 'done')->value('id')]);
        $this->travelTo(CarbonImmutable::parse('2026-09-25 12:00', 'Europe/Madrid'));
        $this->scope = fn (array $query = []): ReportScope => new ReportScope($this->admin, ($this->week)(['departamento' => [$this->design->id], ...$query]));
    });

    it('da la capacidad de los días del periodo hasta ayer (hoy aún se está imputando)', function () {
        $metrics = app(Metrics::class);
        $tuesday = CarbonImmutable::parse('2026-09-22');

        // Con «hoy» el martes 22: solo el lunes → Ana 480, Luis 240.
        expect($metrics->elapsedCapacityByPerson(($this->scope)(), $tuesday))->toEqual([$this->ana->id => 480, $this->luis->id => 240])
            ->and($metrics->elapsedCapacity(($this->scope)(), $tuesday))->toBe(720)
            // El primer día del periodo aún no ha transcurrido ninguno: 0 (nadie sale «baja»).
            ->and($metrics->elapsedCapacity(($this->scope)(), CarbonImmutable::parse('2026-09-21')))->toBe(0)
            // Hoy (viernes 25): de lunes a jueves, 4 × (480 + 240).
            ->and($metrics->elapsedCapacity(($this->scope)()))->toBe(2880)
            // Septiembre hasta el 24: 18 días laborables (del martes 1 al jueves 24) × 720.
            ->and($metrics->elapsedCapacity(($this->scope)(['periodo' => 'mes', 'fecha' => '2026-09-01'])))->toBe(18 * 720)
            // Un periodo cerrado cuenta entero y uno que no ha empezado, nada.
            ->and($metrics->elapsedCapacity(($this->scope)(['fecha' => '2026-09-14'])))->toBe(3600)
            ->and($metrics->elapsedCapacity(($this->scope)(['fecha' => '2026-09-28'])))->toBe(0);
    });

    it('sale de la capacidad ya calculada, sin más consultas', function () {
        $metrics = app(Metrics::class);
        $metrics->capacityByPerson(($this->scope)());

        expect(($this->queries)(fn () => $metrics->elapsedCapacity(($this->scope)())))->toBe(0);
    });

    it('no cambia la ocupación del contrato: imputadas / capacidad del periodo completo', function () {
        // Semana: capacidad 5 × 720 = 3600; imputadas 480 + 240 + 120 + 360 = 1200.
        expect(app(Metrics::class)->summary(($this->scope)()))->toMatchArray([
            'capacity_minutes' => 3600,
            'logged_minutes' => 1200,
            'billable_minutes' => 1080,
            'occupancy' => 0.3333,
            'billable_productivity' => 0.3,
        ]);
    });

    it('resume los primeros días del periodo frente a la capacidad del periodo completo', function () {
        $metrics = app(Metrics::class);

        // Lunes y martes: 480 + 240 + 120 = 840 (720 facturables) frente a 3600: 23,33 % y 20 %;
        // la tarea se completó el miércoles: aún sin precisión de estimación.
        expect($metrics->summaryFirstDays(($this->scope)(), 2))->toMatchArray([
            'capacity_minutes' => 3600,
            'logged_minutes' => 840,
            'billable_minutes' => 720,
            'occupancy' => 0.2333,
            'billability' => 0.8571,
            'billable_productivity' => 0.2,
            'estimation' => ['tasks' => 0, 'estimated_minutes' => 0, 'actual_minutes' => 0, 'accuracy' => null, 'deviation' => null],
        ])
            // De lunes a miércoles: 1200 (1080 facturables) → 33,33 % y 30 %; la tarea, 300 / 360.
            ->and($metrics->summaryFirstDays(($this->scope)(), 3))->toMatchArray([
                'capacity_minutes' => 3600,
                'logged_minutes' => 1200,
                'billable_minutes' => 1080,
                'occupancy' => 0.3333,
                'billability' => 0.9,
                'billable_productivity' => 0.3,
                'estimation' => ['tasks' => 1, 'estimated_minutes' => 300, 'actual_minutes' => 360, 'accuracy' => 0.8333, 'deviation' => 0.2],
            ])
            // Sin ningún día: sin horas, con la capacidad del periodo.
            ->and($metrics->summaryFirstDays(($this->scope)(), 0))->toMatchArray([
                'capacity_minutes' => 3600,
                'logged_minutes' => 0,
                'occupancy' => 0.0,
                'billability' => null,
                'income' => '0.00',
            ]);
    });

    it('valora el ingreso y el coste de esos días como un informe de solo esos días', function () {
        $metrics = app(Metrics::class);
        $partial = $metrics->summaryFirstDays(($this->scope)(), 2);
        $days = $metrics->summary(($this->scope)(['periodo' => 'rango', 'desde' => '2026-09-21', 'hasta' => '2026-09-22']));

        expect($partial['income'])->not->toBeNull()
            ->and([$partial['income'], $partial['cost'], $partial['margin'], $partial['margin_pct']])
            ->toBe([$days['income'], $days['cost'], $days['margin'], $days['margin_pct']])
            // Sin view-financials (o con withoutFinancials) tampoco los calcula.
            ->and($metrics->summaryFirstDays(($this->scope)()->withoutFinancials(), 2)['income'])->toBeNull()
            ->and($metrics->summaryFirstDays(new ReportScope($this->head, ($this->week)()), 2)['income'])->toBeNull();
    });

    it('con todos los días (o más) es summary(), y no recalcula la capacidad del tramo', function () {
        $metrics = app(Metrics::class);
        $summary = $metrics->summary(($this->scope)());

        expect($metrics->summaryFirstDays(($this->scope)(), 7))->toBe($summary)
            ->and($metrics->summaryFirstDays(($this->scope)(), 31))->toBe($summary);

        // La capacidad es la del periodo (ya calculada): el tramo no vuelve a leer los horarios.
        $sql = [];
        DB::listen(function (QueryExecuted $query) use (&$sql): void {
            $sql[] = $query->sql;
        });
        $metrics->summaryFirstDays(($this->scope)(), 2);
        app('events')->forget(QueryExecuted::class);

        expect($sql)->not->toBeEmpty()
            ->and(array_filter($sql, fn (string $query): bool => str_contains($query, 'work_schedules')))->toBe([]);
    });
});

describe('TableExporter sin fórmulas', function () {
    beforeEach(function () {
        $this->headers = ['=Cabecera', 'Horas'];
        $this->rows = [
            ['=HYPERLINK("https://x.test/?"&B2,"Ana")', -1.5],
            ['+34 600 000 000', 2],
            ['-Guion', -3],
            ['@SUM(1+1)', null],
            ["\tTab", 0.5],
            ["\rRetorno", 1],
            ['Normal = texto', 1],
        ];
    });

    it('en el XLSX, todo texto es una celda de texto, también si empieza por =', function () {
        $path = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
        app(TableExporter::class)->write($path, $this->headers, $this->rows, 'xlsx');

        $zip = new ZipArchive;
        $zip->open($path);
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        $reader = new XlsxReader;
        $reader->open($path);
        $cells = [];
        foreach ($reader->getSheetIterator() as $readSheet) {
            foreach ($readSheet->getRowIterator() as $row) {
                $cells[] = $row->cells;
            }
        }
        $reader->close();
        unlink($path);

        // En el XML no hay ninguna fórmula (<f>): los textos son celdas de texto (inlineStr). El
        // lector de OpenSpout devuelve como FormulaCell cualquier texto que empiece por «=», así que
        // el tipo se comprueba en el XML.
        expect($sheet)->not->toContain('<f>')
            ->and($sheet)->toContain('<c r="A1" s="1" t="inlineStr"><is><t>=Cabecera</t></is></c>')
            ->and($sheet)->toContain('<c r="A2" s="0" t="inlineStr"><is><t>=HYPERLINK(&quot;https://x.test/?&quot;&amp;B2,&quot;Ana&quot;)</t></is></c>')
            ->and($cells[0][0]->getValue())->toBe('=Cabecera')
            // El texto se guarda tal cual (sin apóstrofo): en el XLSX el tipo ya lo protege.
            ->and($cells[1][0]->getValue())->toBe('=HYPERLINK("https://x.test/?"&B2,"Ana")')
            ->and($cells[1][1])->toBeInstanceOf(NumericCell::class)
            ->and($cells[1][1]->getValue())->toBe(-1.5)
            ->and($cells[4][0]->getValue())->toBe('@SUM(1+1)');
    });

    it('en el CSV, los textos que empiezan por =, +, -, @, tabulador o retorno llevan un apóstrofo delante', function () {
        $path = tempnam(sys_get_temp_dir(), 'csv');
        app(TableExporter::class)->write($path, $this->headers, $this->rows, 'csv');
        $content = str_replace("\xEF\xBB\xBF", '', (string) file_get_contents($path));
        unlink($path);

        $handle = fopen('php://memory', 'r+');
        fwrite($handle, $content);
        rewind($handle);
        $rows = [];
        while (($row = fgetcsv($handle, null, ';', '"', '')) !== false) {
            $rows[] = $row;
        }
        fclose($handle);

        expect($rows[0])->toBe(["'=Cabecera", 'Horas'])
            ->and($rows[1])->toBe(["'=HYPERLINK(\"https://x.test/?\"&B2,\"Ana\")", '-1,50'])
            ->and($rows[2])->toBe(["'+34 600 000 000", '2'])
            ->and($rows[3])->toBe(["'-Guion", '-3'])
            ->and($rows[4])->toBe(["'@SUM(1+1)", ''])
            ->and($rows[5])->toBe(["'\tTab", '0,50'])
            ->and($rows[6])->toBe(["'\rRetorno", '1'])
            ->and($rows[7])->toBe(['Normal = texto', '1'])
            ->and(TableExporter::neutralize(''))->toBe('')
            ->and(TableExporter::neutralize('Ana'))->toBe('Ana');
    });

    it('escribe un texto vacío como celda vacía (no como «No»), en el CSV y en el XLSX', function () {
        $rows = [['Ana', '', 1.5, false], ['', 'Sin descripción', 0, true]];

        $csvPath = tempnam(sys_get_temp_dir(), 'csv');
        app(TableExporter::class)->write($csvPath, ['Persona', 'Descripción', 'Horas', 'Facturable'], $rows, 'csv');
        $lines = array_values(array_filter(explode("\n", str_replace("\xEF\xBB\xBF", '', (string) file_get_contents($csvPath)))));
        unlink($csvPath);

        $xlsxPath = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
        app(TableExporter::class)->write($xlsxPath, ['Persona', 'Descripción', 'Horas', 'Facturable'], $rows, 'xlsx');
        $reader = new XlsxReader;
        $reader->open($xlsxPath);
        $cells = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $cells[] = $row->cells;
            }
        }
        $reader->close();
        unlink($xlsxPath);

        expect(array_map(fn (string $line): array => str_getcsv($line, ';', '"', ''), $lines))->toBe([
            ['Persona', 'Descripción', 'Horas', 'Facturable'],
            ['Ana', '', '1,50', 'No'],
            ['', 'Sin descripción', '0', 'Sí'],
        ])
            ->and($cells[1][1] ?? null)->not->toBeInstanceOf(StringCell::class)
            ->and(($cells[1][1] ?? null)?->getValue())->toBeIn([null, ''])
            ->and($cells[1][2]->getValue())->toBe(1.5);
    });
});

describe('ReportScope::withoutFinancials', function () {
    it('quita el ingreso, el coste y el margen sin cambiar las horas ni consultar las tarifas', function () {
        $ana = User::factory()->employee()->create(['department_id' => $this->design->id, 'hourly_cost' => '20.00']);
        $project = Project::factory()->create(['hourly_rate' => '60.00']);
        TimeEntry::factory()->forTask(Task::factory()->create(['project_id' => $project->id]))->on('2026-09-22')->minutes(90)->create(['user_id' => $ana->id]);

        $metrics = app(Metrics::class);
        $scope = new ReportScope($this->admin, ($this->week)());
        $plain = $scope->withoutFinancials();

        $full = $metrics->summary($scope);
        $rates = 0;
        DB::listen(function (QueryExecuted $query) use (&$rates): void {
            $rates += str_contains($query->sql, '"projects"') || str_contains($query->sql, '"hour_banks"') ? 1 : 0;
        });
        $without = $metrics->summary($plain);

        expect($full['income'])->toBe('90.00')
            ->and($full['cost'])->toBe('30.00')
            ->and($scope->canSeeFinancials())->toBeTrue()
            ->and($plain->canSeeFinancials())->toBeFalse()
            ->and($without['logged_minutes'])->toBe(90)
            ->and($without['income'])->toBeNull()
            ->and($without['cost'])->toBeNull()
            ->and($without['margin'])->toBeNull()
            ->and($metrics->series($plain, Dimension::Day)[1]['income'])->toBeNull()
            ->and($rates)->toBe(0);

        // Con otros filtros vuelve a mirar el permiso.
        expect($plain->withFilters(($this->week)(['facturable' => 'si']))->canSeeFinancials())->toBeTrue();
    });
});

describe('HourBanksAtRisk', function () {
    beforeEach(function () {
        $this->luis = User::factory()->employee()->create(['department_id' => $this->design->id]);
        $this->marta = User::factory()->employee()->create(['department_id' => $this->marketing->id]);
        $this->clientA = Client::factory()->create(['name' => 'A']);

        // Consumo (dentro de la bolsa / total) → en riesgo desde el 75 % (primer umbral por defecto).
        $this->bank = function (int $total, User $user, int $minutes, array $attributes = []): HourBank {
            $bank = HourBank::factory()->create(['total_minutes' => $total, ...$attributes]);
            TimeEntry::factory()->forTask(Task::factory()->inBank($bank)->create())->on('2026-09-21')->minutes($minutes)->create(['user_id' => $user->id]);

            return $bank->fresh() ?? $bank;
        };
        $this->b78 = ($this->bank)(1000, $this->luis, 780);                                            // 78 %
        $this->b90 = ($this->bank)(600, $this->marta, 540, ['department_id' => $this->marketing->id]);  // 90 %
        $this->b50 = ($this->bank)(600, $this->luis, 300);                                             // 50 %: nunca
        $this->b120 = ($this->bank)(600, $this->marta, 720);                                           // 100 % + 120 de exceso
        $this->b120->project->update(['client_id' => $this->clientA->id]);
    });

    it('ordena de más a menos consumida (dentro de la bolsa) y usa el primer umbral configurado', function () {
        $atRisk = app(HourBanksAtRisk::class);
        $scope = new ReportScope($this->admin, ($this->week)());

        $result = $atRisk->forScope($scope);
        expect($result['threshold'])->toBe(75)
            ->and($result['count'])->toBe(3)
            ->and(array_column($result['banks'], 'id'))->toBe([$this->b120->id, $this->b90->id, $this->b78->id])
            ->and($result['banks'][0])->toMatchArray(['status' => 'exhausted', 'consumed_minutes' => 720, 'overage_minutes' => 120, 'ratio' => 1.0])
            ->and($result['banks'][0]['client'])->toBe('A')
            ->and($result['banks'][2]['ratio'])->toBe(0.78);

        // Con el primer umbral en el 80 %, la del 78 % ya no está en riesgo; el límite corta la lista, no el recuento.
        Setting::set('hour_bank_alert_thresholds', [100, 80]);
        $result = $atRisk->forScope($scope, limit: 1);
        expect($result['threshold'])->toBe(80)
            ->and($result['count'])->toBe(2)
            ->and(array_column($result['banks'], 'id'))->toBe([$this->b120->id]);
    });

    it('aplica los filtros de cliente, proyecto, bolsa y persona', function () {
        $ids = fn (array $query): array => array_column(app(HourBanksAtRisk::class)->forScope(new ReportScope($this->admin, ($this->week)($query)))['banks'], 'id');

        expect($ids(['cliente' => [$this->clientA->id]]))->toBe([$this->b120->id])
            ->and($ids(['proyecto' => [$this->b90->project_id, $this->b50->project_id]]))->toBe([$this->b90->id])
            ->and($ids(['bolsa' => [$this->b78->id, $this->b50->id]]))->toBe([$this->b78->id])
            // Personas: las bolsas de los proyectos donde han imputado.
            ->and($ids(['persona' => [$this->luis->id]]))->toBe([$this->b78->id])
            // Departamento: las suyas y las de proyectos donde ha imputado alguien del departamento.
            ->and($ids(['departamento' => [$this->marketing->id]]))->toBe([$this->b120->id, $this->b90->id]);
    });

    it('un responsable ve las de su departamento y las de proyectos con horas de su equipo; un gestor, las de sus proyectos', function () {
        $designBank = ($this->bank)(100, $this->marta, 80, ['department_id' => $this->design->id]);

        $head = app(HourBanksAtRisk::class)->forScope(new ReportScope($this->head, ($this->week)()));
        expect(array_column($head['banks'], 'id'))->toBe([$designBank->id, $this->b78->id]);

        // Aunque pida Marketing con el filtro, se queda en los suyos.
        $head = app(HourBanksAtRisk::class)->forScope(new ReportScope($this->head, ($this->week)(['departamento' => [$this->marketing->id]])));
        expect($head['count'])->toBe(2);

        $manager = User::factory()->employee()->create();
        $this->b90->project->addMember($manager, isManager: true);
        $managed = app(HourBanksAtRisk::class)->forScope(new ReportScope($manager, ($this->week)()));
        expect(array_column($managed['banks'], 'id'))->toBe([$this->b90->id]);
    });
});

describe('OverdueTasks', function () {
    beforeEach(function () {
        $this->luis = User::factory()->employee()->create(['name' => 'Luis', 'department_id' => $this->design->id]);
        $this->marta = User::factory()->employee()->create(['name' => 'Marta', 'department_id' => $this->marketing->id]);
        $this->client = Client::factory()->create();
        $this->project = Project::factory()->create(['client_id' => $this->client->id]);
        $this->other = Project::factory()->create();
        $this->type = TaskType::factory()->create();
        $this->bankTask = Task::factory()->inBank(HourBank::factory()->create(['project_id' => $this->other->id]))
            ->create(['assignee_user_id' => $this->marta->id, 'due_date' => '2026-09-15', 'title' => 'En bolsa']);

        $this->old = Task::factory()->create(['project_id' => $this->project->id, 'assignee_user_id' => $this->luis->id, 'due_date' => '2026-09-01', 'title' => 'Antigua', 'task_type_id' => $this->type->id]);
        $this->milestone = Task::factory()->milestone()->create(['project_id' => $this->other->id, 'assignee_user_id' => null, 'due_date' => '2026-09-24', 'title' => 'Hito']);
        Task::factory()->create(['project_id' => $this->project->id, 'assignee_user_id' => $this->luis->id, 'due_date' => '2026-09-25']);            // vence hoy
        Task::factory()->create(['project_id' => $this->project->id, 'assignee_user_id' => $this->luis->id, 'due_date' => null]);                    // sin fecha
        Task::factory()->completed()->create(['project_id' => $this->project->id, 'assignee_user_id' => $this->luis->id, 'due_date' => '2026-09-02']);
    });

    it('cuenta y lista las vencidas de la más antigua a la más reciente, con los días de retraso', function () {
        $result = app(OverdueTasks::class)->forScope(new ReportScope($this->admin, ($this->week)()), limit: 2);

        expect($result['count'])->toBe(3)
            ->and(array_column($result['tasks'], 'title'))->toBe(['Antigua', 'En bolsa'])
            ->and($result['tasks'][0])->toMatchArray(['due_date' => '2026-09-01', 'days_overdue' => 24, 'assignee' => 'Luis', 'is_milestone' => false])
            ->and($result['tasks'][0]['project']['code'])->toBe($this->project->code);

        $milestone = collect(app(OverdueTasks::class)->forScope(new ReportScope($this->admin, ($this->week)()))['tasks'])->firstWhere('id', $this->milestone->id);
        expect($milestone)->toMatchArray(['is_milestone' => true, 'assignee' => null, 'days_overdue' => 1]);
    });

    it('aplica los filtros de cliente, proyecto, bolsa, tipo y persona; el periodo no cuenta', function () {
        $titles = fn (array $query): array => array_column(app(OverdueTasks::class)->forScope(new ReportScope($this->admin, ($this->week)($query)))['tasks'], 'title');

        expect($titles(['cliente' => [$this->client->id]]))->toBe(['Antigua'])
            ->and($titles(['proyecto' => [$this->other->id]]))->toBe(['En bolsa', 'Hito'])
            ->and($titles(['bolsa' => [$this->bankTask->hour_bank_id]]))->toBe(['En bolsa'])
            ->and($titles(['tipo' => [$this->type->id]]))->toBe(['Antigua'])
            ->and($titles(['persona' => [$this->marta->id]]))->toBe(['En bolsa'])
            ->and($titles(['periodo' => 'mes', 'fecha' => '2025-01-01']))->toBe(['Antigua', 'En bolsa', 'Hito']);
    });

    it('un responsable solo ve las asignadas a su equipo (y a él)', function () {
        $result = app(OverdueTasks::class)->forScope(new ReportScope($this->head, ($this->week)()));

        expect($result['count'])->toBe(1)->and($result['tasks'][0]['id'])->toBe($this->old->id);
    });
});
