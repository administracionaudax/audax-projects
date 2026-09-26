<?php

use App\Domain\HourBanks\Events\HourBankOverageRecorded;
use App\Domain\HourBanks\Events\HourBankThresholdReached;
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
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;

/*
| Entrada manual (SPEC §7): POST /horas/entradas, PUT y DELETE /horas/entradas/{entry}, siempre con
| TimeEntryWriter. "Hoy" es el viernes 25/09/2026 en Madrid.
*/

beforeEach(function () {
    Event::fake([HourBankThresholdReached::class, HourBankOverageRecorded::class]);
    $this->travelTo(CarbonImmutable::parse('2026-09-25 10:00:00', 'Europe/Madrid'));

    $this->department = Department::factory()->create(['name' => 'Desarrollo']);
    $this->employee = User::factory()->employee()->inDepartment($this->department)->create();
    $this->project = Project::factory()->create();
    $this->project->addMember($this->employee);
    $this->task = Task::factory()->create(['project_id' => $this->project->id, 'title' => 'Maquetar la home']);

    $this->payload = fn (array $overrides = []): array => [
        'task_id' => $this->task->id,
        'date' => '2026-09-24',
        'minutes' => 90,
        'description' => 'Cabecera y menú',
        ...$overrides,
    ];
});

it('crea una entrada en borrador y lo avisa', function () {
    $this->actingAs($this->employee)
        ->from('/horas')
        ->post('/horas/entradas', ($this->payload)())
        ->assertRedirect('/horas')
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Horas guardadas: 1:30 en «Maquetar la home».');

    $entry = TimeEntry::query()->sole();
    expect($entry->user_id)->toBe($this->employee->id)
        ->and($entry->minutes)->toBe(90)
        ->and($entry->status)->toBe(TimeEntryStatus::Draft)
        ->and($entry->description)->toBe('Cabecera y menú')
        ->and($entry->created_by)->toBe($this->employee->id);
});

it('acepta la duración como texto (1h30, 1,5, 90m)', function (string $text) {
    $this->actingAs($this->employee)
        ->post('/horas/entradas', ($this->payload)(['minutes' => $text]))
        ->assertSessionHasNoErrors();

    expect(TimeEntry::query()->sole()->minutes)->toBe(90);
})->with(['1h30', '1,5', '90m', '1:30']);

it('devuelve los errores por campo', function () {
    $this->actingAs($this->employee)
        ->post('/horas/entradas', ['task_id' => $this->task->id, 'date' => '24/09/2026', 'minutes' => 'mucho'])
        ->assertSessionHasErrors(['date', 'minutes']);

    $this->actingAs($this->employee)
        ->post('/horas/entradas', ($this->payload)(['date' => '2026-09-26']))
        ->assertSessionHasErrors(['date' => 'No se pueden imputar horas en fechas futuras.']);

    Setting::set('time_entry_description_required', true);
    $this->actingAs($this->employee)
        ->post('/horas/entradas', ($this->payload)(['description' => '']))
        ->assertSessionHasErrors(['description' => 'Escribe una descripción de lo que has hecho.']);

    expect(TimeEntry::query()->count())->toBe(0);
});

it('avisa sin bloquear si la tarea está completada', function () {
    $this->task->update(['status_id' => Task::factory()->completed()->make()->status_id]);

    $this->actingAs($this->employee)
        ->post('/horas/entradas', ($this->payload)())
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('time_warnings.0.code', 'task_completed');
});

it('rechaza con el saldo disponible en una bolsa block', function () {
    $bank = HourBank::factory()->hours(1)->blockOverage()->create();
    $bank->project->addMember($this->employee);
    $task = Task::factory()->inBank($bank)->create();

    $this->actingAs($this->employee)
        ->post('/horas/entradas', ($this->payload)(['task_id' => $task->id, 'minutes' => 61]))
        ->assertSessionHasErrors(['minutes' => "La bolsa «{$bank->name}» no admite exceso. Saldo disponible: 1:00."]);
});

it('edita y borra sus entradas', function () {
    $entry = TimeEntry::factory()->forTask($this->task)->on('2026-09-24')->minutes(60)->create(['user_id' => $this->employee->id]);

    $this->actingAs($this->employee)
        ->put("/horas/entradas/{$entry->id}", ($this->payload)(['minutes' => 120, 'date' => '2026-09-23']))
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Entrada actualizada: 2:00 en «Maquetar la home».');

    expect($entry->fresh()->minutes)->toBe(120)
        ->and($entry->fresh()->date->toDateString())->toBe('2026-09-23');

    $this->actingAs($this->employee)
        ->delete("/horas/entradas/{$entry->id}")
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Entrada eliminada.');

    expect(TimeEntry::query()->count())->toBe(0);
});

it('no edita ni borra entradas de una semana enviada', function () {
    $entry = TimeEntry::factory()->forTask($this->task)->on('2026-09-24')->status(TimeEntryStatus::Submitted)->create(['user_id' => $this->employee->id]);
    TimesheetPeriod::factory()->for($this->employee)->week('2026-09-24')->status(TimesheetStatus::Submitted)->create();

    $this->actingAs($this->employee)
        ->put("/horas/entradas/{$entry->id}", ($this->payload)(['minutes' => 30]))
        ->assertSessionHasErrors('date');

    $this->actingAs($this->employee)
        ->delete("/horas/entradas/{$entry->id}")
        ->assertSessionHasErrors('date');

    expect($entry->fresh()->minutes)->toBe(60);
});

