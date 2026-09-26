<?php

use App\Domain\HourBanks\Events\HourBankOverageRecorded;
use App\Domain\HourBanks\Events\HourBankThresholdReached;
use App\Domain\HourBanks\HourBankRenewal;
use App\Enums\HourBankStatus;
use App\Enums\OveragePolicy;
use App\Models\Department;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\Setting;
use App\Models\Task;
use App\Models\TimeEntry;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Renovación de bolsas (SPEC §8.7 y §8.8): solo desde una bolsa agotada o próxima a agotarse
| (primer umbral, D-035); bolsa nueva con los mismos parámetros (editables) y renewed_from_id; la
| anterior pasa a «renovada»; se mueven las tareas abiertas (con sus subtareas) si se pide; las
| horas NUNCA se mueven; histórico completo.
*/

beforeEach(function () {
    Event::fake([HourBankThresholdReached::class, HourBankOverageRecorded::class]);

    $this->owner = userWithRole('employee');
    $this->project = Project::factory()->hourBank()->create(['owner_user_id' => $this->owner->id]);
    $this->department = Department::factory()->create();
    $this->bank = HourBank::factory()->hours(10)->create([
        'project_id' => $this->project->id,
        'name' => 'Bolsa T3',
        'department_id' => $this->department->id,
        'overage_policy' => OveragePolicy::Allow,
        'start_date' => '2026-07-01',
        'end_date' => '2026-09-30',
        'hourly_rate' => '60.00',
        'price_amount' => '550.00',
    ]);
    $this->url = "/proyectos/{$this->project->id}/bolsas/{$this->bank->id}/renovar";

    // Imputa en la bolsa en una tarea terminada (que no se mueve al renovar).
    $this->spend = fn (HourBank $bank, int $minutes) => TimeEntry::factory()
        ->forTask(Task::factory()->inBank($bank)->completed()->create())
        ->minutes($minutes)
        ->create();

    // Al 80 %: «próxima a agotarse» (primer umbral, 75 %), se puede renovar.
    ($this->spend)($this->bank, 8 * 60);

    $this->payload = fn (array $overrides = []): array => [
        'name' => 'Bolsa T4',
        'department_id' => $this->department->id,
        'total_minutes' => 20 * 60,
        'start_date' => '2026-10-01',
        'end_date' => '2026-12-31',
        'overage_policy' => 'allow',
        'invoice_reference' => null,
        'notes' => null,
        'move_open_tasks' => true,
        ...$overrides,
    ];
});

test('crea la bolsa nueva enlazada y la anterior queda renovada; lleva al detalle de la nueva', function () {
    $response = $this->actingAs($this->owner)->post($this->url, ($this->payload)());

    $renewal = HourBank::query()->where('renewed_from_id', $this->bank->id)->firstOrFail();
    $response->assertRedirect(route('projects.hour-banks.show', [$this->project, $renewal]));

    expect($renewal)
        ->name->toBe('Bolsa T4')
        ->project_id->toBe($this->project->id)
        ->department_id->toBe($this->department->id)
        ->total_minutes->toBe(1200)
        ->status->toBe(HourBankStatus::Active)
        ->overage_policy->toBe(OveragePolicy::Allow)
        ->consumed_minutes->toBe(0)
        ->and($this->bank->fresh()->status)->toBe(HourBankStatus::Renewed)
        ->and($this->bank->fresh()->renewal?->id)->toBe($renewal->id);
});

test('mueve todas las tareas abiertas con sus subtareas; las horas NUNCA se mueven', function () {
    $open = Task::factory()->inBank($this->bank)->create();
    $openSubtask = Task::factory()->subtaskOf($open)->create();
    $doneSubtask = Task::factory()->subtaskOf($open)->completed()->create();
    // Un padre terminado con una subtarea abierta: van juntos (misma bolsa, D-037).
    $done = Task::factory()->inBank($this->bank)->completed()->create();
    $openUnderDone = Task::factory()->subtaskOf($done)->create();
    $doneSibling = Task::factory()->subtaskOf($done)->completed()->create();
    // Terminada y sin nada abierto: se queda.
    $doneAlone = Task::factory()->inBank($this->bank)->completed()->create();
    $doneAloneSubtask = Task::factory()->subtaskOf($doneAlone)->completed()->create();
    // Los hitos abiertos también se mueven.
    $milestone = Task::factory()->inBank($this->bank)->milestone()->create();

    $entry = TimeEntry::factory()->forTask($open)->minutes(12 * 60)->create();
    TimeEntry::factory()->forTask($done)->minutes(60)->create();

    $overageBefore = $this->bank->fresh()->overage_minutes;

    // Lo que anuncia la tarjeta es lo que se mueve: 4 tareas abiertas.
    $this->actingAs($this->owner)
        ->get("/proyectos/{$this->project->id}/bolsas")
        ->assertInertia(fn (Assert $page) => $page->where('banks.0.open_tasks_count', 4));

    $this->actingAs($this->owner)->post($this->url, ($this->payload)())
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Bolsa renovada: se han movido 4 tareas abiertas. Las horas imputadas se quedan en la anterior.');

    $renewal = HourBank::query()->where('renewed_from_id', $this->bank->id)->firstOrFail();

    expect($open->fresh()->hour_bank_id)->toBe($renewal->id)
        ->and($openSubtask->fresh()->hour_bank_id)->toBe($renewal->id)
        ->and($doneSubtask->fresh()->hour_bank_id)->toBe($renewal->id)
        ->and($done->fresh()->hour_bank_id)->toBe($renewal->id)
        ->and($openUnderDone->fresh()->hour_bank_id)->toBe($renewal->id)
        ->and($doneSibling->fresh()->hour_bank_id)->toBe($renewal->id)
        ->and($milestone->fresh()->hour_bank_id)->toBe($renewal->id)
        ->and($doneAlone->fresh()->hour_bank_id)->toBe($this->bank->id)
        ->and($doneAloneSubtask->fresh()->hour_bank_id)->toBe($this->bank->id)
        // Las horas se quedan en la bolsa anterior, con su consumo y su exceso.
        ->and($entry->fresh()->hour_bank_id)->toBe($this->bank->id)
        ->and(TimeEntry::query()->where('hour_bank_id', $renewal->id)->count())->toBe(0)
        ->and($this->bank->fresh()->consumed_minutes)->toBe(21 * 60)
        ->and($this->bank->fresh()->overage_minutes)->toBe($overageBefore)
        ->and($renewal->fresh()->consumed_minutes)->toBe(0);
});

