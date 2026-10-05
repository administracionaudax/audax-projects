<?php

use App\Domain\HourBanks\Events\HourBankOverageRecorded;
use App\Domain\HourBanks\Events\HourBankThresholdReached;
use App\Domain\Time\TimeEntryData;
use App\Domain\Time\TimeEntryWriter;
use App\Enums\TimesheetStatus;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\TimesheetPeriod;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

/*
| Entrada manual con hora de inicio y de fin (D-172): la duración sale de la franja, se guardan
| started_at y ended_at (UTC), no cruza la medianoche y los solapes con otras entradas de la misma
| persona avisan sin bloquear. Siempre por TimeEntryWriter, con todas sus reglas.
| «Hoy» es el viernes 25/09/2026 en Madrid (UTC+2).
*/

beforeEach(function () {
    Event::fake([HourBankThresholdReached::class, HourBankOverageRecorded::class]);
    $this->travelTo(CarbonImmutable::parse('2026-09-25 18:00:00', 'Europe/Madrid'));

    $this->employee = User::factory()->employee()->create();
    $this->project = Project::factory()->create();
    $this->project->addMember($this->employee);
    $this->task = Task::factory()->create(['project_id' => $this->project->id, 'title' => 'Maquetar la home']);

    $this->payload = fn (array $overrides = []): array => [
        'task_id' => $this->task->id,
        'date' => '2026-09-24',
        'minutes' => null,
        'start_time' => '09:00',
        'end_time' => '11:30',
        'description' => 'Cabecera',
        ...$overrides,
    ];
    $this->post = fn (array $overrides = []) => $this->actingAs($this->employee)->from('/horas')->post('/horas/entradas', ($this->payload)($overrides));
});

it('calcula la duración con la franja y guarda inicio y fin en UTC', function () {
    ($this->post)()
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Horas guardadas: 2:30 en «Maquetar la home».');

    $entry = TimeEntry::query()->sole();

    expect($entry->minutes)->toBe(150)
        ->and($entry->date->toDateString())->toBe('2026-09-24')
        ->and($entry->started_at?->toIso8601ZuluString())->toBe('2026-09-24T07:00:00Z')
        ->and($entry->ended_at?->toIso8601ZuluString())->toBe('2026-09-24T09:30:00Z');
});

it('con franja ignora la duración que llegue y sin franja la exige', function () {
    ($this->post)(['minutes' => 999])->assertSessionHasNoErrors();
    expect(TimeEntry::query()->sole()->minutes)->toBe(150);

    ($this->post)(['start_time' => null, 'end_time' => null, 'minutes' => null])
        ->assertSessionHasErrors('minutes');
});

it('rechaza un fin igual o anterior al inicio, y explica cómo registrar lo que cruza la medianoche', function () {
    ($this->post)(['start_time' => '10:00', 'end_time' => '10:00'])
        ->assertSessionHasErrors(['end_time' => 'La hora de fin tiene que ser posterior a la de inicio.']);

    ($this->post)(['start_time' => '22:00', 'end_time' => '02:00'])
        ->assertSessionHasErrors(['end_time' => __('time.errors.range_midnight')]);

    expect(TimeEntry::query()->count())->toBe(0);
});

it('acepta 00:00 como fin del día (24:00)', function () {
    ($this->post)(['start_time' => '22:00', 'end_time' => '00:00'])->assertSessionHasNoErrors();

    $entry = TimeEntry::query()->sole();

    expect($entry->minutes)->toBe(120)
        ->and($entry->date->toDateString())->toBe('2026-09-24')
        ->and($entry->ended_at?->toIso8601ZuluString())->toBe('2026-09-24T22:00:00Z');
});

it('pide las dos horas y en formato HH:MM', function () {
    ($this->post)(['end_time' => null])
        ->assertSessionHasErrors(['end_time' => 'Indica la hora de inicio y la de fin, o ninguna de las dos.']);

    ($this->post)(['start_time' => '9h', 'end_time' => '25:00'])
        ->assertSessionHasErrors(['start_time' => 'Escribe la hora como 09:30.', 'end_time' => 'Escribe la hora como 09:30.']);
});

it('cuenta el tiempo real en el cambio de hora (25/10/2026: 01:00–04:00 son 4 h)', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-26 10:00:00', 'Europe/Madrid'));

    ($this->post)(['date' => '2026-10-25', 'start_time' => '01:00', 'end_time' => '04:00'])->assertSessionHasNoErrors();

    expect(TimeEntry::query()->sole()->minutes)->toBe(240);
});

it('aplica las reglas de imputación: fecha futura, semana cerrada y más de 24 h en el día', function () {
    ($this->post)(['date' => '2026-09-26'])
        ->assertSessionHasErrors(['date' => 'No se pueden imputar horas en fechas futuras.']);

    TimeEntry::factory()->forTask($this->task)->on('2026-09-23')->minutes(23 * 60)->create(['user_id' => $this->employee->id]);
    ($this->post)(['date' => '2026-09-23'])
        ->assertSessionHasErrors(['minutes' => 'Con esta entrada, el 23/09/2026 suma más de 24 horas.']);

    TimesheetPeriod::query()->create(['user_id' => $this->employee->id, 'week_start' => '2026-09-14', 'status' => TimesheetStatus::Submitted]);
    ($this->post)(['date' => '2026-09-16'])->assertSessionHasErrors('date');

    expect(TimeEntry::query()->count())->toBe(1);
});

