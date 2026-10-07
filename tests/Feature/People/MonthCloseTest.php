<?php

use App\Domain\People\ClockCorrectionService;
use App\Domain\People\MonthCloser;
use App\Domain\People\RegisterImmutable;
use App\Domain\People\RegisterIntegrity;
use App\Domain\People\WorkdayCalculator;
use App\Enums\MonthCloseStatus;
use App\Enums\Role;
use App\Models\MonthClose;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\People\MonthCloseDisagreed;
use App\Notifications\People\MonthCloseReady;
use App\Notifications\People\MonthCloseReminder;
use App\Notifications\People\MonthCloseReopened;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/*
| Cierre mensual del registro (PLAN-FASE-11 §7.5; D-347): congela lo que da WorkdayCalculator, guarda
| el PDF con su SHA-256, lo confirma (o no) solo la persona, la confirmación bloquea el mes y solo
| su responsable o RR. HH. lo desconfirma con motivo. Nada se borra.
*/

beforeEach(function () {
    enablePeople();
    Setting::set('people_register_starts_on', '2026-09-01');
    Notification::fake();
    Storage::fake('local');

    $this->employee = userWithRole('employee');
    ['manager' => $this->manager] = peopleTeam($this->employee);
    $this->hr = tap(userWithRole('employee'), fn ($user) => $user->givePermissionTo('manage-people'));
    $this->closer = app(MonthCloser::class);

    workday($this->employee, '2026-09-01', '09:00', '18:00', '14:00', '15:00');
    workday($this->employee, '2026-09-02', '09:00', '19:30', '14:00', '15:00');
    $this->travelTo(madridAt('2026-10-01 07:00'));
    $this->month = CarbonImmutable::parse('2026-09-01');
});

it('congela los totales y el diario de WorkdayCalculator, el punto de la cadena y el PDF con su huella', function () {
    $close = $this->closer->generate($this->employee, $this->month);
    $days = app(WorkdayCalculator::class)->forUser($this->employee, '2026-09-01', '2026-09-30');
    $totals = WorkdayCalculator::totals($days);

    expect($close->status)->toBe(MonthCloseStatus::Pending)
        ->and($close->version)->toBe(1)
        ->and($close->worked_minutes)->toBe($totals['worked_minutes'])
        ->and($close->expected_minutes)->toBe($totals['expected_minutes'])
        ->and($close->worked_minutes)->toBe(480 + 570)
        ->and($close->totals['excess_minutes'])->toBe(90)
        ->and($close->totals['unclassified_minutes'])->toBe(90)
        ->and($close->days)->toHaveCount(30)
        ->and($close->register_seq)->toBe(8)
        ->and($close->pdf_path)->toBe('people/cierres/'.$this->employee->id.'/2026-09-v1.html');

    Storage::disk('local')->assertExists($close->pdf_path);
    expect(hash('sha256', (string) Storage::disk('local')->get($close->pdf_path)))->toBe($close->pdf_sha256)
        ->and((string) Storage::disk('local')->get($close->pdf_path))->toContain($close->content_hash)
        ->and(app(RegisterIntegrity::class)->verify()['ok'])->toBeTrue();

    Notification::assertSentTo($this->employee, MonthCloseReady::class);
});

it('no cierra un mes que no ha terminado', function () {
    $this->closer->generate($this->employee, CarbonImmutable::parse('2026-10-01'));
})->throws(ValidationException::class);

it('solo la persona confirma o dice que no está de acuerdo; el desacuerdo pide motivo y avisa a la empresa', function () {
    $close = $this->closer->generate($this->employee, $this->month);

    expect(fn () => $this->closer->confirm($this->manager, $close))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->closer->confirm($this->hr, $close))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->closer->disagree($this->employee, $close, 'no'))->toThrow(ValidationException::class);

    $disagreed = $this->closer->disagree($this->employee, $close, 'El día 2 salí a las 19:30 por la entrega, eran horas extra.');
    expect($disagreed->status)->toBe(MonthCloseStatus::Disagreed)->and($disagreed->disagreed_at)->not->toBeNull();
    Notification::assertSentTo([$this->manager, $this->hr], MonthCloseDisagreed::class);

    // Después de hablarlo, la persona lo puede confirmar.
    $confirmed = $this->closer->confirm($this->employee, $disagreed);
    expect($confirmed->status)->toBe(MonthCloseStatus::Confirmed)->and($confirmed->confirmed_at)->not->toBeNull();
});

it('un mes confirmado bloquea las correcciones hasta que su responsable lo desconfirma con motivo', function () {
    $close = $this->closer->confirm($this->employee, $this->closer->generate($this->employee, $this->month));
    $service = app(ClockCorrectionService::class);
    $rows = [['kind' => 'clock_in', 'time' => '08:30'], ['kind' => 'clock_out', 'time' => '18:00']];

    expect(fn () => $service->propose($this->employee, $this->employee, '2026-09-01', $rows, 'Entré antes, a las 8:30.'))
        ->toThrow(ValidationException::class);

    expect(fn () => $this->closer->reopen($this->employee, $close, 'Lo quiero corregir yo'))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->closer->reopen($this->manager, $close, 'no'))->toThrow(ValidationException::class);

    $reopened = $this->closer->reopen($this->manager, $close, 'Elena entró a las 8:30 el día 1; hay que corregirlo.');
    expect($reopened->status)->toBe(MonthCloseStatus::Reopened)
        ->and($reopened->reopened_by)->toBe($this->manager->id)
        ->and($reopened->reopen_reason)->toContain('8:30');
    Notification::assertSentTo($this->employee, MonthCloseReopened::class);

    $current = $service->dayEvents($this->employee->id, '2026-09-01');
    $proposal = array_map(fn ($event): array => ['id' => $event->id, 'kind' => $event->kind->value, 'time' => $event->occurred_at->setTimezone('Europe/Madrid')->format('H:i')], $current);
    $proposal[0] = ['kind' => 'clock_in', 'time' => '08:30'];
    $correction = $service->propose($this->employee, $this->employee, '2026-09-01', $proposal, 'Entré antes, a las 8:30.');
    $service->accept($this->manager, $correction);

    // Se vuelve a cerrar: versión 2, con el registro corregido.
    $again = $this->closer->generate($this->employee, $this->month, $this->manager);
    expect($again->version)->toBe(2)
        ->and($again->worked_minutes)->toBe(480 + 30 + 570)
        ->and(MonthClose::query()->where('user_id', $this->employee->id)->count())->toBe(2);
});

