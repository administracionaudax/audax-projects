<?php

use App\Domain\People\MonthCloser;
use App\Domain\People\OvertimeService;
use App\Domain\People\RegisterAnchors;
use App\Domain\People\RegisterHasher;
use App\Domain\People\RegisterIntegrity;
use App\Domain\People\TimeBalanceLedger;
use App\Domain\Privacy\RetentionPolicy;
use App\Enums\ClockEventKind;
use App\Enums\OvertimeDestination;
use App\Models\ClockEvent;
use App\Models\EmploymentProfile;
use App\Models\MonthClose;
use App\Models\RegisterAnchor;
use App\Models\RegisterCheckpoint;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\People\RegisterIntegrityBroken;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/*
| Conservación del registro (art. 34.9 ET: 4 años; PLAN-FASE-11 §11.2; D-348) y su integridad
| (§11.1; D-352): nada se borra antes del mes 49 contado desde el final del mes, la retención por
| litigio lo bloquea, la cadena se sigue comprobando y numerando tras la supresión, y la
| comprobación nocturna guarda el ancla del día y avisa a los admins si algo no cuadra.
*/

beforeEach(function () {
    enablePeople();
    Setting::set('people_register_starts_on', '2026-01-01');
    Notification::fake();
    Storage::fake('local');

    $this->employee = userWithRole('employee');
    ['manager' => $this->manager] = peopleTeam($this->employee);
});

it('el plazo del registro es de 48 meses como mínimo: el ajuste no deja poner menos', function () {
    $policy = app(RetentionPolicy::class);

    expect($policy->months(RetentionPolicy::PEOPLE_REGISTER))->toBe(48);

    Setting::set('retention_people_register_months', 12);
    expect($policy->months(RetentionPolicy::PEOPLE_REGISTER))->toBe(48);

    Setting::set('retention_people_register_months', 60);
    expect($policy->months(RetentionPolicy::PEOPLE_REGISTER))->toBe(60);
});

it('suprime a partir del mes 49 (no antes), deja el punto de control y la cadena sigue cuadrando y numerando', function () {
    workday($this->employee, '2026-01-15', '09:00', '17:00');
    workday($this->employee, '2026-01-30', '09:00', '19:00', '14:00', '15:00');
    workday($this->employee, '2026-02-02', '09:00', '17:00');
    $this->travelTo(madridAt('2026-02-03 10:00'));
    app(OvertimeService::class)->decide($this->manager, $this->employee, '2026-01-30', 60, OvertimeDestination::Compensate);
    app(MonthCloser::class)->generate($this->employee, CarbonImmutable::parse('2026-01-01'));

    // El 31/01/2030 aún no se borra nada: enero de 2026 se guarda hasta el final de ese día.
    $this->travelTo(madridAt('2030-01-31 03:10'));
    $this->artisan('app:prune-data')->assertSuccessful();
    expect(ClockEvent::query()->count())->toBe(8)->and(MonthClose::query()->count())->toBe(1);

    // El 01/02/2030 (mes 49) se suprime enero de 2026; febrero sigue.
    $this->travelTo(madridAt('2030-02-01 03:10'));
    $this->artisan('app:prune-data')->assertSuccessful();

    expect(ClockEvent::query()->orderBy('seq')->pluck('seq')->all())->toBe([7, 8])
        ->and(MonthClose::query()->count())->toBe(0)
        ->and(DB::table('overtime_decisions')->count())->toBe(0)
        ->and(RegisterCheckpoint::query()->sole()->seq)->toBe(6)
        // El saldo que sumaban los movimientos suprimidos se arrastra: nadie pierde horas.
        ->and(app(TimeBalanceLedger::class)->balance($this->employee->id))->toBe(80)
        ->and(app(RegisterIntegrity::class)->verify()['ok'])->toBeTrue();

    // Los triggers vuelven a estar: fuera de la supresión, nada se borra.
    expect(inSavepoint(fn () => DB::table('clock_events')->delete()))->toThrow(QueryException::class);

    // La cadena sigue desde el punto de control.
    $next = punchAt($this->employee, '2030-02-01 09:00', ClockEventKind::ClockIn);
    expect($next->seq)->toBe(9)->and(app(RegisterIntegrity::class)->verify()['ok'])->toBeTrue();
});

