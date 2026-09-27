<?php

use App\Console\Commands\RemindWeekSubmission;
use App\Domain\Notifications\NotificationPreferences;
use App\Enums\TimesheetStatus;
use App\Models\Absence;
use App\Models\Client;
use App\Models\Holiday;
use App\Models\Setting;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\TimesheetPeriod;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Notifications\Time\WeekSubmissionReminder;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/*
| Recordatorio de enviar la semana (SPEC §13, D-073): time:remind-week, los viernes a las 13:00 de
| Madrid, a cada persona interna y activa con capacidad esa semana (jornada menos festivos y
| ausencias aprobadas) que aún no la ha enviado (sin semana guardada, abierta o devuelta). Hoy es el
| viernes 02/10/2026 a las 13:00: la semana 2026-W40 va del lunes 28/09 al domingo 04/10. Sin
| horario propio, la jornada es la de por defecto (8 h de lunes a viernes: 40:00 a la semana).
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-02 13:00', 'Europe/Madrid'));
    $this->task = Task::factory()->create(['title' => 'Maquetar la home']);
    // Las personas que crea la fábrica de la tarea (el gestor del proyecto…) no cuentan en los tests.
    User::query()->update(['is_active' => false]);

    $this->person = fn (array $attributes = []): User => User::factory()->employee()->create($attributes);
    $this->week = fn (User $user, TimesheetStatus $status): TimesheetPeriod => TimesheetPeriod::factory()
        ->for($user)
        ->week('2026-09-28')
        ->status($status)
        ->create();
    $this->log = fn (User $user, string $date, int $minutes): TimeEntry => TimeEntry::factory()
        ->forTask($this->task)
        ->on($date)
        ->minutes($minutes)
        ->create(['user_id' => $user->id]);
});

it('avisa a quien tiene la semana sin enviar, con sus horas frente a la capacidad', function () {
    Notification::fake();
    $ana = ($this->person)(['name' => 'Ana']);
    foreach (['2026-09-28', '2026-09-29', '2026-09-30'] as $day) {
        ($this->log)($ana, $day, 480);
    }
    // Horas de otra semana: no cuentan.
    ($this->log)($ana, '2026-09-25', 480);

    $this->artisan('time:remind-week')
        ->expectsOutputToContain('Recordatorios enviados: 1.')
        ->assertSuccessful();

    Notification::assertSentTo($ana, WeekSubmissionReminder::class, function (WeekSubmissionReminder $notification, array $channels) use ($ana): bool {
        return $channels === ['database']
            && $notification->toArray($ana) === [
                'kind' => 'time.week_reminder',
                'title' => 'Recuerda enviar tu semana',
                'body' => 'Llevas 24:00 de 40:00 imputadas en la semana del 28/09/2026.',
                'url' => '/horas?semana=2026-W40',
                'icon' => 'calendar-check',
            ];
    });
});

it('avisa con la semana abierta o devuelta, y no con la enviada, aprobada o bloqueada', function () {
    Notification::fake();
    $people = [];
    foreach (TimesheetStatus::cases() as $status) {
        $people[$status->value] = ($this->person)();
        ($this->week)($people[$status->value], $status);
    }
    ($this->log)($people['returned'], '2026-09-28', 90);

    $this->artisan('time:remind-week')->assertSuccessful();

    Notification::assertSentTo($people['open'], WeekSubmissionReminder::class, fn (WeekSubmissionReminder $n): bool => ! $n->returned);
    Notification::assertSentTo($people['returned'], WeekSubmissionReminder::class, fn (WeekSubmissionReminder $n): bool => $n->returned
        && $n->body($people['returned']) === 'Te devolvieron la semana del 28/09/2026 para corregirla: llevas 1:30 de 40:00 imputadas.');

    foreach (['submitted', 'approved', 'locked'] as $status) {
        Notification::assertNotSentTo($people[$status], WeekSubmissionReminder::class);
    }
});

it('no avisa a quien no tiene capacidad esa semana: vacaciones, jornada a cero o festivos', function () {
    Notification::fake();
    $holidays = ($this->person)();
    Absence::factory()->for($holidays)->approved()->between('2026-09-28', '2026-10-02')->create();
    $noSchedule = ($this->person)();
    WorkSchedule::factory()->for($noSchedule)->create(['mon_minutes' => 0, 'tue_minutes' => 0, 'wed_minutes' => 0, 'thu_minutes' => 0, 'fri_minutes' => 0]);
    // Vacaciones de lunes a miércoles: quedan jueves y viernes (16:00).
    $partial = ($this->person)();
    Absence::factory()->for($partial)->approved()->between('2026-09-28', '2026-09-30')->create();
    // Una ausencia solo pedida no quita capacidad.
    $requested = ($this->person)();
    Absence::factory()->for($requested)->between('2026-09-28', '2026-10-02')->create();

    $this->artisan('time:remind-week')->assertSuccessful();

    Notification::assertNotSentTo($holidays, WeekSubmissionReminder::class);
    Notification::assertNotSentTo($noSchedule, WeekSubmissionReminder::class);
    Notification::assertSentTo($partial, WeekSubmissionReminder::class, fn (WeekSubmissionReminder $n): bool => $n->capacityMinutes === 960);
    Notification::assertSentTo($requested, WeekSubmissionReminder::class, fn (WeekSubmissionReminder $n): bool => $n->capacityMinutes === 2400);
});

it('una semana de festivos no avisa a nadie', function () {
    Notification::fake();
    $ana = ($this->person)();
    foreach (['2026-09-28', '2026-09-29', '2026-09-30', '2026-10-01', '2026-10-02'] as $day) {
        Holiday::factory()->create(['date' => $day, 'name' => 'Fiestas']);
    }

    $this->artisan('time:remind-week')->assertSuccessful();

    Notification::assertNotSentTo($ana, WeekSubmissionReminder::class);
});

it('solo a personas internas y activas, también responsables y admins', function () {
    Notification::fake();
    $inactive = User::factory()->employee()->inactive()->create();
    $client = User::factory()->portalOf(Client::factory()->create())->create();
    $admin = User::factory()->admin()->create();
    $manager = User::factory()->departmentManager()->create();

    $this->artisan('time:remind-week')->assertSuccessful();

    Notification::assertNotSentTo($inactive, WeekSubmissionReminder::class);
    Notification::assertNotSentTo($client, WeekSubmissionReminder::class);
    Notification::assertSentTo($admin, WeekSubmissionReminder::class);
    Notification::assertSentTo($manager, WeekSubmissionReminder::class);
});

it('no repite en la misma semana, tampoco si se vacía la caché; la semana siguiente, otra vez', function () {
    $ana = ($this->person)();

    $this->artisan('time:remind-week')->assertSuccessful();
    $this->artisan('time:remind-week')->assertSuccessful();
    expect(Cache::has(RemindWeekSubmission::claimKey($ana->id, '2026-09-28')))->toBeTrue();

    // Sin la reclamación (caché vaciada), el aviso de la campana basta para no repetir.
    Cache::flush();
    $this->travelTo(CarbonImmutable::parse('2026-10-02 18:00', 'Europe/Madrid'));
    $this->artisan('time:remind-week')->assertSuccessful();

    expect($ana->notifications()->where('type', WeekSubmissionReminder::class)->count())->toBe(1);

    $this->travelTo(CarbonImmutable::parse('2026-10-09 13:00', 'Europe/Madrid'));
    $this->artisan('time:remind-week')->assertSuccessful();

    expect($ana->notifications()->where('type', WeekSubmissionReminder::class)->count())->toBe(2)
        ->and($ana->notifications()->latest()->first()?->data['url'])->toBe('/horas?semana=2026-W41');
});

it('por defecto llega a la campana; con el email activado por preferencias, también por email', function () {
    Notification::fake();
    $ana = ($this->person)(['name' => 'Ana Ruiz']);
    $luis = ($this->person)();
    app(NotificationPreferences::class)->update($ana, ['time.week_reminder' => ['email' => true]], false);

    $this->artisan('time:remind-week')->assertSuccessful();

    Notification::assertSentTo($ana, WeekSubmissionReminder::class, fn (WeekSubmissionReminder $n, array $channels): bool => $channels === ['database', 'mail']);
    Notification::assertSentTo($luis, WeekSubmissionReminder::class, fn (WeekSubmissionReminder $n, array $channels): bool => $channels === ['database']);

    $mail = (new WeekSubmissionReminder('2026-09-28', 600, 2400))->toMail($ana->refresh());
    expect($mail)->toBeInstanceOf(MailMessage::class)
        ->and($mail->subject)->toBe('Recuerda enviar tu semana')
        ->and($mail->greeting)->toBe('Hola, Ana Ruiz:')
        ->and($mail->introLines)->toBe(['Recuerda enviar tu semana', 'Llevas 10:00 de 40:00 imputadas en la semana del 28/09/2026.'])
        ->and($mail->actionUrl)->toBe(url('/horas?semana=2026-W40'));
});

it('quien lo desactiva en la app y por email no lo recibe', function () {
    Notification::fake();
    $ana = ($this->person)();
    app(NotificationPreferences::class)->update($ana, ['time.week_reminder' => ['app' => false]], false);

    $this->artisan('time:remind-week')
        ->expectsOutputToContain('Recordatorios enviados: 0.')
        ->assertSuccessful();

    Notification::assertNothingSent();
    expect(Cache::has(RemindWeekSubmission::claimKey($ana->id, '2026-09-28')))->toBeFalse();
});

it('no hace nada con el ajuste desactivado', function () {
    Notification::fake();
    ($this->person)();
    Setting::set('week_reminder_enabled', false);

    $this->artisan('time:remind-week')
        ->expectsOutputToContain('desactivado')
        ->assertSuccessful();

    Notification::assertNothingSent();
});

it('hace las mismas consultas con 5 personas que con 50', function () {
    Notification::fake();
    $count = 0;
    DB::listen(function (QueryExecuted $query) use (&$count): void {
        $count++;
    });
    $withWeek = function (int $people): void {
        foreach (User::factory()->employee()->count($people)->create() as $index => $user) {
            ($this->log)($user, '2026-09-29', 120);
            WorkSchedule::factory()->for($user)->create();
            Absence::factory()->for($user)->approved()->between('2026-09-28', '2026-09-28')->create();
            if ($index % 2 === 0) {
                ($this->week)($user, TimesheetStatus::Returned);
            }
        }
    };

    $withWeek(5);
    Cache::flush();
    $count = 0;
    $this->artisan('time:remind-week')->assertSuccessful();
    $few = $count;

    $withWeek(45);
    Cache::flush();
    $count = 0;
    $this->artisan('time:remind-week')->assertSuccessful();
    $many = $count;

    Notification::assertSentTimes(WeekSubmissionReminder::class, 5 + 50);
    expect($few)->toBeLessThanOrEqual(8)
        ->and($many)->toBe($few);
});

it('se programa los viernes a las 13:00 de Madrid, sin solaparse, en un solo servidor y según el ajuste', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn (ScheduledEvent $event) => str_contains((string) $event->command, 'time:remind-week'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 13 * * 5')
        ->and($event->timezone)->toBe('Europe/Madrid')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->onOneServer)->toBeTrue()
        ->and($event->filtersPass(app()))->toBeTrue();

    Setting::set('week_reminder_enabled', false);
    expect($event->filtersPass(app()))->toBeFalse();
});
