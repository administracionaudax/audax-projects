<?php

use App\Domain\Reports\Delivery\ExportFormat;
use App\Domain\Reports\Delivery\ReportFileGenerator;
use App\Domain\Reports\Delivery\ReportKind;
use App\Domain\Reports\Delivery\ReportRequest;
use App\Domain\Reports\Pdf\PdfConversionFailed;
use App\Models\Project;
use App\Models\WeeklyCycle;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Reports\R2Scenario;

/*
| ReportFileGenerator (Fase 9, entrega 9.2; D-139 y D-140) sobre el escenario de R2 (semana del 21
| al 27/09/2026, hoy viernes 25): los 11 informes (con la weekly, D-192) en Excel, CSV y PDF (con el motor `html` de los
| tests, el HTML que convertiría Gotenberg), con los permisos de quien lo pide, sin importes sin
| view-financials, con título y nombre legibles y en la auditoría (report-delivery). Además, la
| versión para imprimir y la descarga por la URL de cada informe.
*/

beforeEach(function () {
    $this->s = R2Scenario::build($this);
    $this->generator = app(ReportFileGenerator::class);
    $this->week = ['periodo' => 'semana', 'fecha' => '2026-09-25'];

    $s = $this->s;
    $this->request = fn (ReportKind $kind): ReportRequest => match ($kind) {
        ReportKind::Direction => new ReportRequest($kind, [], $this->week),
        ReportKind::Department => new ReportRequest($kind, ['department' => $s->design->id], $this->week),
        ReportKind::Person => new ReportRequest($kind, ['user' => $s->ana->id], $this->week),
        ReportKind::Client => new ReportRequest($kind, ['client' => $s->client->id], $this->week),
        ReportKind::Project => new ReportRequest($kind, ['project' => $s->web->id], $this->week),
        ReportKind::Billing => new ReportRequest($kind, [], $this->week + ['cliente' => [(string) $s->client->id]]),
        ReportKind::Detail => new ReportRequest($kind, [], $this->week + ['filas' => 'persona', 'columnas' => 'dia']),
        ReportKind::Hours => new ReportRequest($kind, [], $this->week),
        ReportKind::ProjectHours => new ReportRequest($kind, ['project' => $s->web->id], ['desde' => '2026-09-01']),
        ReportKind::HourBank => new ReportRequest($kind, ['project' => $s->web->id, 'hourBank' => $s->b1->id]),
        // La weekly (Fase 10, D-192): su informe de una semana.
        ReportKind::Weekly => new ReportRequest($kind, ['cycle' => WeeklyCycle::factory()->create()->id]),
    };
});

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/audax-report-'.getmypid().'-*') ?: [] as $file) {
        @unlink($file);
    }
});

