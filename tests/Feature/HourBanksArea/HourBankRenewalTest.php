<?php

use App\Domain\HourBanks\Events\HourBankOverageRecorded;
use App\Domain\HourBanks\Events\HourBankThresholdReached;
use App\Domain\HourBanks\HourBankRenewal;
use App\Enums\HourBankStatus;
use App\Enums\OveragePolicy;
use App\Models\Department;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Renovación de bolsas (SPEC §8.7 y §8.8): bolsa nueva con los mismos parámetros (editables) y
| renewed_from_id; la anterior pasa a «renovada»; se mueven las tareas abiertas (con sus
| subtareas) si se pide; las horas NUNCA se mueven; histórico completo.
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

test('mueve las tareas abiertas con sus subtareas; las horas NUNCA se mueven', function () {
    $open = Task::factory()->inBank($this->bank)->create();
    $openSubtask = Task::factory()->subtaskOf($open)->create();
    $doneSubtask = Task::factory()->subtaskOf($open)->completed()->create();
    $done = Task::factory()->inBank($this->bank)->completed()->create();
    $openUnderDone = Task::factory()->subtaskOf($done)->create();

    $entry = TimeEntry::factory()->forTask($open)->minutes(12 * 60)->create();
    TimeEntry::factory()->forTask($done)->minutes(60)->create();

    $overageBefore = $this->bank->fresh()->overage_minutes;

    $this->actingAs($this->owner)->post($this->url, ($this->payload)())->assertSessionHasNoErrors();

    $renewal = HourBank::query()->where('renewed_from_id', $this->bank->id)->firstOrFail();

    expect($open->fresh()->hour_bank_id)->toBe($renewal->id)
        ->and($openSubtask->fresh()->hour_bank_id)->toBe($renewal->id)
        ->and($doneSubtask->fresh()->hour_bank_id)->toBe($renewal->id)
        ->and($done->fresh()->hour_bank_id)->toBe($this->bank->id)
        ->and($openUnderDone->fresh()->hour_bank_id)->toBe($this->bank->id)
        // Las horas se quedan en la bolsa anterior, con su consumo y su exceso.
        ->and($entry->fresh()->hour_bank_id)->toBe($this->bank->id)
        ->and(TimeEntry::query()->where('hour_bank_id', $renewal->id)->count())->toBe(0)
        ->and($this->bank->fresh()->consumed_minutes)->toBe(13 * 60)
        ->and($this->bank->fresh()->overage_minutes)->toBe($overageBefore)
        ->and($renewal->fresh()->consumed_minutes)->toBe(0);
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
    $this->actingAs($this->owner)->post($this->url, ($this->payload)(['name' => 'Bolsa T4']));
    $t4 = HourBank::query()->where('name', 'Bolsa T4')->firstOrFail();

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

test('la tarjeta ofrece mover las tareas abiertas de primer nivel', function () {
    $parent = Task::factory()->inBank($this->bank)->create();
    Task::factory()->subtaskOf($parent)->create();
    Task::factory()->inBank($this->bank)->completed()->create();

    $this->actingAs($this->owner)
        ->get("/proyectos/{$this->project->id}/bolsas")
        ->assertInertia(fn (Assert $page) => $page->where('banks.0.open_tasks_count', 1));
});
