<?php

use App\Enums\Permission;
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
    $this->url = fn (array $extra = []): string => '/informes/facturacion?'.R2Scenario::week(['cliente' => [$this->s->client->id], ...$extra]);
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

test('sin cliente, la página pide elegir uno y la exportación no se hace', function () {
    $s = $this->s;

    $this->actingAs($s->admin)->get('/informes/facturacion')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('reports/billing')
            ->where('client', null)
            ->where('summary', null)
            ->where('clients.0.name', 'Bodega Ñandú')
            ->has('clients', 2));

    $this->actingAs($s->admin)->get('/informes/facturacion?formato=xlsx')->assertStatus(422);
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
                'pricing' => 'bank_price', 'rate' => '70.00', 'price_amount' => '1000.00', 'income' => '1221.67',
            ])
            ->where('summary.totals', [
                'logged_minutes' => 940, 'in_bank_minutes' => 600, 'overage_minutes' => 190, 'billable_minutes' => 910,
                'non_billable_minutes' => 30, 'pending_minutes' => 120, 'income' => '1337.67',
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
            'Horas en exceso', 'Facturable', 'Estado', 'Tarifa (€/h)', 'Importe (€)', 'Valoración'])
        // E1: la bloqueada E2 no cambia, así que E1 queda con 200 dentro y 100 de exceso:
        // 1000 × 200/600 + 100 × 70/60 = 450,00.
        ->and($rows[1])->toBe(['2026-09-22', 'Ana', 'NAN-WEB · Web corporativa', 'Bolsa Diseño ñ', 'Versión móvil', '¿Qué tal? ¡Sí! 12 €',
            5, 3.33, 1.67, true, 'Aprobada', 70, 450, $bankPrice])
        ->and($rows[2])->toBe(['2026-09-22', 'Marta', 'NAN-CAMP · Campaña otoño', 'Sin bolsa', 'Plan de medios', 'Plan',
            2, '', '', true, 'Aprobada', 58, 116, 'Tarifa congelada al aprobar'])
        // E2: 1000 × 400/600 = 666,67.
        ->and($rows[3])->toBe(['2026-09-23', 'Luis', 'NAN-WEB · Web corporativa', 'Bolsa Diseño ñ', 'Maquetación', 'Maquetación de cabecera',
            6.67, 6.67, 0, true, 'Bloqueada', 70, 666.67, $bankPrice])
        // E3: todo exceso: 90 × 70/60 = 105,00.
        ->and($rows[4])->toBe(['2026-09-24', 'Ana', 'NAN-WEB · Web corporativa', 'Bolsa Diseño ñ', 'Diseño de la home', 'Borrador que no sale en el PDF',
            1.5, 0, 1.5, true, 'Borrador', 70, 105, $bankPrice])
        ->and($rows[5])->toBe(['2026-09-25', 'Ana', 'NAN-CAMP · Campaña otoño', 'Sin bolsa', 'Plan de medios', 'Reunión interna',
            0.5, '', '', false, 'Borrador', '', 0, 'No facturable'])
        // La suma de los importes es el ingreso estimado del resumen.
        ->and($rows[6])->toBe(['Total', '', '', '', '', '', 15.67, 10, 3.17, '', '', '', 1337.67, '']);
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
        ->and($rows[1][12])->toBe('450,00')
        ->and(end($rows))->toBe(['Total', '', '', '', '', '', '13,67', '10,00', '3,17', '', '', '', '1221,67', '']);
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
    expect($rows[0])->toHaveCount(11)
        ->and($rows[0])->not->toContain('Importe (€)')
        ->and($rows[1])->toHaveCount(11);
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
