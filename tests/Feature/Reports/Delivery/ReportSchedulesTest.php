<?php

use App\Domain\Reports\Delivery\DeliveryAudit;
use App\Domain\Reports\Delivery\PauseReason;
use App\Domain\Reports\Delivery\RelativePeriod;
use App\Domain\Reports\Delivery\ScheduleFrequency;
use App\Domain\Reports\Delivery\Testing\FakeReportFileGenerator;
use App\Jobs\SendReportDelivery;
use App\Models\ReportDelivery;
use App\Models\ReportSchedule;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Spatie\Activitylog\Models\Activity;

/*
| Envíos programados (/informes/envios, D-141): cada persona los suyos; el admin, todos. Crear,
| editar, pausar, reanudar, «Enviar ahora» y borrar.
*/

beforeEach(function () {
    FakeReportFileGenerator::install();
    $this->admin = User::factory()->admin()->create();
    $this->employee = User::factory()->employee()->create();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function schedulePayload(User $owner, array $overrides = []): array
{
    return array_replace([
        'request' => ['kind' => 'person', 'route_params' => ['user' => $owner->id], 'query' => ['periodo' => 'mes']],
        'title' => 'Mi informe',
        'formats' => ['pdf', 'xlsx'],
        'recipient_user_ids' => [],
        'recipient_emails' => ['cliente@example.com'],
        'subject' => 'Informe del mes',
        'message' => null,
        'relative_period' => 'previous',
        'frequency' => 'monthly',
        'run_date' => null,
        'weekday' => null,
        'month_day' => 1,
        'time' => '08:00',
    ], $overrides);
}

it('crea un envío mensual con su próximo envío en UTC y lo deja en la auditoría', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-03 10:00', 'UTC'));

    $this->actingAs($this->employee)
        ->post('/informes/envios', schedulePayload($this->employee))
        ->assertRedirect()
        ->assertInertiaFlash('toast.type', 'success');

    $schedule = ReportSchedule::query()->sole();

    expect($schedule->owner_user_id)->toBe($this->employee->id)
        ->and($schedule->frequency)->toBe(ScheduleFrequency::Monthly)
        ->and($schedule->relative_period)->toBe(RelativePeriod::Previous)
        ->and($schedule->formats)->toBe(['pdf', 'xlsx'])
        ->and($schedule->month_day)->toBe(1)
        ->and($schedule->weekday)->toBeNull()
        ->and($schedule->is_active)->toBeTrue()
        ->and($schedule->next_run_at?->toIso8601ZuluString())->toBe('2026-11-01T07:00:00Z');

    expect(Activity::query()->where('log_name', DeliveryAudit::LOG)->sole()->event)->toBe('schedule_created');
});

it('valida la frecuencia: día de la semana, día del mes (1-28 o último) y fecha futura de «una vez»', function (array $overrides, string $field) {
    $this->travelTo(CarbonImmutable::parse('2026-10-03 10:00', 'UTC'));

    $this->actingAs($this->employee)
        ->post('/informes/envios', schedulePayload($this->employee, $overrides))
        ->assertSessionHasErrors($field);

    expect(ReportSchedule::query()->count())->toBe(0);
})->with([
    'semanal sin día' => [['frequency' => 'weekly', 'weekday' => null], 'weekday'],
    'semanal día 8' => [['frequency' => 'weekly', 'weekday' => 8], 'weekday'],
    'mensual día 29' => [['month_day' => 29], 'month_day'],
    'hora mal escrita' => [['time' => '8h'], 'time'],
    'una vez sin fecha' => [['frequency' => 'once', 'run_date' => null], 'run_date'],
    'una vez en el pasado' => [['frequency' => 'once', 'run_date' => '2026-10-03', 'time' => '11:00'], 'run_date'],
    'periodo desconocido' => [['relative_period' => 'siempre'], 'relative_period'],
    'sin destinatarios' => [['recipient_emails' => []], 'recipients'],
]);

it('«una vez» guarda su fecha y hora de Madrid como instante UTC', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-03 10:00', 'UTC'));

    $this->actingAs($this->employee)
        ->post('/informes/envios', schedulePayload($this->employee, ['frequency' => 'once', 'run_date' => '2026-10-03', 'time' => '13:00', 'month_day' => 5]))
        ->assertSessionHasNoErrors();

    $schedule = ReportSchedule::query()->sole();
    expect($schedule->run_at?->toIso8601ZuluString())->toBe('2026-10-03T11:00:00Z')
        ->and($schedule->next_run_at?->toIso8601ZuluString())->toBe('2026-10-03T11:00:00Z')
        ->and($schedule->month_day)->toBeNull();
});

