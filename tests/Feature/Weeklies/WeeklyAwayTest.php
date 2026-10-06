<?php

use App\Domain\Weeklies\MyWeeklyStatus;
use App\Domain\Weeklies\Reminders\WeeklyReminderRecipients;
use App\Domain\Weeklies\WeeklyAway;
use App\Domain\Weeklies\WeeklyEligibility;
use App\Domain\Weeklies\WeeklyRoster;
use App\Enums\AbsenceStatus;
use App\Enums\AbsenceType;
use App\Enums\WeeklyAwayReason;
use App\Enums\WeeklyExemptionReason;
use App\Models\Absence;
use App\Models\User;
use App\Models\WeeklyCycle;
use App\Models\WeeklyExemption;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

/*
| «Estoy fuera» de la Weekly (10.9b, D-228): el estado VACATION/ABSENT de WeeklySync, que uno mismo
| (o quien gestiona) pone con efecto inmediato. Exime de las semanas cuyo plazo cae antes de la
| vuelta y quita los recordatorios mientras dura, aunque se vuelva antes del plazo.
| Semana del 05/10/2026 (plazo el viernes 09/10); hoy, martes 06/10.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-06 10:00:00', 'Europe/Madrid'));
    $this->cycle = WeeklyCycle::factory()->active('2026-10-05')->create();
    $this->me = userWithRole('employee', ['created_at' => '2026-09-01 08:00:00']);
    $this->manager = userWithRole('department_manager', ['created_at' => '2026-09-01 08:00:00']);
});

function awayRoster(User $user): WeeklyRoster
{
    return app(WeeklyEligibility::class)->rosterForUser(test()->cycle, $user->refresh());
}

it('me marco fuera hasta después del plazo: quedo exento al momento, sin aprobación (P1)', function () {
    expect(app(MyWeeklyStatus::class)->pendingCount($this->me))->toBe(1);

    $this->actingAs($this->me)
        ->put("/equipo/{$this->me->id}/fuera", ['reason' => 'vacation', 'until' => '2026-10-16'])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', __('weeklies.flash.away'));

    $this->me->refresh();
    expect($this->me->weekly_away_reason)->toBe(WeeklyAwayReason::Vacation)
        ->and($this->me->weekly_away_since?->toDateString())->toBe('2026-10-06')
        ->and($this->me->weekly_away_until?->toDateString())->toBe('2026-10-16')
        ->and(awayRoster($this->me)->reasonFor($this->me->id))->toBe(WeeklyExemptionReason::Away)
        ->and(app(MyWeeklyStatus::class)->pendingCount($this->me))->toBe(0);

    $status = app(MyWeeklyStatus::class)->for($this->me, $this->cycle);
    expect($status['exemption_reason'])->toBe('away')
        ->and($status['exemption_until'])->toBe('2026-10-16')
        ->and($status['status'])->toBe('exempt');

    // La semana siguiente (plazo el 16/10) también queda cubierta; la otra (23/10) no.
    $away = new WeeklyAway;
    expect($away->coveringDeadline([$this->me->id], CarbonImmutable::parse('2026-10-16')))->toBe([$this->me->id => true])
        ->and($away->coveringDeadline([$this->me->id], CarbonImmutable::parse('2026-10-23')))->toBe([]);
});

it('sin fecha de vuelta, dura hasta que vuelvo a estar disponible', function () {
    $this->actingAs($this->me)->put("/equipo/{$this->me->id}/fuera", ['reason' => 'absent'])->assertSessionHasNoErrors();

    expect(awayRoster($this->me)->isExempt($this->me->id))->toBeTrue()
        ->and(WeeklyAway::of($this->me->refresh()))->toBe(['reason' => 'absent', 'since' => '2026-10-06', 'until' => null]);

    $this->actingAs($this->me)
        ->delete("/equipo/{$this->me->id}/fuera")
        ->assertInertiaFlash('toast.message', __('weeklies.flash.available'));

    expect(awayRoster($this->me)->isExempt($this->me->id))->toBeFalse()
        ->and($this->me->refresh()->weekly_away_reason)->toBeNull()
        ->and(app(MyWeeklyStatus::class)->pendingCount($this->me))->toBe(1);
});

it('si vuelvo antes del plazo no quedo exento, pero no recibo recordatorios mientras estoy fuera', function () {
    $this->actingAs($this->me)->put("/equipo/{$this->me->id}/fuera", ['reason' => 'vacation', 'until' => '2026-10-07'])->assertSessionHasNoErrors();

    expect(awayRoster($this->me)->isExempt($this->me->id))->toBeFalse();

    $recipients = app(WeeklyReminderRecipients::class);
    expect($recipients->pending($this->cycle)->pluck('id')->all())->not->toContain($this->me->id);

    // El jueves ya ha vuelto: vuelven los recordatorios.
    $this->travelTo(CarbonImmutable::parse('2026-10-08 10:00:00', 'Europe/Madrid'));
    expect($recipients->pending($this->cycle)->pluck('id')->all())->toContain($this->me->id)
        ->and(WeeklyAway::of($this->me->refresh()))->toBeNull();
});

it('una ausencia aprobada que cubre hoy (y no el plazo) también quita los recordatorios de hoy', function () {
    Absence::factory()->for($this->me)->approved()->between('2026-10-05', '2026-10-07')->create();
    $partial = userWithRole('employee', ['created_at' => '2026-09-01 08:00:00']);
    Absence::factory()->for($partial)->approved()->between('2026-10-06', '2026-10-06')->create(['partial_minutes' => 120]);

    $ids = app(WeeklyReminderRecipients::class)->pending($this->cycle)->pluck('id')->all();

    expect($ids)->not->toContain($this->me->id)->toContain($partial->id)
        ->and(awayRoster($this->me)->isExempt($this->me->id))->toBeFalse();
});

it('quien renuncia a su exención para escribir sí recibe los recordatorios', function () {
    $this->actingAs($this->me)->put("/equipo/{$this->me->id}/fuera", ['reason' => 'vacation', 'until' => '2026-10-16']);
    $this->actingAs($this->me)->post("/weeklies/{$this->cycle->id}/exenciones/renuncia")->assertSessionHasNoErrors();

    expect(WeeklyExemption::query()->sole()->reason)->toBe(WeeklyExemptionReason::Waived)
        ->and(awayRoster($this->me)->mustSubmit($this->me->id))->toBeTrue()
        ->and(app(WeeklyReminderRecipients::class)->pending($this->cycle)->pluck('id')->all())->toContain($this->me->id);
});

it('no exime de una semana cuyo plazo ya había pasado al marcarse', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 10:00:00', 'Europe/Madrid'));
    $this->actingAs($this->me)->put("/equipo/{$this->me->id}/fuera", ['reason' => 'vacation', 'until' => '2026-10-20'])->assertSessionHasNoErrors();

    expect(awayRoster($this->me)->isExempt($this->me->id))->toBeFalse();
});

it('puedo pedir a la vez la ausencia, que sigue su aprobación; hace falta la vuelta', function () {
    $this->actingAs($this->me)
        ->putJson("/equipo/{$this->me->id}/fuera", ['reason' => 'vacation', 'request_absence' => true])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['request_absence' => __('weeklies.validation.away_absence_needs_until')]);

    $this->actingAs($this->me)
        ->put("/equipo/{$this->me->id}/fuera", ['reason' => 'vacation', 'until' => '2026-10-16', 'request_absence' => true])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', __('weeklies.flash.away_with_absence'));

    $absence = Absence::query()->sole();
    expect($absence->user_id)->toBe($this->me->id)
        ->and($absence->status)->toBe(AbsenceStatus::Requested)
        ->and($absence->type)->toBe(AbsenceType::Vacation)
        ->and($absence->start_date->toDateString())->toBe('2026-10-06')
        ->and($absence->end_date->toDateString())->toBe('2026-10-16')
        ->and(awayRoster($this->me)->reasonFor($this->me->id))->toBe(WeeklyExemptionReason::Away);
});

it('valida el motivo y que la vuelta no sea pasada', function () {
    $this->actingAs($this->me)
        ->putJson("/equipo/{$this->me->id}/fuera", ['reason' => 'otra', 'until' => '2026-10-05'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['reason', 'until']);

    expect($this->me->refresh()->weekly_away_reason)->toBeNull();
});

it('quien gestiona marca a otra persona fuera varias semanas; la plantilla no puede con otros', function () {
    $this->actingAs($this->manager)
        ->put("/equipo/{$this->me->id}/fuera", ['reason' => 'absent', 'until' => '2026-10-23'])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', __('weeklies.flash.away_other', ['name' => $this->me->name]));

    expect(awayRoster($this->me)->reasonFor($this->me->id))->toBe(WeeklyExemptionReason::Away);

    $other = userWithRole('employee');
    $this->actingAs($other)->put("/equipo/{$this->me->id}/fuera", ['reason' => 'vacation'])->assertForbidden();
    $this->actingAs($other)->delete("/equipo/{$this->me->id}/fuera")->assertForbidden();
    $this->actingAs(User::factory()->collaborator()->create())->put("/equipo/{$this->me->id}/fuera", ['reason' => 'vacation'])->assertForbidden();

    // Quien gestiona no puede marcar a un colaborador externo ni a alguien de baja.
    $this->actingAs($this->manager)->put('/equipo/'.User::factory()->collaborator()->create()->id.'/fuera', ['reason' => 'vacation'])->assertNotFound();
    $this->actingAs($this->manager)->put('/equipo/'.userWithRole('employee', ['is_active' => false])->id.'/fuera', ['reason' => 'vacation'])->assertNotFound();

    $this->actingAs($this->manager)->delete("/equipo/{$this->me->id}/fuera")->assertSessionHasNoErrors();
    expect($this->me->refresh()->weekly_away_reason)->toBeNull();
});

it('al cerrar la semana se congela la exención por estar fuera', function () {
    $this->actingAs($this->me)->put("/equipo/{$this->me->id}/fuera", ['reason' => 'vacation', 'until' => '2026-10-16']);

    app(WeeklyEligibility::class)->freeze($this->cycle);

    $row = WeeklyExemption::query()->where('user_id', $this->me->id)->sole();
    expect($row->reason)->toBe(WeeklyExemptionReason::Away)
        ->and($row->absence_id)->toBeNull()
        ->and($this->cycle->refresh()->expected_user_ids)->not->toContain($this->me->id);
});

it('mi estado va en las props compartidas para la insignia del avatar', function () {
    $this->actingAs($this->me)->put("/equipo/{$this->me->id}/fuera", ['reason' => 'vacation', 'until' => '2026-10-16']);

    $this->actingAs($this->me->refresh())
        ->get('/mi-espacio')
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.weekly_away', ['reason' => 'vacation', 'since' => '2026-10-06', 'until' => '2026-10-16']));
});
