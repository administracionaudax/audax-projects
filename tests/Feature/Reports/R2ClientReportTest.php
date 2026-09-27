<?php

use Inertia\Testing\AssertableInertia as Assert;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Tests\Feature\Reports\R2Scenario;

/*
| Informe de cliente (SPEC §10.2, D-044; R2) frente al escenario calculado a mano (R2Scenario):
| permisos, cifras, bolsas con su consumo, histórico de renovaciones, datos económicos solo con
| view-financials y exportación.
*/

beforeEach(function () {
    $this->s = R2Scenario::build($this);
    $this->url = fn (array $extra = []): string => "/informes/clientes/{$this->s->client->id}?".R2Scenario::week($extra);
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

test('matriz de permisos (D-044): admin, responsable y gestor sí; empleado, gestor de otro cliente y cliente no', function () {
    $s = $this->s;

    $this->actingAs($s->admin)->get(($this->url)())->assertOk();
    $this->actingAs($s->raul)->get(($this->url)())->assertOk();
    $this->actingAs($s->gema)->get(($this->url)())->assertOk();
    $this->actingAs($s->olga)->get(($this->url)())->assertOk();

    $this->actingAs($s->ana)->get(($this->url)())->assertForbidden();
    $this->actingAs($s->marta)->get(($this->url)())->assertForbidden();
    // Gema gestiona NAN-WEB, no ningún proyecto del otro cliente.
    $this->actingAs($s->gema)->get("/informes/clientes/{$s->otherClient->id}")->assertForbidden();
    // Los clientes nunca ven la aplicación interna.
    $this->actingAs(userWithRole('client'))->get(($this->url)())->assertRedirect(route('portal.home'));

    auth()->logout();
    $this->get(($this->url)())->assertRedirect(route('login'));
});

test('cifras del cliente en la semana, calculadas a mano (admin)', function () {
    $s = $this->s;

    $this->actingAs($s->admin)->get(($this->url)())
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('reports/client')
            ->where('client.name', 'Bodega Ñandú')
            ->where('summary.logged_minutes', 940)
            ->where('summary.billable_minutes', 910)
            ->where('summary.overage_minutes', 190)
            ->where('summary.in_bank_minutes', 750)
            ->where('summary.income', '1337.67')
            ->where('summary.cost', '390.00')
            ->where('summary.margin', '947.67')
            ->where('summary.margin_pct', 0.7084)
            ->where('comparison', null)
            ->where('filters.can_see_financials', true)
            ->missing('filters.query.cliente')
            ->has('projects', 2)
            ->where('projects.0.name', 'NAN-WEB · Web corporativa')
            ->where('projects.0.logged_minutes', 790)
            ->where('projects.0.in_bank_minutes', 600)
            ->where('projects.0.overage_minutes', 190)
            ->where('projects.0.income', '1221.67')
            ->where('projects.0.cost', '330.00')
            ->where('projects.1.name', 'NAN-CAMP · Campaña otoño')
            ->where('projects.1.logged_minutes', 150)
            ->where('projects.1.billable_minutes', 120)
            ->where('projects.1.income', '116.00')
            ->where('projects.1.cost', '60.00')
            ->where('scope.projects_only', false)
            ->where('scope.team_only', false));
});

test('horas por proyecto y semana en un periodo corto, y por mes en uno largo, sin huecos', function () {
    $s = $this->s;

    $this->actingAs($s->admin)->get(($this->url)())
        ->assertInertia(fn (Assert $page) => $page
            ->where('timeline.bucket', 'semana')
            ->where('timeline.buckets', ['2026-09-21'])
            ->where('timeline.series.0.name', 'NAN-WEB · Web corporativa')
            ->where('timeline.series.0.total', 790)
            ->where('timeline.series.1.total', 150)
            ->where("timeline.cells.{$s->web->id}.2026-09-21", 790));

    $this->actingAs($s->admin)->get("/informes/clientes/{$s->client->id}?periodo=trimestre&fecha=2026-09-01")
        ->assertInertia(fn (Assert $page) => $page
            ->where('timeline.bucket', 'mes')
            ->where('timeline.buckets', ['2026-07-01', '2026-08-01', '2026-09-01'])
            ->where("timeline.cells.{$s->web->id}.2026-08-01", 300)
            ->where("timeline.cells.{$s->web->id}.2026-09-01", 790)
            ->where("timeline.cells.{$s->campaign->id}.2026-09-01", 150)
            ->where('summary.logged_minutes', 1240));
});

test('bolsas del cliente con su consumo (dentro y exceso por separado) e histórico de renovaciones', function () {
    $s = $this->s;

    $this->actingAs($s->admin)->get(($this->url)())
        ->assertInertia(fn (Assert $page) => $page
            // B0 está renovada y sin horas en la semana: solo sale en el histórico.
            ->has('banks', 1)
            ->where('banks.0.name', 'Bolsa Diseño ñ')
            ->where('banks.0.status', 'exhausted')
            ->where('banks.0.total_minutes', 600)
            ->where('banks.0.consumed_minutes', 790)
            ->where('banks.0.in_bank_minutes', 600)
            ->where('banks.0.overage_minutes', 190)
            ->where('banks.0.remaining_minutes', 0)
            ->where('banks.0.period_in_bank_minutes', 600)
            ->where('banks.0.period_overage_minutes', 190)
            ->where('banks.0.project.code', 'NAN-WEB')
            ->has('history', 1)
            ->has('history.0', 2)
            ->where('history.0.0.name', 'Bolsa 2025')
            ->where('history.0.0.status', 'renewed')
            ->where('history.0.0.consumed_minutes', 300)
            ->where('history.0.1.name', 'Bolsa Diseño ñ'));
});

test('un responsable ve las horas de su equipo en todo el cliente, sin datos económicos', function () {
    $s = $this->s;

    // Marta es de Desarrollo: sus 120 min no cuentan (D-021). Ana y Luis, sí.
    $this->actingAs($s->raul)->get(($this->url)())
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.logged_minutes', 820)
            ->where('summary.income', null)
            ->where('summary.cost', null)
            ->where('summary.margin', null)
            ->where('projects.0.income', null)
            ->where('projects.1.cost', null)
            ->where('filters.can_see_financials', false)
            ->where('scope.projects_only', false)
            ->where('scope.team_only', true));
});

