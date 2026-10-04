<?php

use App\Domain\Audit\AuditCatalog;
use App\Domain\Integrations\Google\GoogleOAuth;
use App\Domain\Integrations\Google\GoogleSheetsUploader;
use App\Domain\Reports\Delivery\ReportFileGenerator;
use App\Models\Client;
use App\Models\GoogleConnection;
use App\Models\User;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Integrations\FakeReportFileGenerator;
use Tests\Feature\Integrations\GoogleFakes;

/*
| POST /informes/sheets (Fase 9, D-142): el XLSX del informe (ReportFileGenerator, con los permisos
| de quien lo pide) se sube a Drive convertido a hoja de cálculo de Google y se devuelve su enlace.
| El generador real es de la entrega 9.2: aquí, uno falso que escribe un XLSX temporal. Nunca se
| llama a Google: Http::fake y preventStrayRequests.
*/

const SHEETS_UPLOAD_PATTERN = 'www.googleapis.com/upload/drive/v3/files*';

beforeEach(function () {
    GoogleFakes::configure();
    Http::preventStrayRequests();

    $this->generator = new FakeReportFileGenerator;
    app()->instance(ReportFileGenerator::class, $this->generator);

    $this->admin = userWithRole('admin');
    $this->client = Client::factory()->create();
    $this->payload = [
        'kind' => 'client',
        'route_params' => ['client' => $this->client->id],
        'query' => ['periodo' => 'mes', 'fecha' => '2026-09-01'],
    ];
});

/** Respuesta de Drive a la subida. */
function driveUploaded(): array
{
    return ['id' => '1AbCdEf', 'webViewLink' => 'https://docs.google.com/spreadsheets/d/1AbCdEf/edit?usp=drivesdk'];
}

test('sin conexión responde 409 con el mensaje para conectar, sin generar nada', function () {
    Http::fake();

    $this->actingAs($this->admin)
        ->postJson(route('reports.sheets.store'), $this->payload)
        ->assertStatus(409)
        ->assertExactJson(['message' => 'Conecta tu cuenta de Google en Ajustes → Integraciones.']);

    expect($this->generator->paths)->toBe([]);
    Http::assertNothingSent();
});

