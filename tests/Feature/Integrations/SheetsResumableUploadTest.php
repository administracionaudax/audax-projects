<?php

use App\Domain\Integrations\Google\GoogleSheetsUploader;
use App\Domain\Reports\Delivery\ReportFileGenerator;
use App\Models\Client;
use App\Models\GoogleConnection;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Integrations\FakeReportFileGenerator;
use Tests\Feature\Integrations\FakeResumableDrive;
use Tests\Feature\Integrations\GoogleFakes;

/*
| Google Sheets con ficheros grandes (D-142): la subida multipart de Drive admite hasta 5 MB. Por
| encima, subida reanudable: POST de inicio con los metadatos y PUT del contenido por trozos de
| 8 MB (múltiplos de 256 KiB). Nunca se llama a Google: Http::fake con un Drive falso.
*/

const MIB = 1024 * 1024;

beforeEach(function () {
    GoogleFakes::configure();
    Http::preventStrayRequests();

    $this->generator = new FakeReportFileGenerator;
    app()->instance(ReportFileGenerator::class, $this->generator);

    $this->admin = userWithRole('admin');
    GoogleFakes::connect($this->admin);
    $this->payload = [
        'kind' => 'client',
        'route_params' => ['client' => Client::factory()->create()->id],
        'query' => ['periodo' => 'mes', 'fecha' => '2026-09-01'],
    ];

    $this->drive = new FakeResumableDrive;
    Http::fake(function (HttpRequest $request) {
        if ($request->url() === 'https://oauth2.googleapis.com/token') {
            return Http::response(['access_token' => 'ya29.renovado', 'expires_in' => 3599]);
        }

        // La multipart va a la misma URL con uploadType=multipart.
        if (str_contains($request->url(), 'uploadType=multipart')) {
            return Http::response(['id' => '1Peque', 'webViewLink' => 'https://docs.google.com/spreadsheets/d/1Peque/edit']);
        }

        return ($this->drive)($request);
    });
});

/** Un XLSX «falso» de $bytes bytes, distinto en cada posición para detectar trozos cambiados. */
function bigXlsx(int $bytes): string
{
    return substr(str_repeat(random_bytes(64 * 1024), intdiv($bytes, 64 * 1024) + 1), 0, $bytes);
}

function exportToSheets(): TestResponse
{
    return test()->actingAs(test()->admin)->postJson(route('reports.sheets.store'), test()->payload);
}

test('los límites: hasta 5 MB multipart; por encima, reanudable en trozos de 8 MB', function (int $bytes, ?array $chunks) {
    $this->generator->contents = bigXlsx($bytes);

    $response = exportToSheets()->assertOk();

    if ($chunks === null) {
        $response->assertExactJson(['url' => 'https://docs.google.com/spreadsheets/d/1Peque/edit']);
        expect($this->drive->starts)->toBe([])->and($this->drive->puts)->toBe([]);
        Http::assertSentCount(1);

        return;
    }

    $response->assertExactJson(['url' => FakeResumableDrive::LINK]);

    expect($this->drive->starts)->toHaveCount(1)
        ->and(array_column($this->drive->chunks, 'bytes'))->toBe($chunks)
        ->and($this->drive->received === $this->generator->contents)->toBeTrue()
        ->and($this->drive->statusQueries)->toBe(0);

    // Todos los trozos menos el último son múltiplos de 256 KiB, y ninguno pasa de 8 MB.
    foreach (array_slice($chunks, 0, -1) as $size) {
        expect($size % GoogleSheetsUploader::CHUNK_UNIT)->toBe(0);
    }
    expect(max($chunks))->toBeLessThanOrEqual(8 * MIB)
        ->and(file_exists($this->generator->paths[0]))->toBeFalse();
})->with([
    '5 MB justos (multipart)' => [5 * MIB, null],
    '5 MB y un byte' => [5 * MIB + 1, [5 * MIB + 1]],
    '8 MB justos (un trozo)' => [8 * MIB, [8 * MIB]],
    '8 MB y un byte (dos trozos)' => [8 * MIB + 1, [8 * MIB, 1]],
    '17 MB (tres trozos)' => [17 * MIB, [8 * MIB, 8 * MIB, MIB]],
]);

