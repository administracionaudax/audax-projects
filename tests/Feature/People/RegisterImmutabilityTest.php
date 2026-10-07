<?php

use App\Domain\People\ClockCorrectionService;
use App\Domain\People\RegisterImmutable;
use App\Domain\People\RegisterIntegrity;
use App\Models\ClockCorrection;
use App\Models\ClockEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
| Inalterabilidad del registro (art. 34.9 ET, L-02 y L-14; D-332 y D-335). Tres capas:
| 1. el modelo no deja cambiar ni borrar un fichaje ni una corrección decidida,
| 2. la base de datos tampoco: un *trigger* (en PostgreSQL y en SQLite) rechaza UPDATE y DELETE
|    aunque se salte el modelo,
| 3. y si alguien quitara el *trigger*, la cadena de huellas lo delata (RegisterIntegrity).
*/

beforeEach(function () {
    $this->user = userWithRole('employee');
    $this->events = workday($this->user, '2026-10-05', '09:00', '17:00', '14:00', '15:00');
});

it('el modelo no deja cambiar ni borrar un fichaje', function () {
    $event = ClockEvent::query()->findOrFail($this->events[0]->id);
    $event->forceFill(['occurred_at' => madridAt('2026-10-05 08:00')]);

    expect(fn () => $event->save())->toThrow(RegisterImmutable::class)
        ->and(fn () => ClockEvent::query()->findOrFail($this->events[0]->id)->delete())->toThrow(RegisterImmutable::class)
        ->and(ClockEvent::query()->count())->toBe(4)
        ->and(ClockEvent::query()->findOrFail($this->events[0]->id)->occurred_at->toIso8601ZuluString())->toBe('2026-10-05T07:00:00Z');
});

it('la base de datos rechaza UPDATE y DELETE aunque se salte el modelo', function (Closure $write) {
    expect(inSavepoint($write))->toThrow(QueryException::class);

    expect(ClockEvent::query()->count())->toBe(4)
        ->and(ClockEvent::query()->orderBy('seq')->first()?->occurred_at->toIso8601ZuluString())->toBe('2026-10-05T07:00:00Z');
})->with([
    'UPDATE con el constructor de consultas' => [fn () => DB::table('clock_events')->where('seq', 1)->update(['occurred_at' => '2026-10-05 06:00:00'])],
    'UPDATE masivo con Eloquent' => [fn () => ClockEvent::query()->update(['kind' => 'clock_out'])],
    'DELETE de una fila' => [fn () => DB::table('clock_events')->where('seq', 4)->delete()],
    'DELETE de todo' => [fn () => DB::table('clock_events')->delete()],
    'UPDATE en SQL a mano' => [fn () => DB::statement("UPDATE clock_events SET occurred_at = '2026-10-05 05:00:00'")],
]);

it('una corrección decidida no se cambia ni se borra, ni con el modelo ni en la base de datos', function () {
    $manager = peopleTeam($this->user)['manager'];

    $this->travelTo(madridAt('2026-10-06 10:00'));
    $correction = app(ClockCorrectionService::class)->propose($this->user, $this->user, '2026-10-05', [
        ['id' => $this->events[0]->id, 'kind' => 'clock_in', 'time' => '08:30'],
        ['id' => $this->events[1]->id, 'kind' => 'pause_start', 'time' => '14:00'],
        ['id' => $this->events[2]->id, 'kind' => 'pause_end', 'time' => '15:00'],
        ['id' => $this->events[3]->id, 'kind' => 'clock_out', 'time' => '17:00'],
    ], 'Entré a las 8:30 y olvidé fichar');
    app(ClockCorrectionService::class)->accept($manager, $correction);

    $decided = ClockCorrection::query()->findOrFail($correction->id);
    $decided->forceFill(['decision_note' => 'Cambiado después']);

    expect(fn () => $decided->save())->toThrow(RegisterImmutable::class)
        ->and(fn () => ClockCorrection::query()->findOrFail($correction->id)->delete())->toThrow(RegisterImmutable::class)
        ->and(inSavepoint(fn () => DB::table('clock_corrections')->where('id', $correction->id)->update(['status' => 'pending'])))->toThrow(QueryException::class)
        ->and(inSavepoint(fn () => DB::table('clock_corrections')->where('id', $correction->id)->delete()))->toThrow(QueryException::class);
});