it('no deja programar un informe que no ve (403)', function () {
    $this->actingAs($this->employee)
        ->post('/informes/envios', schedulePayload($this->employee, ['request' => ['kind' => 'direction', 'route_params' => [], 'query' => []]]))
        ->assertForbidden();

    expect(ReportSchedule::query()->count())->toBe(0);
});

it('la lista muestra los míos; el admin ve todos', function () {
    $mine = ReportSchedule::factory()->future()->create(['owner_user_id' => $this->employee->id, 'title' => 'El mío']);
    ReportSchedule::factory()->future()->create(['owner_user_id' => $this->admin->id, 'title' => 'El del admin']);

    $this->actingAs($this->employee)->get('/informes/envios')->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('reports/schedules/index')
            ->has('schedules', 1)
            ->where('schedules.0.id', $mine->id)
            ->where('schedules.0.title', 'El mío')
            ->where('schedules.0.recipient_count', 1)
            ->where('schedules.0.external_count', 1)
            ->where('sees_all', false)
            ->has('new_reports', 2));

    $this->actingAs($this->admin)->get('/informes/envios')->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('schedules', 2)
            ->where('sees_all', true)
            ->has('new_reports', 3));
});

it('el detalle tiene el historial; solo lo ven el propietario y los admins', function () {
    $schedule = ReportSchedule::factory()->future()->create(['owner_user_id' => $this->employee->id]);
    ReportDelivery::query()->create([
        'schedule_id' => $schedule->id,
        'sender_user_id' => $this->employee->id,
        'title' => 'Informe',
        'request' => $schedule->request,
        'formats' => ['pdf'],
        'recipient_user_ids' => [],
        'recipient_emails' => ['cliente@example.com'],
        'status' => 'failed',
        'error' => 'Gotenberg no responde',
    ]);

    $this->actingAs($this->employee)->get("/informes/envios/{$schedule->id}")->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('reports/schedules/show')
            ->where('schedule.id', $schedule->id)
            ->where('schedule.last_status', 'failed')
            ->has('schedule.deliveries', 1)
            ->where('schedule.deliveries.0.error', 'Gotenberg no responde')
            ->where('schedule.recipient_emails', ['cliente@example.com']));

    $this->actingAs($this->admin)->get("/informes/envios/{$schedule->id}")->assertOk();

    $other = User::factory()->employee()->create();
    $this->actingAs($other)->get("/informes/envios/{$schedule->id}")->assertForbidden();
    $this->actingAs($other)->put("/informes/envios/{$schedule->id}", schedulePayload($other))->assertForbidden();
    $this->actingAs($other)->post("/informes/envios/{$schedule->id}/pausar")->assertForbidden();
    $this->actingAs($other)->post("/informes/envios/{$schedule->id}/enviar-ahora")->assertForbidden();
    $this->actingAs($other)->delete("/informes/envios/{$schedule->id}")->assertForbidden();
});

it('colaboradores externos y clientes no llegan a los envíos', function () {
    $schedule = ReportSchedule::factory()->future()->create(['owner_user_id' => $this->admin->id]);
    $collaborator = User::factory()->collaborator()->create();

    $this->actingAs($collaborator)->get('/informes/envios')->assertForbidden();
    $this->actingAs($collaborator)->get("/informes/envios/{$schedule->id}")->assertForbidden();
    $this->actingAs($collaborator)->post('/informes/envios', schedulePayload($collaborator))->assertForbidden();

    $this->actingAs(User::factory()->client()->create())->get('/informes/envios')->assertRedirect('/portal');
});

