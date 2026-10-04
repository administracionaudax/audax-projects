<?php

namespace App\Domain\Reports\Pdf;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Cliente HTTP de Gotenberg 8 (Chromium en Docker, MIT; D-140): POST /forms/chromium/convert/html
 * con el documento como `index.html` y, si los hay, sus recursos en el mismo campo `files` (se
 * referencian por su nombre, sin rutas). Los informes llevan el CSS, las fuentes y el logo dentro
 * del HTML, así que el PDF y la pestaña de imprimir usan exactamente el mismo documento.
 *
 * Opciones fijas: el tamaño y los márgenes del @page del CSS (preferCssPageSize), los fondos
 * (cabeceras grises de las tablas) y el medio «print». Gotenberg solo escucha en 127.0.0.1 y no
 * carga nada de fuera (docs/DEPLOY.md, «Gotenberg»).
 *
 * Errores: conexión, tiempo agotado (en el cliente o el 503 de Gotenberg) o cualquier respuesta que
 * no sea un PDF → PdfConversionFailed con un mensaje claro.
 */
final class Gotenberg
{
    public const string ENDPOINT = '/forms/chromium/convert/html';

    public function __construct(
        private readonly string $url,
        private readonly int $timeout,
    ) {}

    /**
     * @param  array<string, string>  $assets  nombre de fichero → contenido
     * @param  array<string, string>  $options  campos de formulario adicionales de Gotenberg
     */
    public function convertHtml(string $html, array $assets = [], array $options = []): string
    {
        $request = Http::timeout($this->timeout)
            ->connectTimeout(5)
            ->withHeaders(['Gotenberg-Trace' => 'audax-'.Str::lower((string) Str::ulid())])
            ->attach('files', $html, 'index.html', ['Content-Type' => 'text/html; charset=UTF-8']);

        foreach ($assets as $name => $content) {
            $request = $request->attach('files', $content, basename($name));
        }

        try {
            $response = $request->post(rtrim($this->url, '/').self::ENDPOINT, array_merge([
                'preferCssPageSize' => 'true',
                'printBackground' => 'true',
                'emulatedMediaType' => 'print',
            ], $options));
        } catch (ConnectionException $e) {
            $timedOut = str_contains($e->getMessage(), 'timed out') || str_contains($e->getMessage(), 'cURL error 28');

            throw new PdfConversionFailed($timedOut
                ? "Gotenberg no ha terminado el PDF en {$this->timeout} s ({$this->url})."
                : "Gotenberg no responde en {$this->url}: ".$e->getMessage(), previous: $e);
        }

        if ($response->status() === 503) {
            throw new PdfConversionFailed('Gotenberg ha superado su tiempo máximo por documento (--api-timeout).');
        }

        if (! $response->successful()) {
            throw new PdfConversionFailed("Gotenberg respondió {$response->status()}: ".mb_substr(trim($response->body()), 0, 300));
        }

        $pdf = $response->body();
        if (! str_starts_with($pdf, '%PDF-')) {
            throw new PdfConversionFailed('Gotenberg no ha devuelto un PDF.');
        }

        return $pdf;
    }
}
