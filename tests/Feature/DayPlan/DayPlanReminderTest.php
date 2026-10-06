<?php

use App\Enums\AbsenceStatus;
use App\Enums\AbsenceType;
use App\Enums\WeeklyAwayReason;
use App\Models\Absence;
use App\Models\DayPlan;
use App\Models\DayPlanItem;
use App\Models\Department;
use App\Models\Holiday;
use App\Models\Setting;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Notifications\DayPlan\DayPlanReminder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

/*
| Recordatorio del plan del día (docs/PLAN-CARGAS.md §9 y §15, P2: a las 8:30; D-252): una vez por
| persona y día local de Madrid, solo en sus días con jornada, con sus preferencias y nada en modo de
| prueba. El comando corre cada 5 minutos.
*/

beforeEach(function () {
    Notification::fake();
    $this->ana = userWithRole('employee', ['name' => 'Ana']);
});

function remindAt(string $local): void
{
    test()->travelTo(CarbonImmutable::parse($local, 'Europe/Madrid'));
    test()->artisan('day-plan:remind')->assertSuccessful();
}

it('a las 8:30 avisa a quien no ha escrito su plan, una sola vez aunque el comando se repita', function () {
    $bea = userWithRole('employee', ['name' => 'Bea']);
    DayPlanItem::factory()->create(['user_id' => $bea->id, 'date' => '2026-10-07']);

    remindAt('2026-10-07 08:25:00');
    Notification::assertNothingSent();

    remindAt('2026-10-07 08:30:00');
    remindAt('2026-10-07 08:35:00');
    remindAt('2026-10-07 10:00:00');

    Notification::assertSentToTimes($this->ana, DayPlanReminder::class, 1);
    Notification::assertNotSentTo($bea, DayPlanReminder::class);
    expect(DayPlan::query()->where('user_id', $this->ana->id)->value('reminded_at'))->not->toBeNull();

    // Al día siguiente, otra vez.
    remindAt('2026-10-08 08:40:00');
    Notification::assertSentToTimes($this->ana, DayPlanReminder::class, 2);
});

it('fuera de la ventana de la mañana no avisa (servidor parado hasta la tarde)', function () {
    remindAt('2026-10-07 11:31:00');
    remindAt('2026-10-07 17:00:00');

    Notification::assertNothingSent();
});

it('con el cambio de hora sale a las 8:30 de Madrid y una sola vez', function () {
    // Domingo 25/10/2026: a las 03:00 los relojes vuelven a las 02:00 (CEST → CET). Ana trabaja los domingos.
    WorkSchedule::query()->create(['user_id' => $this->ana->id, 'valid_from' => '2026-01-01', 'mon_minutes' => 480, 'tue_minutes' => 480, 'wed_minutes' => 480, 'thu_minutes' => 480, 'fri_minutes' => 480, 'sat_minutes' => 480, 'sun_minutes' => 480]);

    // Sábado (CEST, UTC+2): las 06:30 UTC son las 08:30.
    $this->travelTo(CarbonImmutable::parse('2026-10-24 06:30:00', 'UTC'));
    $this->artisan('day-plan:remind');
    Notification::assertSentToTimes($this->ana, DayPlanReminder::class, 1);

    // Domingo (CET, UTC+1): las 06:30 UTC son las 07:30, aún no; a las 07:30 UTC, sí, y una vez.
    foreach (['2026-10-25 06:30:00', '2026-10-25 07:25:00'] as $utc) {
        $this->travelTo(CarbonImmutable::parse($utc, 'UTC'));
        $this->artisan('day-plan:remind');
    }
    Notification::assertSentToTimes($this->ana, DayPlanReminder::class, 1);

    foreach (['2026-10-25 07:30:00', '2026-10-25 07:35:00', '2026-10-25 08:00:00'] as $utc) {
        $this->travelTo(CarbonImmutable::parse($utc, 'UTC'));
        $this->artisan('day-plan:remind');
    }
    Notification::assertSentToTimes($this->ana, DayPlanReminder::class, 2);
    Notification::assertSentTo($this->ana, DayPlanReminder::class, fn (DayPlanReminder $n) => $n->date === '2026-10-25');
});

