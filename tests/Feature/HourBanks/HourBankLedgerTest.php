<?php

use App\Domain\HourBanks\Events\HourBankOverageRecorded;
use App\Domain\HourBanks\Events\HourBankThresholdReached;
use App\Domain\HourBanks\HourBankLedger;
use App\Domain\Time\TimeEntryData;
use App\Domain\Time\TimeEntryWriter;
use App\Enums\HourBankStatus;
use App\Enums\OveragePolicy;
use App\Enums\TimeEntryStatus;
use App\Models\HourBank;
use App\Models\HourBankAlert;
use App\Models\Setting;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

/*
| Motor de bolsas (SPEC §8, D-019, D-035). Las entradas se crean con la factoría (sin reglas §7)
| para aislar el cálculo; las reglas de imputación se prueban en tests/Feature/Time.
*/

function bankWithTask(int $hours = 10, array $bankAttributes = []): array
{
    $bank = HourBank::factory()->hours($hours)->create($bankAttributes);
    $task = Task::factory()->inBank($bank)->create();

    return [$bank, $task];
}

function logEntry(Task $task, int $minutes, string $date = '2026-09-01', array $attributes = []): TimeEntry
{
    return TimeEntry::factory()->forTask($task)->minutes($minutes)->on($date)->create($attributes);
}

beforeEach(function () {
    Event::fake([HourBankThresholdReached::class, HourBankOverageRecorded::class]);
});

it('cuenta como consumo todas las entradas, sea cual sea su estado (§8.4)', function () {
    [$bank, $task] = bankWithTask(10);

    logEntry($task, 60, attributes: ['status' => TimeEntryStatus::Draft]);
    logEntry($task, 60, attributes: ['status' => TimeEntryStatus::Submitted]);
    logEntry($task, 60, attributes: ['status' => TimeEntryStatus::Approved]);
    logEntry($task, 60, attributes: ['status' => TimeEntryStatus::Locked]);

    expect($bank->fresh()->consumed_minutes)->toBe(240)
        ->and($bank->fresh()->overage_minutes)->toBe(0)
        ->and($bank->fresh()->remaining_minutes)->toBe(360);
});

it('guarda en una sola entrada los minutos que cruzan el límite como exceso (D-019)', function () {
    [$bank, $task] = bankWithTask(10);

    $first = logEntry($task, 9 * 60, '2026-09-01');
    $crossing = logEntry($task, 2 * 60, '2026-09-02');

    expect($first->fresh()->overage_minutes)->toBe(0)
        ->and($crossing->fresh()->overage_minutes)->toBe(60)
        ->and($crossing->fresh()->in_bank_minutes)->toBe(60)
        ->and($crossing->fresh()->is_overage)->toBeTrue()
        ->and(TimeEntry::query()->count())->toBe(2);

    $bank->refresh();
    expect($bank->consumed_minutes)->toBe(660)
        ->and($bank->overage_minutes)->toBe(60)
        ->and($bank->in_bank_minutes)->toBe(600)
        ->and($bank->remaining_minutes)->toBe(0)
        ->and($bank->consumed_pct)->toBe(110.0)
        ->and($bank->status)->toBe(HourBankStatus::Exhausted);
});

it('una entrada entera en exceso cuando la bolsa ya está agotada', function () {
    [$bank, $task] = bankWithTask(1);

    logEntry($task, 60, '2026-09-01');
    $extra = logEntry($task, 45, '2026-09-02');

    expect($extra->fresh()->overage_minutes)->toBe(45)
        ->and($bank->fresh()->overage_minutes)->toBe(45);
});

it('recalcula el exceso al borrar una entrada y la bolsa vuelve a activa', function () {
    [$bank, $task] = bankWithTask(10);

    $first = logEntry($task, 8 * 60, '2026-09-01');
    $second = logEntry($task, 4 * 60, '2026-09-02');
    expect($second->fresh()->overage_minutes)->toBe(120);

    $first->delete();

    expect($second->fresh()->overage_minutes)->toBe(0)
        ->and($bank->fresh()->consumed_minutes)->toBe(240)
        ->and($bank->fresh()->overage_minutes)->toBe(0)
        ->and($bank->fresh()->status)->toBe(HourBankStatus::Active);
});

it('recalcula el exceso al editar los minutos de una entrada', function () {
    [$bank, $task] = bankWithTask(10);

    $first = logEntry($task, 8 * 60, '2026-09-01');
    $second = logEntry($task, 3 * 60, '2026-09-02');
    expect($second->fresh()->overage_minutes)->toBe(60);

    $first->update(['minutes' => 6 * 60]);

    expect($second->fresh()->overage_minutes)->toBe(0)
        ->and($bank->fresh()->consumed_minutes)->toBe(540);
});

