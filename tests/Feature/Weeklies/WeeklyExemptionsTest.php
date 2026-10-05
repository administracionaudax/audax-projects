<?php

use App\Domain\Weeklies\MyWeeklyStatus;
use App\Domain\Weeklies\WeeklyEligibility;
use App\Domain\Weeklies\WeeklyRoster;
use App\Enums\WeeklyExemptionReason;
use App\Models\Absence;
use App\Models\User;
use App\Models\WeeklyCycle;
use App\Models\WeeklyExemption;
use Carbon\CarbonImmutable;

/*
| Exenciones de la semana activa (10.2, D-151 y D-159; F-038, F-053, F-097 y F-098): eximir a mano
| (manage-weeklies), renunciar a la propia exención y quitarla. Semana del 05/10/2026.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00:00', 'Europe/Madrid'));
    $this->cycle = WeeklyCycle::factory()->active('2026-10-05')->create();
    $this->person = userWithRole('employee', ['created_at' => '2026-09-01 08:00:00']);
    $this->manager = userWithRole('department_manager', ['created_at' => '2026-09-01 08:00:00']);
});

function rosterOf(WeeklyCycle $cycle, User $user): WeeklyRoster
{
    return app(WeeklyEligibility::class)->rosterForUser($cycle, $user);
}

it('quien gestiona exime a mano a quien participa, con una nota (F-038)', function () {
    $this->actingAs($this->manager)
        ->post("/weeklies/{$this->cycle->id}/exenciones", ['user_id' => $this->person->id, 'note' => '  Formación toda la semana  '])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', __('weeklies.flash.exempted', ['name' => $this->person->name]));

    $row = WeeklyExemption::query()->sole();

    expect($row->reason)->toBe(WeeklyExemptionReason::Manual)
        ->and($row->note)->toBe('Formación toda la semana')
        ->and($row->created_by)->toBe($this->manager->id)
        ->and(rosterOf($this->cycle, $this->person)->isExempt($this->person->id))->toBeTrue()
        ->and(app(MyWeeklyStatus::class)->pendingCount($this->person))->toBe(0);
});

it('eximir sustituye una renuncia anterior; no se puede eximir a quien no participa', function () {
    WeeklyExemption::factory()->waived()->create(['weekly_cycle_id' => $this->cycle->id, 'user_id' => $this->person->id]);

    $this->actingAs($this->manager)->post("/weeklies/{$this->cycle->id}/exenciones", ['user_id' => $this->person->id]);

    expect(WeeklyExemption::query()->sole()->reason)->toBe(WeeklyExemptionReason::Manual);

    foreach ([User::factory()->collaborator()->create(), userWithRole('employee', ['is_active' => false]), userWithRole('employee', ['created_at' => '2026-10-12 08:00:00'])] as $outsider) {
        $this->actingAs($this->manager)
            ->postJson("/weeklies/{$this->cycle->id}/exenciones", ['user_id' => $outsider->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['user_id' => __('weeklies.validation.person')]);
    }

    $this->actingAs($this->manager)->postJson("/weeklies/{$this->cycle->id}/exenciones", ['user_id' => 999999])->assertUnprocessable();
    $this->actingAs($this->manager)->postJson("/weeklies/{$this->cycle->id}/exenciones", ['user_id' => $this->person->id, 'note' => str_repeat('a', 501)])->assertJsonValidationErrors(['note']);
});

it('eximir es de quien gestiona y solo con la semana activa', function () {
    $this->actingAs($this->person)->postJson("/weeklies/{$this->cycle->id}/exenciones", ['user_id' => $this->person->id])->assertForbidden();
    $this->actingAs(User::factory()->collaborator()->create())->postJson("/weeklies/{$this->cycle->id}/exenciones", ['user_id' => $this->person->id])->assertForbidden();

    $closed = WeeklyCycle::factory()->create();
    $this->actingAs($this->manager)->postJson("/weeklies/{$closed->id}/exenciones", ['user_id' => $this->person->id])->assertForbidden();
});

it('renunciar a la exención por ausencia deja una renuncia y permite escribir (F-053)', function () {
    $absence = Absence::factory()->approved()->between('2026-10-05', '2026-10-09')->create(['user_id' => $this->person->id]);

    expect(rosterOf($this->cycle, $this->person)->isExempt($this->person->id))->toBeTrue();

    $this->actingAs($this->person)
        ->post("/weeklies/{$this->cycle->id}/exenciones/renuncia")
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', __('weeklies.flash.waived'));

    $row = WeeklyExemption::query()->sole();

    expect($row->reason)->toBe(WeeklyExemptionReason::Waived)
        ->and($row->absence_id)->toBe($absence->id)
        ->and(rosterOf($this->cycle, $this->person)->mustSubmit($this->person->id))->toBeTrue()
        ->and(app(MyWeeklyStatus::class)->pendingCount($this->person))->toBe(1);
});

it('renunciar a una exención manual la borra; si además hay ausencia, queda la renuncia', function () {
    WeeklyExemption::factory()->create(['weekly_cycle_id' => $this->cycle->id, 'user_id' => $this->person->id]);

    $this->actingAs($this->person)->post("/weeklies/{$this->cycle->id}/exenciones/renuncia");

    expect(WeeklyExemption::query()->count())->toBe(0);

    $other = userWithRole('employee', ['created_at' => '2026-09-01 08:00:00']);
    Absence::factory()->approved()->between('2026-10-09', '2026-10-09')->create(['user_id' => $other->id]);
    WeeklyExemption::factory()->create(['weekly_cycle_id' => $this->cycle->id, 'user_id' => $other->id]);

    $this->actingAs($other)->post("/weeklies/{$this->cycle->id}/exenciones/renuncia");

    expect(WeeklyExemption::query()->sole()->reason)->toBe(WeeklyExemptionReason::Waived)
        ->and(rosterOf($this->cycle, $other)->mustSubmit($other->id))->toBeTrue();
});

it('renunciar sin estar exento es un error; con la semana cerrada, 403', function () {
    $this->actingAs($this->person)
        ->postJson("/weeklies/{$this->cycle->id}/exenciones/renuncia")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['exemption' => __('weeklies.validation.not_exempt')]);

    $closed = WeeklyCycle::factory()->create();
    $this->actingAs($this->person)->postJson("/weeklies/{$closed->id}/exenciones/renuncia")->assertForbidden();
    $this->actingAs(User::factory()->collaborator()->create())->postJson("/weeklies/{$this->cycle->id}/exenciones/renuncia")->assertForbidden();
});

it('deshacer la renuncia vuelve a eximir; la exención manual la quita quien gestiona o la persona', function () {
    Absence::factory()->approved()->between('2026-10-05', '2026-10-09')->create(['user_id' => $this->person->id]);
    $waiver = WeeklyExemption::factory()->waived()->create(['weekly_cycle_id' => $this->cycle->id, 'user_id' => $this->person->id]);

    $this->actingAs($this->person)
        ->delete("/weeklies/{$this->cycle->id}/exenciones/{$waiver->id}")
        ->assertInertiaFlash('toast.message', __('weeklies.flash.waiver_undone'));

    expect(rosterOf($this->cycle, $this->person)->reasonFor($this->person->id))->toBe(WeeklyExemptionReason::Absence);

    $other = userWithRole('employee', ['created_at' => '2026-09-01 08:00:00']);
    $manual = WeeklyExemption::factory()->create(['weekly_cycle_id' => $this->cycle->id, 'user_id' => $other->id]);

    $this->actingAs($this->person)->deleteJson("/weeklies/{$this->cycle->id}/exenciones/{$manual->id}")->assertForbidden();
    $this->actingAs($this->manager)
        ->delete("/weeklies/{$this->cycle->id}/exenciones/{$manual->id}")
        ->assertInertiaFlash('toast.message', __('weeklies.flash.exemption_removed'));

    expect(WeeklyExemption::query()->count())->toBe(0);
});

it('la foto de una ausencia de una semana cerrada no se quita (403)', function () {
    $closed = WeeklyCycle::factory()->create();
    $frozen = WeeklyExemption::factory()->absence()->create(['weekly_cycle_id' => $closed->id, 'user_id' => $this->person->id]);

    $this->actingAs(userWithRole('admin'))->deleteJson("/weeklies/{$closed->id}/exenciones/{$frozen->id}")->assertForbidden();
});

it('una ausencia aprobada después renueva el contador de esa persona al momento (F-098)', function () {
    expect(app(MyWeeklyStatus::class)->pendingCount($this->person))->toBe(1);

    $absence = Absence::factory()->approved()->between('2026-10-08', '2026-10-09')->create(['user_id' => $this->person->id]);

    expect(app(MyWeeklyStatus::class)->pendingCount($this->person))->toBe(0);

    $absence->delete();

    expect(app(MyWeeklyStatus::class)->pendingCount($this->person))->toBe(1);
});