test('solo se renueva una bolsa agotada o próxima a agotarse (desde el primer umbral)', function () {
    $bank = HourBank::factory()->hours(10)->create(['project_id' => $this->project->id]);
    $url = "/proyectos/{$this->project->id}/bolsas/{$bank->id}/renovar";

    // Al 50 %: aún no toca.
    ($this->spend)($bank, 5 * 60);
    $this->actingAs($this->owner)
        ->post($url, ($this->payload)())
        ->assertSessionHasErrors(['hour_bank' => 'Solo se puede renovar una bolsa agotada o que haya llegado al 75 % de consumo.']);

    expect($bank->fresh()->status)->toBe(HourBankStatus::Active)
        ->and(HourBank::query()->where('renewed_from_id', $bank->id)->exists())->toBeFalse();

    // Justo en el primer umbral, sí.
    ($this->spend)($bank, 150);
    $this->actingAs($this->owner)->post($url, ($this->payload)())->assertSessionHasNoErrors();

    expect($bank->fresh()->status)->toBe(HourBankStatus::Renewed);
});

test('una bolsa agotada se renueva; el umbral es el primero configurado', function () {
    $exhausted = HourBank::factory()->hours(1)->create(['project_id' => $this->project->id]);
    ($this->spend)($exhausted, 90);
    expect($exhausted->fresh()->status)->toBe(HourBankStatus::Exhausted);

    $this->actingAs($this->owner)
        ->post("/proyectos/{$this->project->id}/bolsas/{$exhausted->id}/renovar", ($this->payload)())
        ->assertSessionHasNoErrors();

    Setting::set('hour_bank_alert_thresholds', [60, 90, 100]);
    $atSixty = HourBank::factory()->hours(10)->create(['project_id' => $this->project->id]);
    ($this->spend)($atSixty, 6 * 60);

    $this->actingAs($this->owner)
        ->post("/proyectos/{$this->project->id}/bolsas/{$atSixty->id}/renovar", ($this->payload)())
        ->assertSessionHasNoErrors();

    expect($exhausted->fresh()->status)->toBe(HourBankStatus::Renewed)
        ->and($atSixty->fresh()->status)->toBe(HourBankStatus::Renewed);
});

test('la tarjeta solo ofrece renovar cuando toca', function () {
    $fresh = HourBank::factory()->hours(10)->create(['project_id' => $this->project->id]);

    $this->actingAs($this->owner)
        ->get("/proyectos/{$this->project->id}/bolsas")
        ->assertInertia(function (Assert $page) use ($fresh) {
            $banks = collect($page->toArray()['props']['banks'])->keyBy('id');

            expect($banks[$this->bank->id]['can']['renew'])->toBeTrue()
                ->and($banks[$fresh->id]['can']['renew'])->toBeFalse()
                ->and($banks[$fresh->id]['can']['close'])->toBeTrue();
        });
});

test('sin mover tareas, se quedan todas en la bolsa anterior', function () {
    $open = Task::factory()->inBank($this->bank)->create();

    $this->actingAs($this->owner)->post($this->url, ($this->payload)(['move_open_tasks' => false]));

    expect($open->fresh()->hour_bank_id)->toBe($this->bank->id);
});

test('el mensaje dice cuántas tareas se han movido', function () {
    Task::factory()->inBank($this->bank)->count(2)->create();

    $this->actingAs($this->owner)->post($this->url, ($this->payload)())
        ->assertInertiaFlash('toast.message', 'Bolsa renovada: se han movido 2 tareas abiertas. Las horas imputadas se quedan en la anterior.');
});

