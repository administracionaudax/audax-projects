<?php

use App\Domain\HourBanks\Events\HourBankOverageRecorded;
use App\Domain\HourBanks\Events\HourBankThresholdReached;
use App\Enums\HourBankStatus;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

/*
| Cierre manual y reapertura (SPEC §8.9, D-035): al cerrar queda registrado el saldo no consumido
| (y quién y cuándo); reabrir solo lo hace un admin y solo si no está renovada; HourBankLedger
| decide si al reabrir queda activa o agotada.
*/

beforeEach(function () {
    Event::fake([HourBankThresholdReached::class, HourBankOverageRecorded::class]);

    $this->owner = userWithRole('employee');
    $this->project = Project::factory()->hourBank()->create(['owner_user_id' => $this->owner->id]);
    $this->bank = HourBank::factory()->hours(10)->create(['project_id' => $this->project->id]);
    $this->task = Task::factory()->inBank($this->bank)->create();
    $this->url = "/proyectos/{$this->project->id}/bolsas/{$this->bank->id}";
});

test('cerrar registra el saldo sin consumir, quién y cuándo', function () {
    Carbon::setTestNow('2026-09-26 10:00:00');
    TimeEntry::factory()->forTask($this->task)->minutes(7 * 60 + 30)->create();

    $this->actingAs($this->owner)
        ->from($this->url)
        ->post("{$this->url}/cerrar")
        ->assertRedirect($this->url)
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Bolsa cerrada. Saldo sin consumir: 2:30.');

    expect($this->bank->fresh())
        ->status->toBe(HourBankStatus::Closed)
        ->closed_remaining_minutes->toBe(150)
        ->closed_by->toBe($this->owner->id)
        ->and($this->bank->fresh()->closed_at?->toDateTimeString())->toBe('2026-09-26 10:00:00');

    Carbon::setTestNow();
});

test('una bolsa agotada se cierra con saldo 0', function () {
    TimeEntry::factory()->forTask($this->task)->minutes(11 * 60)->create();

    $this->actingAs($this->owner)->post("{$this->url}/cerrar")->assertSessionHasNoErrors();

    expect($this->bank->fresh()->closed_remaining_minutes)->toBe(0);
});

test('no se cierra dos veces ni una renovada', function () {
    $this->actingAs($this->owner)->post("{$this->url}/cerrar");
    $this->actingAs($this->owner)->post("{$this->url}/cerrar")->assertSessionHasErrors('hour_bank');

    $renewed = HourBank::factory()->renewed()->create(['project_id' => $this->project->id]);
    $this->actingAs($this->owner)
        ->post("/proyectos/{$this->project->id}/bolsas/{$renewed->id}/cerrar")
        ->assertSessionHasErrors('hour_bank');
});

test('reabrir: solo un admin', function (string $role) {
    $this->bank->update(['status' => HourBankStatus::Closed, 'closed_at' => now(), 'closed_remaining_minutes' => 600]);

    $user = $role === 'gestor' ? $this->owner : userWithRole($role);

    $this->actingAs($user)->post("{$this->url}/reabrir")->assertForbidden();

    expect($this->bank->fresh()->status)->toBe(HourBankStatus::Closed);
})->with(['gestor', 'department_manager', 'employee']);

test('al reabrir vuelve a activa y se borra el cierre', function () {
    $this->actingAs($this->owner)->post("{$this->url}/cerrar");

    $this->actingAs(userWithRole('admin'))->post("{$this->url}/reabrir")->assertSessionHasNoErrors();

    expect($this->bank->fresh())
        ->status->toBe(HourBankStatus::Active)
        ->closed_at->toBeNull()
        ->closed_by->toBeNull()
        ->closed_remaining_minutes->toBeNull();
});

test('al reabrir una bolsa sin saldo queda agotada (lo decide HourBankLedger)', function () {
    TimeEntry::factory()->forTask($this->task)->minutes(10 * 60)->create();
    $this->actingAs($this->owner)->post("{$this->url}/cerrar");

    $this->actingAs(userWithRole('admin'))->post("{$this->url}/reabrir");

    expect($this->bank->fresh()->status)->toBe(HourBankStatus::Exhausted);
});

test('no se reabre una bolsa renovada ni una abierta', function () {
    $renewed = HourBank::factory()->renewed()->create(['project_id' => $this->project->id]);
    $admin = userWithRole('admin');

    $this->actingAs($admin)
        ->post("/proyectos/{$this->project->id}/bolsas/{$renewed->id}/reabrir")
        ->assertSessionHasErrors(['hour_bank' => 'Solo se puede reabrir una bolsa cerrada que no se haya renovado.']);

    $this->actingAs($admin)->post("{$this->url}/reabrir")->assertSessionHasErrors('hour_bank');

    expect($renewed->fresh()->status)->toBe(HourBankStatus::Renewed);
});

test('el cierre y la reapertura quedan en la auditoría de la bolsa', function () {
    $admin = userWithRole('admin');

    $this->actingAs($this->owner)->post("{$this->url}/cerrar");
    $this->actingAs($admin)->post("{$this->url}/reabrir");

    $this->assertDatabaseHas('activity_log', ['subject_id' => $this->bank->id, 'subject_type' => $this->bank->getMorphClass(), 'causer_id' => $this->owner->id, 'event' => 'updated']);
    $this->assertDatabaseHas('activity_log', ['subject_id' => $this->bank->id, 'subject_type' => $this->bank->getMorphClass(), 'causer_id' => $admin->id, 'event' => 'updated']);
});