it('un compañero sin permiso no edita ni borra la entrada de otro (403)', function () {
    $entry = TimeEntry::factory()->forTask($this->task)->on('2026-09-24')->create(['user_id' => $this->employee->id]);
    $colleague = User::factory()->employee()->inDepartment($this->department)->create();
    $this->project->addMember($colleague);

    $this->actingAs($colleague)->put("/horas/entradas/{$entry->id}", ($this->payload)())->assertForbidden();
    $this->actingAs($colleague)->delete("/horas/entradas/{$entry->id}")->assertForbidden();
});

it('imputa en nombre de otra persona con permisos: gestor, responsable y admin', function () {
    $manager = User::factory()->employee()->create();
    $this->project->addMember($manager, isManager: true);
    $head = User::factory()->departmentManager()->create();
    $this->department->managers()->attach($head);
    $admin = User::factory()->admin()->create();

    foreach ([$manager, $head, $admin] as $actor) {
        $this->actingAs($actor)
            ->post('/horas/entradas', ($this->payload)(['user_id' => $this->employee->id, 'minutes' => 30]))
            ->assertSessionHasNoErrors();
    }

    $entries = TimeEntry::query()->orderBy('id')->get();
    expect($entries)->toHaveCount(3)
        ->and($entries->pluck('user_id')->unique()->all())->toBe([$this->employee->id])
        ->and($entries->pluck('created_by')->all())->toBe([$manager->id, $head->id, $admin->id]);
});

it('un compañero no puede imputar en nombre de otro', function () {
    $colleague = User::factory()->employee()->create();
    $this->project->addMember($colleague);

    $this->actingAs($colleague)
        ->post('/horas/entradas', ($this->payload)(['user_id' => $this->employee->id]))
        ->assertSessionHasErrors(['user_id' => 'No puedes imputar horas en nombre de esta persona en este proyecto.']);
});

it('el gestor de un proyecto interno solo imputa por sus miembros, no por cualquiera (SEG-02, D-036)', function () {
    $manager = User::factory()->employee()->create();
    $internal = Project::factory()->internal()->create(['owner_user_id' => $manager->id]);
    $meetings = Task::factory()->create(['project_id' => $internal->id]);
    $member = User::factory()->employee()->create();
    $internal->addMember($member);
    $outsider = User::factory()->employee()->inDepartment(Department::factory()->create())->create();
    $admin = User::factory()->admin()->create();
    expect($manager->isManagerOf($internal))->toBeTrue();

    // Por quien no es miembro (ni de su equipo), aunque el proyecto sea interno: no.
    foreach ([$outsider, $admin] as $target) {
        $this->actingAs($manager)
            ->post('/horas/entradas', ($this->payload)(['task_id' => $meetings->id, 'user_id' => $target->id, 'minutes' => 480]))
            ->assertSessionHasErrors(['user_id' => 'No puedes imputar horas en nombre de esta persona en este proyecto.']);
    }

    expect(TimeEntry::query()->count())->toBe(0);

    // Por un miembro, sí. Y la propia persona imputa sin ser miembro (D-033).
    $this->actingAs($manager)
        ->post('/horas/entradas', ($this->payload)(['task_id' => $meetings->id, 'user_id' => $member->id, 'minutes' => 30]))
        ->assertSessionHasNoErrors();
    $this->actingAs($outsider)
        ->post('/horas/entradas', ($this->payload)(['task_id' => $meetings->id, 'minutes' => 30]))
        ->assertSessionHasNoErrors();

    expect(TimeEntry::query()->pluck('user_id')->sort()->values()->all())->toBe(collect([$member->id, $outsider->id])->sort()->values()->all());
});

it('una entrada bloqueada solo la edita un admin', function () {
    $entry = TimeEntry::factory()->forTask($this->task)->on('2026-09-21')->minutes(60)->status(TimeEntryStatus::Locked)->create(['user_id' => $this->employee->id]);
    TimesheetPeriod::factory()->for($this->employee)->week('2026-09-21')->status(TimesheetStatus::Locked)->create();

    $this->actingAs($this->employee)
        ->put("/horas/entradas/{$entry->id}", ($this->payload)(['date' => '2026-09-21', 'minutes' => 30]))
        ->assertForbidden();

    $head = User::factory()->departmentManager()->create();
    $this->department->managers()->attach($head);
    $this->actingAs($head)
        ->put("/horas/entradas/{$entry->id}", ($this->payload)(['date' => '2026-09-21', 'minutes' => 30]))
        ->assertForbidden();

    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)
        ->put("/horas/entradas/{$entry->id}", ($this->payload)(['date' => '2026-09-21', 'minutes' => 30]))
        ->assertSessionHasNoErrors();

    expect($entry->fresh()->minutes)->toBe(30)
        ->and($entry->fresh()->status)->toBe(TimeEntryStatus::Locked);
});

it('no cambia la persona de una entrada al editar', function () {
    $entry = TimeEntry::factory()->forTask($this->task)->on('2026-09-24')->create(['user_id' => $this->employee->id]);
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->put("/horas/entradas/{$entry->id}", ($this->payload)(['user_id' => $admin->id]))
        ->assertSessionHasErrors('user_id');
});

it('con quiet (celdas de la hoja semanal) no envía el aviso de éxito, pero sí los avisos de imputación', function () {
    $this->task->update(['status_id' => Task::factory()->completed()->make()->status_id]);

    $this->actingAs($this->employee)
        ->post('/horas/entradas', ($this->payload)(['quiet' => 1]))
        ->assertSessionHasNoErrors()
        ->assertInertiaFlashMissing('toast')
        ->assertInertiaFlash('time_warnings.0.code', 'task_completed');

    $entry = TimeEntry::query()->sole();

    $this->actingAs($this->employee)
        ->delete("/horas/entradas/{$entry->id}?quiet=1")
        ->assertSessionHasNoErrors()
        ->assertInertiaFlashMissing('toast');

    expect(TimeEntry::query()->count())->toBe(0);
});
