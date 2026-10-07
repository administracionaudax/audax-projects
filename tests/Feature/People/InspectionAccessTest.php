<?php

use App\Domain\People\InspectionAccesses;
use App\Models\InspectionAccess;
use App\Models\PeopleExport;
use App\Models\Setting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Exportación para la Inspección y su acceso temporal de solo lectura (PLAN-FASE-11 §6.4 y §11.3;
| D-353): apagado por defecto; solo RR. HH. lo enciende y crea accesos con ámbito y caducidad; se
| entra con enlace y código (dos factores); a los 5 códigos mal puestos se bloquea; cada consulta
| queda en la auditoría y nunca se sale de sus páginas.
*/

beforeEach(function () {
    enablePeople();
    Setting::set('people_register_starts_on', '2026-09-01');
    Notification::fake();
    Storage::fake('local');

    $this->employee = userWithRole('employee', ['name' => 'Elena Empleada']);
    $this->other = userWithRole('employee', ['name' => 'Pablo Ruiz']);
    ['manager' => $this->manager] = peopleTeam($this->employee, $this->other);
    $this->hr = tap(userWithRole('employee'), fn ($user) => $user->givePermissionTo('manage-people'));

    workday($this->employee, '2026-09-01', '09:00', '18:00', '14:00', '15:00');
    workday($this->other, '2026-09-01', '08:30', '17:00', '14:00', '15:00');
    $this->travelTo(madridAt('2026-10-06 10:00'));
});

/** Crea un acceso con RR. HH. y devuelve el enlace y el código. */
function inspectionAccess(array $overrides = []): array
{
    Setting::set('people_inspection_enabled', true);

    return app(InspectionAccesses::class)->create(test()->hr, [
        'name' => 'Inspectora Martínez',
        'email' => 'inspeccion@example.com',
        'reference' => 'OS 46/0001234/26',
        'user_ids' => [test()->employee->id],
        'scope_from' => '2026-09-01',
        'scope_to' => '2026-09-30',
        'days' => 7,
        ...$overrides,
    ]);
}

it('RR. HH. exporta para la Inspección un ZIP con el registro, la cadena, las correcciones, los cierres, las anclas, la comprobación y las huellas', function () {
    $response = $this->actingAs($this->hr)->get('/personas/inspeccion/exportar?desde=2026-09-01&hasta=2026-09-30&personas[]='.$this->employee->id);
    $response->assertOk()->assertHeader('Content-Type', 'application/zip');

    $path = tempnam(sys_get_temp_dir(), 'itss');
    file_put_contents($path, (string) $response->getContent());
    $zip = new ZipArchive;
    $zip->open($path);
    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = $zip->getNameIndex($i);
    }

    expect($names)->toContain('registro-de-la-jornada.html', 'registro-de-la-jornada.csv', 'fichajes.csv', 'correcciones.csv', 'presencia-diaria.csv', 'cierres-mensuales.csv', 'horas-extra.csv', 'anclas.csv', 'integridad.txt', 'LEEME.txt', 'SHA256SUMS.txt');

    $sums = (string) $zip->getFromName('SHA256SUMS.txt');
    expect($sums)->toContain(hash('sha256', (string) $zip->getFromName('fichajes.csv')).'  fichajes.csv')
        ->and((string) $zip->getFromName('fichajes.csv'))->toContain('Elena Empleada')->not->toContain('Pablo Ruiz')
        ->and((string) $zip->getFromName('integridad.txt'))->toContain('correcto')
        ->and((string) $zip->getFromName('LEEME.txt'))->toContain('34.9');
    $zip->close();
    @unlink($path);

    expect(PeopleExport::query()->where('kind', 'itss')->sole()->sha256)->toBe(hash('sha256', (string) $response->getContent()));
});

it('el acceso de la Inspección está apagado por defecto y solo RR. HH. lo enciende y crea accesos', function () {
    expect((bool) Setting::get('people_inspection_enabled'))->toBeFalse();

    $this->actingAs($this->hr)->post('/personas/inspeccion/accesos', [
        'name' => 'Inspectora', 'email' => 'i@example.com', 'scope_from' => '2026-09-01', 'scope_to' => '2026-09-30', 'days' => 7,
    ])->assertSessionHasErrors('enabled');

    $this->actingAs($this->manager)->put('/personas/inspeccion/ajustes', ['enabled' => true])->assertForbidden();
    $this->actingAs($this->employee)->put('/personas/inspeccion/ajustes', ['enabled' => true])->assertForbidden();

    $this->actingAs($this->hr)->put('/personas/inspeccion/ajustes', ['enabled' => true])->assertRedirect();
    expect((bool) Setting::get('people_inspection_enabled'))->toBeTrue();

    $this->actingAs($this->hr)->post('/personas/inspeccion/accesos', [
        'name' => 'Inspectora Martínez', 'email' => 'i@example.com', 'scope_from' => '2026-09-01', 'scope_to' => '2026-09-30', 'days' => 7,
    ])->assertRedirect()->assertSessionHas('inspection_created');

    $access = InspectionAccess::query()->sole();
    expect($access->token_hash)->toHaveLength(64)
        ->and($access->code_hash)->not->toContain('-')
        ->and($access->valid_until->diffInDays($access->valid_from))->toBe(-7.0);
    expect(DB::table('activity_log')->where('log_name', 'inspection')->where('event', 'created')->count())->toBe(1);
});

