<?php

use App\Domain\Reports\Export\TableExporter;
use App\Enums\Permission;
use App\Models\Client;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use Inertia\Testing\AssertableInertia as Assert;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Reports\R2Scenario;

/*
| Exportación de horas para facturar (SPEC §10, D-045; R2) frente al escenario calculado a mano:
| permisos (admins y view-financials), resumen por proyecto y bolsa con dentro, exceso, facturables,
| pendientes de aprobar, tarifas e importes (D-043), y el detalle de cada entrada en XLSX y CSV.
*/

beforeEach(function () {
    $this->s = R2Scenario::build($this);
    $this->url = fn (array $extra = []): string => '/facturacion/por-facturar?'.R2Scenario::week(['cliente' => [$this->s->client->id], ...$extra]);
    $this->read = function (string $content, string $format): array {
        $path = tempnam(sys_get_temp_dir(), 'r2').'.'.$format;
        file_put_contents($path, $content);
        $reader = $format === 'csv' ? new CsvReader(new CsvOptions(FIELD_DELIMITER: ';')) : new XlsxReader;
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

test('permisos: admins y quien tenga view-financials; el resto no', function () {
    $s = $this->s;

    $this->actingAs($s->admin)->get(($this->url)())->assertOk();
    $this->actingAs($s->raul)->get(($this->url)())->assertForbidden();
    $this->actingAs($s->gema)->get(($this->url)())->assertForbidden();
    $this->actingAs($s->ana)->get(($this->url)(['formato' => 'xlsx']))->assertForbidden();
    $this->actingAs(userWithRole('client'))->get(($this->url)())->assertRedirect(route('portal.home'));

    $s->raul->givePermissionTo(Permission::ViewFinancials->value);
    $this->actingAs($s->raul->fresh())->get(($this->url)())->assertOk();

    auth()->logout();
    $this->get(($this->url)())->assertRedirect(route('login'));
});

test('no compara con el periodo anterior: sin comparar en la barra ni en sus enlaces (INT-05)', function () {
    $this->actingAs($this->s->admin)->get(($this->url)(['comparar' => '1']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.compare', false)
            ->where('filters.comparison', null)
            ->missing('filters.query.comparar')
            ->missing('filters.previous.comparar')
            ->missing('filters.next.comparar'));
});

test('sin cliente, la página es la lista de clientes por facturar (I10) y la exportación no se hace', function () {
    $s = $this->s;

    $this->actingAs($s->admin)->get('/facturacion/por-facturar')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('billing/unbilled')
            ->where('filters.period', 'anio')
            ->has('report.clients'));

    $this->actingAs($s->admin)->get('/facturacion/por-facturar?formato=xlsx')->assertStatus(422);
});

test('resumen por proyecto y bolsa con tarifas e importes (D-043), calculado a mano', function () {
    $s = $this->s;

    $this->actingAs($s->admin)->get(($this->url)())
        ->assertInertia(fn (Assert $page) => $page
            ->where('client.name', 'Bodega Ñandú')
            ->where('filters.query.cliente', [$s->client->id])
            ->has('summary.rows', 2)
            ->where('summary.rows.0', [
                'project' => ['id' => $s->campaign->id, 'code' => 'NAN-CAMP', 'name' => 'Campaña otoño', 'billing_type' => 'time_and_materials'],
                'bank' => null,
                'logged_minutes' => 150, 'in_bank_minutes' => 0, 'overage_minutes' => 0,
                'billable_minutes' => 120, 'non_billable_minutes' => 30, 'pending_minutes' => 30,
                'pricing' => 'hourly', 'rate' => '60.00', 'price_amount' => null, 'income' => '116.00',
            ])
            ->where('summary.rows.1', [
                'project' => ['id' => $s->web->id, 'code' => 'NAN-WEB', 'name' => 'Web corporativa', 'billing_type' => 'hour_bank'],
                'bank' => ['id' => $s->b1->id, 'name' => 'Bolsa Diseño ñ', 'status' => 'exhausted'],
                'logged_minutes' => 790, 'in_bank_minutes' => 600, 'overage_minutes' => 190,
                'billable_minutes' => 790, 'non_billable_minutes' => 0, 'pending_minutes' => 90,
                // 1000 × 600/600 + 100 × 55/60 (exceso de E1, aprobada: su tarifa congelada) + 90 × 70/60 (E3).
                'pricing' => 'bank_price', 'rate' => '70.00', 'price_amount' => '1000.00', 'income' => '1196.67',
            ])
            ->where('export_limit', 19999)
            ->where('can.viewReport', true)
            ->where('summary.totals', [
                'entries' => 5, 'logged_minutes' => 940, 'in_bank_minutes' => 600, 'overage_minutes' => 190, 'billable_minutes' => 910,
                'non_billable_minutes' => 30, 'pending_minutes' => 120, 'income' => '1312.67',
            ]));
});

test('el detalle de cada entrada en XLSX: dentro y exceso por separado, tarifa, importe y totales', function () {
    $s = $this->s;

    $response = $this->actingAs($s->admin)->get(($this->url)(['formato' => 'xlsx']));
    $response->assertOk();
    expect($response->headers->get('Content-Disposition'))->toContain('horas-para-facturar-bodega-nandu');

    $rows = ($this->read)($response->streamedContent(), 'xlsx');
    $bankPrice = 'Precio de la bolsa (el exceso, a tarifa)';

    expect($rows)->toHaveCount(7)
        ->and($rows[0])->toBe(['Fecha', 'Persona', 'Proyecto', 'Bolsa', 'Tarea', 'Descripción', 'Horas', 'Horas dentro de bolsa',
            'Horas en exceso', 'Facturable', 'Estado', 'Tarifa (€/h)', 'Importe (€)', 'Valoración',
            'Minutos', 'Minutos dentro de bolsa', 'Minutos en exceso'])
        // E1: la bloqueada E2 no cambia, así que E1 queda con 200 dentro y 100 de exceso. Está
        // aprobada: su exceso va a su tarifa congelada (D-043, BIZ-01): 1000 × 200/600 + 100 × 55/60 = 425,00.
        ->and($rows[1])->toBe(['2026-09-22', 'Ana', 'NAN-WEB · Web corporativa', 'Bolsa Diseño ñ', 'Versión móvil', '¿Qué tal? ¡Sí! 12 €',
            5, 3.33, 1.67, true, 'Aprobada', 55, 425, $bankPrice, 300, 200, 100])
        ->and($rows[2])->toBe(['2026-09-22', 'Marta', 'NAN-CAMP · Campaña otoño', 'Sin bolsa', 'Plan de medios', 'Plan',
            2, '', '', true, 'Aprobada', 58, 116, 'Tarifa congelada al aprobar', 120, '', ''])
        // E2: 1000 × 400/600 = 666,67.
        ->and($rows[3])->toBe(['2026-09-23', 'Luis', 'NAN-WEB · Web corporativa', 'Bolsa Diseño ñ', 'Maquetación', 'Maquetación de cabecera',
            6.67, 6.67, 0, true, 'Bloqueada', 70, 666.67, $bankPrice, 400, 400, 0])
        // E3: todo exceso: 90 × 70/60 = 105,00.
        ->and($rows[4])->toBe(['2026-09-24', 'Ana', 'NAN-WEB · Web corporativa', 'Bolsa Diseño ñ', 'Diseño de la home', 'Borrador que no sale en el PDF',
            1.5, 0, 1.5, true, 'Borrador', 70, 105, $bankPrice, 90, 0, 90])
        ->and($rows[5])->toBe(['2026-09-25', 'Ana', 'NAN-CAMP · Campaña otoño', 'Sin bolsa', 'Plan de medios', 'Reunión interna',
            0.5, '', '', false, 'Borrador', '', 0, 'No facturable', 30, '', ''])
        // La suma de los importes es el ingreso estimado del resumen; los minutos suman exacto (D-081).
        ->and($rows[6])->toBe(['Total', '', '', '', '', '', 15.67, 10, 3.17, '', '', '', 1312.67, '', 940, 600, 190]);
});

test('el CSV sale para Excel en español; quien tiene view-financials sin ser admin solo exporta las horas que ve', function () {
    $s = $this->s;
    $s->raul->givePermissionTo(Permission::ViewFinancials->value);

    $response = $this->actingAs($s->raul->fresh())->get(($this->url)(['formato' => 'csv']));
    $content = $response->assertOk()->streamedContent();
    expect(str_starts_with($content, "\xEF\xBB\xBF"))->toBeTrue();

    $rows = ($this->read)($content, 'csv');
    // Marta es de Desarrollo: su entrada no sale (D-021).
    expect(array_column(array_slice($rows, 1, -1), 1))->toBe(['Ana', 'Luis', 'Ana', 'Ana'])
        ->and($rows[1][6])->toBe('5,00')
        ->and($rows[1][9])->toBe('Sí')
        ->and($rows[1][12])->toBe('425,00')
        ->and(end($rows))->toBe(['Total', '', '', '', '', '', '13,67', '10,00', '3,17', '', '', '', '1196,67', '', '820', '600', '190']);
});

test('un admin sin view-financials exporta las horas sin tarifas ni importes', function () {
    $s = $this->s;
    Role::findByName('admin')->revokePermissionTo(Permission::ViewFinancials->value);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->actingAs($s->admin->fresh())->get(($this->url)())
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.can_see_financials', false)
            ->where('summary.rows.1.rate', null)
            ->where('summary.rows.1.price_amount', null)
            ->where('summary.rows.1.income', null)
            ->where('summary.rows.1.pricing', null)
            ->where('summary.totals.income', null));

    $rows = ($this->read)($this->actingAs($s->admin->fresh())->get(($this->url)(['formato' => 'xlsx']))->streamedContent(), 'xlsx');
    expect($rows[0])->toHaveCount(14)
        ->and($rows[0])->not->toContain('Importe (€)')
        ->and(array_slice($rows[0], -3))->toBe(['Minutos', 'Minutos dentro de bolsa', 'Minutos en exceso'])
        ->and($rows[1])->toHaveCount(14);
});

test('los textos que empiezan por = + - @ salen como texto, nunca como fórmula (XLSX y CSV)', function () {
    $s = $this->s;
    $s->e1->forceFill(['description' => '=1+1'])->save();
    $s->e2->forceFill(['description' => '-2+3'])->save();
    $s->e4->forceFill(['description' => '@SUM(A1)'])->save();
    $s->s1->forceFill(['title' => '=HYPERLINK("http://evil.example","Ver factura")'])->save();
    $s->marta->forceFill(['name' => '+Marta'])->save();

    $content = $this->actingAs($s->admin)->get(($this->url)(['formato' => 'xlsx']))->assertOk()->streamedContent();

    // En la hoja no hay ninguna fórmula: cada texto es una cadena en línea.
    $path = tempnam(sys_get_temp_dir(), 'r2').'.xlsx';
    file_put_contents($path, $content);
    $zip = new ZipArchive;
    $zip->open($path);
    $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    unlink($path);

    expect($sheet)->not->toContain('<f>')
        ->and($sheet)->toContain('t="inlineStr"><is><t>=1+1</t></is>')
        ->and($sheet)->toContain('<t>=HYPERLINK(&quot;http://evil.example&quot;,&quot;Ver factura&quot;)</t>');

    $rows = ($this->read)($content, 'xlsx');
    expect($rows[1][4])->toBe('=HYPERLINK("http://evil.example","Ver factura")')
        ->and($rows[1][5])->toBe('=1+1')
        ->and($rows[2][1])->toBe('+Marta')
        ->and($rows[2][5])->toBe('@SUM(A1)')
        ->and($rows[3][5])->toBe('-2+3');

    // En el CSV, un apóstrofo delante para que Excel no lo ejecute; los números negativos no cambian.
    $csv = ($this->read)($this->actingAs($s->admin)->get(($this->url)(['formato' => 'csv']))->streamedContent(), 'csv');
    expect($csv[1][4])->toBe('\'=HYPERLINK("http://evil.example","Ver factura")')
        ->and($csv[1][5])->toBe("'=1+1")
        ->and($csv[2][1])->toBe("'+Marta")
        ->and($csv[2][5])->toBe("'@SUM(A1)")
        ->and($csv[3][5])->toBe("'-2+3")
        ->and($csv[1][6])->toBe('5,00');
});

test('el total del importe es el mismo en la página y en la exportación, y las líneas suman ese total', function () {
    $s = $this->s;

    // Dos bolsas de 1000 € por 3600 min (60 h) con 7 min cada una: 1000 × 7/3600 = 1,944444 €.
    // Redondeando cada grupo saldría 1,94 + 1,94 = 3,88; la suma exacta es 3,888888 → 3,89. Los
    // céntimos se reparten por resto mayor (a igualdad, la primera fila): 1,95 + 1,94.
    $client = Client::factory()->create(['name' => 'Redondeos']);
    $project = Project::factory()->hourBank()->create(['client_id' => $client->id, 'code' => 'RED-WEB']);
    foreach (['Bolsa A', 'Bolsa B'] as $name) {
        $bank = HourBank::factory()->create(['project_id' => $project->id, 'name' => $name, 'total_minutes' => 3600, 'price_amount' => '1000.00', 'start_date' => '2026-09-01']);
        TimeEntry::factory()->forTask(Task::factory()->inBank($bank)->create())->on('2026-09-22')->minutes(7)->create(['user_id' => $s->ana->id]);
    }
    $url = '/facturacion/por-facturar?'.R2Scenario::week(['cliente' => [$client->id]]);

    $this->actingAs($s->admin)->get($url)
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.rows.0.income', '1.95')
            ->where('summary.rows.1.income', '1.94')
            ->where('summary.totals.income', '3.89'));

    $rows = ($this->read)($this->actingAs($s->admin)->get($url.'&formato=xlsx')->streamedContent(), 'xlsx');
    expect(array_column(array_slice($rows, 1, -1), 12))->toBe([1.94, 1.95])
        ->and(end($rows)[12])->toBe(3.89);
});

test('si el total del resumen difiere por el truncado de cada entrada, la última línea con importe recoge el céntimo', function () {
    $s = $this->s;

    // Bolsa de 30 min por 0,05 €: tres entradas de 1 min valen 0,05/30 = 0,001666… cada una. Por
    // grupos: 0,05 × 3/30 = 0,005 → 0,01 €. Entrada a entrada (truncadas a 6 decimales) sumarían
    // 0,004998 → 0,00 €: la última línea con importe lleva el céntimo y el fichero cuadra con la página.
    $client = Client::factory()->create(['name' => 'Céntimos']);
    $project = Project::factory()->hourBank()->create(['client_id' => $client->id, 'code' => 'CEN-WEB']);
    $bank = HourBank::factory()->create(['project_id' => $project->id, 'total_minutes' => 30, 'price_amount' => '0.05', 'start_date' => '2026-09-01']);
    $task = Task::factory()->inBank($bank)->create();
    foreach (['2026-09-21', '2026-09-22', '2026-09-23'] as $date) {
        TimeEntry::factory()->forTask($task)->on($date)->minutes(1)->create(['user_id' => $s->ana->id]);
    }
    // Una entrada no facturable al final: nunca recibe importe.
    TimeEntry::factory()->forTask($task)->on('2026-09-24')->minutes(1)->create(['user_id' => $s->ana->id, 'is_billable' => false]);
    $url = '/facturacion/por-facturar?'.R2Scenario::week(['cliente' => [$client->id]]);

    $this->actingAs($s->admin)->get($url)->assertInertia(fn (Assert $page) => $page->where('summary.totals.income', '0.01'));

    $rows = ($this->read)($this->actingAs($s->admin)->get($url.'&formato=csv')->streamedContent(), 'csv');
    expect(array_column(array_slice($rows, 1, -1), 12))->toBe(['0,00', '0,00', '0,01', '0,00'])
        ->and(end($rows)[12])->toBe('0,01');
});

test('la exportación calcula su total con las mismas entradas que exporta, aunque el resumen de la página esté en caché (PERF-02)', function () {
    $s = $this->s;
    $this->actingAs($s->admin)->get(($this->url)())->assertInertia(fn (Assert $page) => $page->where('summary.totals.income', '1312.67'));

    // Un cambio que no invalida la caché de informes (una actualización sin eventos): E4 pasa de 120 a 150 min.
    TimeEntry::query()->whereKey($s->e4->id)->update(['minutes' => 150]);
    $this->actingAs($s->admin)->get(($this->url)())->assertInertia(fn (Assert $page) => $page->where('summary.totals.income', '1312.67'));

    // El fichero no fuerza el total de la página: E4 vale 150 × 58/60 = 145, ninguna línea recoge la
    // diferencia y el total es la suma de sus líneas (1312,67 − 116 + 145).
    $rows = ($this->read)($this->actingAs($s->admin)->get(($this->url)(['formato' => 'xlsx']))->streamedContent(), 'xlsx');
    $lines = array_column(array_slice($rows, 1, -1), 12);

    expect($lines)->toBe([425, 145, 666.67, 105, 0])
        ->and(end($rows)[12])->toBe(1341.67)
        ->and(round(array_sum($lines), 2))->toBe(1341.67);
});

test('las horas en decimal no siempre suman el total; los minutos, sí (INT-06, D-081)', function () {
    $s = $this->s;
    // Tres entradas de 20 min: 0,33 h cada una (0,99 h en total) frente a 1,00 h.
    $client = Client::factory()->create(['name' => 'Minutos']);
    $task = Task::factory()->create(['project_id' => Project::factory()->create(['client_id' => $client->id])->id]);
    foreach (['2026-09-21', '2026-09-22', '2026-09-23'] as $date) {
        TimeEntry::factory()->forTask($task)->on($date)->minutes(20)->create(['user_id' => $s->ana->id]);
    }

    $rows = ($this->read)($this->actingAs($s->admin)
        ->get('/facturacion/por-facturar?'.R2Scenario::week(['cliente' => [$client->id], 'formato' => 'xlsx']))->streamedContent(), 'xlsx');
    $lines = array_slice($rows, 1, -1);
    $total = end($rows);
    $hours = array_search('Horas', $rows[0], true);
    $minutes = array_search('Minutos', $rows[0], true);

    expect(round(array_sum(array_column($lines, $hours)), 2))->toBe(0.99)
        ->and($total[$hours])->toBe(1)
        ->and(array_sum(array_column($lines, $minutes)))->toBe(60)
        ->and($total[$minutes])->toBe(60);
});

test('si las entradas no caben en la exportación responde 422 en vez de recortarla; la página lo avisa', function () {
    $s = $this->s;
    // 5 entradas + la fila de totales = 6 filas.
    app()->instance(TableExporter::class, (new TableExporter)->withMaxRows(5));

    $this->actingAs($s->admin)->get(($this->url)())
        ->assertInertia(fn (Assert $page) => $page
            ->where('export_limit', 4)
            ->where('summary.totals.entries', 5));

    $this->actingAs($s->admin)->get(($this->url)(['formato' => 'xlsx']))
        ->assertStatus(422)
        ->assertSee('Hay 5 entradas y la exportación admite hasta 4.');

    // Con el filtro de proyecto caben (NAN-CAMP: 2 entradas + totales).
    $rows = ($this->read)($this->actingAs($s->admin)->get(($this->url)(['formato' => 'csv', 'proyecto' => [$s->campaign->id]]))->assertOk()->streamedContent(), 'csv');
    expect($rows)->toHaveCount(4)
        ->and(end($rows)[0])->toBe('Total');

    app()->instance(TableExporter::class, (new TableExporter)->withMaxRows(6));
    $this->actingAs($s->admin)->get(($this->url)(['formato' => 'xlsx']))->assertOk();
});

test('el enlace al informe del cliente solo va a quien puede verlo', function () {
    $s = $this->s;
    $s->luis->givePermissionTo(Permission::ViewFinancials->value);

    // Luis (empleado con view-financials) exporta para facturar, pero no ve el informe del cliente.
    $this->actingAs($s->luis->fresh())->get(($this->url)())
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('can.viewReport', false));
    $this->actingAs($s->luis->fresh())->get('/informes/clientes/'.$s->client->id)->assertForbidden();

    // Sin cliente, la lista (I10): no lleva enlace a ningún informe de cliente.
    $this->actingAs($s->admin)->get('/facturacion/por-facturar')
        ->assertInertia(fn (Assert $page) => $page->component('billing/unbilled')->missing('can'));
});

test('la ficha del cliente enlaza sus horas para facturar a quien puede exportarlas (can.viewBilling)', function () {
    $s = $this->s;
    $show = "/clientes/{$s->client->id}";
    $s->luis->givePermissionTo(Permission::ViewFinancials->value);

    foreach ([[$s->admin, true], [$s->luis->fresh(), true], [$s->raul, false], [$s->gema, false], [$s->ana, false]] as [$viewer, $expected]) {
        $this->actingAs($viewer)->get($show)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('clients/show')->where('can.viewBilling', $expected));
    }
});