it('avisa (sin bloquear) si la franja se pisa con otra entrada de la misma persona', function () {
    ($this->post)()->assertSessionHasNoErrors();
    // Tocar el extremo no es solaparse.
    ($this->post)(['start_time' => '11:30', 'end_time' => '12:00'])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlashMissing('time_warnings');

    ($this->post)(['start_time' => '11:00', 'end_time' => '12:30'])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('time_warnings.0.code', 'overlap')
        ->assertInertiaFlash('time_warnings.0.message', 'Esta franja se solapa con 2 entradas tuyas: 24/09/2026 09:00–11:30, 24/09/2026 11:30–12:00.');

    expect(TimeEntry::query()->count())->toBe(3);
});

it('las franjas de otras personas no cuentan como solape', function () {
    $other = User::factory()->employee()->create();
    TimeEntry::factory()->forTask($this->task)->on('2026-09-24')->minutes(60)->create([
        'user_id' => $other->id,
        'started_at' => '2026-09-24 07:00:00',
        'ended_at' => '2026-09-24 08:00:00',
    ]);

    ($this->post)()->assertSessionHasNoErrors()->assertInertiaFlashMissing('time_warnings');
});

it('al editar se puede pasar a franja y de vuelta a duración (que la quita si cambian los minutos)', function () {
    $entry = TimeEntry::factory()->forTask($this->task)->on('2026-09-24')->minutes(60)->create(['user_id' => $this->employee->id]);

    $this->actingAs($this->employee)
        ->put("/horas/entradas/{$entry->id}", ($this->payload)(['start_time' => '15:00', 'end_time' => '16:15']))
        ->assertSessionHasNoErrors();

    $entry->refresh();
    expect($entry->minutes)->toBe(75)
        ->and($entry->started_at?->toIso8601ZuluString())->toBe('2026-09-24T13:00:00Z');

    // Sin franja y con los mismos minutos, la franja se conserva (solo cambia la descripción).
    $this->actingAs($this->employee)
        ->put("/horas/entradas/{$entry->id}", ($this->payload)(['start_time' => null, 'end_time' => null, 'minutes' => 75, 'description' => 'Otra']))
        ->assertSessionHasNoErrors();
    expect($entry->refresh()->started_at)->not->toBeNull();

    // Con otros minutos, ya no describiría la entrada: se quita.
    $this->actingAs($this->employee)
        ->put("/horas/entradas/{$entry->id}", ($this->payload)(['start_time' => null, 'end_time' => null, 'minutes' => 90]))
        ->assertSessionHasNoErrors();

    $entry->refresh();
    expect($entry->minutes)->toBe(90)
        ->and($entry->started_at)->toBeNull()
        ->and($entry->ended_at)->toBeNull();
});

it('al editar, el solape no cuenta con la propia entrada', function () {
    ($this->post)()->assertSessionHasNoErrors();
    $entry = TimeEntry::query()->sole();

    $this->actingAs($this->employee)
        ->put("/horas/entradas/{$entry->id}", ($this->payload)(['start_time' => '09:30', 'end_time' => '11:30']))
        ->assertSessionHasNoErrors()
        ->assertInertiaFlashMissing('time_warnings');
});

it('TimeEntryWriter rechaza una franja a medias o al revés (invariante)', function () {
    $writer = app(TimeEntryWriter::class);
    $data = fn (?string $start, ?string $end): TimeEntryData => new TimeEntryData(
        userId: $this->employee->id,
        taskId: $this->task->id,
        date: CarbonImmutable::parse('2026-09-24'),
        minutes: 60,
        startedAt: $start === null ? null : CarbonImmutable::parse($start),
        endedAt: $end === null ? null : CarbonImmutable::parse($end),
    );

    expect(fn () => $writer->create($this->employee, $data('2026-09-24 08:00:00', null)))
        ->toThrow(ValidationException::class, 'Indica la hora de inicio y la de fin, o ninguna de las dos.');
    expect(fn () => $writer->create($this->employee, $data('2026-09-24 09:00:00', '2026-09-24 08:00:00')))
        ->toThrow(ValidationException::class, 'La franja horaria no es válida: el fin es anterior al inicio.');
    expect(TimeEntry::query()->count())->toBe(0);
});

it('imputa por otra persona y el aviso de solape la nombra', function () {
    $manager = User::factory()->employee()->create(['name' => 'Gestora']);
    $this->project->update(['owner_user_id' => $manager->id]);
    $this->project->addMember($manager);
    TimeEntry::factory()->forTask($this->task)->on('2026-09-24')->minutes(60)->create([
        'user_id' => $this->employee->id,
        'started_at' => '2026-09-24 07:00:00',
        'ended_at' => '2026-09-24 08:00:00',
    ]);

    $this->actingAs($manager)
        ->post('/horas/entradas', ($this->payload)(['user_id' => $this->employee->id]))
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('time_warnings.0.message', "Esta franja se solapa con otra entrada de {$this->employee->name}: 24/09/2026 09:00–10:00.");
});