it('lo propuesto en una corrección pendiente tampoco se cambia; solo su decisión', function () {
    $this->travelTo(madridAt('2026-10-06 10:00'));
    $correction = app(ClockCorrectionService::class)->propose($this->user, $this->user, '2026-10-05', [
        ['id' => $this->events[0]->id, 'kind' => 'clock_in', 'time' => '08:45'],
        ['id' => $this->events[1]->id, 'kind' => 'pause_start', 'time' => '14:00'],
        ['id' => $this->events[2]->id, 'kind' => 'pause_end', 'time' => '15:00'],
        ['id' => $this->events[3]->id, 'kind' => 'clock_out', 'time' => '17:00'],
    ], 'Entrada real a las 8:45');

    expect(inSavepoint(fn () => DB::table('clock_corrections')->where('id', $correction->id)->update(['reason' => 'Otro motivo'])))->toThrow(QueryException::class)
        ->and(inSavepoint(fn () => DB::table('clock_corrections')->where('id', $correction->id)->update(['adds' => '[]'])))->toThrow(QueryException::class)
        ->and(inSavepoint(fn () => DB::table('clock_corrections')->where('id', $correction->id)->delete()))->toThrow(QueryException::class);
});

it('no se puede borrar a una persona con fichajes (su registro se conserva)', function () {
    expect(inSavepoint(fn () => DB::table('users')->where('id', $this->user->id)->delete()))->toThrow(QueryException::class)
        ->and(ClockEvent::query()->where('user_id', $this->user->id)->count())->toBe(4);
});

it('la cadena de huellas delata un cambio hecho quitando el trigger', function () {
    expect(app(RegisterIntegrity::class)->verify()['ok'])->toBeTrue();

    DB::beginTransaction();
    dropRegisterTriggers();
    DB::table('clock_events')->where('user_id', $this->user->id)->where('seq', 1)->update(['occurred_at' => '2026-10-05 06:00:00']);
    $result = app(RegisterIntegrity::class)->verify();
    DB::rollBack();

    expect($result['ok'])->toBeFalse()
        ->and($result['problems'][0])->toContain('fila 1')
        ->and($result['problems'][0])->toContain('huella')
        // Al deshacer, el trigger vuelve y la cadena está como estaba.
        ->and(app(RegisterIntegrity::class)->verify()['ok'])->toBeTrue()
        ->and(inSavepoint(fn () => DB::table('clock_events')->delete()))->toThrow(QueryException::class);
});

it('la cadena delata una fila borrada o intercalada', function () {
    DB::beginTransaction();
    dropRegisterTriggers();
    DB::table('clock_events')->where('user_id', $this->user->id)->where('seq', 2)->delete();
    $result = app(RegisterIntegrity::class)->verify([$this->user->id]);
    DB::rollBack();

    expect($result['ok'])->toBeFalse()
        ->and(implode(' ', $result['problems']))->toContain('no enlaza');
});

it('people:verify-register sale bien con el registro íntegro', function () {
    expect(Artisan::call('people:verify-register'))->toBe(0)
        ->and(Artisan::output())->toContain('Registro íntegro: 4 filas');
});

/** Quita los *triggers* del registro (solo dentro de una transacción que se deshace). */
function dropRegisterTriggers(): void
{
    if (DB::getDriverName() === 'pgsql') {
        DB::statement('ALTER TABLE clock_events DISABLE TRIGGER clock_events_no_update_delete');

        return;
    }

    DB::statement('DROP TRIGGER clock_events_no_update');
    DB::statement('DROP TRIGGER clock_events_no_delete');
}