it('una imputación con fecha anterior desplaza el exceso a las entradas posteriores (orden cronológico)', function () {
    [$bank, $task] = bankWithTask(10);

    $late = logEntry($task, 6 * 60, '2026-09-10');
    expect($late->fresh()->overage_minutes)->toBe(0);

    $early = logEntry($task, 6 * 60, '2026-09-01');

    expect($early->fresh()->overage_minutes)->toBe(0)
        ->and($late->fresh()->overage_minutes)->toBe(120)
        ->and($bank->fresh()->overage_minutes)->toBe(120);
});

it('a igual fecha ordena por creación (created_at, id)', function () {
    [$bank, $task] = bankWithTask(1);

    $this->travelTo('2026-09-01 09:00:00');
    $a = logEntry($task, 40, '2026-09-01');
    $this->travelTo('2026-09-01 10:00:00');
    $b = logEntry($task, 40, '2026-09-01');

    expect($a->fresh()->overage_minutes)->toBe(0)
        ->and($b->fresh()->overage_minutes)->toBe(20);
});

it('las entradas bloqueadas nunca cambian y reservan su parte de la bolsa', function () {
    [$bank, $task] = bankWithTask(10);

    $locked = logEntry($task, 6 * 60, '2026-09-10', ['status' => TimeEntryStatus::Locked, 'locked_at' => now()]);
    expect($locked->fresh()->overage_minutes)->toBe(0);

    // Una entrada anterior no puede empujar a exceso a la bloqueada: se queda ella con el exceso.
    $earlier = logEntry($task, 6 * 60, '2026-09-01');

    expect($locked->fresh()->overage_minutes)->toBe(0)
        ->and($earlier->fresh()->overage_minutes)->toBe(120)
        ->and($bank->fresh()->overage_minutes)->toBe(120)
        ->and($bank->fresh()->in_bank_minutes)->toBe(600);
});

it('una bloqueada con exceso lo conserva aunque luego se libere saldo', function () {
    [$bank, $task] = bankWithTask(10);

    $first = logEntry($task, 10 * 60, '2026-09-01');
    $second = logEntry($task, 2 * 60, '2026-09-02');
    expect($second->fresh()->overage_minutes)->toBe(120);

    TimeEntry::query()->whereKey($second->id)->update(['status' => TimeEntryStatus::Locked->value, 'locked_at' => now()]);
    $first->delete();

    expect($second->fresh()->overage_minutes)->toBe(120)
        ->and($bank->fresh()->consumed_minutes)->toBe(120)
        ->and($bank->fresh()->overage_minutes)->toBe(120);
});

it('si se cambian los minutos de una bloqueada (admin), su exceso se recalcula entre 0 y sus minutos', function () {
    [$bank, $task] = bankWithTask(1);

    // Reducir una bloqueada que estaba entera en exceso: su exceso no puede superar sus minutos.
    logEntry($task, 60, '2026-09-01');
    $locked = logEntry($task, 60, '2026-09-02', ['status' => TimeEntryStatus::Locked, 'overage_minutes' => 60]);
    app(HourBankLedger::class)->recalculate($bank);
    expect($locked->fresh()->overage_minutes)->toBe(60);

    $locked->update(['minutes' => 15]);

    expect($locked->fresh()->overage_minutes)->toBe(15)
        ->and($locked->fresh()->in_bank_minutes)->toBe(0)
        ->and($bank->fresh()->consumed_minutes)->toBe(75)
        ->and($bank->fresh()->overage_minutes)->toBe(15)
        ->and($bank->fresh()->in_bank_minutes)->toBe(60);
});

it('si se amplía una bloqueada sin exceso en una bolsa llena, lo que no cabe pasa a exceso', function () {
    [$bank, $task] = bankWithTask(1);
    $locked = logEntry($task, 60, '2026-09-02', ['status' => TimeEntryStatus::Locked]);

    $locked->update(['minutes' => 120]);

    expect($locked->fresh()->overage_minutes)->toBe(60)
        ->and($bank->fresh()->consumed_minutes)->toBe(120)
        ->and($bank->fresh()->overage_minutes)->toBe(60)
        ->and($bank->fresh()->in_bank_minutes)->toBe(60);
});