test('cada informe se genera en Excel, CSV y PDF con su título y su nombre', function (ReportKind $kind) {
    $request = ($this->request)($kind);

    foreach (ExportFormat::cases() as $format) {
        $file = $this->generator->generate($request, $format, $this->s->admin);
        $content = (string) file_get_contents($file->path);

        expect($file->format)->toBe($format)
            ->and($file->title)->not->toBe('')
            ->and($file->title)->toBe($this->generator->title($request, $this->s->admin));

        match ($format) {
            ExportFormat::Xlsx => expect($content)->toStartWith('PK')->and($file->filename)->toEndWith('-2026-09-25.xlsx')
                ->and($file->mime())->toBe('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
            ExportFormat::Csv => expect($content)->toStartWith("\xEF\xBB\xBF")->and($file->filename)->toEndWith('-2026-09-25.csv')
                ->and($file->mime())->toBe('text/csv; charset=UTF-8'),
            // Motor html: el documento que convertiría Gotenberg, con la hoja de Audax y su título.
            ExportFormat::Pdf => expect($content)->toStartWith('<!doctype html>')
                ->toContain('<title>'.e($file->title).'</title>')
                ->toContain('@page')
                ->and($file->filename)->toEndWith('.html')
                ->and($file->mime())->toBe('text/html; charset=UTF-8'),
        };

        @unlink($file->path);
    }
})->with(array_map(fn (ReportKind $kind): array => [$kind], array_combine(array_map(fn (ReportKind $kind): string => $kind->value, ReportKind::cases()), ReportKind::cases())));

test('títulos y nombres de fichero legibles', function () {
    $s = $this->s;
    $file = $this->generator->generate(($this->request)(ReportKind::Client), ExportFormat::Pdf, $s->admin);

    expect($file->title)->toBe('Informe de cliente · Bodega Ñandú · Semana del 21/09/2026 al 27/09/2026')
        ->and($file->filename)->toBe('informe-cliente-bodega-nandu-2026-s39.html');

    $month = new ReportRequest(ReportKind::Client, ['client' => $s->client->id], ['periodo' => 'mes', 'fecha' => '2026-09-25']);
    expect($this->generator->title($month, $s->admin))->toBe('Informe de cliente · Bodega Ñandú · septiembre de 2026')
        ->and($this->generator->generate($month, ExportFormat::Pdf, $s->admin)->filename)->toBe('informe-cliente-bodega-nandu-2026-09.html');

    // Excel y CSV, con los nombres de siempre (TableExporter::filename).
    expect($this->generator->generate(($this->request)(ReportKind::Client), ExportFormat::Xlsx, $s->admin)->filename)
        ->toBe('informe-de-bodega-nandu-proyectos-2026-09-25.xlsx')
        ->and($this->generator->generate(($this->request)(ReportKind::HourBank), ExportFormat::Pdf, $s->admin)->filename)
        ->toBe('NAN-WEB-consumo-bolsa-diseno-n-2026-09-25.html');
});

test('permisos: las mismas políticas que la página', function () {
    $s = $this->s;

    // Una empleada no saca el informe de dirección, ni el de su departamento, ni el de un cliente.
    foreach ([ReportKind::Direction, ReportKind::Department, ReportKind::Client, ReportKind::Billing] as $kind) {
        expect(fn () => $this->generator->generate(($this->request)($kind), ExportFormat::Pdf, $s->ana))
            ->toThrow(AuthorizationException::class);
    }
    expect(fn () => $this->generator->title(($this->request)(ReportKind::Direction), $s->ana))->toThrow(AuthorizationException::class);

    // Su propio informe y sus horas, sí; el de otra persona, no.
    expect($this->generator->generate(($this->request)(ReportKind::Person), ExportFormat::Csv, $s->ana)->filename)->toEndWith('.csv');
    expect(fn () => $this->generator->generate(new ReportRequest(ReportKind::Person, ['user' => $s->luis->id], $this->week), ExportFormat::Csv, $s->ana))
        ->toThrow(AuthorizationException::class);

    // El responsable de Diseño, su departamento y la dirección limitada a él.
    expect($this->generator->generate(($this->request)(ReportKind::Department), ExportFormat::Pdf, $s->raul)->filename)->toEndWith('.html')
        ->and((string) file_get_contents($this->generator->generate(($this->request)(ReportKind::Direction), ExportFormat::Pdf, $s->raul)->path))
        ->toContain('Limitado a los departamentos que diriges.');

    // Un informe que ya no existe (proyecto borrado) tampoco se puede generar.
    Project::query()->whereKey($s->web->id)->delete();
    expect(fn () => $this->generator->generate(($this->request)(ReportKind::Project), ExportFormat::Xlsx, $s->admin))
        ->toThrow(AuthorizationException::class, 'Este informe ya no existe o ya no lo puedes ver.');
});

test('sin view-financials no hay importes en el PDF; con ellos, sí', function () {
    $s = $this->s;
    $html = fn ($user) => reportHtmlText((string) file_get_contents($this->generator->generate(($this->request)(ReportKind::Client), ExportFormat::Pdf, $user)->path));

    // Admin: ingreso 1312,67 €, coste 390,00 € y rentabilidad 922,67 € (R2Scenario).
    expect($html($s->admin))
        ->toContain('Ingreso estimado 1.312,67 €')
        ->toContain('Rentabilidad 922,67 €')
        ->toContain('Datos económicos Incluidos: documento de uso interno');

    // Raúl (responsable, sin view-financials): ni importes ni definiciones de dinero.
    expect($html($s->raul))
        ->not->toContain('€')
        ->not->toContain('Ingreso estimado')
        ->not->toContain('Rentabilidad')
        ->toContain('Datos económicos No incluidos');
});

test('el PDF lleva portada, cifras clave, tablas con totales y cómo se calculan las cifras', function () {
    $s = $this->s;
    $file = $this->generator->generate(($this->request)(ReportKind::Project), ExportFormat::Pdf, $s->admin);
    $html = (string) file_get_contents($file->path);
    $text = reportHtmlText($html);

    expect($html)->toContain('<section class="cover">')
        ->toContain('class="kpis"')
        ->toContain('<tr class="sum">')
        ->toContain('aria-label="Audax Studio"')
        ->not->toContain('<script');

    expect($text)
        ->toContain('Informe de proyecto NAN-WEB · Web corporativa Semana del 21/09/2026 al 27/09/2026')
        ->toContain('Cliente Bodega Ñandú')
        ->toContain('Cifras clave')
        ->toContain('Estimado frente a real por tarea')
        ->toContain('Diseño de la home')
        ->toContain('Hitos')
        ->toContain('Entrega de diseño')
        ->toContain('Cómo se calculan las cifras');
});

test('cada fichero generado queda en la auditoría (report-delivery) con el informe, el formato y los filtros', function () {
    $s = $this->s;
    $request = ($this->request)(ReportKind::Billing);

    $file = $this->generator->generate($request, ExportFormat::Csv, $s->admin);

    $activity = Activity::query()->where('log_name', 'report-delivery')->latest('id')->firstOrFail();
    expect($activity->event)->toBe('generated')
        ->and($activity->causer_id)->toBe($s->admin->id)
        ->and($activity->getProperty('kind'))->toBe('billing')
        ->and($activity->getProperty('format'))->toBe('csv')
        ->and($activity->getProperty('query'))->toBe($this->week + ['cliente' => [(string) $s->client->id]])
        ->and($activity->getProperty('filename'))->toBe($file->filename)
        ->and($activity->getProperty('title'))->toBe($file->title);

    // Lo que no se genera (sin permiso) no queda como generado.
    $before = Activity::query()->where('log_name', 'report-delivery')->count();
    try {
        $this->generator->generate(($this->request)(ReportKind::Direction), ExportFormat::Pdf, $s->ana);
    } catch (AuthorizationException) {
    }
    expect(Activity::query()->where('log_name', 'report-delivery')->count())->toBe($before);

    // En la auditoría visible: «Informes exportados», con el título.
    $this->actingAs($s->admin)->get('/admin/auditoria?entidad=report_delivery')->assertOk()
        ->assertInertia(fn ($page) => $page->where('entries.0.event_label', 'Informe generado')
            ->where('entries.0.subject.label', $file->title));
});

test('la descarga por la URL del informe pasa por el generador: PDF, Excel y la auditoría', function () {
    $s = $this->s;
    $url = "/informes/clientes/{$s->client->id}?periodo=semana&fecha=2026-09-25";

    $pdf = $this->actingAs($s->admin)->get($url.'&formato=pdf');
    $pdf->assertOk()->assertHeader('Content-Type', 'text/html; charset=UTF-8')->assertHeader('Cache-Control', 'no-store, private');
    expect($pdf->headers->get('Content-Disposition'))->toBe('attachment; filename=informe-cliente-bodega-nandu-2026-s39.html')
        ->and(reportHtmlText((string) $pdf->getContent()))->toContain('Resumen por proyecto');

    $this->actingAs($s->admin)->get($url.'&formato=xlsx&tabla=bolsas')->assertOk()->streamedContent();

    expect(Activity::query()->where('log_name', 'report-delivery')->pluck('properties')->map(fn ($p) => $p['format'].':'.($p['query']['tabla'] ?? '-'))->all())
        ->toBe(['pdf:-', 'xlsx:bolsas']);

    // Un empleado, 403 antes de generar nada.
    $this->actingAs($s->ana)->get($url.'&formato=pdf')->assertForbidden();
});

test('Gotenberg caído: la descarga del PDF responde 503 con un mensaje claro', function () {
    config(['services.reports_pdf.driver' => 'gotenberg', 'services.gotenberg.url' => 'http://gotenberg.test']);
    Http::fake(['gotenberg.test/*' => Http::response('Chromium crashed', 500)]);

    Exceptions::fake();

    $this->actingAs($this->s->admin)
        ->get("/informes/clientes/{$this->s->client->id}?formato=pdf")
        ->assertStatus(503);

    Exceptions::assertReported(fn (PdfConversionFailed $e): bool => str_contains($e->getMessage(), 'Gotenberg respondió 500: Chromium crashed'));

    expect(Activity::query()->where('log_name', 'report-delivery')->count())->toBe(0);
});

test('imprimir: el mismo HTML del PDF en la pestaña, sin la app, con el diálogo de impresión y en la auditoría', function () {
    $s = $this->s;
    $response = $this->actingAs($s->admin)->get("/informes/proyectos/{$s->web->id}?periodo=semana&fecha=2026-09-25&formato=imprimir");

    $response->assertOk()->assertHeader('Content-Type', 'text/html; charset=UTF-8');
    expect($response->headers->get('Content-Disposition'))->toBeNull();
    $html = (string) $response->getContent();

    // El nonce del script es el de la CSP de la respuesta.
    preg_match('/<script nonce="([^"]+)">window\.addEventListener\(\'load\', function \(\) \{ window\.print\(\); \}\);<\/script>/', $html, $script);
    expect($script)->not->toBe([])
        ->and((string) $response->headers->get('Content-Security-Policy'))->toContain("'nonce-{$script[1]}'")
        // Sin la app: ni Inertia ni Vite.
        ->and($html)->not->toContain('data-page=')
        ->not->toContain('/build/assets/')
        ->toContain('class="report report--landscape report--print"')
        ->and(reportHtmlText($html))->toContain('Estimado frente a real por tipo');

    $activity = Activity::query()->where('log_name', 'report-delivery')->sole();
    expect($activity->event)->toBe('printed')->and($activity->getProperty('kind'))->toBe('project');

    // Quien no ve el informe tampoco lo imprime.
    $this->actingAs($s->ana)->get("/informes/proyectos/{$s->web->id}?formato=imprimir")->assertForbidden();
});

test('ReportRequest::fromArray y toArray: lo que guardan los envíos programados se vuelve a generar igual', function () {
    $request = ($this->request)(ReportKind::Hours);
    $again = ReportRequest::fromArray($request->toArray());

    expect($again)->toEqual($request)
        ->and($this->generator->title($again, $this->s->admin))->toBe('Horas · Semana del 21/09/2026 al 27/09/2026');
});
