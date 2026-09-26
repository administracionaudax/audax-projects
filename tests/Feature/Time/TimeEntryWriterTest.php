<?php

use App\Domain\HourBanks\Events\HourBankOverageRecorded;
use App\Domain\HourBanks\Events\HourBankThresholdReached;
use App\Domain\HourBanks\FirstHourBank;
use App\Domain\Time\TimeEntryData;
use App\Domain\Time\TimeEntryWarning;
use App\Domain\Time\TimeEntryWriter;
use App\Enums\BillingType;
use App\Enums\OveragePolicy;
use App\Enums\TimeEntryStatus;
use App\Enums\TimesheetStatus;
use App\Models\Department;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\Setting;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\TimesheetPeriod;
use App\Models\User;
use App\Models\WorkSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

/*
| Imputación de horas (SPEC §7 y §8) a través de TimeEntryWriter, la única vía de escritura.
| "Hoy" es el viernes 25/09/2026 (Europe/Madrid).
*/

beforeEach(function () {
    Event::fake([HourBankThresholdReached::class, HourBankOverageRecorded::class]);
    $this->travelTo(CarbonImmutable::parse('2026-09-25 10:00:00', 'Europe/Madrid'));
    $this->writer = app(TimeEntryWriter::class);
    $this->employee = User::factory()->employee()->create();
});

function entryData(User $user, Task $task, int $minutes = 60, string $date = '2026-09-24', ?string $description = null): TimeEntryData
{
    return new TimeEntryData(
        userId: $user->id,
        taskId: $task->id,
        date: CarbonImmutable::parse($date),
        minutes: $minutes,
        description: $description,
    );
}

function memberTask(User $user, array $projectState = [], ?HourBank $bank = null): Task
{
    if ($bank !== null) {
        $bank->project->addMember($user);

        return Task::factory()->inBank($bank)->create();
    }

    $project = Project::factory()->create($projectState);
    $project->addMember($user);

    return Task::factory()->create(['project_id' => $project->id]);
}

