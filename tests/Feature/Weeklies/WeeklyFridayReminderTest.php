<?php

use App\Domain\Notifications\NotificationPreferences;
use App\Enums\TimesheetStatus;
use App\Enums\WeeklyReminderChannel;
use App\Enums\WeeklyReminderStatus;
use App\Enums\WeeklyReminderTemplate;
use App\Models\Setting;
use App\Models\Task;
use App\Models\TimesheetPeriod;
use App\Models\User;
use App\Models\WeeklyCycle;
use App\Models\WeeklyExemption;
use App\Models\WeeklyReminderLog;
use App\Models\WeeklyReminderRule;
use App\Models\WeeklySubmission;
use App\Notifications\Time\WeekSubmissionReminder;
use App\Notifications\Weeklies\WeeklyReminder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

/*
| Un solo recordatorio de los viernes (10.5, D-150 y D-200): time:remind-week, los viernes a las 13:00
| de Madrid, manda UN aviso por persona con lo que le falte: la semana de horas (D-123) y la weekly
| de la semana activa. La parte de la weekly va al registro de avisos (plantilla «friday») y respeta
| las exenciones. Hoy es el viernes 09/10/2026 a las 13:00: semana de horas 2026-W41 y weekly W41-26.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-09 13:00', 'Europe/Madrid'));
    Task::factory()->create();
    User::query()->update(['is_active' => false]);
    $this->cycle = WeeklyCycle::factory()->active('2026-10-05')->create();
    $this->person = fn (string $name): User => userWithRole('employee', ['name' => $name, 'created_at' => '2026-09-01 08:00:00']);
    $this->hoursSent = fn (User $user) => TimesheetPeriod::factory()->for($user)->week('2026-10-05')->status(TimesheetStatus::Submitted)->create();
    $this->weeklySent = fn (User $user) => WeeklySubmission::factory()->submitted()->create(['weekly_cycle_id' => $this->cycle->id, 'user_id' => $user->id]);
});

it('a quien le faltan las dos cosas, un solo aviso con las horas y la weekly', function () {
    Notification::fake();
    $ana = ($this->person)('Ana');

    $this->artisan('time:remind-week')->assertSuccessful();

    Notification::assertSentToTimes($ana, WeekSubmissionReminder::class, 1);
    Notification::assertNotSentTo($ana, WeeklyReminder::class);
    Notification::assertSentTo($ana, WeekSubmissionReminder::class, function (WeekSubmissionReminder $notification, array $channels) use ($ana): bool {
        return $channels === ['database']
            && $notification->title($ana) === 'Recuerda enviar tu semana y tu weekly'
            && str_contains((string) $notification->body($ana), '0:00 de 40:00')
            && str_contains((string) $notification->body($ana), 'Tu weekly de la Semana 41')
            && $notification->url($ana) === '/horas?semana=2026-W41'
            && $notification->reminderLogIds !== [];
    });

    $log = WeeklyReminderLog::query()->sole();
    expect($log->template)->toBe(WeeklyReminderTemplate::Friday)
        ->and($log->channel)->toBe(WeeklyReminderChannel::App)
        ->and($log->trigger_key)->toBe("friday:{$this->cycle->id}:2026-10-09")
        ->and($log->user_id)->toBe($ana->id);
});

it('solo la weekly o solo las horas, según lo que falte; nunca la weekly a un exento', function () {
    Notification::fake();
    $onlyWeekly = ($this->person)('Solo weekly');
    ($this->hoursSent)($onlyWeekly);
    $onlyHours = ($this->person)('Solo horas');
    ($this->weeklySent)($onlyHours);
    $exempt = ($this->person)('Exenta');
    WeeklyExemption::factory()->create(['weekly_cycle_id' => $this->cycle->id, 'user_id' => $exempt->id]);
    $nothing = ($this->person)('Todo enviado');
    ($this->hoursSent)($nothing);
    ($this->weeklySent)($nothing);

    $this->artisan('time:remind-week')->assertSuccessful();

    Notification::assertSentTo($onlyWeekly, WeekSubmissionReminder::class, fn (WeekSubmissionReminder $notification) => $notification->title($onlyWeekly) === 'Recuerda enviar tu weekly'
        && ! $notification->hours
        && $notification->url($onlyWeekly) === "/mi-espacio?semana={$this->cycle->id}"
        && ! str_contains((string) $notification->body($onlyWeekly), 'imputadas'));
    Notification::assertSentTo($onlyHours, WeekSubmissionReminder::class, fn (WeekSubmissionReminder $notification) => $notification->title($onlyHours) === 'Recuerda enviar tu semana'
        && $notification->weekly === null);
    Notification::assertSentTo($exempt, WeekSubmissionReminder::class, fn (WeekSubmissionReminder $notification) => $notification->weekly === null);
    Notification::assertNotSentTo($nothing, WeekSubmissionReminder::class);

    expect(WeeklyReminderLog::query()->pluck('user_id')->all())->toBe([$onlyWeekly->id]);
});

it('no se repite: ni otra pasada ni la caché vacía mandan otro aviso ni otra fila', function () {
    Notification::fake();
    $ana = ($this->person)('Ana');
    ($this->hoursSent)($ana);

    $this->artisan('time:remind-week')->assertSuccessful();
    $this->artisan('time:remind-week')->assertSuccessful();
    cache()->flush();
    $this->artisan('time:remind-week')->assertSuccessful();

    Notification::assertSentToTimes($ana, WeekSubmissionReminder::class, 1);
    expect(WeeklyReminderLog::query()->count())->toBe(1);
});

it('con la weekly del viernes desactivada, solo las horas; con las horas desactivadas, solo la weekly', function () {
    Notification::fake();
    $ana = ($this->person)('Ana');
    $luis = ($this->person)('Luis');
    ($this->weeklySent)($luis);

    Setting::set('weekly_friday_reminder', false);
    $this->artisan('time:remind-week')->assertSuccessful();

    Notification::assertSentTo($ana, WeekSubmissionReminder::class, fn (WeekSubmissionReminder $notification) => $notification->weekly === null && $notification->hours);
    expect(WeeklyReminderLog::query()->count())->toBe(0);

    // La semana siguiente, al revés: sin las horas, solo a quien le falta la weekly.
    Notification::fake();
    $this->cycle->forceFill(['status' => 'closed'])->save();
    $next = WeeklyCycle::factory()->active('2026-10-12')->create();
    WeeklySubmission::factory()->submitted()->create(['weekly_cycle_id' => $next->id, 'user_id' => $luis->id]);
    $this->travelTo(CarbonImmutable::parse('2026-10-16 13:00', 'Europe/Madrid'));
    Setting::set('weekly_friday_reminder', true);
    Setting::set('week_reminder_enabled', false);

    $this->artisan('time:remind-week')->assertSuccessful();

    Notification::assertSentTo($ana, WeekSubmissionReminder::class, fn (WeekSubmissionReminder $notification) => ! $notification->hours && $notification->weekly !== null);
    Notification::assertNotSentTo($luis, WeekSubmissionReminder::class);
});

it('con el módulo de la Weekly apagado, el de los viernes es solo el de las horas', function () {
    Notification::fake();
    $ana = ($this->person)('Ana');
    Setting::set('modules', ['weeklies' => false]);

    $this->artisan('time:remind-week')->assertSuccessful();

    Notification::assertSentTo($ana, WeekSubmissionReminder::class, fn (WeekSubmissionReminder $notification) => $notification->weekly === null);
    expect(WeeklyReminderLog::query()->count())->toBe(0);
});

it('enviado de verdad: la campana, el email con el enlace a la weekly y la fila «enviada»', function () {
    $ana = ($this->person)('Ana');
    app(NotificationPreferences::class)->update($ana, ['time.week_reminder' => ['email' => true]], false);

    $this->artisan('time:remind-week')->assertSuccessful();

    expect($ana->notifications()->sole()->data['title'])->toBe('Recuerda enviar tu semana y tu weekly');

    $logs = WeeklyReminderLog::query()->orderBy('channel')->get();
    expect($logs->pluck('channel')->all())->toBe([WeeklyReminderChannel::App, WeeklyReminderChannel::Email])
        ->and($logs->pluck('status')->unique()->all())->toBe([WeeklyReminderStatus::Sent]);

    $notification = new WeekSubmissionReminder('2026-10-05', 0, 2400, weekly: ['cycle_id' => $this->cycle->id, 'label' => $this->cycle->label, 'deadline' => '2026-10-09']);
    $html = (string) $notification->toMail($ana)->render();

    expect($html)->toContain('Tu weekly de la Semana 41')
        ->and($html)->toContain('viernes 9 de octubre')
        ->and($html)->toContain(url("/mi-espacio?semana={$this->cycle->id}"));
});

it('las reglas de la weekly siguen saliendo aparte y no repiten la parte del viernes', function () {
    Notification::fake();
    $ana = ($this->person)('Ana');
    WeeklyReminderRule::query()->create(['channel' => WeeklyReminderChannel::Email, 'day_of_week' => 5, 'time' => '16:00']);

    $this->artisan('time:remind-week')->assertSuccessful();
    $this->travelTo(CarbonImmutable::parse('2026-10-09 16:01', 'Europe/Madrid'));
    $this->artisan('weeklies:remind')->assertSuccessful();

    Notification::assertSentToTimes($ana, WeekSubmissionReminder::class, 1);
    Notification::assertSentToTimes($ana, WeeklyReminder::class, 1);
    expect(WeeklyReminderLog::query()->pluck('template')->map->value->all())->toBe(['friday', 'automatic']);
});