test('éxito: sube el XLSX en multipart con el mimeType de conversión y devuelve el enlace', function () {
    GoogleFakes::connect($this->admin);
    Http::fake([SHEETS_UPLOAD_PATTERN => Http::response(driveUploaded())]);

    $this->actingAs($this->admin)
        ->postJson(route('reports.sheets.store'), $this->payload)
        ->assertOk()
        ->assertExactJson(['url' => 'https://docs.google.com/spreadsheets/d/1AbCdEf/edit?usp=drivesdk']);

    Http::assertSentCount(1);
    Http::assertSent(function (HttpRequest $request) {
        $contentType = $request->header('Content-Type')[0] ?? '';
        preg_match('/^multipart\/related; boundary=(.+)$/', $contentType, $match);
        $boundary = $match[1] ?? '';
        $parts = array_values(array_filter(
            array_map('trim', explode("--{$boundary}", $request->body())),
            fn (string $part) => $part !== '' && $part !== '--',
        ));
        [$metaHeaders, $meta] = explode("\r\n\r\n", $parts[0] ?? '', 2) + [1 => ''];
        [$fileHeaders, $file] = explode("\r\n\r\n", $parts[1] ?? '', 2) + [1 => ''];
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return str_starts_with($request->url(), GoogleSheetsUploader::UPLOAD_URL.'?')
            && $request->method() === 'POST'
            && ($query['uploadType'] ?? null) === 'multipart'
            && str_contains($query['fields'] ?? '', 'webViewLink')
            && $request->header('Authorization') === ['Bearer ya29.acceso-guardado']
            && $boundary !== ''
            && count($parts) === 2
            && str_contains($metaHeaders, 'Content-Type: application/json; charset=UTF-8')
            && json_decode($meta, true) === [
                'name' => 'Informe de cliente · Montó · septiembre 2026',
                'mimeType' => 'application/vnd.google-apps.spreadsheet',
            ]
            && str_contains($fileHeaders, 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            && $file === FakeReportFileGenerator::CONTENTS;
    });

    // El temporal se borra.
    expect($this->generator->paths)->toHaveCount(1)
        ->and(file_exists($this->generator->paths[0]))->toBeFalse();
});

test('cada subida queda en la auditoría (report-delivery, evento sheets), sin tokens', function () {
    GoogleFakes::connect($this->admin);
    Http::fake([SHEETS_UPLOAD_PATTERN => Http::response(driveUploaded())]);

    $this->actingAs($this->admin)->postJson(route('reports.sheets.store'), $this->payload)->assertOk();

    $activity = Activity::query()->where('log_name', 'report-delivery')->sole();

    expect($activity->event)->toBe('sheets')
        ->and($activity->causer_id)->toBe($this->admin->id)
        ->and($activity->properties['kind'])->toBe('client')
        ->and($activity->properties['route_params'])->toBe(['client' => $this->client->id])
        ->and($activity->properties['query'])->toBe(['periodo' => 'mes', 'fecha' => '2026-09-01'])
        ->and($activity->properties['title'])->toBe('Informe de cliente · Montó · septiembre 2026')
        ->and(json_encode($activity->properties))->not->toContain('ya29')
        ->and(AuditCatalog::entityOf('report-delivery'))->toBe('report_delivery')
        ->and(AuditCatalog::ACTIONS['sheets_exported'])->toBe(['sheets'])
        ->and(AuditCatalog::eventLabel('sheets'))->toBe('Exportado a Google Sheets')
        ->and(AuditCatalog::entityLabel('report_delivery'))->toBe('Informes (exportados y enviados)');
});

test('con el token caducado, lo renueva antes de subir', function () {
    GoogleFakes::connect($this->admin, ['expires_at' => now()->subMinute()]);
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.renovado', 'expires_in' => 3599]),
        SHEETS_UPLOAD_PATTERN => Http::response(driveUploaded()),
    ]);

    $this->actingAs($this->admin)->postJson(route('reports.sheets.store'), $this->payload)->assertOk();

    Http::assertSent(fn (HttpRequest $request) => str_starts_with($request->url(), GoogleSheetsUploader::UPLOAD_URL)
        && $request->header('Authorization') === ['Bearer ya29.renovado']);
});

test('si Drive rechaza el token, lo renueva una vez y reintenta', function () {
    GoogleFakes::connect($this->admin);
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.renovado', 'expires_in' => 3599]),
        SHEETS_UPLOAD_PATTERN => Http::sequence()
            ->push(['error' => ['code' => 401]], 401)
            ->push(driveUploaded()),
    ]);

    $this->actingAs($this->admin)->postJson(route('reports.sheets.store'), $this->payload)->assertOk();

    Http::assertSentCount(3);
});

test('si Google retira el acceso (invalid_grant), borra la conexión y responde 409 para reconectar', function () {
    GoogleFakes::connect($this->admin, ['expires_at' => now()->subMinute()]);
    Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400)]);

    $this->actingAs($this->admin)
        ->postJson(route('reports.sheets.store'), $this->payload)
        ->assertStatus(409)
        ->assertExactJson(['message' => __('integrations.google.errors.reconnect')]);

    expect(GoogleConnection::query()->count())->toBe(0)
        ->and(file_exists($this->generator->paths[0]))->toBeFalse()
        ->and(Activity::query()->where('log_name', 'report-delivery')->count())->toBe(0);
});

