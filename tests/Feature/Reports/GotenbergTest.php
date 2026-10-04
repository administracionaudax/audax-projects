<?php

use App\Domain\Reports\Pdf\Gotenberg;
use App\Domain\Reports\Pdf\GotenbergEngine;
use App\Domain\Reports\Pdf\HtmlEngine;
use App\Domain\Reports\Pdf\PdfConversionFailed;
use App\Domain\Reports\Pdf\PdfEngine;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
| Cliente de Gotenberg (Fase 9, D-140): POST /forms/chromium/convert/html con el documento como
| index.html (y sus recursos) en el campo `files`, el tamaño del @page del CSS y los fondos; los
| errores (respuesta de error, su 503 por tiempo, tiempo agotado en el cliente o algo que no es un
| PDF) acaban en PdfConversionFailed con un mensaje claro. El motor se elige con
| services.reports_pdf.driver.
*/

beforeEach(function () {
    $this->gotenberg = new Gotenberg('http://127.0.0.1:18092/', 7);
});

test('envía el HTML como index.html en una petición multipart y devuelve el PDF', function () {
    Http::fake(['127.0.0.1:18092/*' => Http::response('%PDF-1.7 hola', 200, ['Content-Type' => 'application/pdf'])]);

    $pdf = $this->gotenberg->convertHtml('<!doctype html><title>Informe ñ</title>', ['logo.svg' => '<svg/>']);

    expect($pdf)->toBe('%PDF-1.7 hola');
    Http::assertSentCount(1);
    Http::assertSent(function (Request $request): bool {
        $body = $request->body();

        return $request->method() === 'POST'
            && $request->url() === 'http://127.0.0.1:18092/forms/chromium/convert/html'
            && $request->isMultipart()
            && str_starts_with($request->header('Gotenberg-Trace')[0] ?? '', 'audax-')
            && str_contains($body, 'name="files"; filename="index.html"')
            && str_contains($body, '<!doctype html><title>Informe ñ</title>')
            && str_contains($body, 'name="files"; filename="logo.svg"')
            && (bool) preg_match('/name="preferCssPageSize"\r\n(?:[^\r\n]*\r\n)*\r\ntrue\r\n/', $body)
            && (bool) preg_match('/name="printBackground"\r\n(?:[^\r\n]*\r\n)*\r\ntrue\r\n/', $body)
            && (bool) preg_match('/name="emulatedMediaType"\r\n(?:[^\r\n]*\r\n)*\r\nprint\r\n/', $body);
    });
});

test('una respuesta de error es una excepción clara con el estado y el motivo', function () {
    Http::fake(['*' => Http::response('Chromium failed: context deadline', 500)]);

    expect(fn () => $this->gotenberg->convertHtml('<p>x</p>'))
        ->toThrow(PdfConversionFailed::class, 'Gotenberg respondió 500: Chromium failed: context deadline');
});

test('su 503 es el tiempo máximo por documento', function () {
    Http::fake(['*' => Http::response('Service Unavailable', 503)]);

    expect(fn () => $this->gotenberg->convertHtml('<p>x</p>'))
        ->toThrow(PdfConversionFailed::class, 'Gotenberg ha superado su tiempo máximo por documento (--api-timeout).');
});

test('tiempo agotado en el cliente: excepción clara, no un error de cURL', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 7001 milliseconds'));

    expect(fn () => $this->gotenberg->convertHtml('<p>x</p>'))
        ->toThrow(PdfConversionFailed::class, 'Gotenberg no ha terminado el PDF en 7 s (http://127.0.0.1:18092/).');
});

test('Gotenberg caído: excepción clara con la URL', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect to 127.0.0.1 port 18092'));

    expect(fn () => $this->gotenberg->convertHtml('<p>x</p>'))
        ->toThrow(PdfConversionFailed::class, 'Gotenberg no responde en http://127.0.0.1:18092/: cURL error 7');
});

test('si lo que vuelve no es un PDF, tampoco se da por bueno', function () {
    Http::fake(['*' => Http::response('<html>proxy</html>', 200)]);

    expect(fn () => $this->gotenberg->convertHtml('<p>x</p>'))->toThrow(PdfConversionFailed::class, 'Gotenberg no ha devuelto un PDF.');
});

test('el motor de PDF según services.reports_pdf.driver; la URL y el timeout, de services.gotenberg', function () {
    config(['services.reports_pdf.driver' => 'html']);
    $html = app(PdfEngine::class);
    expect($html)->toBeInstanceOf(HtmlEngine::class)
        ->and($html->render('<p>hola</p>'))->toBe('<p>hola</p>')
        ->and($html->extension())->toBe('html');

    config(['services.reports_pdf.driver' => 'gotenberg', 'services.gotenberg.url' => 'http://gotenberg.test']);
    app()->forgetInstance(Gotenberg::class);
    Http::fake(['gotenberg.test/*' => Http::response('%PDF-1.7', 200)]);
    $engine = app(PdfEngine::class);

    expect($engine)->toBeInstanceOf(GotenbergEngine::class)
        ->and($engine->render('<p>hola</p>'))->toBe('%PDF-1.7')
        ->and($engine->extension())->toBe('pdf')
        ->and($engine->mime())->toBe('application/pdf')
        ->and(config('services.gotenberg.timeout'))->toBe(65);
});
