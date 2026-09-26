<?php

use App\Domain\HourBanks\Events\HourBankOverageRecorded;
use App\Domain\HourBanks\Events\HourBankThresholdReached;
use App\Domain\HourBanks\HourBankRenewal;
use App\Enums\HourBankStatus;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Borrar una bolsa (solo admin y sin horas, HourBankPolicy::delete) sin romper el histórico de
| renovaciones (SPEC §8.8): una bolsa ya renovada no se borra; borrar la renovación de otra la
| deshace y la anterior vuelve a estar activa o agotada según su consumo.
*/

beforeEach(function () {
    Event::fake([HourBankThresholdReached::class, HourBankOverageRecorded::class]);

    $this->admin = userWithRole('admin');
    $this->project = Project::factory()->hourBank()->create();
    $this->banksUrl = "/proyectos/{$this->project->id}/bolsas";

    // Una bolsa con horas (al :pct %) renovada sin mover tareas.
    $this->renewed = function (int $consumedMinutes): array {
        $previous = HourBank::factory()->hours(10)->create(['project_id' => $this->project->id, 'name' => 'Bolsa T3']);
        TimeEntry::factory()->forTask(Task::factory()->inBank($previous)->create())->minutes($consumedMinutes)->create();

        $renewal = app(HourBankRenewal::class)->renew($previous, ['name' => 'Bolsa T4'], false)['bank'];

        return [$previous->fresh(), $renewal];
    };
});

test('borrar la renovación de una bolsa la deshace: la anterior vuelve a estar activa', function () {
    [$previous, $renewal] = ($this->renewed)(8 * 60);
    expect($previous->status)->toBe(HourBankStatus::Renewed);

    $this->actingAs($this->admin)
        ->delete("{$this->banksUrl}/{$renewal->id}")
        ->assertRedirect(route('projects.hour-banks.index', $this->project))
        ->assertInertiaFlash('toast.message', 'Bolsa «Bolsa T4» eliminada. «Bolsa T3» deja de estar renovada y vuelve a estar activa.');

    expect($renewal->fresh()->trashed())->toBeTrue()
        ->and($previous->fresh())
        ->status->toBe(HourBankStatus::Active)
        ->consumed_minutes->toBe(480);
});

test('si la anterior ya no tenía saldo, vuelve como agotada', function () {
    [$previous, $renewal] = ($this->renewed)(11 * 60);

    $this->actingAs($this->admin)->delete("{$this->banksUrl}/{$renewal->id}")->assertSessionHasNoErrors();

    expect($previous->fresh())
        ->status->toBe(HourBankStatus::Exhausted)
        ->overage_minutes->toBe(60);
});

test('una bolsa ya renovada no se borra: su renovación quedaría sin la anterior', function () {
    [$previous, $renewal] = ($this->renewed)(10 * 60);
    // Sin horas propias: la política lo permitiría.
    TimeEntry::query()->where('hour_bank_id', $previous->id)->delete();
    Task::query()->where('hour_bank_id', $previous->id)->forceDelete();

    $this->actingAs($this->admin)
        ->delete("{$this->banksUrl}/{$previous->id}")
        ->assertSessionHasErrors(['hour_bank' => 'La bolsa ya se ha renovado: no se puede eliminar sin romper el histórico. Si hace falta, elimina antes la bolsa que la renueva.']);

    expect($previous->fresh()->trashed())->toBeFalse()
        ->and($renewal->fresh()->renewed_from_id)->toBe($previous->id);
});

test('la renovación con tareas movidas no se borra', function () {
    [$previous, $renewal] = ($this->renewed)(8 * 60);
    Task::factory()->inBank($renewal)->create();

    $this->actingAs($this->admin)
        ->delete("{$this->banksUrl}/{$renewal->id}")
        ->assertSessionHasErrors('hour_bank');

    expect($renewal->fresh()->trashed())->toBeFalse()
        ->and($previous->fresh()->status)->toBe(HourBankStatus::Renewed);
});

test('las tarjetas no ofrecen borrar una bolsa renovada, sí su renovación vacía', function () {
    [$previous, $renewal] = ($this->renewed)(8 * 60);
    TimeEntry::query()->where('hour_bank_id', $previous->id)->delete();
    Task::query()->where('hour_bank_id', $previous->id)->forceDelete();

    $this->actingAs($this->admin)
        ->get("{$this->banksUrl}?todas=1")
        ->assertInertia(function (Assert $page) use ($previous, $renewal) {
            $banks = collect($page->toArray()['props']['banks'])->keyBy('id');

            expect($banks[$previous->id]['can']['delete'])->toBeFalse()
                ->and($banks[$renewal->id]['can']['delete'])->toBeTrue();
        });

    $this->actingAs($this->admin)
        ->get("{$this->banksUrl}/{$previous->id}")
        ->assertInertia(fn (Assert $page) => $page->where('bank.can.delete', false));
});