test('con Google caído responde 502, borra el temporal y no audita', function (int $status) {
    GoogleFakes::connect($this->admin);
    Http::fake([SHEETS_UPLOAD_PATTERN => Http::response(['error' => ['code' => $status]], $status)]);

    $this->actingAs($this->admin)
        ->postJson(route('reports.sheets.store'), $this->payload)
        ->assertStatus(502)
        ->assertExactJson(['message' => __('integrations.google.errors.unavailable')]);

    expect(file_exists($this->generator->paths[0]))->toBeFalse()
        ->and(GoogleConnection::query()->count())->toBe(1)
        ->and(Activity::query()->where('log_name', 'report-delivery')->count())->toBe(0);
})->with([500, 503, 403]);

test('sin red con Google responde 502', function () {
    GoogleFakes::connect($this->admin);
    Http::fake([SHEETS_UPLOAD_PATTERN => Http::failedConnection()]);

    $this->actingAs($this->admin)
        ->postJson(route('reports.sheets.store'), $this->payload)
        ->assertStatus(502);

    expect(file_exists($this->generator->paths[0]))->toBeFalse();
});

test('quien no puede ver el informe recibe 403 y no se sube nada', function () {
    $employee = userWithRole('employee');
    GoogleFakes::connect($employee);
    Http::fake();

    $this->actingAs($employee)
        ->postJson(route('reports.sheets.store'), $this->payload)
        ->assertForbidden();

    Http::assertNothingSent();
    expect(Activity::query()->where('log_name', 'report-delivery')->count())->toBe(0);
});

test('un colaborador externo no puede exportar a Google Sheets', function () {
    $collaborator = User::factory()->collaborator()->create();
    GoogleFakes::connect($collaborator);
    Http::fake();

    $this->actingAs($collaborator)
        ->postJson(route('reports.sheets.store'), $this->payload)
        ->assertForbidden()
        ->assertJson(['message' => __('app.collaborator_forbidden')]);

    expect($this->generator->paths)->toBe([]);
    Http::assertNothingSent();
});

test('el portal nunca exporta a Google Sheets', function () {
    $this->actingAs(userWithRole('client'))
        ->postJson(route('reports.sheets.store'), $this->payload)
        ->assertForbidden();

    expect($this->generator->paths)->toBe([]);
});

test('valida el informe pedido', function (array $payload, string $field) {
    GoogleFakes::connect($this->admin);
    Http::fake();

    $this->actingAs($this->admin)
        ->postJson(route('reports.sheets.store'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);

    Http::assertNothingSent();
})->with([
    'sin informe' => [[], 'kind'],
    'informe desconocido' => [['kind' => 'nomina'], 'kind'],
    'parámetro de ruta raro' => [['kind' => 'client', 'route_params' => ['client' => '../1']], 'route_params.client'],
    'filtros que no son una lista' => [['kind' => 'direction', 'query' => 'periodo=mes'], 'query'],
]);

test('la exportación tiene su propio límite por persona', function () {
    $route = collect(app('router')->getRoutes()->getRoutes())->first(fn ($route) => $route->getName() === 'reports.sheets.store');

    expect($route->gatherMiddleware())->toContain('throttle:10,1,google-sheets')
        ->and($route->uri())->toBe('informes/sheets')
        ->and($route->methods())->toContain('POST');
});

test('la prop compartida dice si la cuenta está conectada', function () {
    $this->actingAs($this->admin)->get(route('home'))
        ->assertInertia(fn ($page) => $page->where('integrations.google_sheets', true)->where('integrations.google_connected', false));

    GoogleFakes::connect($this->admin);

    $this->actingAs($this->admin)->get(route('home'))
        ->assertInertia(fn ($page) => $page->where('integrations.google_sheets', true)->where('integrations.google_connected', true));

    config(['services.google.client_secret' => '']);

    $this->actingAs($this->admin)->get(route('home'))
        ->assertInertia(fn ($page) => $page->where('integrations.google_sheets', false)->where('integrations.google_connected', false));

    expect(GoogleOAuth::configured())->toBeFalse();
});
