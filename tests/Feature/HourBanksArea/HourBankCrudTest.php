<?php

use App\Domain\HourBanks\Events\HourBankOverageRecorded;
use App\Domain\HourBanks\Events\HourBankThresholdReached;
use App\Enums\HourBankStatus;
use App\Enums\OveragePolicy;
use App\Models\Department;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Alta, edición y borrado de bolsas (SPEC §8, D-035) y permisos (HourBankPolicy): las crean,
| editan, renuevan y cierran quienes gestionan el proyecto; reabrir y borrar (sin horas), solo un
| admin. Cambiar el total recalcula (HourBank::booted → HourBankLedger).
*/

beforeEach(function () {
    Event::fake([HourBankThresholdReached::class, HourBankOverageRecorded::class]);

    $this->owner = userWithRole('employee');
    $this->member = userWithRole('employee');
    $this->project = Project::factory()->hourBank()->create(['owner_user_id' => $this->owner->id]);
    $this->project->addMember($this->member);
    $this->banksUrl = "/proyectos/{$this->project->id}/bolsas";

    $this->valid = fn (array $overrides = []): array => [
        'name' => 'Bolsa Desarrollo T4',
        'total_minutes' => 50 * 60,
        'start_date' => '2026-10-01',
        'end_date' => '2026-12-31',
        'overage_policy' => 'inherit',
        ...$overrides,
    ];
});

test('quien gestiona el proyecto crea bolsas; un empleado miembro no', function (string $actor, bool $allowed) {
    $user = match ($actor) {
        'gestor' => $this->owner,
        'miembro' => $this->member,
        default => userWithRole($actor),
    };

    $response = $this->actingAs($user)->post($this->banksUrl, ($this->valid)());

    if ($allowed) {
        $response->assertRedirect(route('projects.hour-banks.index', $this->project))->assertSessionHasNoErrors();
        expect(HourBank::query()->where('name', 'Bolsa Desarrollo T4')->firstOrFail())
            ->status->toBe(HourBankStatus::Active)
            ->project_id->toBe($this->project->id)
            ->total_minutes->toBe(3000);
    } else {
        $response->assertForbidden();
        expect(HourBank::query()->count())->toBe(0);
    }
})->with([
    'admin' => ['admin', true],
    'responsable' => ['department_manager', true],
    'gestor del proyecto' => ['gestor', true],
    'empleado miembro' => ['miembro', false],
    'empleado' => ['employee', false],
]);

test('solo en proyectos de bolsas', function () {
    $project = Project::factory()->create(['owner_user_id' => $this->owner->id]);

    $this->actingAs($this->owner)
        ->post("/proyectos/{$project->id}/bolsas", ($this->valid)())
        ->assertSessionHasErrors('hour_bank');

    $this->actingAs($this->owner)->get("/proyectos/{$project->id}/bolsas")->assertNotFound();
});

test('valida el total, las fechas, la política y el departamento', function () {
    $this->actingAs($this->owner)
        ->post($this->banksUrl, ($this->valid)([
            'name' => '',
            'total_minutes' => 0,
            'start_date' => '2026-10-10',
            'end_date' => '2026-10-01',
            'overage_policy' => 'maybe',
            'department_id' => 999,
        ]))
        ->assertSessionHasErrors(['name', 'total_minutes', 'end_date', 'overage_policy', 'department_id']);
});

test('con departamento, política y datos de facturación', function () {
    $department = Department::factory()->create();

    $this->actingAs(userWithRole('admin'))
        ->post($this->banksUrl, ($this->valid)([
            'department_id' => $department->id,
            'overage_policy' => 'block',
            'hourly_rate' => '55',
            'price_amount' => '2500.00',
            'invoice_reference' => 'F-2026-101',
            'notes' => 'Renovación anual',
        ]))
        ->assertSessionHasNoErrors();

    $bank = HourBank::query()->firstOrFail();

    expect($bank->department_id)->toBe($department->id)
        ->and($bank->overage_policy)->toBe(OveragePolicy::Block)
        ->and($bank->hourly_rate)->toBe('55.00')
        ->and($bank->price_amount)->toBe('2500.00')
        ->and($bank->invoice_reference)->toBe('F-2026-101');
});

test('sin view-financials se ignoran la tarifa y el precio, pero no la referencia de factura', function () {
    $this->actingAs($this->owner)
        ->post($this->banksUrl, ($this->valid)(['hourly_rate' => '55', 'price_amount' => '2500', 'invoice_reference' => 'F-1']))
        ->assertSessionHasNoErrors();

    $bank = HourBank::query()->firstOrFail();

    expect($bank->hourly_rate)->toBeNull()
        ->and($bank->price_amount)->toBeNull()
        ->and($bank->invoice_reference)->toBe('F-1');
});

test('editar el total recalcula el consumo, el exceso y el estado', function () {
    $bank = HourBank::factory()->hours(10)->create(['project_id' => $this->project->id]);
    $task = Task::factory()->inBank($bank)->create();
    $entry = TimeEntry::factory()->forTask($task)->minutes(12 * 60)->create();

    expect($bank->fresh()->status)->toBe(HourBankStatus::Exhausted)
        ->and($entry->fresh()->overage_minutes)->toBe(120);

    $this->actingAs($this->owner)
        ->from("{$this->banksUrl}/{$bank->id}")
        ->put("{$this->banksUrl}/{$bank->id}", [
            ...($this->valid)(),
            'name' => $bank->name,
            'total_minutes' => 15 * 60,
        ])
        ->assertRedirect("{$this->banksUrl}/{$bank->id}")
        ->assertSessionHasNoErrors();

    expect($bank->fresh())
        ->total_minutes->toBe(900)
        ->overage_minutes->toBe(0)
        ->status->toBe(HourBankStatus::Active)
        ->and($entry->fresh()->overage_minutes)->toBe(0);
});

