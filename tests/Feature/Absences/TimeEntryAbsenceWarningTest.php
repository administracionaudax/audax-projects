<?php

use App\Domain\Absences\AbsenceData;
use App\Domain\Absences\AbsenceService;
use App\Domain\Time\TimeEntryData;
use App\Domain\Time\TimeEntryWarning;
use App\Domain\Time\TimeEntryWriter;
use App\Enums\AbsenceStatus;
use App\Enums\AbsenceType;
use App\Models\Absence;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

/*
| Aviso al imputar en un día con una ausencia aprobada (SPEC §7, D-049): no bloquea. Nuevo código
| TimeEntryWarning::ABSENCE en TimeEntryRules. "Hoy" es el viernes 25/09/2026.
*/

beforeEach(function () {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-09-25 10:00:00', 'Europe/Madrid'));
    $this->employee = User::factory()->employee()->create();
    $project = Project::factory()->create();
    $project->addMember($this->employee);
    $this->task = Task::factory()->create(['project_id' => $project->id]);

    $this->log = fn (string $date, int $minutes = 60) => app(TimeEntryWriter::class)->create($this->employee, new TimeEntryData(
        userId: $this->employee->id,
        taskId: $this->task->id,
        date: CarbonImmutable::parse($date),
        minutes: $minutes,
        description: null,
    ));
});

test('avisa, sin bloquear, si ese día hay una ausencia aprobada de día completo', function () {
    Absence::factory()->for($this->employee)->approved()->between('2026-09-21', '2026-09-23')->create(['type' => AbsenceType::Vacation]);

    $result = ($this->log)('2026-09-22');

    expect($result->entry->exists)->toBeTrue()
        ->and(collect($result->warnings)->pluck('code')->all())->toBe([TimeEntryWarning::ABSENCE, TimeEntryWarning::OVER_CAPACITY])
        ->and($result->warnings[0]->message)->toBe('Ese día hay una ausencia aprobada (Vacaciones). Revisa que la fecha sea correcta.');
});

test('una ausencia parcial avisa con sus horas y deja la jornada restante', function () {
    Absence::factory()->for($this->employee)->approved()->between('2026-09-24', '2026-09-24')->create(['type' => AbsenceType::Leave, 'partial_minutes' => 120]);

    $result = ($this->log)('2026-09-24', 360);

    expect(collect($result->warnings)->pluck('code')->all())->toBe([TimeEntryWarning::ABSENCE])
        ->and($result->warningsArray()[0]['message'])->toBe('Ese día hay una ausencia aprobada de parte del día (Permiso, 2:00).');
});

test('las ausencias solicitadas, rechazadas o canceladas no avisan', function (AbsenceStatus $status) {
    Absence::factory()->for($this->employee)->between('2026-09-21', '2026-09-23')->create(['status' => $status]);

    expect(($this->log)('2026-09-22')->warnings)->toBe([]);
})->with([AbsenceStatus::Requested, AbsenceStatus::Rejected, AbsenceStatus::Cancelled]);

test('un día sin ausencia no avisa', function () {
    Absence::factory()->for($this->employee)->approved()->between('2026-09-21', '2026-09-21')->create();

    expect(($this->log)('2026-09-22')->warnings)->toBe([]);
});

test('el aviso llega en el flash time_warnings de la imputación', function () {
    $absence = app(AbsenceService::class)->request($this->employee, new AbsenceData(AbsenceType::Training, '2026-09-23', '2026-09-23', 240));
    expect($absence->status)->toBe(AbsenceStatus::Requested);
    Absence::query()->whereKey($absence->id)->update(['status' => AbsenceStatus::Approved->value]);

    $this->actingAs($this->employee)
        ->post('/horas/entradas', ['task_id' => $this->task->id, 'date' => '2026-09-23', 'minutes' => '2:00'])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('time_warnings.0.code', 'absence')
        ->assertInertiaFlash('time_warnings.0.message', 'Ese día hay una ausencia aprobada de parte del día (Formación externa, 4:00).');
});