function validationErrors(callable $callback): array
{
    try {
        $callback();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    throw new RuntimeException('Se esperaba una ValidationException.');
}

it('crea la entrada en borrador copiando el proyecto y la bolsa de la tarea', function () {
    $bank = HourBank::factory()->create();
    $task = memberTask($this->employee, bank: $bank);

    $result = $this->writer->create($this->employee, entryData($this->employee, $task, 90, description: '  Maquetación  '));
    $entry = $result->entry;

    expect($entry->project_id)->toBe($bank->project_id)
        ->and($entry->hour_bank_id)->toBe($bank->id)
        ->and($entry->minutes)->toBe(90)
        ->and($entry->date->toDateString())->toBe('2026-09-24')
        ->and($entry->status)->toBe(TimeEntryStatus::Draft)
        ->and($entry->description)->toBe('Maquetación')
        ->and($entry->created_by)->toBe($this->employee->id)
        ->and($entry->wasLoggedOnBehalf())->toBeFalse()
        ->and($bank->fresh()->consumed_minutes)->toBe(90)
        ->and($result->warnings)->toBe([]);
});

it('registra en la auditoría la creación de la entrada', function () {
    $task = memberTask($this->employee);
    $this->actingAs($this->employee);

    $entry = $this->writer->create($this->employee, entryData($this->employee, $task))->entry;

    $activity = $entry->activitiesAsSubject()->latest('id')->first();
    expect($activity)->not->toBeNull()
        ->and($activity->event)->toBe('created')
        ->and($activity->causer_id)->toBe($this->employee->id);
});

it('no imputa en proyectos archivados', function () {
    $task = memberTask($this->employee, ['status' => 'archived']);

    expect(validationErrors(fn () => $this->writer->create($this->employee, entryData($this->employee, $task))))
        ->toHaveKey('task_id');
});

it('no imputa en bolsas cerradas o renovadas', function (string $state) {
    $bank = HourBank::factory()->{$state}()->create();
    $task = memberTask($this->employee, bank: $bank);

    $errors = validationErrors(fn () => $this->writer->create($this->employee, entryData($this->employee, $task)));

    expect($errors['task_id'][0])->toContain($bank->name);
})->with(['closed', 'renewed']);

it('no imputa en una bolsa restringida a otro departamento', function () {
    $design = Department::factory()->create(['name' => 'Diseño']);
    $development = Department::factory()->create(['name' => 'Desarrollo']);
    $this->employee->update(['department_id' => $development->id]);
    $bank = HourBank::factory()->forDepartment($design)->create();
    $task = memberTask($this->employee, bank: $bank);

    $errors = validationErrors(fn () => $this->writer->create($this->employee, entryData($this->employee, $task)));

    expect($errors['task_id'][0])->toContain('Diseño');

    $this->employee->update(['department_id' => $design->id]);
    expect($this->writer->create($this->employee->fresh(), entryData($this->employee, $task))->entry->exists)->toBeTrue();
});

it('solo imputan los miembros del proyecto, salvo en proyectos internos (D-033)', function () {
    $project = Project::factory()->create();
    $task = Task::factory()->create(['project_id' => $project->id]);

    expect(validationErrors(fn () => $this->writer->create($this->employee, entryData($this->employee, $task))))
        ->toHaveKey('task_id');

    $internal = Project::factory()->internal()->create();
    $meeting = Task::factory()->create(['project_id' => $internal->id]);

    $entry = $this->writer->create($this->employee, entryData($this->employee, $meeting))->entry;

    expect($entry->exists)->toBeTrue()
        ->and($entry->is_billable)->toBeFalse();
});

it('no imputa en hitos ni en tareas sin bolsa de un proyecto de bolsas', function () {
    $milestone = memberTask($this->employee);
    $milestone->update(['is_milestone' => true]);

    expect(validationErrors(fn () => $this->writer->create($this->employee, entryData($this->employee, $milestone))))
        ->toHaveKey('task_id');

    $noBank = memberTask($this->employee, ['billing_type' => 'hour_bank']);

    expect(validationErrors(fn () => $this->writer->create($this->employee, entryData($this->employee, $noBank)))['task_id'][0])
        ->toContain('no tiene bolsa');
});

it('no imputa en fechas futuras salvo que el ajuste lo permita', function () {
    $task = memberTask($this->employee);

    expect(validationErrors(fn () => $this->writer->create($this->employee, entryData($this->employee, $task, date: '2026-09-26'))))
        ->toHaveKey('date');

    // Hoy (en Madrid) sí.
    expect($this->writer->create($this->employee, entryData($this->employee, $task, date: '2026-09-25'))->entry->exists)->toBeTrue();

    Setting::set('allow_future_time_entries', true);
    expect($this->writer->create($this->employee, entryData($this->employee, $task, date: '2026-09-28'))->entry->exists)->toBeTrue();
});

it('no permite más de 24 horas en un día', function () {
    $task = memberTask($this->employee);
    $this->writer->create($this->employee, entryData($this->employee, $task, 20 * 60));

    expect(validationErrors(fn () => $this->writer->create($this->employee, entryData($this->employee, $task, 4 * 60 + 1))))
        ->toHaveKey('minutes');

    expect($this->writer->create($this->employee, entryData($this->employee, $task, 4 * 60))->entry->exists)->toBeTrue();
});

it('rechaza duraciones fuera de rango', function (int $minutes) {
    $task = memberTask($this->employee);

    expect(validationErrors(fn () => $this->writer->create($this->employee, entryData($this->employee, $task, $minutes))))
        ->toHaveKey('minutes');
})->with([0, -5, 24 * 60 + 1]);

it('no imputa en semanas enviadas, aprobadas o bloqueadas; sí en abiertas o devueltas', function (TimesheetStatus $status, bool $allowed) {
    $task = memberTask($this->employee);
    TimesheetPeriod::factory()->for($this->employee)->week('2026-09-24')->status($status)->create();

    $attempt = fn () => $this->writer->create($this->employee, entryData($this->employee, $task));

    if ($allowed) {
        expect($attempt()->entry->exists)->toBeTrue();
    } else {
        expect(validationErrors($attempt)['date'][0])->toContain('21/09/2026');
    }
})->with([
    [TimesheetStatus::Open, true],
    [TimesheetStatus::Returned, true],
    [TimesheetStatus::Submitted, false],
    [TimesheetStatus::Approved, false],
    [TimesheetStatus::Locked, false],
]);

it('con política block rechaza la imputación que supera el saldo, indicando el disponible', function () {
    $bank = HourBank::factory()->hours(2)->blockOverage()->create();
    $task = memberTask($this->employee, bank: $bank);
    $this->writer->create($this->employee, entryData($this->employee, $task, 90));

    $errors = validationErrors(fn () => $this->writer->create($this->employee, entryData($this->employee, $task, 45)));

    expect($errors['minutes'][0])->toContain('0:30')
        ->and(TimeEntry::query()->count())->toBe(1)
        ->and($bank->fresh()->consumed_minutes)->toBe(90);
});

it('con política inherit y el ajuste desactivado, bloquea el exceso', function () {
    Setting::set('allow_hour_bank_overage', false);
    $bank = HourBank::factory()->hours(1)->create(['overage_policy' => OveragePolicy::Inherit]);
    $task = memberTask($this->employee, bank: $bank);

    expect(validationErrors(fn () => $this->writer->create($this->employee, entryData($this->employee, $task, 61))))
        ->toHaveKey('minutes');
});

it('con política allow registra el exceso y avisa sin bloquear (aceptación de la Fase 1)', function () {
    $bank = HourBank::factory()->hours(1)->allowOverage()->create();
    $task = memberTask($this->employee, bank: $bank);

    $partial = $this->writer->create($this->employee, entryData($this->employee, $task, 90));
    expect($partial->entry->overage_minutes)->toBe(30)
        ->and(collect($partial->warnings)->pluck('code')->all())->toContain(TimeEntryWarning::OVERAGE)
        ->and($partial->warningsArray()[0]['message'])->toContain('0:30');

    $all = $this->writer->create($this->employee, entryData($this->employee, $task, 20, '2026-09-25'));
    expect($all->entry->overage_minutes)->toBe(20)
        ->and($all->warnings[0]->message)->toContain('agotada');
});

it('imputar en nombre de otra persona: gestor en su proyecto, responsable de su equipo y admin', function () {
    $department = Department::factory()->create();
    $this->employee->update(['department_id' => $department->id]);
    $task = memberTask($this->employee);
    $project = $task->project;

    $colleague = User::factory()->employee()->create();
    expect(validationErrors(fn () => $this->writer->create($colleague, entryData($this->employee, $task))))
        ->toHaveKey('user_id');

    $manager = User::factory()->employee()->create();
    $project->addMember($manager, isManager: true);
    $entry = $this->writer->create($manager, entryData($this->employee, $task))->entry;
    expect($entry->user_id)->toBe($this->employee->id)
        ->and($entry->created_by)->toBe($manager->id)
        ->and($entry->wasLoggedOnBehalf())->toBeTrue();

    $head = User::factory()->departmentManager()->create();
    $department->managers()->attach($head);
    expect($this->writer->create($head, entryData($this->employee, $task, 30))->entry->exists)->toBeTrue();

    $admin = User::factory()->admin()->create();
    expect($this->writer->create($admin, entryData($this->employee, $task, 30))->entry->exists)->toBeTrue();
});

it('la persona destino debe cumplir las reglas aunque imputa otro', function () {
    $admin = User::factory()->admin()->create();
    $project = Project::factory()->create();
    $task = Task::factory()->create(['project_id' => $project->id]);

    // El empleado no es miembro del proyecto.
    expect(validationErrors(fn () => $this->writer->create($admin, entryData($this->employee, $task))))
        ->toHaveKey('task_id');
});

it('al editar conserva el proyecto y la bolsa originales aunque la tarea se haya movido (§6)', function () {
    $bankA = HourBank::factory()->create();
    $bankB = HourBank::factory()->create(['project_id' => $bankA->project_id]);
    $task = memberTask($this->employee, bank: $bankA);
    $entry = $this->writer->create($this->employee, entryData($this->employee, $task, 60))->entry;

    $task->update(['hour_bank_id' => $bankB->id]);
    $updated = $this->writer->update($this->employee, $entry, entryData($this->employee, $task, 120))->entry;

    expect($updated->hour_bank_id)->toBe($bankA->id)
        ->and($bankA->fresh()->consumed_minutes)->toBe(120)
        ->and($bankB->fresh()->consumed_minutes)->toBe(0);
});

it('al cambiar la tarea de la entrada copia el proyecto y la bolsa de la nueva', function () {
    $bankA = HourBank::factory()->create();
    $bankB = HourBank::factory()->create();
    $taskA = memberTask($this->employee, bank: $bankA);
    $taskB = memberTask($this->employee, bank: $bankB);
    $entry = $this->writer->create($this->employee, entryData($this->employee, $taskA, 60))->entry;

    $updated = $this->writer->update($this->employee, $entry, entryData($this->employee, $taskB, 60))->entry;

    expect($updated->project_id)->toBe($bankB->project_id)
        ->and($updated->hour_bank_id)->toBe($bankB->id)
        ->and($bankA->fresh()->consumed_minutes)->toBe(0)
        ->and($bankB->fresh()->consumed_minutes)->toBe(60);
});

it('no permite cambiar la persona de una entrada', function () {
    $task = memberTask($this->employee);
    $entry = $this->writer->create($this->employee, entryData($this->employee, $task))->entry;
    $admin = User::factory()->admin()->create();
    $other = User::factory()->employee()->create();

    expect(validationErrors(fn () => $this->writer->update($admin, $entry, entryData($other, $task))))
        ->toHaveKey('user_id');
});

it('una entrada bloqueada solo la edita un admin, sin reabrir la semana', function () {
    $task = memberTask($this->employee);
    $entry = TimeEntry::factory()->forTask($task)->on('2026-09-21')->status(TimeEntryStatus::Locked)->create(['user_id' => $this->employee->id]);
    TimesheetPeriod::factory()->for($this->employee)->week('2026-09-21')->status(TimesheetStatus::Locked)->create();

    expect(fn () => $this->writer->update($this->employee, $entry, entryData($this->employee, $task, 30, '2026-09-21')))
        ->toThrow(AuthorizationException::class);

    $admin = User::factory()->admin()->create();
    $updated = $this->writer->update($admin, $entry, entryData($this->employee, $task, 30, '2026-09-21'))->entry;

    expect($updated->minutes)->toBe(30)
        ->and($updated->status)->toBe(TimeEntryStatus::Locked);
});

it('un admin que reduce o amplía una entrada bloqueada recalcula su exceso (nunca mayor que sus minutos)', function () {
    $admin = User::factory()->admin()->create();
    $bank = HourBank::factory()->hours(1)->allowOverage()->create();
    $task = memberTask($this->employee, bank: $bank);
    TimeEntry::factory()->forTask($task)->minutes(60)->on('2026-09-01')->create(['user_id' => $this->employee->id]);
    $locked = TimeEntry::factory()->forTask($task)->minutes(60)->on('2026-09-02')->create(['user_id' => $this->employee->id]);
    expect($locked->fresh()->overage_minutes)->toBe(60);
    TimeEntry::query()->whereKey($locked->id)->update(['status' => TimeEntryStatus::Locked->value, 'locked_at' => now()]);

    $reduced = $this->writer->update($admin, $locked->fresh(), entryData($this->employee, $task, 15, '2026-09-02'))->entry;

    expect($reduced->overage_minutes)->toBe(15)
        ->and($reduced->in_bank_minutes)->toBe(0)
        ->and($bank->fresh()->only(['consumed_minutes', 'overage_minutes']))->toBe(['consumed_minutes' => 75, 'overage_minutes' => 15]);

    $extended = $this->writer->update($admin, $reduced, entryData($this->employee, $task, 120, '2026-09-02'))->entry;

    expect($extended->overage_minutes)->toBe(120)
        ->and($extended->status)->toBe(TimeEntryStatus::Locked)
        ->and($bank->fresh()->only(['consumed_minutes', 'overage_minutes']))->toBe(['consumed_minutes' => 180, 'overage_minutes' => 120])
        ->and($bank->fresh()->in_bank_minutes)->toBeLessThanOrEqual($bank->fresh()->total_minutes);
});

it('al pasar el proyecto a bolsas, sus entradas anteriores se siguen corrigiendo sin bolsa (FirstHourBank no mueve horas)', function () {
    $task = memberTask($this->employee, ['billing_type' => BillingType::TimeAndMaterials]);
    $project = $task->project;
    $entry = $this->writer->create($this->employee, entryData($this->employee, $task, 90))->entry;

    $project->update(['billing_type' => BillingType::HourBank]);
    $bank = app(FirstHourBank::class)->create($project->fresh(), [
        'name' => 'Bolsa inicial',
        'total_minutes' => 600,
        'start_date' => '2026-09-01',
    ])['bank'];
    expect($task->fresh()->hour_bank_id)->toBe($bank->id);

    // Corregir la descripción o los minutos: la entrada conserva su bolsa (ninguna).
    $updated = $this->writer->update($this->employee, $entry, entryData($this->employee, $task, 60, description: 'Corregida'))->entry;

    expect($updated->description)->toBe('Corregida')
        ->and($updated->minutes)->toBe(60)
        ->and($updated->hour_bank_id)->toBeNull()
        ->and($bank->fresh()->consumed_minutes)->toBe(0);

    // Una entrada nueva en la misma tarea sí va a la bolsa.
    $new = $this->writer->create($this->employee, entryData($this->employee, $task, 30))->entry;
    expect($new->hour_bank_id)->toBe($bank->id);

    // Pasarla a otra tarea sin bolsa del proyecto sigue sin poder hacerse.
    $unbanked = Task::factory()->create(['project_id' => $project->id]);
    expect(validationErrors(fn () => $this->writer->update($this->employee, $updated, entryData($this->employee, $unbanked, 60))))
        ->toBe(['task_id' => ['La tarea no tiene bolsa. Asígnale una antes de imputar.']]);
});

it('no mueve una entrada fuera de una semana cerrada', function () {
    $task = memberTask($this->employee);
    $entry = $this->writer->create($this->employee, entryData($this->employee, $task, 60, '2026-09-14'))->entry;
    TimesheetPeriod::factory()->for($this->employee)->week('2026-09-14')->status(TimesheetStatus::Submitted)->create();

    expect(validationErrors(fn () => $this->writer->update($this->employee, $entry, entryData($this->employee, $task, 60, '2026-09-24'))))
        ->toHaveKey('date');
});

it('no borra entradas de semanas enviadas; sí de abiertas', function () {
    $task = memberTask($this->employee);
    $open = $this->writer->create($this->employee, entryData($this->employee, $task, 60, '2026-09-24'))->entry;
    $closed = $this->writer->create($this->employee, entryData($this->employee, $task, 60, '2026-09-14'))->entry;
    TimesheetPeriod::factory()->for($this->employee)->week('2026-09-14')->status(TimesheetStatus::Submitted)->create();

    $this->writer->delete($this->employee, $open);
    expect(TimeEntry::query()->whereKey($open->id)->exists())->toBeFalse();

    expect(validationErrors(fn () => $this->writer->delete($this->employee, $closed)))->toHaveKey('date');
});

it('otra persona sin permiso no puede editar ni borrar la entrada', function () {
    $task = memberTask($this->employee);
    $entry = $this->writer->create($this->employee, entryData($this->employee, $task))->entry;
    $colleague = User::factory()->employee()->create();

    expect(fn () => $this->writer->delete($colleague, $entry))->toThrow(AuthorizationException::class);
});

it('avisa si la tarea está completada o si el día supera la jornada en más de un 25 %', function () {
    WorkSchedule::factory()->for($this->employee)->create(['thu_minutes' => 240]);
    $task = memberTask($this->employee);
    $task->update(['status_id' => Task::factory()->completed()->make()->status_id]);

    $result = $this->writer->create($this->employee, entryData($this->employee, $task->fresh(), 301, '2026-09-24'));

    expect(collect($result->warnings)->pluck('code')->all())
        ->toBe([TimeEntryWarning::TASK_COMPLETED, TimeEntryWarning::OVER_CAPACITY]);
});

it('exige descripción si el ajuste lo pide', function () {
    Setting::set('time_entry_description_required', true);
    $task = memberTask($this->employee);

    expect(validationErrors(fn () => $this->writer->create($this->employee, entryData($this->employee, $task, description: '   '))))
        ->toHaveKey('description');

    expect($this->writer->create($this->employee, entryData($this->employee, $task, description: 'Reunión'))->entry->exists)->toBeTrue();
});

it('no imputa a personas desactivadas', function () {
    $task = memberTask($this->employee);
    $admin = User::factory()->admin()->create();
    $this->employee->update(['is_active' => false]);

    expect(validationErrors(fn () => $this->writer->create($admin, entryData($this->employee->fresh(), $task))))
        ->toHaveKey('user_id');
});