test('el inicio lleva los metadatos de conversión, el tamaño y el tipo, y cada trozo su rango', function () {
    $this->generator->contents = bigXlsx(8 * MIB + 10);

    exportToSheets()->assertOk();

    $start = $this->drive->starts[0];
    parse_str((string) parse_url($start->url(), PHP_URL_QUERY), $query);

    expect($start->method())->toBe('POST')
        ->and($query)->toBe(['uploadType' => 'resumable', 'fields' => 'id,webViewLink'])
        ->and($start->header('Authorization'))->toBe(['Bearer ya29.acceso-guardado'])
        ->and($start->header('Content-Type')[0] ?? '')->toStartWith('application/json')
        ->and($start->header('X-Upload-Content-Type'))->toBe(['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
        ->and($start->header('X-Upload-Content-Length'))->toBe([(string) (8 * MIB + 10)])
        ->and(json_decode($start->body(), true))->toBe([
            'name' => 'Informe de cliente · Montó · septiembre 2026',
            'mimeType' => GoogleSheetsUploader::SPREADSHEET_MIME,
        ]);

    expect(array_map(fn (HttpRequest $put) => $put->header('Content-Range')[0], $this->drive->puts))->toBe([
        'bytes 0-8388607/8388618',
        'bytes 8388608-8388617/8388618',
    ])
        ->and(array_map(fn (HttpRequest $put) => $put->header('Authorization')[0], $this->drive->puts))->toBe(['Bearer ya29.acceso-guardado', 'Bearer ya29.acceso-guardado'])
        ->and(Activity::query()->where('log_name', 'report-delivery')->where('event', 'sheets')->count())->toBe(1);
});

test('un error a mitad (5xx o sin red) pregunta a Drive cuánto tiene y sigue desde ahí', function (int|string $failure) {
    $this->generator->contents = bigXlsx(17 * MIB);
    $this->drive->failures = [2 => $failure];

    exportToSheets()->assertOk()->assertExactJson(['url' => FakeResumableDrive::LINK]);

    expect($this->drive->statusQueries)->toBe(1)
        ->and($this->drive->puts)->toHaveCount(4)
        ->and(array_column($this->drive->chunks, 'from'))->toBe([0, 8 * MIB, 16 * MIB])
        ->and($this->drive->received === $this->generator->contents)->toBeTrue();
})->with([
    '503' => [503],
    '500' => [500],
    'sin red' => ['connection'],
]);

test('si el error a mitad persiste, se rinde tras los reintentos: 502, sin temporal ni auditoría', function () {
    $this->generator->contents = bigXlsx(17 * MIB);
    $this->drive->failures = [2 => 503, 3 => 503, 4 => 503, 5 => 503, 6 => 503];

    exportToSheets()
        ->assertStatus(502)
        ->assertExactJson(['message' => __('integrations.google.errors.unavailable')]);

    expect($this->drive->statusQueries)->toBe(GoogleSheetsUploader::MAX_RESUMES)
        ->and($this->drive->puts)->toHaveCount(GoogleSheetsUploader::MAX_RESUMES + 2)
        ->and(file_exists($this->generator->paths[0]))->toBeFalse()
        ->and(GoogleConnection::query()->count())->toBe(1)
        ->and(Activity::query()->where('log_name', 'report-delivery')->count())->toBe(0);
});

test('una sesión caducada (404 en un trozo) no se reintenta: 502', function () {
    $this->generator->contents = bigXlsx(9 * MIB);
    $this->drive->failures = [2 => 404];

    exportToSheets()->assertStatus(502);

    expect($this->drive->statusQueries)->toBe(0)
        ->and($this->drive->puts)->toHaveCount(2);
});

test('si el inicio falla, 502 y no se sube nada', function (int $status) {
    $this->generator->contents = bigXlsx(6 * MIB);
    $this->drive->startFailures = [1 => $status];

    exportToSheets()->assertStatus(502);

    expect($this->drive->puts)->toBe([])
        ->and(file_exists($this->generator->paths[0]))->toBeFalse();
})->with([500, 403]);

test('una URI de sesión que no es de Drive se rechaza sin mandarle el token', function () {
    $this->generator->contents = bigXlsx(6 * MIB);
    $this->drive->sessionUri = 'https://evil.example.com/upload?upload_id=1';

    exportToSheets()->assertStatus(502);

    Http::assertNotSent(fn (HttpRequest $request) => str_starts_with($request->url(), 'https://evil.example.com'));
});

test('si Drive rechaza el token al iniciar, lo renueva una vez y sube con el nuevo', function () {
    $this->generator->contents = bigXlsx(6 * MIB);
    // El primer inicio da 401; el segundo, ya con el token renovado, la sesión.
    $this->drive->startFailures = [1 => 401];

    exportToSheets()->assertOk();

    expect($this->drive->starts)->toHaveCount(2)
        ->and($this->drive->starts[1]->header('Authorization'))->toBe(['Bearer ya29.renovado'])
        ->and($this->drive->puts[0]->header('Authorization'))->toBe(['Bearer ya29.renovado']);
});

test('si el token caduca a mitad de la subida (401 en un trozo), lo renueva y repite ese trozo', function () {
    $this->generator->contents = bigXlsx(9 * MIB);
    $this->drive->failures = [2 => 401];

    exportToSheets()->assertOk();

    expect(array_map(fn (HttpRequest $put) => $put->header('Authorization')[0], $this->drive->puts))->toBe([
        'Bearer ya29.acceso-guardado',
        'Bearer ya29.acceso-guardado',
        'Bearer ya29.renovado',
    ])
        ->and(array_column($this->drive->chunks, 'from'))->toBe([0, 8 * MIB])
        ->and($this->drive->received === $this->generator->contents)->toBeTrue();
});
