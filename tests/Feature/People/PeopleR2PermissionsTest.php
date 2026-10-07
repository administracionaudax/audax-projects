<?php

use App\Domain\People\MonthCloser;
use App\Domain\People\PeopleDocuments;
use App\Domain\Privacy\Export\PersonalDataArchive;
use App\Enums\Role;
use App\Models\EmploymentProfile;
use App\Models\PeopleDocument;
use App\Models\PeopleDocumentRead;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\People\PeopleDocumentPublished;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Matriz de permisos de R2 (PLAN-FASE-11 §9; D-355), los documentos de RR. HH. con lectura
| registrada (D-354), los datos laborales (tiempo parcial y retención por litigio, D-348) y la sección
| del registro en la exportación de datos personales (D-357).
*/

beforeEach(function () {
    enablePeople();
    Setting::set('people_register_starts_on', '2026-09-01');
    Notification::fake();
    Storage::fake('local');

    $this->employee = userWithRole('employee');
    $this->colleague = userWithRole('employee');
    ['manager' => $this->manager] = peopleTeam($this->employee, $this->colleague);
    ['manager' => $this->otherManager] = peopleTeam();
    $this->hr = tap(userWithRole('employee'), fn (User $user) => $user->givePermissionTo('manage-people'));
    $this->admin = userWithRole('admin');
    $this->collaborator = User::factory()->withRole(Role::Collaborator)->create();
    $this->client = userWithRole('client');

    workday($this->employee, '2026-09-01', '09:00', '19:00', '14:00', '15:00');
    $this->travelTo(madridAt('2026-10-02 10:00'));
    $this->close = app(MonthCloser::class)->generate($this->employee, CarbonImmutable::parse('2026-09-01'));
});

function r2Actor(string $who): User
{
    return match ($who) {
        'persona' => test()->employee,
        'compañero' => test()->colleague,
        'responsable' => test()->manager,
        'otro responsable' => test()->otherManager,
        'rrhh' => test()->hr,
        'admin' => test()->admin,
        'colaborador' => test()->collaborator,
        'cliente' => test()->client,
    };
}

it('Mi registro y Documentos: toda la plantilla; ni colaboradores ni clientes', function (string $who, int $status) {
    $this->actingAs(r2Actor($who))->get('/personas/registro')->assertStatus($status);
    $this->actingAs(r2Actor($who))->get('/personas/documentos')->assertStatus($status);
})->with([
    ['persona', 200], ['responsable', 200], ['rrhh', 200], ['admin', 200],
    ['colaborador', 403], ['cliente', 302],
]);

it('Cierres y Horas extra: responsables y RR. HH.', function (string $who, int $status) {
    $this->actingAs(r2Actor($who))->get('/personas/cierres')->assertStatus($status);
    $this->actingAs(r2Actor($who))->get('/personas/horas-extra')->assertStatus($status);
})->with([
    ['persona', 403], ['responsable', 200], ['otro responsable', 200], ['rrhh', 200], ['admin', 200],
    ['colaborador', 403], ['cliente', 302],
]);

it('Informes e Inspección: solo RR. HH. (manage-people) y los admins', function (string $who, int $status) {
    $this->actingAs(r2Actor($who))->get('/personas/informes')->assertStatus($status);
    $this->actingAs(r2Actor($who))->get('/personas/informes/fichajes?formato=csv')->assertStatus($status);
    $this->actingAs(r2Actor($who))->get('/personas/inspeccion')->assertStatus($status);
    $this->actingAs(r2Actor($who))->get('/personas/inspeccion/exportar')->assertStatus($status);
})->with([
    ['persona', 403], ['responsable', 403], ['rrhh', 200], ['admin', 200],
    ['colaborador', 403], ['cliente', 302],
]);

it('el cierre lo confirma solo la persona; lo desconfirman su responsable o RR. HH., nunca otro responsable ni un compañero', function () {
    $this->actingAs($this->manager)->post("/personas/cierres/{$this->close->id}/confirmar")->assertForbidden();
    $this->actingAs($this->colleague)->post("/personas/cierres/{$this->close->id}/desacuerdo", ['note' => 'No es mío, pero opino'])->assertForbidden();
    $this->actingAs($this->employee)->post("/personas/cierres/{$this->close->id}/confirmar")->assertRedirect();

    $this->actingAs($this->otherManager)->post("/personas/cierres/{$this->close->id}/desconfirmar", ['reason' => 'No es de mi equipo'])->assertForbidden();
    $this->actingAs($this->employee)->post("/personas/cierres/{$this->close->id}/desconfirmar", ['reason' => 'Lo quiero cambiar'])->assertForbidden();
    $this->actingAs($this->manager)->post("/personas/cierres/{$this->close->id}/desconfirmar", ['reason' => 'Hay que corregir el día 1'])->assertRedirect();
});

it('el PDF de un cierre: la persona, su responsable y RR. HH.; no un compañero ni otro responsable', function (string $who, int $status) {
    $this->actingAs(r2Actor($who))->get("/personas/cierres/{$this->close->id}/pdf")->assertStatus($status);
})->with([
    ['persona', 200], ['responsable', 200], ['rrhh', 200], ['admin', 200],
    ['compañero', 403], ['otro responsable', 403], ['colaborador', 403],
]);

