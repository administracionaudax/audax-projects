<?php

use App\Domain\Absences\AbsenceData;
use App\Domain\Absences\AbsenceService;
use App\Domain\Time\TimeEntryData;
use App\Domain\Time\TimeEntryWarning;
use App\Domain\Time\TimeEntryWriter;
use App\Enums\AbsenceStatus;
use App\Enums\AbsenceType;
use App\Models\Absence;
use App\Models\Department;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

/*
| Aviso al imputar en un día con una ausencia aprobada (SPEC §7, D-049): no bloquea. Nuevo código
| TimeEntryWarning::ABSENCE en TimeEntryRules. "Hoy" es el viernes 25/09/2026.
| Al imputar por otra persona (D-036), el tipo de ausencia (una baja es un dato de salud) solo lo
| ven la propia persona, un admin o quien la supervisa; a los demás, que ese día no está disponible
| (D-082). Los avisos nombran a la persona por la que se imputa.
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

describe('al imputar por otra persona (D-082)', function () {
    beforeEach(function () {
        $design = Department::factory()->create(['name' => 'Diseño']);
        $development = Department::factory()->create(['name' => 'Desarrollo']);

        $this->person = User::factory()->employee()->inDepartment($design)->create(['name' => 'Pedro Pérez']);
        $this->supervisor = User::factory()->departmentManager()->inDepartment($design)->create();
        $design->managers()->attach($this->supervisor);
        // Gestor del proyecto, de otro departamento: puede imputar por los miembros (D-036), pero no
        // supervisa a Pedro.
        $this->gestor = User::factory()->departmentManager()->inDepartment($development)->create();
        $development->managers()->attach($this->gestor);
        $this->admin = User::factory()->admin()->create();

        $project = Project::factory()->create();
        $project->addMember($this->gestor, isManager: true);
        $project->addMember($this->person);
        $task = Task::factory()->create(['project_id' => $project->id]);

        Absence::factory()->for($this->person)->approved()->between('2026-09-21', '2026-09-22')->create(['type' => AbsenceType::Sick]);
        Absence::factory()->for($this->person)->approved()->partial(120)->between('2026-09-24', '2026-09-24')->create(['type' => AbsenceType::Sick]);

        $this->logFor = fn (User $actor, string $date, int $minutes = 60) => app(TimeEntryWriter::class)->create($actor, new TimeEntryData(
            userId: $this->person->id,
            taskId: $task->id,
            date: CarbonImmutable::parse($date),
            minutes: $minutes,
            description: null,
        ));
        $this->messages = fn (User $actor, string $date, int $minutes = 60): array => collect(($this->logFor)($actor, $date, $minutes)->warnings)
            ->mapWithKeys(fn (TimeEntryWarning $warning): array => [$warning->code => $warning->message])
            ->all();
    });

    test('quien no la supervisa (un gestor de su proyecto) no ve el tipo: solo que no está disponible', function () {
        expect(($this->messages)($this->gestor, '2026-09-22'))->toBe([
            TimeEntryWarning::ABSENCE => 'Ese día Pedro Pérez no está disponible. Revisa que la fecha sea correcta.',
            TimeEntryWarning::OVER_CAPACITY => 'Ese día Pedro Pérez no tiene jornada y suma 1:00.',
        ])
            ->and(($this->messages)($this->gestor, '2026-09-24', 30))->toBe([
                TimeEntryWarning::ABSENCE => 'Ese día Pedro Pérez no está disponible una parte de la jornada.',
            ]);
    });

    test('un admin o su responsable ven el tipo, con el nombre de la persona', function (string $who) {
        $actor = $this->{$who};

        expect(($this->messages)($actor, '2026-09-21'))->toBe([
            TimeEntryWarning::ABSENCE => 'Ese día Pedro Pérez tiene una ausencia aprobada (Baja). Revisa que la fecha sea correcta.',
            TimeEntryWarning::OVER_CAPACITY => 'Ese día Pedro Pérez no tiene jornada y suma 1:00.',
        ])
            ->and(($this->messages)($actor, '2026-09-24', 30))->toBe([
                TimeEntryWarning::ABSENCE => 'Ese día Pedro Pérez tiene una ausencia aprobada de parte del día (Baja, 2:00).',
            ]);
    })->with(['admin', 'supervisor']);

    test('la propia persona ve el tipo de su ausencia', function () {
        expect(($this->messages)($this->person, '2026-09-22'))->toBe([
            TimeEntryWarning::ABSENCE => 'Ese día hay una ausencia aprobada (Baja). Revisa que la fecha sea correcta.',
            TimeEntryWarning::OVER_CAPACITY => 'Ese día no tienes jornada y sumas 1:00.',
        ]);
    });

    test('los avisos de jornada se refieren a la persona por la que se imputa, no a quien imputa', function () {
        // Domingo 20/09: sin jornada. Miércoles 23/09: 8 h de jornada y 11 h imputadas.
        expect(($this->messages)($this->gestor, '2026-09-20'))->toBe([
            TimeEntryWarning::OVER_CAPACITY => 'Ese día Pedro Pérez no tiene jornada y suma 1:00.',
        ])
            ->and(($this->messages)($this->admin, '2026-09-23', 660))->toBe([
                TimeEntryWarning::OVER_CAPACITY => 'Ese día Pedro Pérez suma 11:00, más de un 25 % por encima de su jornada (8:00).',
            ])
            ->and(($this->messages)($this->person, '2026-09-18', 660))->toBe([
                TimeEntryWarning::OVER_CAPACITY => 'Ese día sumas 11:00, más de un 25 % por encima de tu jornada (8:00).',
            ]);
    });
});
