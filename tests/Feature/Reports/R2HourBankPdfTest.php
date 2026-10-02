<?php

use App\Domain\Reports\Pdf\AudaxPdf;
use App\Domain\Reports\Pdf\HourBankStatement;
use App\Domain\Reports\Pdf\HourBankStatementPdf;
use App\Enums\TimeEntryStatus;
use App\Models\HourBank;
use App\Models\Setting;
use App\Models\Task;
use App\Models\TimeEntry;
use Tests\Feature\Reports\R2Scenario;

/*
| PDF de consumo de bolsa (D-045; R2) de B1 en el escenario calculado a mano (R2Scenario): solo
| las horas aprobadas o bloqueadas en el listado y el consumo por mes (E1 y E2; el borrador E3 no
| sale): 700 aprobados, 600 dentro y 100 de exceso sobre 600 contratados. El saldo es el de la
| bolsa (HourBankLedger) y el borrador E3 (90, todo exceso) sale aparte como «sin aprobar».
| Acentos, eñes, ¿¡ y € bien codificados, e importes solo en el PDF de uso interno y con
| view-financials.
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
    // E3 (borrador, 90 min en exceso): aparte, sin salir en el listado.
    $has('Sin aprobar');
    $has('+1:30 de exceso');
    $has('Exceso sin aprobar: +1:30');
    $has('Saldo restante: 0:00');
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

test('el PDF para el cliente nunca lleva importes; el de uso interno, solo con view-financials', function () {
    $s = $this->s;

    // Por defecto (el que se envía al cliente), ni siquiera un admin ve importes.
    $plainAdmin = ($this->text)((string) $this->actingAs($s->admin)->get(($this->url)())->getContent());
    expect($plainAdmin)
        ->not->toContain(AudaxPdf::encode('Datos económicos'))
        ->not->toContain(AudaxPdf::encode('1.091,67 €'));

    $response = $this->actingAs($s->admin)->get(($this->url)().'?importes=1');
    expect($response->headers->get('Content-Disposition'))->toBe('attachment; filename=NAN-WEB-consumo-bolsa-diseno-n-interno-2026-09-25.pdf');
    $admin = ($this->text)((string) $response->getContent());

    // 1000 × 600/600 + 100 × 55/60 (el exceso de E1, aprobada, a su tarifa congelada: BIZ-01) = 1091,67 €.
    expect($admin)
        ->toContain(AudaxPdf::encode('Datos económicos \\(uso interno\\)'))
        ->toContain(($this->pdfString)('1.000,00 €'))
        ->toContain(($this->pdfString)('70,00 €/h'))
        ->toContain(($this->pdfString)('1.091,67 €'));

    // Sin view-financials, ?importes=1 no cambia nada (ni el nombre del fichero).
    foreach ([$s->gema, $s->raul] as $viewer) {
        $response = $this->actingAs($viewer)->get(($this->url)().'?importes=1');
        expect($response->headers->get('Content-Disposition'))->not->toContain('interno');
        $plain = ($this->text)((string) $response->getContent());

        expect($plain)
            ->not->toContain(AudaxPdf::encode('Datos económicos'))
            ->not->toContain(AudaxPdf::encode('1.000,00 €'))
            ->not->toContain(AudaxPdf::encode('70,00 €/h'))
            ->not->toContain(AudaxPdf::encode('1.091,67 €'))
            ->toContain(($this->pdfString)('11:40'));
    }
});

test('un responsable que no gestiona el proyecto recibe el aviso de que el PDF puede ser parcial', function () {
    $s = $this->s;
    $partial = AudaxPdf::encode('Incluye solo las horas');

    // Lo que no sale en su listado se llama «Otras horas» (pueden ser de otras personas).
    expect(($this->text)((string) $this->actingAs($s->raul)->get(($this->url)())->getContent()))->toContain($partial)
        ->toContain(($this->pdfString)('Otras horas'))
        ->toContain(($this->pdfString)('Exceso de otras horas: +1:30'))
        ->and(($this->text)((string) $this->actingAs($s->gema)->get(($this->url)())->getContent()))->not->toContain($partial)
        ->and(($this->text)((string) $this->actingAs($s->admin)->get(($this->url)())->getContent()))->not->toContain($partial);
});

test('sin compresión (SetCompression(false)) el texto va tal cual en el flujo; sin horas aprobadas lo dice', function () {
    $s = $this->s;
    $statement = app(HourBankStatement::class)->build($s->admin, $s->b1, withFinancials: true);
    $pdf = app(HourBankStatementPdf::class)->render($statement, compress: false);

    expect($statement['figures'])->toBe(['consumed' => 700, 'in_bank' => 600, 'overage' => 100, 'pending_in_bank' => 0, 'pending_overage' => 90, 'remaining' => 0, 'ratio' => 1.1667])
        ->and($statement['months'])->toBe([['month' => '2026-09-01', 'in_bank' => 600, 'overage' => 100]])
        ->and(array_column($statement['entries'], 'person'))->toBe(['Ana', 'Luis'])
        ->and($statement['financials'])->toBe(['price_amount' => '1000.00', 'rate' => '70.00', 'income' => '1091.67'])
        ->and($pdf)->toContain("(\xBFQu\xE9 tal? \xA1S\xED! 12 \x80)")
        ->and($pdf)->not->toContain('/Filter /FlateDecode');

    // Una bolsa sin horas aprobadas: cifras a cero y el aviso en lugar del listado.
    $empty = app(HourBankStatement::class)->build($s->admin, HourBank::factory()->create(['project_id' => $s->web->id, 'name' => 'Vacía']));
    $emptyPdf = app(HourBankStatementPdf::class)->render($empty, compress: false);

    expect($empty['figures']['consumed'])->toBe(0)
        ->and($empty['entries'])->toBe([])
        ->and($emptyPdf)->toContain(($this->pdfString)('Todavía no hay horas aprobadas en esta bolsa.'));
});

test('las cifras del PDF van en la caché de informes y se renuevan al aprobar horas (D-046)', function () {
    $s = $this->s;
    $draft = AudaxPdf::encode('Borrador que no sale en el PDF');

    expect(($this->text)((string) $this->actingAs($s->gema)->get(($this->url)())->getContent()))->not->toContain($draft);

    // Aprobar la entrada en borrador invalida la caché (ReportsServiceProvider): ya sale.
    $s->e3->forceFill(['status' => TimeEntryStatus::Approved])->save();

    expect(($this->text)((string) $this->actingAs($s->gema)->get(($this->url)())->getContent()))->toContain($draft);
});

test('una entrada sin aprobar anterior deja en exceso a la aprobada: el saldo es el de la bolsa y las cifras cuadran', function () {
    $s = $this->s;

    // Bolsa de 600 (10:00): borrador de 500 (8:20) el 01/09 y aprobada de 300 (5:00) el 05/09.
    // El exceso va por fecha (HourBankLedger): la aprobada queda con 100 (1:40) dentro y 200 (3:20)
    // de exceso. La bolsa: 800 consumidos, 600 dentro, 200 de exceso y 0 de saldo.
    $bank = HourBank::factory()->create(['project_id' => $s->web->id, 'name' => 'Bolsa octubre', 'total_minutes' => 600, 'start_date' => '2026-09-01']);
    $task = Task::factory()->inBank($bank)->create(['title' => 'Soporte']);
    TimeEntry::factory()->forTask($task)->on('2026-09-01')->minutes(500)->create(['user_id' => $s->ana->id, 'description' => 'Devuelta']);
    TimeEntry::factory()->forTask($task)->on('2026-09-05')->minutes(300)->status(TimeEntryStatus::Approved)->create(['user_id' => $s->luis->id, 'description' => 'Aprobada']);
    $bank->refresh();

    expect([$bank->consumed_minutes, $bank->overage_minutes, $bank->remaining_minutes])->toBe([800, 200, 0]);

    $statement = app(HourBankStatement::class)->build($s->admin, $bank);
    // Aprobadas dentro (100) + sin aprobar dentro (500) + saldo (0) = 600 contratados.
    expect($statement['figures'])->toBe(['consumed' => 300, 'in_bank' => 100, 'overage' => 200, 'pending_in_bank' => 500, 'pending_overage' => 0, 'remaining' => 0, 'ratio' => 0.5])
        ->and(array_column($statement['entries'], 'description'))->toBe(['Aprobada']);

    $text = ($this->text)((string) $this->actingAs($s->admin)->get(($this->url)(null, $bank->id))->getContent());
    $has = fn (string $utf8) => expect($text)->toContain(($this->pdfString)($utf8));
    $has('Dentro de la bolsa: 1:40');
    $has('Sin aprobar, dentro de la bolsa: 8:20');
    $has('Exceso: +3:20');
    $has('Saldo restante: 0:00');
    expect($text)->toContain(AudaxPdf::encode('Hay 8:20 sin aprobar'))
        ->not->toContain(($this->pdfString)('Saldo restante: 8:20'))
        ->not->toContain(AudaxPdf::encode('Devuelta'));
});

test('un listado largo ocupa varias páginas: repite la cabecera de la tabla y recorta las descripciones a 600 caracteres', function () {
    $s = $this->s;
    $bank = HourBank::factory()->create(['project_id' => $s->web->id, 'name' => 'Bolsa grande', 'total_minutes' => 6000, 'start_date' => '2026-09-01']);
    $task = Task::factory()->inBank($bank)->create();
    // 592 caracteres de palabras + «MARCAFIN» = 600; lo que sigue no cabe.
    $long = str_repeat('palabra ', 74).'MARCAFIN COLAFUERA';
    foreach (range(1, 24) as $day) {
        TimeEntry::factory()->forTask($task)->on(sprintf('2026-09-%02d', $day))->minutes(60)->status(TimeEntryStatus::Approved)
            ->create(['user_id' => $s->ana->id, 'description' => $long]);
    }

    $statement = app(HourBankStatement::class)->build($s->admin, $bank->refresh());
    $pdf = app(HourBankStatementPdf::class)->render($statement, compress: false);

    preg_match_all('/\(P\xE1gina (\d+) de (\d+)\)/', $pdf, $pages);
    $count = count($pages[1]);

    expect($count)->toBeGreaterThanOrEqual(3)
        ->and($pages[1])->toBe(array_map('strval', range(1, $count)))
        ->and(array_unique($pages[2]))->toBe([(string) $count])
        // La cabecera del listado se repite en cada página por la que pasa la tabla.
        ->and(substr_count($pdf, AudaxPdf::encode('(Descripción)')))->toBeGreaterThanOrEqual($count - 1)
        ->and($pdf)->toContain("MARCAFIN\x85")
        ->and($pdf)->not->toContain('COLAFUERA')
        ->and(substr_count($pdf, 'MARCAFIN'))->toBe(24);
});