test('un gestor solo ve los proyectos del cliente que gestiona, aunque pida otros por la URL', function () {
    $s = $this->s;

    $this->actingAs($s->gema)->get(($this->url)())
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.logged_minutes', 790)
            // Ve todas las horas de su proyecto: cuenta T2 aunque sea de Luis (240 frente a 400).
            ->where('summary.estimation.tasks', 1)
            ->where('summary.estimation.accuracy', 0.6)
            ->has('projects', 1)
            ->where('projects.0.name', 'NAN-WEB · Web corporativa')
            ->has('banks', 1)
            ->where('scope.projects_only', true));

    // Pide NAN-CAMP, que no gestiona: nada (nunca «sin filtro»).
    $this->actingAs($s->gema)->get(($this->url)(['proyecto' => [$s->campaign->id]]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.logged_minutes', 0)
            ->has('projects', 0)
            ->has('banks', 0)
            ->where('filters.query.proyecto', [$s->campaign->id]));
});

test('filtra por proyecto, facturable y bolsa, y compara con el periodo anterior', function () {
    $s = $this->s;

    $this->actingAs($s->admin)->get(($this->url)(['proyecto' => [$s->campaign->id], 'comparar' => '1']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.logged_minutes', 150)
            ->has('banks', 0)
            ->where('comparison.logged_minutes', 0)
            ->where('filters.comparison', ['from' => '2026-09-14', 'to' => '2026-09-20']));

    $this->actingAs($s->admin)->get(($this->url)(['facturable' => 'no']))
        ->assertInertia(fn (Assert $page) => $page->where('summary.logged_minutes', 30));

    $this->actingAs($s->admin)->get(($this->url)(['bolsa' => [$s->b1->id]]))
        ->assertInertia(fn (Assert $page) => $page->where('summary.logged_minutes', 790)->has('banks', 1));
});

test('exporta el resumen por proyecto a XLSX con importes y totales (admin)', function () {
    $s = $this->s;

    $response = $this->actingAs($s->admin)->get(($this->url)(['formato' => 'xlsx']));
    $response->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    expect($response->headers->get('Content-Disposition'))->toContain('informe-de-bodega-nandu-proyectos');

    $rows = ($this->read)($response->streamedContent(), 'xlsx');

    expect($rows[0])->toBe(['Proyecto', 'Horas imputadas', 'Horas facturables', 'Horas dentro de bolsa', 'Horas en exceso', 'Ingreso estimado (€)', 'Coste (€)', 'Rentabilidad (€)'])
        ->and($rows[1])->toBe(['NAN-WEB · Web corporativa', 13.17, 13.17, 10, 3.17, 1221.67, 330, 891.67])
        ->and($rows[2])->toBe(['NAN-CAMP · Campaña otoño', 2.5, 2, 2.5, 0, 116, 60, 56])
        ->and($rows[3])->toBe(['Total', 15.67, 15.17, 12.5, 3.17, 1337.67, 390, 947.67])
        ->and($rows)->toHaveCount(4);
});

test('la exportación de un responsable no lleva datos económicos; también por meses y por bolsas en CSV', function () {
    $s = $this->s;

    $rows = ($this->read)($this->actingAs($s->raul)->get(($this->url)(['formato' => 'csv']))->assertOk()->streamedContent(), 'csv');
    expect($rows[0])->toBe(['Proyecto', 'Horas imputadas', 'Horas facturables', 'Horas dentro de bolsa', 'Horas en exceso'])
        ->and($rows[1])->toBe(['NAN-WEB · Web corporativa', '13,17', '13,17', '10,00', '3,17']);

    $months = ($this->read)($this->actingAs($s->admin)->get(($this->url)(['formato' => 'csv', 'tabla' => 'meses']))->streamedContent(), 'csv');
    expect($months)->toBe([
        ['Proyecto', 'Semana (lunes)', 'Horas imputadas'],
        ['NAN-WEB · Web corporativa', '2026-09-21', '13,17'],
        ['NAN-CAMP · Campaña otoño', '2026-09-21', '2,50'],
    ]);

    $banks = ($this->read)($this->actingAs($s->admin)->get(($this->url)(['formato' => 'xlsx', 'tabla' => 'bolsas']))->streamedContent(), 'xlsx');
    expect($banks[0][0])->toBe('Bolsa')
        ->and($banks[1])->toBe(['Bolsa Diseño ñ', 'NAN-WEB · Web corporativa', 'Agotada', '2026-09-01', '', 10, 13.17, 10, 3.17, 0, 10, 3.17])
        ->and($banks[2][0])->toBe('Bolsa 2025')
        ->and($banks[2][2])->toBe('Renovada');
});

test('la ficha del cliente enlaza el informe solo a quien puede verlo (can.viewReport)', function () {
    $s = $this->s;
    $show = "/clientes/{$s->client->id}";

    foreach ([[$s->admin, true], [$s->raul, true], [$s->gema, true], [$s->ana, false], [$s->marta, false]] as [$viewer, $expected]) {
        $this->actingAs($viewer)->get($show)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('clients/show')->where('can.viewReport', $expected));
    }

    // Gema no gestiona ningún proyecto del otro cliente.
    $this->actingAs($s->gema)->get("/clientes/{$s->otherClient->id}")
        ->assertInertia(fn (Assert $page) => $page->where('can.viewReport', false));
});
