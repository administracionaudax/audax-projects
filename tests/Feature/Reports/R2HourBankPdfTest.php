<?php

use App\Domain\Reports\Pdf\AudaxPdf;
use App\Domain\Reports\Pdf\HourBankStatement;
use App\Domain\Reports\Pdf\HourBankStatementPdf;
use App\Models\HourBank;
use App\Models\Setting;
use Tests\Feature\Reports\R2Scenario;

/*
| PDF de consumo de bolsa (D-045; R2) de B1 en el escenario calculado a mano (R2Scenario): solo
| las horas aprobadas o bloqueadas (E1 y E2; el borrador E3 no sale), cifras y barra de esas horas
| (700 consumidos: 600 dentro y 100 de exceso sobre 600 contratados), consumo por mes, acentos,
| eñes, ¿¡ y € bien codificados, e importes solo con view-financials.
*/

beforeEach(function () {
    $this->s = R2Scenario::build($this);
    $this->url = fn (?int $projectId = null, ?int $bankId = null): string => '/proyectos/'.($projectId ?? $this->s->web->id).'/bolsas/'.($bankId ?? $this->s->b1->id).'/pdf';

    // Texto de todos los flujos del PDF (FPDF comprime el contenido de las páginas con zlib).
    $this->text = function (string $pdf): string {
        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $streams);
        $text = '';
        foreach ($streams[1] as $stream) {
            $plain = @gzuncompress($stream);
            $text .= $plain === false ? $stream : $plain;
        }

        return $text;
    };
    // Una cadena tal como FPDF la escribe (Windows-1252, dentro de un operador de texto).
    $this->pdfString = fn (string $utf8): string => '('.AudaxPdf::encode($utf8).')';
});

test('permisos (viewBreakdown): admin, responsable y gestor del proyecto; nadie más', function () {
    $s = $this->s;

    $this->actingAs($s->admin)->get(($this->url)())->assertOk();
    $this->actingAs($s->gema)->get(($this->url)())->assertOk();
    $this->actingAs($s->raul)->get(($this->url)())->assertOk();

    $this->actingAs($s->olga)->get(($this->url)())->assertForbidden();
    $this->actingAs($s->ana)->get(($this->url)())->assertForbidden();
    $this->actingAs(userWithRole('client'))->get(($this->url)())->assertRedirect(route('portal.home'));

    // La bolsa siempre es del proyecto de la URL (scopeBindings).
    $this->actingAs($s->admin)->get(($this->url)($s->campaign->id))->assertNotFound();

    auth()->logout();
    $this->get(($this->url)())->assertRedirect(route('login'));
});

test('se descarga como PDF con el código del proyecto en el nombre', function () {
    $response = $this->actingAs($this->s->admin)->get(($this->url)());

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($response->headers->get('Content-Disposition'))->toBe('attachment; filename=NAN-WEB-consumo-bolsa-diseno-n-2026-09-25.pdf')
        ->and(str_starts_with((string) $response->getContent(), '%PDF-1.'))->toBeTrue();
});

test('lleva la marca, los datos de la bolsa, las cifras de las horas aprobadas y el consumo por mes', function () {
    $text = ($this->text)((string) $this->actingAs($this->s->admin)->get(($this->url)())->getContent());
    $has = fn (string $utf8) => expect($text)->toContain(($this->pdfString)($utf8));

    // Logotipo vectorial (el trazado relleno) y nombre de la empresa.
    expect($text)->toMatch('/ m .* c .* h .* f/s');
    $has(Setting::DEFAULTS['company_name']);
    $has('Consumo de la bolsa de horas');
    $has('Bodega Ñandú');
    $has('NAN-WEB · Web corporativa');
    $has('Bolsa Diseño ñ');
    $has('Desde el 01/09/2026');
    $has('Agotada');

    // Solo aprobadas o bloqueadas: 300 + 400 = 700 (11:40) de 600 (10:00); dentro 10:00; exceso 1:40.
    $has('10:00');
    $has('11:40');
    $has('117 % de la bolsa');
    $has('+1:40');
    $has('Septiembre de 2026');
    $has('Solo incluye las horas aprobadas o bloqueadas a fecha de 25/09/2026.');
    $has('Página 1 de 1');
});

