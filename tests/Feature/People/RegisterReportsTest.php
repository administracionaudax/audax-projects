<?php

use App\Domain\People\MonthCloser;
use App\Domain\People\OvertimeService;
use App\Domain\People\RegisterHasher;
use App\Domain\People\Reports\PeopleReportKind;
use App\Domain\People\Reports\PeopleReports;
use App\Domain\People\Reports\RegisterScope;
use App\Enums\OvertimeDestination;
use App\Models\PeopleExport;
use App\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Informes del registro con huella (PLAN-FASE-11 §6.3.2; D-351; W-089 a W-093) y «Mi registro»
| (D-346): en pantalla y en PDF, Excel y CSV; cada fichero lleva la huella de su contenido (la misma
| en los tres formatos), su SHA-256 queda en people_exports y en la auditoría y se envía en la
| cabecera X-Content-SHA256.
*/

beforeEach(function () {
    enablePeople();
    Setting::set('people_register_starts_on', '2026-09-01');
    Notification::fake();
    Storage::fake('local');

    $this->employee = userWithRole('employee', ['name' => 'Elena Empleada']);
    ['manager' => $this->manager] = peopleTeam($this->employee);
    $this->hr = tap(userWithRole('employee'), fn ($user) => $user->givePermissionTo('manage-people'));

    workday($this->employee, '2026-09-01', '09:00', '18:00', '14:00', '15:00');
    workday($this->employee, '2026-09-02', '09:00', '20:00', '14:00', '15:00');
    $this->travelTo(madridAt('2026-10-01 09:00'));
    app(OvertimeService::class)->decide($this->manager, $this->employee, '2026-09-02', 90, OvertimeDestination::Compensate);
});

it('cada informe se genera con su contenido', function (PeopleReportKind $kind, int $rows) {
    $document = app(PeopleReports::class)->build($kind, new RegisterScope([$this->employee], '2026-09-01', '2026-09-30', 'Elena'));

    expect($document->rows)->toHaveCount($rows)
        ->and($document->headers)->not->toBeEmpty()
        ->and($document->sections)->not->toBeEmpty();
})->with([
    'registro mensual' => [PeopleReportKind::MonthlyRegister, 30],
    'anexo de horas' => [PeopleReportKind::OvertimeAnnex, 1],
    'presencia diaria' => [PeopleReportKind::DailyPresence, 30],
    'presencia mensual' => [PeopleReportKind::MonthlyPresence, 1],
    'fichajes' => [PeopleReportKind::Punches, 8],
    'incidencias' => [PeopleReportKind::Incidents, 21],
]);

it('el registro mensual separa las ordinarias, las extra con su destino y las que faltan por clasificar', function () {
    $document = app(PeopleReports::class)->monthlyRegister(new RegisterScope([$this->employee], '2026-09-01', '2026-09-30', 'Elena'));
    $day2 = collect($document->rows)->firstWhere(1, '2026-09-02');

    // Persona, fecha, previsto, entrada, salida, tramos, comida, trabajado, ordinarias, extra, destino…
    expect($day2[2])->toBe(480)
        ->and($day2[3])->toBe('09:00')
        ->and($day2[4])->toBe('20:00')
        ->and($day2[7])->toBe(600)
        ->and($day2[8])->toBe(510)
        ->and($day2[9])->toBe(90)
        ->and($day2[10])->toBe('Compensar con descanso');
});

it('el anexo de horas lleva el acumulado del año frente al tope de 80 h', function () {
    $document = app(PeopleReports::class)->overtimeAnnex(new RegisterScope([$this->employee], '2026-09-01', '2026-09-30', 'Elena'));

    expect($document->rows[0][0])->toBe('Elena Empleada')
        ->and($document->rows[0][1])->toBe('Valencia (Valencia)')
        ->and($document->rows[0][3])->toBe(90)
        ->and($document->rows[0][8])->toBe(90)
        ->and($document->rows[0][9])->toBe(80 * 60 - 90);
});