test('una bolsa renovada no se edita', function () {
    $bank = HourBank::factory()->renewed()->create(['project_id' => $this->project->id]);

    $this->actingAs($this->owner)
        ->put("{$this->banksUrl}/{$bank->id}", ($this->valid)())
        ->assertSessionHasErrors(['hour_bank' => 'Una bolsa renovada no se puede editar: su histórico queda como estaba.']);
});

test('un empleado miembro no edita, ni renueva, ni cierra', function () {
    $bank = HourBank::factory()->create(['project_id' => $this->project->id]);

    $this->actingAs($this->member)->put("{$this->banksUrl}/{$bank->id}", ($this->valid)())->assertForbidden();
    $this->actingAs($this->member)->post("{$this->banksUrl}/{$bank->id}/renovar", ($this->valid)())->assertForbidden();
    $this->actingAs($this->member)->post("{$this->banksUrl}/{$bank->id}/cerrar")->assertForbidden();

    expect($bank->fresh()->status)->toBe(HourBankStatus::Active)
        ->and(HourBank::query()->count())->toBe(1);
});

test('borrar: solo un admin y solo sin horas ni tareas', function () {
    $empty = HourBank::factory()->create(['project_id' => $this->project->id, 'name' => 'Vacía']);
    $withTime = HourBank::factory()->create(['project_id' => $this->project->id]);
    TimeEntry::factory()->forTask(Task::factory()->inBank($withTime)->create())->create();
    $withTasks = HourBank::factory()->create(['project_id' => $this->project->id]);
    Task::factory()->inBank($withTasks)->create();

    $this->actingAs($this->owner)->delete("{$this->banksUrl}/{$empty->id}")->assertForbidden();

    $admin = userWithRole('admin');
    $this->actingAs($admin)->delete("{$this->banksUrl}/{$withTime->id}")->assertForbidden();
    $this->actingAs($admin)->delete("{$this->banksUrl}/{$withTasks->id}")->assertSessionHasErrors('hour_bank');
    $this->actingAs($admin)->delete("{$this->banksUrl}/{$empty->id}")->assertRedirect(route('projects.hour-banks.index', $this->project));

    expect(HourBank::query()->pluck('id')->all())->toBe([$withTime->id, $withTasks->id])
        ->and(HourBank::withTrashed()->find($empty->id)?->trashed())->toBeTrue();
});

test('una bolsa de otro proyecto da 404 (la bolsa siempre es del proyecto de la URL)', function () {
    $other = HourBank::factory()->create();

    $this->actingAs(userWithRole('admin'))->get("{$this->banksUrl}/{$other->id}")->assertNotFound();
    $this->actingAs(userWithRole('admin'))->post("{$this->banksUrl}/{$other->id}/cerrar")->assertNotFound();
});

test('la pestaña Bolsas: abiertas por defecto, con el filtro también cerradas y renovadas', function () {
    HourBank::factory()->create(['project_id' => $this->project->id, 'name' => 'Activa']);
    HourBank::factory()->closed()->create(['project_id' => $this->project->id, 'name' => 'Cerrada']);
    HourBank::factory()->renewed()->create(['project_id' => $this->project->id, 'name' => 'Renovada']);

    $this->actingAs($this->member)
        ->get($this->banksUrl)
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/hour-banks')
            ->has('banks', 1)
            ->where('banks.0.name', 'Activa')
            ->where('hiddenCount', 2)
            ->where('can.create', false)
            ->where('banks.0.can.renew', false));

    $this->actingAs($this->owner)
        ->get("{$this->banksUrl}?todas=1")
        ->assertInertia(fn (Assert $page) => $page
            ->has('banks', 3)
            ->where('filters.todas', true)
            ->where('can.create', true)
            ->where('overageDefault', 'allow'));
});

test('las tarjetas dicen qué acciones ofrecer', function () {
    $active = HourBank::factory()->create(['project_id' => $this->project->id]);
    $closed = HourBank::factory()->closed()->create(['project_id' => $this->project->id]);

    $this->actingAs(userWithRole('admin'))
        ->get("{$this->banksUrl}?todas=1")
        ->assertInertia(function (Assert $page) use ($active, $closed) {
            $banks = collect($page->toArray()['props']['banks'])->keyBy('id');

            expect($banks[$active->id]['can'])->toBe(['update' => true, 'renew' => true, 'close' => true, 'reopen' => false, 'delete' => true])
                ->and($banks[$closed->id]['can'])->toBe(['update' => true, 'renew' => false, 'close' => false, 'reopen' => true, 'delete' => true]);
        });
});

test('sin view-financials las bolsas no llevan tarifa ni precio', function () {
    HourBank::factory()->create(['project_id' => $this->project->id, 'hourly_rate' => '50.00', 'price_amount' => '1000.00']);

    $this->actingAs($this->owner)
        ->get($this->banksUrl)
        ->assertInertia(fn (Assert $page) => $page->missing('banks.0.hourly_rate')->missing('banks.0.price_amount'));

    $this->actingAs(userWithRole('admin'))
        ->get($this->banksUrl)
        ->assertInertia(fn (Assert $page) => $page->where('banks.0.price_amount', '1000.00'));
});
