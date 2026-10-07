<?php

use App\Domain\Absences\LeaveLedger;
use App\Models\Absence;
use App\Models\PeopleExport;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Informes de R3 (D-371): «Saldos», «Actividad» y «Justificantes pendientes», con el patrón de R2:
| en pantalla y en PDF, Excel y CSV con huella; solo RR. HH.
*/

beforeEach(function () {
    enablePeople();
    Notification::fake();
    Storage::fake('local');
    $this->travelTo(madridAt('2026-10-07 10:00'));
    $this->employee = userWithRole('employee', ['name' => 'Elena']);
    peopleTeam($this->employee);
    $this->hr = hrUser();
    app(LeaveLedger::class)->syncYear(2026);
    Absence::factory()->for($this->employee)->approved()->between('2026-08-03', '2026-08-07')->create();
    Absence::factory()->for($this->employee)->approved()->between('2026-10-05', '2026-10-05')->create(['leave_type_id' => leaveType('moving')->id, 'type' => 'leave']);
});

it('Saldos: lo asignado, lo disfrutado y lo disponible de cada persona', function () {
    $this->actingAs($this->hr)->get('/personas/informes?informe=saldos&desde=2026-01-01&hasta=2026-10-07')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('kind', 'saldos')
            ->where('preview.rows', fn ($rows) => collect($rows)->contains(fn ($row) => $row[0] === 'Elena' && $row[1] === 'Vacaciones' && $row[4] === 2200 && $row[8] === 500 && $row[10] === 1700)));
});

it('Actividad: las solicitudes y los movimientos del libro del periodo', function () {
    $this->actingAs($this->hr)->get('/personas/informes?informe=actividad&desde=2026-01-01&hasta=2026-10-07')
        ->assertInertia(fn (Assert $page) => $page
            ->where('preview.rows', fn ($rows) => collect($rows)->where(1, 'request')->where(0, 'Elena')->count() === 2
                && collect($rows)->where(1, 'accrual')->where(0, 'Elena')->pluck(3)->sort()->values()->all() === ['Fuerza mayor familiar (por horas)', 'Vacaciones']));
});

it('Justificantes pendientes, y la descarga en CSV con huella anotada', function () {
    $this->actingAs($this->hr)->get('/personas/informes?informe=justificantes-pendientes&desde=2026-01-01&hasta=2026-12-31')
        ->assertInertia(fn (Assert $page) => $page->where('preview.total', 1)->where('preview.rows.0.1', 'Traslado del domicilio habitual'));

    $response = $this->actingAs($this->hr)->get('/personas/informes/justificantes-pendientes?formato=csv&desde=2026-01-01&hasta=2026-12-31')->assertOk();
    expect($response->headers->get('X-Content-SHA256'))->toHaveLength(64)
        ->and(PeopleExport::query()->where('kind', 'missing_documents')->exists())->toBeTrue();
});

it('solo RR. HH. los ve', function () {
    $this->actingAs($this->employee)->get('/personas/informes?informe=saldos')->assertForbidden();
});