it('se entra con el enlace y el código; ve solo su ámbito, en solo lectura, y cada consulta queda en la auditoría', function () {
    ['token' => $token, 'code' => $code] = inspectionAccess();

    $this->get("/inspeccion/acceso/{$token}")->assertOk()->assertInertia(fn (Assert $page) => $page->component('inspection/access')->where('usable', true)->etc());
    $this->get('/inspeccion')->assertNotFound();

    $this->post("/inspeccion/acceso/{$token}", ['code' => '0000-0000'])->assertSessionHasErrors('code');
    $this->post("/inspeccion/acceso/{$token}", ['code' => $code])->assertRedirect('/inspeccion');

    $this->get('/inspeccion')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('inspection/index')
        ->has('people', 1)
        ->where('people.0.name', 'Elena Empleada')
        ->where('period.from', '2026-09-01')
        ->etc());
    $this->get("/inspeccion/personas/{$this->employee->id}?mes=2026-09")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('inspection/person')->where('totals.worked_minutes', 480)->etc());
    $this->get("/inspeccion/personas/{$this->other->id}")->assertNotFound();
    $this->get('/inspeccion/exportar')->assertOk()->assertHeader('Content-Type', 'application/zip');

    // No es una cuenta de la app: el resto no se abre.
    $this->get('/personas/jornada')->assertRedirect('/login');
    $this->get('/')->assertRedirect('/login');

    expect(DB::table('activity_log')->where('log_name', 'inspection')->where('event', 'viewed')->count())->toBeGreaterThanOrEqual(3)
        ->and(PeopleExport::query()->whereNotNull('inspection_access_id')->count())->toBe(1);
});

it('a los 5 códigos mal puestos el acceso se bloquea', function () {
    ['token' => $token, 'code' => $code] = inspectionAccess();

    foreach (range(1, 5) as $attempt) {
        $this->post("/inspeccion/acceso/{$token}", ['code' => '1111-1111']);
    }

    $this->post("/inspeccion/acceso/{$token}", ['code' => $code])->assertSessionHasErrors('code');
    expect(InspectionAccess::query()->sole()->state())->toBe('locked');
});

it('caduca, se revoca y deja de funcionar si se apaga el acceso o el módulo', function () {
    ['access' => $access, 'token' => $token, 'code' => $code] = inspectionAccess(['days' => 1]);

    $this->post("/inspeccion/acceso/{$token}", ['code' => $code])->assertRedirect('/inspeccion');
    $this->get('/inspeccion')->assertOk();

    Setting::set('people_inspection_enabled', false);
    $this->get('/inspeccion')->assertNotFound();
    Setting::set('people_inspection_enabled', true);
    $this->get('/inspeccion')->assertOk();

    $this->travelTo(madridAt('2026-10-07 11:00'));
    $this->get('/inspeccion')->assertNotFound();
    expect($access->fresh()->state())->toBe('expired');

    ['access' => $second, 'token' => $token2, 'code' => $code2] = inspectionAccess();
    $this->post("/inspeccion/acceso/{$token2}", ['code' => $code2])->assertRedirect('/inspeccion');
    $this->actingAs($this->hr)->post("/personas/inspeccion/accesos/{$second->id}/revocar")->assertRedirect();
    auth()->logout();
    $this->get('/inspeccion')->assertNotFound();
    $this->get("/inspeccion/acceso/{$token2}")->assertNotFound();
});

it('comprobar un fichero: dice si coincide con uno que salió de la app', function () {
    $download = $this->actingAs($this->hr)->get('/personas/informes/fichajes?desde=2026-09-01&hasta=2026-09-30&formato=csv');
    $content = (string) $download->getContent();
    $file = UploadedFile::fake()->createWithContent('fichajes.csv', $content);

    $this->actingAs($this->hr)->post('/personas/inspeccion/comprobar', ['file' => $file])->assertRedirect()
        ->assertSessionHas('file_verification', fn (array $result) => $result['match'] !== null && $result['match']['kind'] === 'punches');

    $tampered = UploadedFile::fake()->createWithContent('fichajes.csv', $content.'x');
    $this->actingAs($this->hr)->post('/personas/inspeccion/comprobar', ['file' => $tampered])
        ->assertSessionHas('file_verification', fn (array $result) => $result['match'] === null);
});