it('nada en días sin jornada: fin de semana, festivo, ausencia de día completo o «Estoy fuera»', function () {
    $part = userWithRole('employee', ['name' => 'Media jornada']);
    $away = userWithRole('employee', ['name' => 'Fuera']);
    $sick = userWithRole('employee', ['name' => 'Baja']);
    $partial = userWithRole('employee', ['name' => 'Médico']);
    WorkSchedule::query()->create(['user_id' => $part->id, 'valid_from' => '2026-01-01', 'mon_minutes' => 240, 'tue_minutes' => 240, 'wed_minutes' => 0, 'thu_minutes' => 240, 'fri_minutes' => 240]);
    $away->forceFill(['weekly_away_reason' => WeeklyAwayReason::Vacation, 'weekly_away_since' => '2026-10-05', 'weekly_away_until' => '2026-10-09'])->save();
    Absence::factory()->create(['user_id' => $sick->id, 'type' => AbsenceType::Sick, 'status' => AbsenceStatus::Approved, 'start_date' => '2026-10-07', 'end_date' => '2026-10-07']);
    Absence::factory()->create(['user_id' => $partial->id, 'type' => AbsenceType::Leave, 'status' => AbsenceStatus::Approved, 'start_date' => '2026-10-07', 'end_date' => '2026-10-07', 'partial_minutes' => 120]);
    // Una ausencia solo solicitada no quita el recordatorio.
    $requested = userWithRole('employee', ['name' => 'Solicitada']);
    Absence::factory()->create(['user_id' => $requested->id, 'status' => AbsenceStatus::Requested, 'start_date' => '2026-10-07', 'end_date' => '2026-10-07']);

    remindAt('2026-10-07 08:30:00');

    Notification::assertSentTo($this->ana, DayPlanReminder::class);
    Notification::assertSentTo($partial, DayPlanReminder::class);
    Notification::assertSentTo($requested, DayPlanReminder::class);
    Notification::assertNotSentTo($part, DayPlanReminder::class);
    Notification::assertNotSentTo($away, DayPlanReminder::class);
    Notification::assertNotSentTo($sick, DayPlanReminder::class);

    // Sábado: nadie (jornada por defecto de lunes a viernes).
    remindAt('2026-10-10 08:30:00');
    Notification::assertSentToTimes($this->ana, DayPlanReminder::class, 1);

    // Festivo nacional (12 de octubre): nadie.
    Holiday::query()->create(['date' => '2026-10-12', 'name' => 'Fiesta Nacional de España']);
    remindAt('2026-10-12 08:30:00');
    Notification::assertSentToTimes($this->ana, DayPlanReminder::class, 1);
});

it('a la plantilla sí; a los colaboradores externos, inactivos y clientes, no', function () {
    $collaborator = User::factory()->collaborator()->create();
    $inactive = userWithRole('employee', ['is_active' => false]);
    $client = userWithRole('client');
    $manager = userWithRole('department_manager');
    $admin = userWithRole('admin');

    remindAt('2026-10-07 08:30:00');

    Notification::assertSentTo([$this->ana, $manager, $admin], DayPlanReminder::class);
    Notification::assertNotSentTo([$collaborator, $inactive, $client], DayPlanReminder::class);
});

it('respeta las preferencias: canales por defecto (app y navegador) y quien lo apaga', function () {
    $bea = userWithRole('employee');
    $bea->forceFill(['notification_preferences' => ['events' => ['day_plan.reminder' => ['app' => false, 'push' => false]], 'daily_digest' => false]])->save();

    remindAt('2026-10-07 08:30:00');

    Notification::assertSentTo($this->ana, DayPlanReminder::class, fn (DayPlanReminder $n, array $channels) => in_array('database', $channels, true) && ! in_array('mail', $channels, true));
    Notification::assertNotSentTo($bea, DayPlanReminder::class);
    // Sin canal no se reclama: si lo vuelve a activar esa mañana, le llega.
    expect(DayPlan::query()->where('user_id', $bea->id)->whereNotNull('reminded_at')->exists())->toBeFalse();
});

it('con la hora límite cambiada, sale a esa hora', function () {
    Setting::set('day_plan_deadline', '09:15');

    remindAt('2026-10-07 08:30:00');
    Notification::assertNothingSent();

    remindAt('2026-10-07 09:15:00');
    Notification::assertSentTo($this->ana, DayPlanReminder::class);
});

it('apagado en los ajustes, con el módulo apagado o en modo de prueba no sale', function () {
    Setting::set('day_plan_reminder_enabled', false);
    remindAt('2026-10-07 08:30:00');

    Setting::set('day_plan_reminder_enabled', true);
    Setting::set('modules', ['day_plan' => false]);
    Setting::set('modules_preview', true);
    remindAt('2026-10-07 08:35:00');

    Notification::assertNothingSent();
});

it('«Recordar» desde Equipo hoy: su responsable, una vez al día y nunca en modo de prueba', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-07 08:00:00', 'Europe/Madrid'));
    $department = Department::factory()->create();
    $manager = userWithRole('department_manager', ['name' => 'Raúl', 'department_id' => $department->id]);
    $department->managers()->attach($manager->id);
    $this->ana->forceFill(['department_id' => $department->id])->save();

    $this->actingAs(userWithRole('employee'))->post("/dia/equipo/{$this->ana->id}/recordar")->assertForbidden();

    $this->actingAs($manager)->post("/dia/equipo/{$this->ana->id}/recordar")
        ->assertInertiaFlash('toast.message', 'Se le ha recordado a Ana que escriba su plan.');
    Notification::assertSentTo($this->ana, DayPlanReminder::class, fn (DayPlanReminder $n) => $n->by === 'Raúl');

    $this->actingAs($manager)->post("/dia/equipo/{$this->ana->id}/recordar")
        ->assertInertiaFlash('toast.message', 'A Ana ya se le ha recordado hoy.');

    // El automático de las 8:30 ya no sale ese día.
    remindAt('2026-10-07 08:30:00');
    Notification::assertSentToTimes($this->ana, DayPlanReminder::class, 1);

    // Modo de prueba: nadie recibe nada.
    $admin = userWithRole('admin');
    $bea = userWithRole('employee');
    Setting::set('modules', ['day_plan' => false]);
    Setting::set('modules_preview', true);
    $this->actingAs($admin)->post("/dia/equipo/{$bea->id}/recordar")
        ->assertInertiaFlash('toast.message', 'Modo de prueba: no se avisa a nadie.');
    Notification::assertNotSentTo($bea, DayPlanReminder::class);
});