it('editar una bloqueada no cambia el exceso de las demás bloqueadas', function () {
    [$bank, $task] = bankWithTask(1);
    $first = logEntry($task, 30, '2026-09-01', ['status' => TimeEntryStatus::Locked]);
    logEntry($task, 30, '2026-09-02');
    $other = logEntry($task, 60, '2026-09-03', ['status' => TimeEntryStatus::Locked, 'overage_minutes' => 60]);
    app(HourBankLedger::class)->recalculate($bank);

    $other->update(['minutes' => 30]);

    expect($first->fresh()->overage_minutes)->toBe(0)
        ->and($other->fresh()->overage_minutes)->toBe(30)
        ->and($bank->fresh()->overage_minutes)->toBe(30)
        ->and($bank->fresh()->in_bank_minutes)->toBe(60);
});

it('al mover una entrada a otra bolsa recalcula las dos', function () {
    [$bankA, $taskA] = bankWithTask(1);
    $bankB = HourBank::factory()->hours(1)->create(['project_id' => $bankA->project_id]);

    $entry = logEntry($taskA, 90, '2026-09-01');
    expect($bankA->fresh()->overage_minutes)->toBe(30);

    $entry->update(['hour_bank_id' => $bankB->id]);

    expect($bankA->fresh()->consumed_minutes)->toBe(0)
        ->and($bankA->fresh()->overage_minutes)->toBe(0)
        ->and($bankB->fresh()->consumed_minutes)->toBe(90)
        ->and($bankB->fresh()->overage_minutes)->toBe(30);
});

it('cambiar el total de la bolsa recalcula el exceso y el estado', function () {
    [$bank, $task] = bankWithTask(1);
    $entry = logEntry($task, 90, '2026-09-01');
    expect($bank->fresh()->status)->toBe(HourBankStatus::Exhausted);

    $bank->refresh()->update(['total_minutes' => 120]);

    expect($entry->fresh()->overage_minutes)->toBe(0)
        ->and($bank->fresh()->status)->toBe(HourBankStatus::Active)
        ->and($bank->fresh()->overage_minutes)->toBe(0);
});

it('saldo, estado y block usan lo que va dentro: una bloqueada en exceso no ocupa el total ampliado', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-25 10:00:00', 'Europe/Madrid'));
    $employee = User::factory()->employee()->create();
    [$bank, $task] = bankWithTask(1, ['overage_policy' => OveragePolicy::Block]);
    $task->project->addMember($employee);
    logEntry($task, 60, '2026-09-01', ['user_id' => $employee->id]);
    logEntry($task, 60, '2026-09-02', ['user_id' => $employee->id, 'status' => TimeEntryStatus::Locked, 'overage_minutes' => 60]);
    app(HourBankLedger::class)->recalculate($bank);

    $bank->refresh()->update(['total_minutes' => 120]);
    $bank->refresh();

    // El motor deja 1 h libre: la tarjeta, el estado y la regla block dicen lo mismo.
    expect($bank->only(['consumed_minutes', 'overage_minutes']))->toBe(['consumed_minutes' => 120, 'overage_minutes' => 60])
        ->and($bank->in_bank_minutes)->toBe(60)
        ->and($bank->remaining_minutes)->toBe(60)
        ->and(app(HourBankLedger::class)->available($bank))->toBe(60)
        ->and($bank->status)->toBe(HourBankStatus::Active);

    $entry = app(TimeEntryWriter::class)->create($employee, new TimeEntryData(
        userId: $employee->id,
        taskId: $task->id,
        date: CarbonImmutable::parse('2026-09-24'),
        minutes: 30,
        description: null,
    ))->entry;

    expect($entry->overage_minutes)->toBe(0)
        ->and($bank->fresh()->remaining_minutes)->toBe(30);

    // Lo que no cabe en el saldo real se sigue rechazando con ese saldo.
    expect(fn () => app(HourBankLedger::class)->assertFits($bank->fresh(), 45))
        ->toThrow(ValidationException::class, 'Saldo disponible: 0:30');
});

it('no cambia el estado de una bolsa cerrada o renovada al recalcular', function (HourBankStatus $status) {
    [$bank, $task] = bankWithTask(1);
    $bank->update(['status' => $status]);

    logEntry($task, 90, '2026-09-01');

    expect($bank->fresh()->status)->toBe($status)
        ->and($bank->fresh()->consumed_minutes)->toBe(90);
})->with([HourBankStatus::Closed, HourBankStatus::Renewed]);

it('la política inherit sigue el ajuste allow_hour_bank_overage', function () {
    $ledger = app(HourBankLedger::class);
    $bank = HourBank::factory()->create(['overage_policy' => OveragePolicy::Inherit]);

    expect($ledger->effectivePolicy($bank))->toBe(OveragePolicy::Allow);

    Setting::set('allow_hour_bank_overage', false);
    expect($ledger->effectivePolicy($bank))->toBe(OveragePolicy::Block);

    $bank->overage_policy = OveragePolicy::Allow;
    expect($ledger->effectivePolicy($bank))->toBe(OveragePolicy::Allow);
});