it('con la retención por litigio activa no se suprime nada de esa persona', function () {
    workday($this->employee, '2026-01-15', '09:00', '17:00');
    $other = userWithRole('employee');
    workday($other, '2026-01-16', '09:00', '17:00');
    EmploymentProfile::query()->create(['user_id' => $this->employee->id, 'legal_hold' => true, 'legal_hold_reason' => 'Reclamación de horas extra']);

    $this->travelTo(madridAt('2031-06-01 03:10'));
    $this->artisan('app:prune-data')->assertSuccessful();

    expect(ClockEvent::query()->where('user_id', $this->employee->id)->count())->toBe(2)
        ->and(ClockEvent::query()->where('user_id', $other->id)->count())->toBe(0);
});

it('la auditoría del registro se guarda como el registro aunque la general sea más corta', function () {
    Setting::set('retention_activity_log_months', 12);
    activity('month_closes')->log('cierre');
    activity('tasks')->log('tarea');

    $this->travelTo(now()->addMonths(13));
    $this->artisan('app:prune-data')->assertSuccessful();

    expect(DB::table('activity_log')->where('log_name', 'month_closes')->count())->toBe(1)
        ->and(DB::table('activity_log')->where('log_name', 'tasks')->count())->toBe(0);
});

it('cada noche comprueba la cadena y guarda el ancla del día, encadenada con la anterior', function () {
    workday($this->employee, '2026-10-05', '09:00', '17:00');

    $this->travelTo(madridAt('2026-10-06 02:50'));
    $this->artisan('people:verify-register --nightly')->assertSuccessful();
    $this->travelTo(madridAt('2026-10-07 02:50'));
    $this->artisan('people:verify-register --nightly')->assertSuccessful();

    $anchors = RegisterAnchor::query()->orderBy('date')->get();
    expect($anchors)->toHaveCount(2)
        ->and($anchors[0]->prev_digest)->toBe(RegisterHasher::GENESIS)
        ->and($anchors[1]->prev_digest)->toBe($anchors[0]->digest)
        ->and($anchors[0]->heads[$this->employee->id]['seq'])->toBe(2)
        ->and($anchors[0]->verified_ok)->toBeTrue();
    Storage::disk('local')->assertExists('people/anclas/2026-10-06.json');
    Notification::assertNothingSent();
});

it('el ancla delata a quien reescribe la cadena entera recalculando las huellas, y avisa a los admins', function () {
    $admin = userWithRole('admin');
    workday($this->employee, '2026-10-05', '09:00', '17:00');
    $this->travelTo(madridAt('2026-10-06 02:50'));
    app(RegisterAnchors::class)->nightly();

    // Alguien con acceso a la base quita el trigger y reescribe la salida, recalculando las huellas.
    tamperRegisterTable('clock_events', 'clock_events_no_update');
    $out = ClockEvent::query()->where('user_id', $this->employee->id)->where('seq', 2)->firstOrFail();
    $out->occurred_at = $out->occurred_at->addHour();
    $hash = app(RegisterHasher::class)->event($out);
    DB::table('clock_events')->where('id', $out->id)->update(['occurred_at' => $out->occurred_at, 'hash' => $hash]);

    $result = app(RegisterIntegrity::class)->verify();
    expect($result['ok'])->toBeFalse()
        ->and($result['problems'])->toContain("ancla del 2026-10-06: la fila 2 de la persona {$this->employee->id} ya no es la que se ancló.");

    $this->travelTo(madridAt('2026-10-07 02:50'));
    $this->artisan('people:verify-register --nightly')->assertFailed();
    Notification::assertSentTo($admin, RegisterIntegrityBroken::class);
    expect(RegisterAnchor::query()->where('date', '2026-10-07')->sole()->verified_ok)->toBeFalse();
});

it('las anclas, las decisiones y los movimientos no se cambian (solo alta)', function () {
    workday($this->employee, '2026-10-05', '09:00', '17:00');
    $this->travelTo(madridAt('2026-10-06 02:50'));
    $anchor = app(RegisterAnchors::class)->nightly()['anchor'];

    expect(inSavepoint(fn () => DB::table('register_anchors')->where('id', $anchor->id)->update(['digest' => str_repeat('0', 64)])))->toThrow(QueryException::class)
        ->and(inSavepoint(fn () => DB::table('register_anchors')->delete()))->toThrow(QueryException::class);
});

it('desactivar a una persona no borra nada de su registro', function () {
    workday($this->employee, '2026-10-05', '09:00', '17:00');
    $this->employee->forceFill(['is_active' => false])->save();

    expect(ClockEvent::query()->where('user_id', $this->employee->id)->count())->toBe(2)
        ->and(fn () => User::query()->whereKey($this->employee->id)->delete())->toThrow(QueryException::class);
});
