<?php

use App\Domain\Reports\Delivery\DeliveryAudit;
use App\Domain\Reports\Delivery\DeliveryStatus;
use App\Domain\Reports\Delivery\PauseReason;
use App\Domain\Reports\Delivery\RelativePeriod;
use App\Domain\Reports\Delivery\ScheduleFrequency;
use App\Domain\Reports\Delivery\Testing\FakeReportFileGenerator;
use App\Jobs\SendReportDelivery;
use App\Mail\ReportDeliveryMail;
use App\Models\ReportDelivery;
use App\Models\ReportSchedule;
use App\Models\User;
use App\Notifications\Reports\ReportSchedulePausedNotification;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;

/*
| reports:send-scheduled (D-141): cada 5 minutos encola los envíos programados que ya tocan, con
| los permisos ACTUALES de quien los programó y el periodo relativo resuelto el día del envío.
*/

beforeEach(function () {
    $this->fake = FakeReportFileGenerator::install();
    $this->admin = User::factory()->admin()->create();
});

it('está en el programador cada 5 minutos, sin solaparse y en un solo servidor', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($event): bool => str_contains((string) $event->command, 'reports:send-scheduled'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/5 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->onOneServer)->toBeTrue();

    $prune = collect(app(Schedule::class)->events())->first(fn ($event): bool => str_contains((string) $event->command, 'reports:prune-downloads'));
    expect($prune)->not->toBeNull();
});

it('encola los vencidos y no los demás, y prepara el siguiente envío', function () {
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-01 06:02', 'UTC'));

    $due = ReportSchedule::factory()->create(['owner_user_id' => $this->admin->id, 'next_run_at' => CarbonImmutable::parse('2026-10-01 06:00', 'UTC')]);
    $future = ReportSchedule::factory()->create(['owner_user_id' => $this->admin->id, 'next_run_at' => CarbonImmutable::parse('2026-10-01 06:10', 'UTC')]);
    $paused = ReportSchedule::factory()->create(['owner_user_id' => $this->admin->id, 'is_active' => false, 'paused_reason' => PauseReason::Manual, 'next_run_at' => null]);

    $this->artisan('reports:send-scheduled')->assertSuccessful();

    Queue::assertPushedOn('mail', SendReportDelivery::class);
    Queue::assertPushed(SendReportDelivery::class, 1);

    $delivery = ReportDelivery::query()->sole();
    expect($delivery->schedule_id)->toBe($due->id)
        ->and($due->refresh()->last_run_at?->toIso8601ZuluString())->toBe('2026-10-01T06:02:00Z')
        // Día 1 a las 08:00 de Madrid: el 1 de noviembre ya es horario de invierno.
        ->and($due->next_run_at?->toIso8601ZuluString())->toBe('2026-11-01T07:00:00Z')
        ->and($future->refresh()->last_run_at)->toBeNull()
        ->and($paused->refresh()->last_run_at)->toBeNull();

    // Una segunda pasada no lo vuelve a enviar.
    $this->artisan('reports:send-scheduled')->assertSuccessful();
    Queue::assertPushed(SendReportDelivery::class, 1);
});

it('resuelve el periodo relativo el día del envío: el mes anterior o el mes en curso', function (RelativePeriod $relative, string $fecha) {
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-01 06:05', 'UTC'));

    ReportSchedule::factory()->create([
        'owner_user_id' => $this->admin->id,
        'relative_period' => $relative,
        'request' => ['kind' => 'detail', 'route_params' => [], 'query' => ['periodo' => 'mes', 'fecha' => '2026-03-01', 'persona' => [7]]],
    ]);

    $this->artisan('reports:send-scheduled')->assertSuccessful();

    $query = ReportDelivery::query()->sole()->request['query'];
    expect($query['periodo'])->toBe('mes')
        ->and($query['fecha'])->toBe($fecha)
        ->and($query['persona'])->toBe([7]);
})->with([
    'anterior' => [RelativePeriod::Previous, '2026-09-01'],
    'en curso' => [RelativePeriod::Current, '2026-10-01'],
    'fijo' => [RelativePeriod::Fixed, '2026-03-01'],
]);

it('«una vez» se desactiva tras enviarse', function () {
    Queue::fake();

    $schedule = ReportSchedule::factory()->create([
        'owner_user_id' => $this->admin->id,
        'frequency' => ScheduleFrequency::Once,
        'month_day' => null,
        'run_at' => now()->subMinute(),
        'next_run_at' => now()->subMinute(),
    ]);

    $this->artisan('reports:send-scheduled')->assertSuccessful();

    expect($schedule->refresh()->is_active)->toBeFalse()
        ->and($schedule->next_run_at)->toBeNull()
        ->and($schedule->paused_reason)->toBeNull();
    Queue::assertPushed(SendReportDelivery::class, 1);
});

