<?php

use App\Broadcasting\WebPushChannel;
use App\Domain\Notifications\NotificationPreferences;
use App\Domain\Weeklies\Reminders\WeeklyReminders;
use App\Enums\WeeklyReminderChannel;
use App\Enums\WeeklyReminderStatus;
use App\Enums\WeeklyReminderTemplate;
use App\Events\Weeklies\WeeklyCycleClosed;
use App\Models\Absence;
use App\Models\PushSubscription;
use App\Models\Setting;
use App\Models\User;
use App\Models\WeeklyCycle;
use App\Models\WeeklyExemption;
use App\Models\WeeklyReminderLog;
use App\Models\WeeklyReminderRule;
use App\Models\WeeklySubmission;
use App\Notifications\Weeklies\WeeklyClosedNotice;
use App\Notifications\Weeklies\WeeklyReminder;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/*
| Recordatorios de la weekly por reglas (10.5, F-101, F-102 y F-106 a F-108, D-199 a D-201): el
| comando weeklies:remind cada 5 minutos, solo a quien debe enviar y no ha enviado (sin exentos,
| activos y nunca colaboradores externos), por el canal de la regla y según las preferencias de
| cada persona, una sola vez por disparo y con su registro. Y «weekly cerrada» al cerrar la semana.
| Semana del 05/10/2026; hoy, viernes 09/10 a las 16:02 de Madrid.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-09 16:02:00', 'Europe/Madrid'));
    $this->cycle = WeeklyCycle::factory()->active('2026-10-05')->create();
    $this->since = ['created_at' => '2026-09-01 08:00:00'];
    $this->ana = userWithRole('employee', ['name' => 'Ana', 'email' => 'ana@audaxstudio.com', ...$this->since]);
    $this->rule = fn (WeeklyReminderChannel $channel = WeeklyReminderChannel::Email, int $day = 5, string $time = '16:00', bool $enabled = true): WeeklyReminderRule => WeeklyReminderRule::query()->create(['channel' => $channel, 'day_of_week' => $day, 'time' => $time, 'enabled' => $enabled]);
});

it('avisa por el canal de la regla solo a quien debe enviar y aún no lo ha hecho (F-106)', function () {
    Notification::fake();
    $rule = ($this->rule)();

    $draft = userWithRole('employee', ['name' => 'Borrador', ...$this->since]);
    WeeklySubmission::factory()->create(['weekly_cycle_id' => $this->cycle->id, 'user_id' => $draft->id]);
    $sent = userWithRole('department_manager', ['name' => 'Enviada', ...$this->since]);
    WeeklySubmission::factory()->submitted()->create(['weekly_cycle_id' => $this->cycle->id, 'user_id' => $sent->id]);
    $exempt = userWithRole('employee', ['name' => 'Exenta', ...$this->since]);
    WeeklyExemption::factory()->create(['weekly_cycle_id' => $this->cycle->id, 'user_id' => $exempt->id]);
    $absent = userWithRole('employee', ['name' => 'De vacaciones', ...$this->since]);
    Absence::factory()->for($absent)->approved()->between('2026-10-08', '2026-10-12')->create();
    $waived = userWithRole('employee', ['name' => 'Renuncia', ...$this->since]);
    Absence::factory()->for($waived)->approved()->between('2026-10-08', '2026-10-12')->create();
    WeeklyExemption::factory()->waived()->create(['weekly_cycle_id' => $this->cycle->id, 'user_id' => $waived->id]);
    $inactive = userWithRole('employee', ['name' => 'Baja', 'is_active' => false, ...$this->since]);
    $collaborator = User::factory()->collaborator()->create(['name' => 'Externa', ...$this->since]);
    $client = userWithRole('client', ['name' => 'Cliente', ...$this->since]);
    $late = userWithRole('employee', ['name' => 'Alta posterior', 'created_at' => '2026-10-12 09:00:00']);

    $this->artisan('weeklies:remind')->expectsOutputToContain('3 avisos')->assertSuccessful();

    $reminded = [$this->ana->id, $draft->id, $waived->id];

    foreach ([$this->ana, $draft, $waived] as $user) {
        Notification::assertSentTo($user, WeeklyReminder::class, fn (WeeklyReminder $notification, array $channels) => $channels === ['mail']
            && $notification->template === 'automatic'
            && $notification->onlyChannels === ['mail']);
    }

    foreach ([$sent, $exempt, $absent, $inactive, $collaborator, $client, $late] as $user) {
        Notification::assertNotSentTo($user, WeeklyReminder::class);
    }

    $logs = WeeklyReminderLog::query()->orderBy('user_id')->get();

    expect($logs->pluck('user_id')->all())->toEqualCanonicalizing($reminded)
        ->and($logs->pluck('channel')->unique()->all())->toBe([WeeklyReminderChannel::Email])
        ->and($logs->pluck('template')->unique()->all())->toBe([WeeklyReminderTemplate::Automatic])
        ->and($logs->pluck('status')->unique()->all())->toBe([WeeklyReminderStatus::Queued])
        ->and($logs->pluck('trigger_key')->unique()->all())->toBe(["rule:{$rule->id}:{$this->cycle->id}:2026-10-09T16:00"])
        ->and($logs->firstWhere('user_id', $this->ana->id)?->recipient_email)->toBe('ana@audaxstudio.com');
});

it('un mismo disparo no llega dos veces aunque el comando se repita (F-107)', function () {
    Notification::fake();
    ($this->rule)();

    $this->artisan('weeklies:remind')->assertSuccessful();
    $this->travel(5)->minutes();
    $this->artisan('weeklies:remind')->expectsOutputToContain('0 avisos, 0 omitidos, 1 ya enviados')->assertSuccessful();
    $this->travel(10)->minutes();
    $this->artisan('weeklies:remind')->expectsOutputToContain('Ninguna regla toca ahora')->assertSuccessful();

    Notification::assertSentToTimes($this->ana, WeeklyReminder::class, 1);
    expect(WeeklyReminderLog::query()->count())->toBe(1);

    // La semana siguiente, la misma regla vuelve a avisar (otra clave).
    $this->cycle->forceFill(['status' => 'closed'])->save();
    $next = WeeklyCycle::factory()->active('2026-10-12')->create();
    $this->travelTo(CarbonImmutable::parse('2026-10-16 16:01:00', 'Europe/Madrid'));
    $this->artisan('weeklies:remind')->assertSuccessful();

    Notification::assertSentToTimes($this->ana, WeeklyReminder::class, 2);
    expect(WeeklyReminderLog::query()->where('weekly_cycle_id', $next->id)->count())->toBe(1);
});

it('cada regla sale por su canal y las preferencias de cada persona pueden quitarlo', function () {
    Notification::fake();
    ($this->rule)(WeeklyReminderChannel::App, time: '16:00');
    ($this->rule)(WeeklyReminderChannel::Email, time: '15:55');
    $quiet = userWithRole('employee', ['name' => 'Sin email', ...$this->since]);
    app(NotificationPreferences::class)->update($quiet, ['weeklies.reminder' => ['email' => false]], false);

    $this->artisan('weeklies:remind')->assertSuccessful();

    Notification::assertSentToTimes($this->ana, WeeklyReminder::class, 2);
    Notification::assertSentTo($this->ana, WeeklyReminder::class, fn ($notification, array $channels) => $channels === ['database']);
    Notification::assertSentTo($this->ana, WeeklyReminder::class, fn ($notification, array $channels) => $channels === ['mail']);
    Notification::assertSentToTimes($quiet, WeeklyReminder::class, 1);

    $skipped = WeeklyReminderLog::query()->where('user_id', $quiet->id)->where('channel', 'email')->sole();

    expect($skipped->status)->toBe(WeeklyReminderStatus::Skipped)
        ->and($skipped->error)->toBe(__('weeklies.reminders.skipped.preferences'));
});

it('con el resumen diario, el email del recordatorio va a la campana y al resumen', function () {
    Notification::fake();
    ($this->rule)();
    app(NotificationPreferences::class)->update($this->ana, [], true);

    $this->artisan('weeklies:remind')->assertSuccessful();

    Notification::assertSentTo($this->ana, WeeklyReminder::class, fn ($notification, array $channels) => $channels === ['database']);
});

it('el navegador solo llega a quien tiene alguno suscrito y con Web Push configurado', function () {
    Notification::fake();
    ($this->rule)(WeeklyReminderChannel::Push);
    $subscribed = userWithRole('employee', ['name' => 'Con navegador', ...$this->since]);
    PushSubscription::query()->create(['user_id' => $subscribed->id, 'endpoint' => 'https://push.example/1', 'endpoint_hash' => PushSubscription::hashEndpoint('https://push.example/1'), 'public_key' => 'clave', 'auth_token' => 'token']);

    // Sin Web Push configurado (sin claves VAPID), nadie.
    $this->artisan('weeklies:remind')->assertSuccessful();
    Notification::assertNothingSent();
    expect(WeeklyReminderLog::query()->pluck('error')->unique()->all())->toBe([__('weeklies.reminders.skipped.push_unavailable')]);

    // Con Web Push, solo quien tiene un navegador suscrito (otra pasada: otra regla).
    WeeklyReminderLog::query()->delete();
    config(['notifications.channels.push' => WebPushChannel::class]);
    $this->artisan('weeklies:remind')->assertSuccessful();

    Notification::assertSentTo($subscribed, WeeklyReminder::class, fn ($notification, array $channels) => $channels === [WebPushChannel::class]);
    Notification::assertNotSentTo($this->ana, WeeklyReminder::class);
    expect(WeeklyReminderLog::query()->where('user_id', $this->ana->id)->sole()->error)->toBe(__('weeklies.reminders.skipped.push_unsubscribed'));
});

it('no hace nada sin semana activa, con el módulo apagado o con la regla desactivada', function () {
    Notification::fake();
    $rule = ($this->rule)(enabled: false);

    $this->artisan('weeklies:remind')->expectsOutputToContain('Ninguna regla')->assertSuccessful();

    $rule->forceFill(['enabled' => true])->save();
    Setting::set('modules', ['weeklies' => false]);
    $this->artisan('weeklies:remind')->expectsOutputToContain('desactivado')->assertSuccessful();

    Setting::set('modules', ['weeklies' => true]);
    $this->cycle->forceFill(['status' => 'closed'])->save();
    $this->artisan('weeklies:remind')->expectsOutputToContain('ninguna semana activa')->assertSuccessful();

    Notification::assertNothingSent();
    expect(WeeklyReminderLog::query()->count())->toBe(0);
});

it('la regla es de Madrid: a las 16:00 de Madrid, que en invierno son las 15:00 UTC', function () {
    Notification::fake();
    $this->cycle->delete();
    $winter = WeeklyCycle::factory()->active('2026-11-02')->create();
    ($this->rule)();

    $this->travelTo(CarbonImmutable::parse('2026-11-06T14:02:00Z'));
    $this->artisan('weeklies:remind')->assertSuccessful();
    Notification::assertNothingSent();

    $this->travelTo(CarbonImmutable::parse('2026-11-06T15:02:00Z'));
    $this->artisan('weeklies:remind')->assertSuccessful();
    Notification::assertSentTo($this->ana, WeeklyReminder::class);
    expect(WeeklyReminderLog::query()->sole()->trigger_key)->toEndWith(":{$winter->id}:2026-11-06T16:00");
});

it('enviado de verdad: la campana y el email con la plantilla, y el registro pasa a «enviado»', function () {
    ($this->rule)(WeeklyReminderChannel::App);
    ($this->rule)(WeeklyReminderChannel::Email, time: '15:58');
    Setting::set('weekly_email_templates', ['automatic' => ['subject' => 'Weekly {semana} pendiente', 'body' => "Hola {nombre},\n\nEntra en {weekly_url}\n\nGracias."]]);

    $this->artisan('weeklies:remind')->assertSuccessful();

    $bell = $this->ana->notifications()->sole();

    expect($bell->data['kind'])->toBe('weeklies.reminder')
        ->and($bell->data['title'])->toBe('Weekly W41-26 pendiente')
        ->and($bell->data['url'])->toBe("/mi-espacio?semana={$this->cycle->id}")
        ->and($bell->data['body'])->toContain('Semana 41')
        ->and(WeeklyReminderLog::query()->pluck('status')->unique()->all())->toBe([WeeklyReminderStatus::Sent]);

    $mail = (new WeeklyReminder($this->cycle, WeeklyReminderTemplate::Automatic, 'Weekly {semana} pendiente', "Hola {nombre},\n\nEntra en {weekly_url}\n\nGracias."))->toMail($this->ana);
    $html = (string) $mail->render();

    expect($mail->subject)->toBe('Weekly W41-26 pendiente')
        ->and($html)->toContain('Hola Ana,')
        ->and($html)->toContain(url("/mi-espacio?semana={$this->cycle->id}"))
        ->and($html)->toContain(__('weeklies.reminders.notice.reminder_action'));
});

it('si el canal falla, la fila queda «fallida» con el error (F-108)', function () {
    Notification::fake();
    ($this->rule)();
    $this->artisan('weeklies:remind')->assertSuccessful();

    $notification = Notification::sent($this->ana, WeeklyReminder::class)->first();
    event(new NotificationFailed($this->ana, $notification, 'mail', ['exception' => new RuntimeException('El relé de Google rechaza el envío')]));

    $log = WeeklyReminderLog::query()->sole();

    expect($log->status)->toBe(WeeklyReminderStatus::Failed)
        ->and($log->error)->toBe('El relé de Google rechaza el envío');

    // Un reintento que llega lo deja enviado.
    $notification->afterSending($this->ana, 'mail');
    expect($log->refresh()->status)->toBe(WeeklyReminderStatus::Sent)
        ->and($log->error)->toBeNull();
});

it('el comando hace las mismas consultas con 5 personas pendientes que con 40', function () {
    Notification::fake();
    ($this->rule)();
    $count = 0;
    DB::listen(function (QueryExecuted $query) use (&$count): void {
        $count++;
    });

    User::factory()->employee()->count(4)->create($this->since);
    Setting::allCached();
    $count = 0;
    $this->artisan('weeklies:remind')->assertSuccessful();
    $few = $count;

    WeeklyReminderLog::query()->delete();
    User::factory()->employee()->count(35)->create($this->since);
    $count = 0;
    $this->artisan('weeklies:remind')->assertSuccessful();

    Notification::assertSentTimes(WeeklyReminder::class, 5 + 40);
    expect($count)->toBe($few)->and($few)->toBeLessThanOrEqual(14);
});

it('se programa cada 5 minutos, sin solaparse y en un solo servidor', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn (ScheduledEvent $event) => str_contains((string) $event->command, 'weeklies:remind'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/5 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->onOneServer)->toBeTrue();
});

it('«weekly cerrada» llega a todo el equipo activo una sola vez, con el enlace al informe (F-095)', function () {
    Notification::fake();
    WeeklySubmission::factory()->submitted()->create(['weekly_cycle_id' => $this->cycle->id, 'user_id' => $this->ana->id]);
    $manager = userWithRole('department_manager', ['name' => 'Marta', ...$this->since]);
    $inactive = userWithRole('employee', ['is_active' => false]);
    $collaborator = User::factory()->collaborator()->create();
    $this->cycle->forceFill(['status' => 'closed', 'closed_at' => now()])->save();

    event(new WeeklyCycleClosed($this->cycle->id, $manager->id, null));
    event(new WeeklyCycleClosed($this->cycle->id, $manager->id, null));

    Notification::assertSentToTimes($this->ana, WeeklyClosedNotice::class, 1);
    Notification::assertSentToTimes($manager, WeeklyClosedNotice::class, 1);
    Notification::assertNotSentTo($inactive, WeeklyClosedNotice::class);
    Notification::assertNotSentTo($collaborator, WeeklyClosedNotice::class);
    Notification::assertSentTo($this->ana, WeeklyClosedNotice::class, fn (WeeklyClosedNotice $notice, array $channels) => $channels === ['database', 'mail']
        && $notice->url($this->ana) === "/weeklies/{$this->cycle->id}");

    $logs = WeeklyReminderLog::query()->where('trigger_key', "closed:{$this->cycle->id}")->get();
    expect($logs)->toHaveCount(4)
        ->and($logs->pluck('template')->unique()->all())->toBe([WeeklyReminderTemplate::WeeklyClosed]);
});

it('«weekly cerrada» usa su plantilla con {weekly_url} y no sale con el módulo apagado', function () {
    $this->cycle->forceFill(['status' => 'closed', 'closed_at' => now()])->save();
    $notice = new WeeklyClosedNotice($this->cycle, ...array_values(__('weeklies.templates.weekly_closed')));
    $html = (string) $notice->toMail($this->ana)->render();

    expect($notice->title($this->ana))->toBe('Weekly cerrada: W41-26')
        ->and($html)->toContain('La weekly W41-26 ya se ha generado y cerrado.')
        ->and($html)->toContain(url("/weeklies/{$this->cycle->id}"));

    Notification::fake();
    Setting::set('modules', ['weeklies' => false]);
    expect(app(WeeklyReminders::class)->notifyClosed($this->cycle)->notified)->toBe(0);
    Notification::assertNothingSent();
});

it('los avisos de la Weekly son un grupo de las preferencias, no para colaboradores ni con el módulo apagado', function () {
    $preferences = app(NotificationPreferences::class);
    $groups = fn (User $user): array => array_column($preferences->forUser($user)['groups'], 'key');

    expect($groups($this->ana))->toContain('weeklies')
        ->and($groups(User::factory()->collaborator()->create()))->not->toContain('weeklies');

    $weekly = collect($preferences->forUser($this->ana)['groups'])->firstWhere('key', 'weeklies');
    expect(array_column($weekly['events'], 'kind'))->toBe(['weeklies.reminder', 'weeklies.closed', 'weeklies.deadline_changed'])
        ->and($weekly['events'][0]['channels']['email']['enabled'])->toBeTrue()
        ->and($weekly['events'][2]['channels']['email']['enabled'])->toBeFalse();

    Setting::set('modules', ['weeklies' => false]);
    expect($groups($this->ana))->not->toContain('weeklies');
});