it('RR. HH. descarga cada informe en PDF, Excel y CSV con la misma huella de contenido; queda anotado', function () {
    $hashes = [];

    foreach (['pdf', 'xlsx', 'csv'] as $format) {
        $response = $this->actingAs($this->hr)->get("/personas/informes/registro-mensual?mes=2026-09&formato={$format}");
        $response->assertOk()->assertHeader('X-Content-SHA256');
        $body = (string) $response->getContent();

        expect(hash('sha256', (string) $body))->toBe($response->headers->get('X-Content-SHA256'));
        $export = PeopleExport::query()->latest('id')->firstOrFail();
        expect($export->sha256)->toBe($response->headers->get('X-Content-SHA256'))
            ->and($export->kind)->toBe('monthly_register')
            ->and($export->user_id)->toBe($this->hr->id);
        $hashes[] = $export->content_hash;

        if ($format !== 'xlsx') {
            expect((string) $body)->toContain((string) $export->content_hash);
        }
    }

    expect(array_unique($hashes))->toHaveCount(1)
        ->and(DB::table('activity_log')->where('log_name', 'people-exports')->count())->toBe(3);
});

it('la pantalla de informes enseña las primeras filas, la huella y las últimas descargas', function () {
    $this->actingAs($this->hr)->get('/personas/informes?informe=fichajes&desde=2026-09-01&hasta=2026-09-30')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('people/reports')
            ->where('kind', 'fichajes')
            ->where('preview.total', 8)
            ->has('preview.rows', 8)
            ->has('kinds', 6)
            ->etc());
});

it('Mi registro: cada persona descarga el suyo de cualquier periodo, con la cadena y las correcciones', function () {
    $response = $this->actingAs($this->employee)->get('/personas/registro/descargar?desde=2026-09-01&hasta=2026-09-30&formato=csv');
    $response->assertOk();
    $body = (string) $response->getContent();

    expect($body)->toContain('Elena Empleada')->toContain('2026-09-02')
        ->and(PeopleExport::query()->latest('id')->firstOrFail()->kind)->toBe('my_register');

    $pdf = $this->actingAs($this->employee)->get('/personas/registro/descargar?desde=2026-09-01&hasta=2026-09-30&formato=pdf');
    $pdf->assertOk();
    expect((string) $pdf->getContent())->toContain('Fichajes y anulaciones')->toContain('Historial de correcciones');

    $this->actingAs($this->employee)->get('/personas/registro/descargar?formato=xlsx')->assertOk();
});

it('Mi registro pinta los cierres, las horas extra del año y el saldo de horas', function () {
    app(MonthCloser::class)->generate($this->employee, CarbonImmutable::parse('2026-09-01'));

    $this->actingAs($this->employee)->get('/personas/registro')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('people/register')
            ->has('closes', 1)
            ->where('closes.0.month', '2026-09')
            ->where('closes.0.status', 'pending')
            ->where('closes.0.can.confirm', true)
            ->where('overtime.overtime_minutes', 90)
            ->where('balance.minutes', 120)
            ->has('decisions', 1)
            ->etc());
});

it('el PDF guardado de un cierre lo descargan la persona, su responsable y RR. HH., tal cual y con su huella', function () {
    $close = app(MonthCloser::class)->generate($this->employee, CarbonImmutable::parse('2026-09-01'));

    foreach ([$this->employee, $this->manager, $this->hr] as $viewer) {
        $response = $this->actingAs($viewer)->get("/personas/cierres/{$close->id}/pdf");
        $response->assertOk()->assertHeader('X-Content-SHA256', $close->pdf_sha256);
        expect(hash('sha256', (string) $response->getContent()))->toBe($close->pdf_sha256);
    }

    $stranger = userWithRole('employee');
    $this->actingAs($stranger)->get("/personas/cierres/{$close->id}/pdf")->assertForbidden();
});

it('el contenido lleva la huella canónica: el mismo informe da la misma huella', function () {
    $reports = app(PeopleReports::class);
    $scope = new RegisterScope([$this->employee], '2026-09-01', '2026-09-30', 'Elena');

    expect(RegisterHasher::contentHash($reports->punches($scope)->content()))->toBe(RegisterHasher::contentHash($reports->punches($scope)->content()));
});