it('edita el envío y recalcula el próximo', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-03 10:00', 'UTC'));
    $schedule = ReportSchedule::factory()->future()->create([
        'owner_user_id' => $this->employee->id,
        'request' => ['kind' => 'person', 'route_params' => ['user' => $this->employee->id], 'query' => ['periodo' => 'mes']],
    ]);

    $this->actingAs($this->employee)
        ->put("/informes/envios/{$schedule->id}", schedulePayload($this->employee, ['frequency' => 'weekly', 'weekday' => 1, 'time' => '09:30', 'relative_period' => 'current']))
        ->assertSessionHasNoErrors();

    $schedule->refresh();
    expect($schedule->frequency)->toBe(ScheduleFrequency::Weekly)
        ->and($schedule->weekday)->toBe(1)
        ->and($schedule->month_day)->toBeNull()
        ->and($schedule->relative_period)->toBe(RelativePeriod::Current)
        ->and($schedule->next_run_at?->toIso8601ZuluString())->toBe('2026-10-05T07:30:00Z');

    expect(Activity::query()->where('event', 'schedule_updated')->count())->toBe(1);
});

it('pausa y reanuda; al reanudar comprueba que el propietario aún ve el informe', function () {
    $schedule = ReportSchedule::factory()->future()->create(['owner_user_id' => $this->admin->id]);

    $this->actingAs($this->admin)->post("/informes/envios/{$schedule->id}/pausar")->assertRedirect();
    expect($schedule->refresh()->is_active)->toBeFalse()
        ->and($schedule->paused_reason)->toBe(PauseReason::Manual)
        ->and($schedule->next_run_at)->toBeNull();

    $this->actingAs($this->admin)->post("/informes/envios/{$schedule->id}/reanudar")->assertRedirect()->assertInertiaFlash('toast.type', 'success');
    expect($schedule->refresh()->is_active)->toBeTrue()
        ->and($schedule->paused_reason)->toBeNull()
        ->and($schedule->next_run_at)->not->toBeNull();

    // Un responsable que deja de dirigir su departamento ya no ve el de dirección.
    $lost = ReportSchedule::factory()->create([
        'owner_user_id' => $this->employee->id,
        'request' => ['kind' => 'direction', 'route_params' => [], 'query' => []],
        'is_active' => false,
        'paused_reason' => PauseReason::NoAccess,
        'next_run_at' => null,
    ]);

    $this->actingAs($this->admin)->post("/informes/envios/{$lost->id}/reanudar")->assertRedirect()->assertInertiaFlash('toast.type', 'error');
    expect($lost->refresh()->is_active)->toBeFalse();

    expect(Activity::query()->where('event', 'schedule_paused')->count())->toBe(1)
        ->and(Activity::query()->where('event', 'schedule_resumed')->count())->toBe(1);
});

it('«Enviar ahora» encola un envío sin mover el próximo', function () {
    Queue::fake();
    $schedule = ReportSchedule::factory()->future()->create(['owner_user_id' => $this->admin->id]);
    $next = $schedule->next_run_at?->toIso8601ZuluString();

    $this->actingAs($this->admin)->post("/informes/envios/{$schedule->id}/enviar-ahora")
        ->assertRedirect()
        ->assertInertiaFlash('toast.type', 'success');

    Queue::assertPushedOn('mail', SendReportDelivery::class);
    expect(ReportDelivery::query()->sole()->schedule_id)->toBe($schedule->id)
        ->and($schedule->refresh()->next_run_at?->toIso8601ZuluString())->toBe($next)
        ->and($schedule->last_run_at)->not->toBeNull();
});

it('borra el envío; su historial queda sin programación', function () {
    $schedule = ReportSchedule::factory()->future()->create(['owner_user_id' => $this->employee->id]);
    $delivery = ReportDelivery::query()->create([
        'schedule_id' => $schedule->id,
        'sender_user_id' => $this->employee->id,
        'title' => 'Informe',
        'request' => $schedule->request,
        'formats' => ['pdf'],
        'recipient_user_ids' => [],
        'recipient_emails' => ['cliente@example.com'],
        'status' => 'sent',
    ]);

    $this->actingAs($this->employee)->delete("/informes/envios/{$schedule->id}")->assertRedirect('/informes/envios');

    expect(ReportSchedule::query()->count())->toBe(0)
        ->and($delivery->refresh()->schedule_id)->toBeNull()
        ->and(Activity::query()->where('event', 'schedule_deleted')->count())->toBe(1);
});