it('si cambia el registro de un mes aún sin confirmar, el cierre se regenera solo', function () {
    $first = $this->closer->generate($this->employee, $this->month);
    $service = app(ClockCorrectionService::class);
    $current = $service->dayEvents($this->employee->id, '2026-09-01');
    $proposal = array_map(fn ($event): array => ['id' => $event->id, 'kind' => $event->kind->value, 'time' => $event->occurred_at->setTimezone('Europe/Madrid')->format('H:i')], $current);
    $proposal[0] = ['kind' => 'clock_in', 'time' => '08:00'];
    $service->accept($this->manager, $service->propose($this->employee, $this->employee, '2026-09-01', $proposal, 'Entré a las 8:00.'));

    expect($first->fresh()->status)->toBe(MonthCloseStatus::Superseded);
    $current = MonthClose::query()->current()->where('user_id', $this->employee->id)->sole();
    expect($current->version)->toBe(2)->and($current->worked_minutes)->toBe(540 + 570);
    Notification::assertSentTo($this->employee, MonthCloseReady::class, fn (MonthCloseReady $notification) => $notification->changed);
});

it('nada se borra y lo congelado no cambia: el modelo y la base de datos lo impiden', function () {
    $close = $this->closer->generate($this->employee, $this->month);

    $close->worked_minutes = 1;
    expect(fn () => $close->save())->toThrow(RegisterImmutable::class)
        ->and(fn () => MonthClose::query()->findOrFail($close->id)->delete())->toThrow(RegisterImmutable::class)
        ->and(inSavepoint(fn () => DB::table('month_closes')->where('id', $close->id)->update(['worked_minutes' => 1])))->toThrow(QueryException::class)
        ->and(inSavepoint(fn () => DB::table('month_closes')->where('id', $close->id)->delete()))->toThrow(QueryException::class);

    $confirmed = $this->closer->confirm($this->employee, $close);
    expect(inSavepoint(fn () => DB::table('month_closes')->where('id', $confirmed->id)->update(['confirmed_at' => now()->addDay()])))->toThrow(QueryException::class);
});

it('la comprobación detecta un cierre o un PDF tocados', function () {
    $close = $this->closer->generate($this->employee, $this->month);

    Storage::disk('local')->put($close->pdf_path, 'otro contenido');
    expect(app(RegisterIntegrity::class)->verify()['problems'])->toContain("cierre de 2026-09 (persona {$this->employee->id}, versión 1): su PDF no coincide con su huella.");

    // Quien quite el trigger para cambiar los totales tampoco pasa la comprobación.
    tamperRegisterTable('month_closes', 'month_closes_frozen');
    DB::table('month_closes')->where('id', $close->id)->update(['worked_minutes' => 99999]);
    expect(app(RegisterIntegrity::class)->verify()['problems'])->toContain("cierre de 2026-09 (persona {$this->employee->id}, versión 1): lo congelado no coincide con su sello.");
});

it('el día 1 genera los cierres que faltan y recuerda a los 3 y a los 7 días; con el módulo apagado, nada', function () {
    $other = userWithRole('employee');
    $collaborator = User::factory()->withRole(Role::Collaborator)->create();

    Setting::set('modules', [...(array) Setting::get('modules', []), 'people' => false]);
    expect($this->closer->runDue())->toBe(['generated' => 0, 'reminded' => 0]);

    enablePeople();
    $result = $this->closer->runDue();
    expect($result['generated'])->toBeGreaterThanOrEqual(2)
        ->and(MonthClose::query()->where('user_id', $this->employee->id)->exists())->toBeTrue()
        ->and(MonthClose::query()->where('user_id', $other->id)->exists())->toBeTrue()
        ->and(MonthClose::query()->where('user_id', $collaborator->id)->exists())->toBeFalse();

    // Solo genera los que faltan.
    expect($this->closer->runDue()['generated'])->toBe(0);

    $this->travelTo(madridAt('2026-10-04 07:00'));
    expect($this->closer->remind())->toBeGreaterThanOrEqual(2);
    Notification::assertSentTo($this->employee, MonthCloseReminder::class);

    $this->travelTo(madridAt('2026-10-05 07:00'));
    expect($this->closer->remind())->toBe(0);

    $this->travelTo(madridAt('2026-10-08 07:00'));
    expect($this->closer->remind())->toBeGreaterThanOrEqual(2);

    $this->travelTo(madridAt('2026-10-20 07:00'));
    expect($this->closer->remind())->toBe(0);
});

it('un mes desconfirmado no se vuelve a cerrar solo', function () {
    $close = $this->closer->confirm($this->employee, $this->closer->generate($this->employee, $this->month));
    $this->closer->reopen($this->hr, $close, 'Revisamos con la asesoría el día 2.');

    expect(collect($this->closer->dueSubjects($this->month))->pluck('id')->all())->not->toContain($this->employee->id);
});
