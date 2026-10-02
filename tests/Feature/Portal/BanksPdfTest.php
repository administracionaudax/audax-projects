<?php

use App\Domain\Reports\Pdf\AudaxPdf;
use App\Models\HourBank;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Portal\BanksScenario;

/*
| Descarga del PDF de consumo desde el portal (P1, D-066): GET /portal/bolsas/{bolsa}/pdf, el PDF de
| la Fase 2 en modo portal (su contenido, en BanksPdfStatementTest). Nunca importes, ni con
| ?importes=1; limitado por minuto. Las bolsas de otro cliente, en BanksIsolationTest.
*/

beforeEach(function () {
    $this->s = BanksScenario::build($this);
    $this->url = fn (HourBank $bank, string $query = ''): string => "/portal/bolsas/{$bank->id}/pdf".$query;

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
});

it('se descarga como PDF con el código del proyecto en el nombre y sin caché', function () {
    $response = $this->actingAs($this->s->portal)->get(($this->url)($this->s->b1));

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($response->headers->get('Content-Disposition'))->toBe('attachment; filename=NAN-WEB-consumo-bolsa-diseno-n-2026-10-15.pdf')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and(str_starts_with((string) $response->getContent(), '%PDF-'))->toBeTrue();
});

it('es el PDF en modo portal y nunca lleva importes, ni siquiera con ?importes=1', function () {
    $s = $this->s;

    foreach (['', '?importes=1'] as $query) {
        $response = $this->actingAs($s->portal)->get(($this->url)($s->b1, $query));
        $text = ($this->text)((string) $response->getContent());

        expect($response->headers->get('Content-Disposition'))->not->toContain('interno')
            ->and($text)
            ->toContain('('.AudaxPdf::encode('Solo incluye las horas ya aprobadas a fecha de 15/10/2026. Las horas en curso aparecerán cuando se aprueben.').')')
            ->toContain('('.AudaxPdf::encode('Luis Pérez').')')
            ->not->toContain("\x80") // «€» en Windows-1252
            ->not->toContain(AudaxPdf::encode('Datos económicos'))
            ->not->toContain('1.000,00')
            ->not->toContain('75,00')
            ->not->toContain(AudaxPdf::encode('Enviada sin aprobar'))
            ->not->toContain(AudaxPdf::encode('Borrador que no se ve'));
    }
});

it('está limitado por minuto (throttle:30,1)', function () {
    $s = $this->s;

    expect(Route::getRoutes()->getByName('portal.banks.pdf')?->gatherMiddleware())->toContain('throttle:30,1');

    foreach (range(1, 30) as $attempt) {
        $this->actingAs($s->portal)->get(($this->url)($s->b2))->assertOk();
    }
    $this->actingAs($s->portal)->get(($this->url)($s->b2))->assertTooManyRequests();
});