it('clasificar horas extra y anotar el saldo: su responsable o RR. HH.; nunca la propia persona', function () {
    $data = ['user_id' => $this->employee->id, 'date' => '2026-09-01', 'overtime_minutes' => 60, 'destination' => 'pay'];

    $this->actingAs($this->employee)->post('/personas/horas-extra', $data)->assertForbidden();
    $this->actingAs($this->otherManager)->post('/personas/horas-extra', $data)->assertForbidden();
    $this->actingAs($this->manager)->post('/personas/horas-extra', $data)->assertRedirect()->assertSessionHasNoErrors();

    $this->actingAs($this->manager)->post('/personas/saldo', ['user_id' => $this->employee->id, 'kind' => 'adjustment', 'minutes' => 30, 'date' => '2026-10-01', 'reason' => 'Ajuste manual'])->assertForbidden();
    $this->actingAs($this->hr)->post('/personas/saldo', ['user_id' => $this->employee->id, 'kind' => 'opening_balance', 'minutes' => 30, 'date' => '2026-10-01', 'reason' => 'Saldo inicial desde Woffu'])->assertRedirect()->assertSessionHasNoErrors();
});

it('con el módulo apagado nada de R2 se abre (salvo a un admin en modo de prueba)', function () {
    Setting::set('modules', [...(array) Setting::get('modules', []), 'people' => false]);

    $this->actingAs($this->employee)->get('/personas/registro')->assertNotFound();
    $this->actingAs($this->hr)->get('/personas/informes')->assertNotFound();
});

it('documentos: el borrador es la versión 1, «He leído» queda registrado y una versión nueva pide otra lectura', function () {
    $documents = app(PeopleDocuments::class);
    $protocol = $documents->current('register_protocol');

    expect($protocol->version)->toBe(1)->and($protocol->is_draft)->toBeTrue()->and($protocol->body)->toContain('pendiente de revisión');
    expect($documents->unreadFor($this->employee))->toBe(['register_protocol', 'disconnection_policy']);

    $this->actingAs($this->employee)->post("/personas/documentos/{$protocol->id}/leido")->assertRedirect();
    expect($documents->unreadFor($this->employee))->toBe(['disconnection_policy'])
        ->and(PeopleDocumentRead::query()->where('user_id', $this->employee->id)->count())->toBe(1);

    $this->actingAs($this->employee)->put('/personas/documentos/register_protocol', ['title' => 'Otro', 'body' => 'Texto'])->assertForbidden();
    $this->actingAs($this->hr)->put('/personas/documentos/register_protocol', ['title' => 'Registro de jornada', 'body' => 'Texto definitivo revisado por la asesoría.'])->assertRedirect();

    $current = $documents->current('register_protocol');
    expect($current->version)->toBe(2)->and($current->is_draft)->toBeFalse()
        ->and(PeopleDocument::query()->count())->toBe(3)
        ->and($documents->unreadFor($this->employee))->toBe(['register_protocol', 'disconnection_policy']);
    Notification::assertSentTo($this->employee, PeopleDocumentPublished::class);

    // La versión anterior ya no se puede marcar como leída.
    $this->actingAs($this->colleague)->post("/personas/documentos/{$protocol->id}/leido")->assertSessionHasErrors('document');

    $this->actingAs($this->hr)->get('/personas/documentos')->assertInertia(fn (Assert $page) => $page
        ->component('people/documents')
        ->has('documents', 2)
        ->where('documents.0.version', 2)
        ->where('can_publish', true)
        ->has('documents.0.readers')
        ->etc());
});

it('datos laborales: RR. HH. marca el tiempo parcial y la retención por litigio con su motivo, también de quien ya no está', function () {
    $this->employee->forceFill(['is_active' => false])->save();

    $this->actingAs($this->manager)->put("/admin/usuarios/{$this->employee->id}/laboral", ['subject_to_register' => true, 'legal_hold' => true, 'legal_hold_reason' => 'Demanda'])->assertForbidden();

    $this->actingAs($this->hr)->put("/admin/usuarios/{$this->employee->id}/laboral", ['subject_to_register' => true, 'legal_hold' => true])
        ->assertSessionHasErrors('legal_hold_reason');

    $this->actingAs($this->hr)->put("/admin/usuarios/{$this->employee->id}/laboral", [
        'subject_to_register' => true, 'part_time' => true, 'legal_hold' => true, 'legal_hold_reason' => 'Reclamación de horas extra (juzgado n.º 3)',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $profile = EmploymentProfile::query()->where('user_id', $this->employee->id)->sole();
    expect($profile->legal_hold)->toBeTrue()->and($profile->part_time)->toBeTrue()
        ->and($profile->legal_hold_by)->toBe($this->hr->id)
        ->and($profile->legal_hold_since)->not->toBeNull();
});

it('la exportación de datos personales lleva el registro de jornada, las correcciones, los cierres, las horas extra, el saldo y los datos laborales', function () {
    $keys = array_map(fn ($section) => $section->key(), app(PersonalDataArchive::class)->sections());

    expect($keys)->toContain('registro-jornada', 'correcciones-registro', 'cierres-mensuales', 'horas-extra', 'saldo-horas', 'datos-laborales');

    $section = collect(app(PersonalDataArchive::class)->sections())->first(fn ($section) => $section->key() === 'registro-jornada');
    $rows = iterator_to_array($section->rows($this->employee), false);
    expect($rows)->toHaveCount(4)->and($rows[0]['kind'])->toBe('clock_in')->and($rows[0])->not->toHaveKey('ip_hash');

    $closes = collect(app(PersonalDataArchive::class)->sections())->first(fn ($section) => $section->key() === 'cierres-mensuales');
    expect(iterator_to_array($closes->rows($this->employee), false)[0]['pdf_sha256'])->toBe($this->close->pdf_sha256);
});

it('el texto de privacidad por defecto cuenta el registro de jornada (pendiente de asesor)', function () {
    $text = (string) __('privacy.default_notice');

    expect($text)->toContain('registro diario de tu jornada')
        ->toContain('6.1.c')
        ->toContain('cuatro años')
        ->toContain('No se usa geolocalización ni datos biométricos')
        ->toContain('Borrador pendiente de revisión');
});
