<?php

namespace Tests\Feature\Integrations;

use App\Domain\Integrations\Google\GoogleSheetsUploader;
use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Drive falso para la subida reanudable (D-142): responde al POST de inicio con la URI de la sesión
 * y a cada PUT como Drive (308 con `Range` mientras falten bytes y 200 con la hoja al terminar), y
 * guarda lo recibido para compararlo con el fichero. Se usa con Http::fake($drive).
 */
final class FakeResumableDrive
{
    public const string LINK = 'https://docs.google.com/spreadsheets/d/1Grande/edit?usp=drivesdk';

    public string $sessionUri = GoogleSheetsUploader::UPLOAD_URL.'?uploadType=resumable&upload_id=sesion-falsa';

    /** Lo que Drive ha guardado de la subida. */
    public string $received = '';

    /** @var list<Request> peticiones de inicio (POST) */
    public array $starts = [];

    /** @var list<array{from: int, to: int, total: int, bytes: int}> trozos aceptados */
    public array $chunks = [];

    /** @var list<Request> PUT de trozo, aceptados o no */
    public array $puts = [];

    public int $statusQueries = 0;

    /**
     * Fallos del POST de inicio por número (1, 2…); el resto, 200 con la URI de la sesión.
     *
     * @var array<int, int>
     */
    public array $startFailures = [];

    /**
     * Fallos por número de PUT de trozo (1, 2…): un estado HTTP o 'connection' (sin red).
     *
     * @var array<int, int|string>
     */
    public array $failures = [];

    public function __invoke(Request $request): PromiseInterface|Closure
    {
        if ($request->method() === 'POST' && str_starts_with($request->url(), GoogleSheetsUploader::UPLOAD_URL)) {
            $this->starts[] = $request;
            $status = $this->startFailures[count($this->starts)] ?? null;

            return $status !== null
                ? Http::response(['error' => ['code' => $status]], $status)
                : Http::response('', 200, ['Location' => $this->sessionUri]);
        }

        if ($request->method() !== 'PUT' || $request->url() !== $this->sessionUri) {
            return Http::response(['error' => 'petición inesperada'], 418);
        }

        $range = $request->header('Content-Range')[0] ?? '';

        // Consulta del estado de la subida: cuánto se ha guardado.
        if (preg_match('/^bytes \*\/(\d+)$/', $range) === 1) {
            $this->statusQueries++;

            return $this->incomplete();
        }

        $this->puts[] = $request;
        $failure = $this->failures[count($this->puts)] ?? null;

        if ($failure === 'connection') {
            return Http::failedConnection();
        }

        if (is_int($failure)) {
            return Http::response(['error' => ['code' => $failure]], $failure);
        }

        if (preg_match('/^bytes (\d+)-(\d+)\/(\d+)$/', $range, $match) !== 1 || (int) $match[1] !== strlen($this->received)) {
            return Http::response(['error' => 'rango incorrecto: '.$range], 400);
        }

        $body = $request->body();
        $this->received .= $body;
        $this->chunks[] = ['from' => (int) $match[1], 'to' => (int) $match[2], 'total' => (int) $match[3], 'bytes' => strlen($body)];

        return strlen($this->received) === (int) $match[3]
            ? Http::response(['id' => '1Grande', 'webViewLink' => self::LINK])
            : $this->incomplete();
    }

    private function incomplete(): PromiseInterface
    {
        return $this->received === ''
            ? Http::response('', 308)
            : Http::response('', 308, ['Range' => 'bytes=0-'.(strlen($this->received) - 1)]);
    }
}
