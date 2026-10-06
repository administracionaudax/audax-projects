<?php

use App\Models\HourBank;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Portal\BanksScenario;

/*
| Descarga del PDF de consumo desde el portal (P1, D-066): GET /portal/bolsas/{bolsa}/pdf, el PDF de
| la Fase 2 en modo portal (su contenido, en BanksPdfStatementTest). Nunca importes, ni con
| ?importes=1; limitado por minuto. Las bolsas de otro cliente, en BanksIsolationTest. Desde la
| Fase 9 (D-140) es el HTML con la hoja de Audax convertido por Gotenberg; en los tests, el motor
| `html` devuelve el HTML.
*/

beforeEach(function () {
    $this->s = BanksScenario::build($this);
    $this->url = fn (HourBank $bank, string $query = ''): string => "/portal/bolsas/{$bank->id}/pdf".$query;

    $this->text = fn (string $html): string => reportHtmlText($html);
});

it('se descarga como PDF (Gotenberg) con el código del proyecto en el nombre y sin caché', function () {
    config(['services.reports_pdf.driver' => 'gotenberg', 'services.gotenberg.url' => 'http://gotenberg.test']);
    Http::fake(['gotenberg.test/*' => Http::response('%PDF-1.7 portal', 200)]);

    $response = $this->actingAs($this->s->portal)->get(($this->url)($this->s->b1));

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($response->headers->get('Content-Disposition'))->toBe('attachment; filename=NAN-WEB-consumo-bolsa-diseno-n-2026-10-15.pdf')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and((string) $response->getContent())->toBe('%PDF-1.7 portal');
});

it('es el PDF en modo portal y nunca lleva importes, ni siquiera con ?importes=1', function () {
    $s = $this->s;

    foreach (['', '?importes=1'] as $query) {
        $response = $this->actingAs($s->portal)->get(($this->url)($s->b1, $query));
        $text = ($this->text)((string) $response->getContent());

        expect($response->headers->get('Content-Disposition'))->not->toContain('interno')
            ->and($text)
            ->toContain('Solo incluye las horas ya aprobadas a fecha de 15/10/2026. Las horas en curso aparecerán cuando se aprueben.')
            ->toContain('Luis Pérez')
            ->not->toContain('€')
            ->not->toContain('Datos económicos')
            ->not->toContain('1.000,00')
            ->not->toContain('75,00')
            ->not->toContain('Enviada sin aprobar')
            ->not->toContain('Borrador que no se ve');
    }
});

it('está limitado por minuto (throttle:30,1, con su propio contador)', function () {
    $s = $this->s;

    expect(Route::getRoutes()->getByName('portal.banks.pdf')?->gatherMiddleware())->toContain('throttle:30,1,portal.banks.pdf');

    foreach (range(1, 30) as $attempt) {
        $this->actingAs($s->portal)->get(($this->url)($s->b2))->assertOk();
    }
    $this->actingAs($s->portal)->get(($this->url)($s->b2))->assertTooManyRequests();
});