test('lista solo las entradas aprobadas o bloqueadas, con acentos, ñ, ¿¡ y € bien codificados', function () {
    $text = ($this->text)((string) $this->actingAs($this->s->admin)->get(($this->url)())->getContent());

    expect($text)
        ->toContain(($this->pdfString)('¿Qué tal? ¡Sí! 12 €'))
        ->toContain("(\xBFQu\xE9 tal? \xA1S\xED! 12 \x80)")
        ->toContain(($this->pdfString)('Maquetación de cabecera'))
        ->toContain(($this->pdfString)('22/09/2026'))
        ->toContain(($this->pdfString)('Versión móvil'))
        // E1: 200 dentro y 100 de exceso (la bloqueada E2 no cambia, D-019); E2: 400 dentro.
        ->toContain(($this->pdfString)('3:20'))
        ->toContain(($this->pdfString)('6:40'))
        ->not->toContain('Borrador que no sale en el PDF')
        ->not->toContain(AudaxPdf::encode('Borrador que no sale en el PDF'))
        // Las horas de agosto son de otra bolsa (B0).
        ->not->toContain(AudaxPdf::encode('Horas de agosto'));
});

test('con view-financials lleva precio, tarifa e ingreso estimado; sin él, ningún importe', function () {
    $s = $this->s;
    $admin = ($this->text)((string) $this->actingAs($s->admin)->get(($this->url)())->getContent());

    // 1000 × 600/600 + 100 × 70/60 = 1116,67 €.
    expect($admin)
        ->toContain(AudaxPdf::encode('Datos económicos \\(uso interno\\)'))
        ->toContain(($this->pdfString)('1.000,00 €'))
        ->toContain(($this->pdfString)('70,00 €/h'))
        ->toContain(($this->pdfString)('1.116,67 €'));

    foreach ([$s->gema, $s->raul] as $viewer) {
        $plain = ($this->text)((string) $this->actingAs($viewer)->get(($this->url)())->getContent());

        expect($plain)
            ->not->toContain(AudaxPdf::encode('Datos económicos'))
            ->not->toContain(AudaxPdf::encode('1.000,00 €'))
            ->not->toContain(AudaxPdf::encode('70,00 €/h'))
            ->not->toContain(AudaxPdf::encode('1.116,67 €'))
            ->toContain(($this->pdfString)('11:40'));
    }
});

test('un responsable que no gestiona el proyecto recibe el aviso de que el PDF puede ser parcial', function () {
    $s = $this->s;
    $partial = AudaxPdf::encode('Incluye solo las horas');

    expect(($this->text)((string) $this->actingAs($s->raul)->get(($this->url)())->getContent()))->toContain($partial)
        ->and(($this->text)((string) $this->actingAs($s->gema)->get(($this->url)())->getContent()))->not->toContain($partial)
        ->and(($this->text)((string) $this->actingAs($s->admin)->get(($this->url)())->getContent()))->not->toContain($partial);
});

test('sin compresión (SetCompression(false)) el texto va tal cual en el flujo; sin horas aprobadas lo dice', function () {
    $s = $this->s;
    $statement = app(HourBankStatement::class)->build($s->admin, $s->b1);
    $pdf = app(HourBankStatementPdf::class)->render($statement, compress: false);

    expect($statement['figures'])->toBe(['consumed' => 700, 'in_bank' => 600, 'overage' => 100, 'remaining' => 0, 'ratio' => 1.1667])
        ->and($statement['months'])->toBe([['month' => '2026-09-01', 'in_bank' => 600, 'overage' => 100]])
        ->and(array_column($statement['entries'], 'person'))->toBe(['Ana', 'Luis'])
        ->and($statement['financials'])->toBe(['price_amount' => '1000.00', 'rate' => '70.00', 'income' => '1116.67'])
        ->and($pdf)->toContain("(\xBFQu\xE9 tal? \xA1S\xED! 12 \x80)")
        ->and($pdf)->not->toContain('/Filter /FlateDecode');

    // Una bolsa sin horas aprobadas: cifras a cero y el aviso en lugar del listado.
    $empty = app(HourBankStatement::class)->build($s->admin, HourBank::factory()->create(['project_id' => $s->web->id, 'name' => 'Vacía']));
    $emptyPdf = app(HourBankStatementPdf::class)->render($empty, compress: false);

    expect($empty['figures']['consumed'])->toBe(0)
        ->and($empty['entries'])->toBe([])
        ->and($emptyPdf)->toContain(($this->pdfString)('Todavía no hay horas aprobadas en esta bolsa.'));
});
