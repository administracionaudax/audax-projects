<?php

use App\Domain\Reports\Pdf\HourBankStatement;
use App\Domain\Reports\Pdf\HourBankStatementView;
use App\Domain\Reports\Pdf\ReportHtml;
use App\Enums\TimeEntryStatus;
use App\Models\HourBank;
use App\Models\Task;
use App\Models\TimeEntry;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Reports\R2Scenario;

/*
| PDF de consumo de bolsa (D-045; R2; con la hoja de Audax y Gotenberg desde la Fase 9, D-140) de B1
| en el escenario calculado a mano (R2Scenario): solo las horas aprobadas o bloqueadas en el listado
| y el consumo por mes (E1 y E2; el borrador E3 no sale): 700 aprobados, 600 dentro y 100 de exceso
| sobre 600 contratados. El saldo es el de la bolsa (HourBankLedger) y el borrador E3 (90, todo
| exceso) sale aparte como «sin aprobar». Importes solo en el PDF de uso interno y con
| view-financials. En los tests el motor de PDF es `html` (REPORTS_PDF_DRIVER): se lee el HTML que
| convertiría Gotenberg.
*/

beforeEach(function () {
    $this->s = R2Scenario::build($this);
    $this->url = fn (?int $projectId = null, ?int $bankId = null): string => '/proyectos/'.($projectId ?? $this->s->web->id).'/bolsas/'.($bankId ?? $this->s->b1->id).'/pdf';
    $this->text = fn (string $html): string => reportHtmlText($html);
    // El HTML del PDF de una bolsa, sin pasar por la ruta.
    $this->render = fn (array $statement): string => app(ReportHtml::class)->render(HourBankStatementView::make($statement, '', 'prueba'));
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

test('con Gotenberg se descarga como PDF con el código del proyecto en el nombre', function () {
    config(['services.reports_pdf.driver' => 'gotenberg', 'services.gotenberg.url' => 'http://gotenberg.test']);
    Http::fake(['gotenberg.test/*' => Http::response('%PDF-1.7 prueba', 200, ['Content-Type' => 'application/pdf'])]);

    $response = $this->actingAs($this->s->admin)->get(($this->url)());

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($response->headers->get('Content-Disposition'))->toBe('attachment; filename=NAN-WEB-consumo-bolsa-diseno-n-2026-09-25.pdf')
        ->and((string) $response->getContent())->toBe('%PDF-1.7 prueba');

    // Gotenberg recibe el HTML de la bolsa como index.html.
    Http::assertSent(fn (HttpRequest $request): bool => str_contains($request->url(), '/forms/chromium/convert/html')
        && str_contains($request->body(), 'filename="index.html"')
        && str_contains($request->body(), 'Bolsa Diseño ñ'));
});

test('con el motor html (tests y local) descarga el HTML del PDF', function () {
    $response = $this->actingAs($this->s->admin)->get(($this->url)());

    $response->assertOk()->assertHeader('Content-Type', 'text/html; charset=UTF-8');
    expect($response->headers->get('Content-Disposition'))->toBe('attachment; filename=NAN-WEB-consumo-bolsa-diseno-n-2026-09-25.html')
        ->and((string) $response->getContent())->toStartWith('<!doctype html>');
});

test('lleva la marca, los datos de la bolsa, las cifras de las horas aprobadas y el consumo por mes', function () {
    $html = (string) $this->actingAs($this->s->admin)->get(($this->url)())->getContent();
    $text = ($this->text)($html);

    // Logotipo, DM Sans incrustada, cabecera y pie de página de la hoja de Audax.
    expect($html)->toContain('aria-label="Audax Studio"')
        ->toContain("font-family:'DM Sans'")
        ->toContain('data:font/woff2;base64,')
        ->toContain('counter(page) " de " counter(pages)')
        ->toContain('audaxstudio.com');

    expect($text)
        ->toContain('Consumo de la bolsa de horas')
        ->toContain('Bodega Ñandú')
        ->toContain('NAN-WEB · Web corporativa')
        ->toContain('Bolsa Diseño ñ')
        ->toContain('Desde el 01/09/2026')
        ->toContain('Agotada')
        // Solo aprobadas o bloqueadas: 300 + 400 = 700 (11:40) de 600 (10:00); dentro 10:00; exceso 1:40.
        ->toContain('Horas contratadas 10:00')
        ->toContain('Horas aprobadas 11:40 117 % de la bolsa')
        ->toContain('Exceso +1:40')
        // E3 (borrador, 90 min en exceso): aparte, sin salir en el listado.
        ->toContain('Sin aprobar 1:30 +1:30 de exceso')
        ->toContain('Exceso sin aprobar: +1:30')
        ->toContain('Saldo restante: 0:00')
        ->toContain('Septiembre de 2026')
        ->toContain('Solo incluye las horas aprobadas o bloqueadas a fecha de 25/09/2026.');
});

test('lista solo las entradas aprobadas o bloqueadas, con acentos, ñ, ¿¡ y €', function () {
    $text = ($this->text)((string) $this->actingAs($this->s->admin)->get(($this->url)())->getContent());

    expect($text)
        ->toContain('¿Qué tal? ¡Sí! 12 €')
        ->toContain('Maquetación de cabecera')
        ->toContain('22/09/2026')
        ->toContain('Versión móvil')
        // E1: 200 dentro y 100 de exceso (la bloqueada E2 no cambia, D-019); E2: 400 dentro.
        ->toContain('3:20 +1:40')
        ->toContain('6:40')
        ->not->toContain('Borrador que no sale en el PDF')
        // Las horas de agosto son de otra bolsa (B0).
        ->not->toContain('Horas de agosto');
});

test('el PDF para el cliente nunca lleva importes; el de uso interno, solo con view-financials', function () {
    $s = $this->s;

    // Por defecto (el que se envía al cliente), ni siquiera un admin ve importes.
    $plainAdmin = ($this->text)((string) $this->actingAs($s->admin)->get(($this->url)())->getContent());
    expect($plainAdmin)->not->toContain('Datos económicos')->not->toContain('1.091,67 €');

    $response = $this->actingAs($s->admin)->get(($this->url)().'?importes=1');
    expect($response->headers->get('Content-Disposition'))->toBe('attachment; filename=NAN-WEB-consumo-bolsa-diseno-n-interno-2026-09-25.html');

    // 1000 × 600/600 + 100 × 55/60 (el exceso de E1, aprobada, a su tarifa congelada: BIZ-01) = 1091,67 €.
    expect(($this->text)((string) $response->getContent()))
        ->toContain('Datos económicos (uso interno)')
        ->toContain('Precio de la bolsa 1.000,00 €')
        ->toContain('70,00 €/h')
        ->toContain('1.091,67 €');

    // Sin view-financials, ?importes=1 no cambia nada (ni el nombre del fichero).
    foreach ([$s->gema, $s->raul] as $viewer) {
        $response = $this->actingAs($viewer)->get(($this->url)().'?importes=1');
        expect($response->headers->get('Content-Disposition'))->not->toContain('interno');

        expect(($this->text)((string) $response->getContent()))
            ->not->toContain('Datos económicos')
            ->not->toContain('1.000,00 €')
            ->not->toContain('70,00 €/h')
            ->not->toContain('1.091,67 €')
            ->toContain('11:40');
    }
});

test('un responsable que no gestiona el proyecto recibe el aviso de que el PDF puede ser parcial', function () {
    $s = $this->s;
    $partial = 'Incluye solo las horas de las personas cuyas horas puedes ver';

    // Lo que no sale en su listado se llama «Otras horas» (pueden ser de otras personas).
    expect(($this->text)((string) $this->actingAs($s->raul)->get(($this->url)())->getContent()))->toContain($partial)
        ->toContain('Otras horas')
        ->toContain('Exceso de otras horas: +1:30')
        ->and(($this->text)((string) $this->actingAs($s->gema)->get(($this->url)())->getContent()))->not->toContain($partial)
        ->and(($this->text)((string) $this->actingAs($s->admin)->get(($this->url)())->getContent()))->not->toContain($partial);
});

test('las cifras del statement y, sin horas aprobadas, el aviso en lugar del listado', function () {
    $s = $this->s;
    $statement = app(HourBankStatement::class)->build($s->admin, $s->b1, withFinancials: true);

    expect($statement['figures'])->toBe(['consumed' => 700, 'in_bank' => 600, 'overage' => 100, 'pending_in_bank' => 0, 'pending_overage' => 90, 'remaining' => 0, 'ratio' => 1.1667])
        ->and($statement['months'])->toBe([['month' => '2026-09-01', 'in_bank' => 600, 'overage' => 100]])
        ->and(array_column($statement['entries'], 'person'))->toBe(['Ana', 'Luis'])
        ->and($statement['financials'])->toBe(['price_amount' => '1000.00', 'rate' => '70.00', 'income' => '1091.67']);

    // Una bolsa sin horas aprobadas: cifras a cero y el aviso en lugar del listado.
    $empty = app(HourBankStatement::class)->build($s->admin, HourBank::factory()->create(['project_id' => $s->web->id, 'name' => 'Vacía']));

    expect($empty['figures']['consumed'])->toBe(0)
        ->and($empty['entries'])->toBe([])
        ->and(($this->text)(($this->render)($empty)))->toContain('Todavía no hay horas aprobadas en esta bolsa.');
});

test('la barra de consumo: dentro hasta el total, el exceso detrás y la marca en el total contratado', function () {
    // 600 contratados, 600 dentro, 100 de exceso aprobado y 90 sin aprobar: escala 790.
    expect(HourBankStatementView::meter(600, ['in_bank' => 600, 'overage' => 100, 'pending_in_bank' => 0, 'pending_overage' => 90]))->toBe([
        'segments' => [
            ['class' => 'in', 'width' => 75.949],
            ['class' => 'over', 'width' => 12.658],
            ['class' => 'pending-over', 'width' => 11.392],
        ],
        'mark' => 75.949,
    ]);
});

test('las cifras del PDF van en la caché de informes y se renuevan al aprobar horas (D-046)', function () {
    $s = $this->s;
    $draft = 'Borrador que no sale en el PDF';

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

    expect(($this->text)((string) $this->actingAs($s->admin)->get(($this->url)(null, $bank->id))->getContent()))
        ->toContain('Dentro de la bolsa: 1:40')
        ->toContain('Sin aprobar, dentro de la bolsa: 8:20')
        ->toContain('Exceso: +3:20')
        ->toContain('Saldo restante: 0:00')
        ->toContain('Hay 8:20 sin aprobar')
        ->not->toContain('Saldo restante: 8:20')
        ->not->toContain('Devuelta');
});

test('un listado largo: la cabecera de la tabla se repite en cada página y las descripciones se recortan a 600 caracteres', function () {
    $s = $this->s;
    $bank = HourBank::factory()->create(['project_id' => $s->web->id, 'name' => 'Bolsa grande', 'total_minutes' => 6000, 'start_date' => '2026-09-01']);
    $task = Task::factory()->inBank($bank)->create();
    // 592 caracteres de palabras + «MARCAFIN» = 600; lo que sigue no cabe.
    $long = str_repeat('palabra ', 74).'MARCAFIN COLAFUERA';
    foreach (range(1, 24) as $day) {
        TimeEntry::factory()->forTask($task)->on(sprintf('2026-09-%02d', $day))->minutes(60)->status(TimeEntryStatus::Approved)
            ->create(['user_id' => $s->ana->id, 'description' => $long]);
    }

    $html = ($this->render)(app(HourBankStatement::class)->build($s->admin, $bank->refresh()));

    expect($html)->toContain('thead{display:table-header-group;}')
        ->toContain('MARCAFIN…')
        ->not->toContain('COLAFUERA')
        ->and(substr_count($html, 'MARCAFIN'))->toBe(24);
});