it('con block rechaza lo que no cabe e indica el saldo disponible, sin recortarlo', function () {
    [$bank, $task] = bankWithTask(2, ['overage_policy' => OveragePolicy::Block]);
    logEntry($task, 90, '2026-09-01');

    $ledger = app(HourBankLedger::class);
    $ledger->assertFits($bank->fresh(), 30);

    expect(fn () => $ledger->assertFits($bank->fresh(), 31))
        ->toThrow(ValidationException::class, 'Saldo disponible: 0:30');
});

it('con block no impide reducir ni mantener una entrada que ya tenía exceso', function () {
    [$bank, $task] = bankWithTask(1, ['overage_policy' => OveragePolicy::Allow]);
    $entry = logEntry($task, 90, '2026-09-01');
    $bank->update(['overage_policy' => OveragePolicy::Block]);

    $ledger = app(HourBankLedger::class);
    $ledger->assertFits($bank->fresh(), 90, $entry->fresh());
    $ledger->assertFits($bank->fresh(), 60, $entry->fresh());

    expect(fn () => $ledger->assertFits($bank->fresh(), 91, $entry->fresh()))->toThrow(ValidationException::class);
});

it('avisa de cada umbral una sola vez y, si se cruzan varios a la vez, solo del más alto', function () {
    [$bank, $task] = bankWithTask(10);

    logEntry($task, 5 * 60, '2026-09-01');
    Event::assertNotDispatched(HourBankThresholdReached::class);

    // Del 50 % al 95 %: cruza 75 y 90 → un solo aviso (90) y los dos registrados.
    logEntry($task, 270, '2026-09-02');
    Event::assertDispatchedTimes(HourBankThresholdReached::class, 1);
    Event::assertDispatched(HourBankThresholdReached::class, fn ($event) => $event->threshold === 90);
    expect(HourBankAlert::query()->where('hour_bank_id', $bank->id)->pluck('key')->sort()->values()->all())
        ->toBe(['threshold:75', 'threshold:90']);

    // Bajar y volver a subir no repite el aviso.
    $last = logEntry($task, 10, '2026-09-03');
    $last->delete();
    logEntry($task, 10, '2026-09-04');
    Event::assertDispatchedTimes(HourBankThresholdReached::class, 1);

    logEntry($task, 60, '2026-09-05');
    Event::assertDispatched(HourBankThresholdReached::class, fn ($event) => $event->threshold === 100);
    Event::assertDispatchedTimes(HourBankThresholdReached::class, 2);
});

it('usa los umbrales configurados', function () {
    Setting::set('hour_bank_alert_thresholds', [50]);
    [$bank, $task] = bankWithTask(10);

    logEntry($task, 5 * 60, '2026-09-01');

    Event::assertDispatched(HourBankThresholdReached::class, fn ($event) => $event->threshold === 50);
});

it('avisa del exceso como máximo una vez al día por bolsa', function () {
    [$bank, $task] = bankWithTask(1);

    $this->travelTo('2026-09-10 10:00:00');
    logEntry($task, 90, '2026-09-10');
    logEntry($task, 30, '2026-09-10');
    Event::assertDispatchedTimes(HourBankOverageRecorded::class, 1);

    $this->travelTo('2026-09-11 10:00:00');
    logEntry($task, 30, '2026-09-11');
    Event::assertDispatchedTimes(HourBankOverageRecorded::class, 2);
});

it('no avisa si el recálculo se pide sin notificaciones (seeders)', function () {
    [$bank, $task] = bankWithTask(1);

    TimeEntry::withoutEvents(fn () => logEntry($task, 90, '2026-09-01'));
    app(HourBankLedger::class)->recalculate($bank, notify: false);

    expect($bank->overage_minutes)->toBe(30);
    Event::assertNotDispatched(HourBankOverageRecorded::class);
    Event::assertNotDispatched(HourBankThresholdReached::class);
});

it('la suma dentro de la bolsa nunca supera el total', function () {
    [$bank, $task] = bankWithTask(3);
    $user = User::factory()->create();

    foreach ([50, 70, 25, 90, 15, 40] as $i => $minutes) {
        logEntry($task, $minutes, '2026-09-0'.($i + 1), ['user_id' => $user->id]);
    }

    $bank->refresh();
    $inBank = TimeEntry::query()->get()->sum(fn (TimeEntry $entry) => $entry->in_bank_minutes);

    expect($inBank)->toBe(180)
        ->and($bank->consumed_minutes)->toBe(290)
        ->and($bank->overage_minutes)->toBe(110);
});