test('mover tareas queda en la auditoría de cada tarea', function () {
    $task = Task::factory()->inBank($this->bank)->create();

    $this->actingAs($this->owner)->post($this->url, ($this->payload)());

    $this->assertDatabaseHas('activity_log', [
        'subject_type' => $task->getMorphClass(),
        'subject_id' => $task->id,
        'event' => 'updated',
        'causer_id' => $this->owner->id,
    ]);
});

test('sin view-financials, la tarifa y el precio se copian de la anterior', function () {
    $this->actingAs($this->owner)->post($this->url, ($this->payload)(['hourly_rate' => '1', 'price_amount' => '1']));

    $renewal = HourBank::query()->where('renewed_from_id', $this->bank->id)->firstOrFail();

    expect($renewal->hourly_rate)->toBe('60.00')
        ->and($renewal->price_amount)->toBe('550.00');
});

test('con view-financials se pueden cambiar la tarifa y el precio', function () {
    $this->actingAs(userWithRole('admin'))->post($this->url, ($this->payload)(['hourly_rate' => '65', 'price_amount' => '1300']));

    $renewal = HourBank::query()->where('renewed_from_id', $this->bank->id)->firstOrFail();

    expect($renewal->hourly_rate)->toBe('65.00')
        ->and($renewal->price_amount)->toBe('1300.00');
});

test('no se renueva una bolsa cerrada o ya renovada', function (string $state) {
    $this->bank->update(['status' => $state]);

    $this->actingAs($this->owner)
        ->post($this->url, ($this->payload)())
        ->assertSessionHasErrors('hour_bank');

    expect(HourBank::query()->where('renewed_from_id', $this->bank->id)->exists())->toBeFalse();
})->with(['closed', 'renewed']);

test('la renovación es atómica: si falla, nada cambia', function () {
    $task = Task::factory()->inBank($this->bank)->create();
    $renewal = app(HourBankRenewal::class);

    $this->bank->update(['status' => HourBankStatus::Closed]);

    expect(fn () => $renewal->renew($this->bank, ['name' => 'X'], true))->toThrow(ValidationException::class)
        ->and($task->fresh()->hour_bank_id)->toBe($this->bank->id)
        ->and(HourBank::query()->count())->toBe(1);
});

test('histórico de renovaciones: la cadena completa, de la más antigua a la más reciente', function () {
    $this->actingAs($this->owner)->post($this->url, ($this->payload)(['name' => 'Bolsa T4', 'total_minutes' => 60]));
    $t4 = HourBank::query()->where('name', 'Bolsa T4')->firstOrFail();
    ($this->spend)($t4, 60);

    $this->actingAs($this->owner)->post(
        "/proyectos/{$this->project->id}/bolsas/{$t4->id}/renovar",
        ($this->payload)(['name' => 'Bolsa T1 2027', 'start_date' => '2027-01-01', 'end_date' => '2027-03-31']),
    );

    HourBank::factory()->create(['project_id' => $this->project->id, 'name' => 'Suelta']);

    $this->actingAs($this->owner)
        ->get("/proyectos/{$this->project->id}/bolsas")
        ->assertInertia(fn (Assert $page) => $page
            ->has('history', 1)
            ->has('history.0', 3)
            ->where('history.0.0.name', 'Bolsa T3')
            ->where('history.0.0.project_id', $this->project->id)
            ->where('history.0.0.status', 'renewed')
            ->where('history.0.1.name', 'Bolsa T4')
            ->where('history.0.1.status', 'renewed')
            ->where('history.0.2.name', 'Bolsa T1 2027')
            ->where('history.0.2.status', 'active'));
});

test('el detalle enlaza con la bolsa anterior y con su renovación', function () {
    $this->actingAs($this->owner)->post($this->url, ($this->payload)());
    $renewal = HourBank::query()->where('renewed_from_id', $this->bank->id)->firstOrFail();

    $this->actingAs($this->owner)
        ->get("/proyectos/{$this->project->id}/bolsas/{$renewal->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('bank.renewed_from.id', $this->bank->id)
            ->where('bank.renewed_from.status', 'renewed')
            ->where('bank.renewal', null));

    $this->actingAs($this->owner)
        ->get("/proyectos/{$this->project->id}/bolsas/{$this->bank->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('bank.renewal.id', $renewal->id)
            ->where('bank.can.update', false)
            ->where('bank.can.renew', false));
});

test('la tarjeta cuenta las tareas abiertas que se moverían: subtareas e hitos incluidos', function () {
    $parent = Task::factory()->inBank($this->bank)->create();
    Task::factory()->subtaskOf($parent)->create();
    Task::factory()->subtaskOf($parent)->completed()->create();
    Task::factory()->inBank($this->bank)->milestone()->create();
    Task::factory()->inBank($this->bank)->completed()->create();

    $this->actingAs($this->owner)
        ->get("/proyectos/{$this->project->id}/bolsas")
        ->assertInertia(fn (Assert $page) => $page->where('banks.0.open_tasks_count', 3));
});
