<?php

use App\Models\ActiveTimer;
use App\Models\Setting;
use App\Models\Task;
use App\Models\User;
use App\Notifications\Time\TimerRunningLong;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;

/*
| Temporizador largo (SPEC §7): timers:warn avisa en la app una sola vez por temporizador.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-24 20:00:00', 'Europe/Madrid'));

    $this->timer = function (string $startedAt, ?User $user = null, array $attributes = []): ActiveTimer {
        return ActiveTimer::query()->create([
            'user_id' => ($user ?? User::factory()->employee()->create())->id,
            'task_id' => Task::factory()->create(['title' => 'Maquetar la home'])->id,
            'started_at' => CarbonImmutable::parse($startedAt, 'Europe/Madrid')->utc(),
            ...$attributes,
        ]);
    };
});

it('avisa a quien supera timer_warning_hours y lo marca para no repetir', function () {
    Notification::fake();
    $long = ($this->timer)('2026-09-24 09:00:00');
    $short = ($this->timer)('2026-09-24 12:00:00');

    $this->artisan('timers:warn')->assertSuccessful();

    Notification::assertSentTo($long->user, TimerRunningLong::class, function (TimerRunningLong $notification) use ($long) {
        $data = $notification->toArray($long->user);

        return $data['kind'] === 'time.timer_long'
            && $data['title'] === 'Tu temporizador lleva más de 10 horas en marcha'
            && str_contains((string) $data['body'], 'Maquetar la home')
            && $data['url'] === '/horas?semana=2026-W39';
    });
    Notification::assertNotSentTo($short->user, TimerRunningLong::class);
    expect($long->fresh()->warned_at)->not->toBeNull()
        ->and($short->fresh()->warned_at)->toBeNull();

    // Una hora después: el largo ya avisó; el corto aún no llega a 10 h.
    $this->travelTo(CarbonImmutable::parse('2026-09-24 21:00:00', 'Europe/Madrid'));
    $this->artisan('timers:warn')->assertSuccessful();
    Notification::assertSentToTimes($long->user, TimerRunningLong::class, 1);

    // Con 10 h cumplidas, avisa al segundo.
    $this->travelTo(CarbonImmutable::parse('2026-09-24 22:00:00', 'Europe/Madrid'));
    $this->artisan('timers:warn')->assertSuccessful();
    Notification::assertSentToTimes($short->user, TimerRunningLong::class, 1);
});

it('respeta el ajuste de horas y no avisa a personas desactivadas', function () {
    Notification::fake();
    Setting::set('timer_warning_hours', 4);
    $active = ($this->timer)('2026-09-24 15:30:00');
    $inactive = ($this->timer)('2026-09-24 08:00:00', User::factory()->employee()->inactive()->create());

    $this->artisan('timers:warn')->assertSuccessful();

    Notification::assertSentTo($active->user, TimerRunningLong::class);
    Notification::assertNotSentTo($inactive->user, TimerRunningLong::class);
});

it('guarda el aviso en la campana (canal database)', function () {
    $timer = ($this->timer)('2026-09-24 06:00:00');

    $this->artisan('timers:warn')->assertSuccessful();

    $notification = $timer->user->notifications()->sole();
    expect($notification->data['kind'])->toBe('time.timer_long')
        ->and($notification->data['icon'])->toBe('timer');
});

it('programa timers:warn cada hora sin solaparse y app:notify-due-tasks a las 8:00 de Madrid solo si existe', function () {
    /** @var list<ScheduledEvent> $events */
    $events = app(Schedule::class)->events();
    $find = fn (string $command): ?ScheduledEvent => collect($events)->first(fn (ScheduledEvent $event) => str_contains((string) $event->command, $command));

    $warn = $find('timers:warn');
    expect($warn)->not->toBeNull()
        ->and($warn->expression)->toBe('0 * * * *')
        ->and($warn->withoutOverlapping)->toBeTrue();

    $due = $find('app:notify-due-tasks');
    expect($due)->not->toBeNull()
        ->and($due->expression)->toBe('0 8 * * *')
        ->and($due->timezone)->toBe('Europe/Madrid')
        // Mientras el área de Tareas no cree el comando, la tarea se omite.
        ->and($due->filtersPass(app()))->toBe(array_key_exists('app:notify-due-tasks', Artisan::all()));
});