it('pausa el envío y avisa al propietario y a los admins si ya no puede ver el informe', function () {
    Queue::fake();
    Notification::fake();
    $owner = User::factory()->employee()->create();
    $otherAdmin = User::factory()->admin()->create();

    $schedule = ReportSchedule::factory()->create([
        'owner_user_id' => $owner->id,
        'title' => 'Dirección mensual',
        'request' => ['kind' => 'direction', 'route_params' => [], 'query' => ['periodo' => 'mes']],
    ]);

    $this->artisan('reports:send-scheduled')->assertSuccessful();

    expect($schedule->refresh()->is_active)->toBeFalse()
        ->and($schedule->paused_reason)->toBe(PauseReason::NoAccess)
        ->and($schedule->next_run_at)->toBeNull()
        ->and(ReportDelivery::query()->count())->toBe(0);
    Queue::assertNothingPushed();

    foreach ([$owner, $this->admin, $otherAdmin] as $user) {
        Notification::assertSentTo($user, ReportSchedulePausedNotification::class, fn (ReportSchedulePausedNotification $notification): bool => $notification->scheduleId === $schedule->id
            && $notification->reason === PauseReason::NoAccess
            && $notification->url($user) === "/informes/envios/{$schedule->id}");
    }

    $notification = new ReportSchedulePausedNotification($schedule->id, 'Dirección mensual', $owner->name, PauseReason::NoAccess);
    expect($notification->title($owner))->toBe('Envío programado en pausa: «Dirección mensual»')
        ->and($notification->toArray($owner)['kind'])->toBe('reports.schedule_paused');

    expect(Activity::query()->where('log_name', DeliveryAudit::LOG)->where('event', 'schedule_paused')->sole()->properties['reason'])->toBe('no_access');
});

it('pausa el envío si el propietario está desactivado y avisa solo a los admins', function () {
    Queue::fake();
    Notification::fake();
    $owner = User::factory()->admin()->create();
    $schedule = ReportSchedule::factory()->create(['owner_user_id' => $owner->id]);
    $owner->forceFill(['is_active' => false])->save();

    $this->artisan('reports:send-scheduled')->assertSuccessful();

    expect($schedule->refresh()->paused_reason)->toBe(PauseReason::OwnerInactive);
    Notification::assertSentTo($this->admin, ReportSchedulePausedNotification::class);
    Notification::assertNotSentTo($owner, ReportSchedulePausedNotification::class);
    Queue::assertNothingPushed();
});

it('quita a las personas destinatarias desactivadas, y sin destinatarios lo pausa', function () {
    Queue::fake();
    Notification::fake();
    $active = User::factory()->employee()->create();
    $gone = User::factory()->employee()->inactive()->create();

    $schedule = ReportSchedule::factory()->create([
        'owner_user_id' => $this->admin->id,
        'recipient_user_ids' => [$gone->id, $active->id],
        'recipient_emails' => [],
    ]);

    $this->artisan('reports:send-scheduled')->assertSuccessful();

    expect($schedule->refresh()->recipient_user_ids)->toBe([$active->id])
        ->and(ReportDelivery::query()->sole()->recipient_user_ids)->toBe([$active->id]);

    $alone = ReportSchedule::factory()->create([
        'owner_user_id' => $this->admin->id,
        'recipient_user_ids' => [$gone->id],
        'recipient_emails' => [],
    ]);

    $this->artisan('reports:send-scheduled')->assertSuccessful();

    expect($alone->refresh()->paused_reason)->toBe(PauseReason::NoRecipients)
        ->and($alone->recipient_user_ids)->toBe([]);
    Notification::assertSentTo($this->admin, ReportSchedulePausedNotification::class);
});

it('envía el correo de principio a fin con el generador, con los permisos del propietario', function () {
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-01 06:05', 'UTC'));
    $owner = User::factory()->departmentManager()->create();
    $colleague = User::factory()->employee()->create();

    ReportSchedule::factory()->create([
        'owner_user_id' => $owner->id,
        'request' => ['kind' => 'person', 'route_params' => ['user' => $owner->id], 'query' => ['periodo' => 'mes']],
        'recipient_user_ids' => [$colleague->id],
        'recipient_emails' => ['cliente@example.com'],
        'formats' => ['pdf', 'xlsx'],
    ]);

    $this->artisan('reports:send-scheduled')->assertSuccessful();

    expect(array_column($this->fake->generated, 'user_id'))->toBe([$owner->id, $owner->id])
        ->and($this->fake->generated[0]['request']->query['fecha'])->toBe('2026-09-01');
    Mail::assertSent(ReportDeliveryMail::class, 2);
    Mail::assertSent(ReportDeliveryMail::class, fn (ReportDeliveryMail $mail): bool => $mail->hasTo($colleague->email) && $mail->period === 'septiembre de 2026');
    expect(ReportDelivery::query()->sole()->status)->toBe(DeliveryStatus::Sent);
});

it('si al generarlo en la cola el propietario ya no puede verlo, se omite y se pausa con aviso', function () {
    Mail::fake();
    Notification::fake();
    $this->fake->deny();

    $schedule = ReportSchedule::factory()->create(['owner_user_id' => $this->admin->id]);

    $this->artisan('reports:send-scheduled')->assertSuccessful();

    expect(ReportDelivery::query()->sole()->status)->toBe(DeliveryStatus::Skipped)
        ->and($schedule->refresh()->paused_reason)->toBe(PauseReason::NoAccess);
    Mail::assertNothingSent();
    Notification::assertSentTo($this->admin, ReportSchedulePausedNotification::class);
});
