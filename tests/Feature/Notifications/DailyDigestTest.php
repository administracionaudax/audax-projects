<?php

use App\Console\Commands\SendDailyDigest;
use App\Domain\Notifications\NotificationPreferences;
use App\Models\Client;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\DailyDigestNotification;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
| Resumen diario por email (SPEC §13, D-073): notifications:daily-digest, a las 08:00 de Madrid, a
| quien lo tiene activado, con sus avisos SIN LEER de las últimas 24 h cuyo email quiere (no los
| obligatorios), agrupados como en /ajustes/notificaciones. Si no hay nada, no se envía. Hoy es el
| martes 29/09/2026 a las 08:00 de Madrid (06:00 UTC).
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-29 08:00', 'Europe/Madrid'));
    $this->preferences = app(NotificationPreferences::class);

    $this->ana = User::factory()->employee()->create(['name' => 'Ana Ruiz']);
    $this->preferences->update($this->ana, [], true);
    $this->ana->refresh();

    // Un aviso de la campana de $user, creado hace $minutesAgo minutos.
    $this->notice = function (User $user, string $kind, string $title, int $minutesAgo = 60, array $attributes = []): DatabaseNotification {
        return DatabaseNotification::query()->create([
            'id' => (string) Str::uuid(),
            'type' => 'App\\Notifications\\Prueba',
            'notifiable_type' => $user->getMorphClass(),
            'notifiable_id' => $user->id,
            'data' => ['kind' => $kind, 'title' => $title, 'body' => null, 'url' => $attributes['url'] ?? '/mis-tareas', 'icon' => null],
            'read_at' => $attributes['read_at'] ?? null,
            'created_at' => now()->subMinutes($minutesAgo),
            'updated_at' => now()->subMinutes($minutesAgo),
        ]);
    };

    // El DailyDigestNotification que recibió $user (con Notification::fake).
    $this->sentTo = function (User $user): DailyDigestNotification {
        $sent = Notification::sent($user, DailyDigestNotification::class);
        expect($sent)->toHaveCount(1);

        return $sent->first();
    };

    // HTML del email sin los estilos en línea que añade la plantilla.
    $this->html = fn (DailyDigestNotification $notification, User $user): string => (string) preg_replace('/ style="[^"]*"/', '', (string) $notification->toMail($user)->render());
});

it('agrupa los avisos sin leer de las últimas 24 h cuyo email quiere, en el orden de la página', function () {
    Notification::fake();
    ($this->notice)($this->ana, 'absence.approved', 'Tu ausencia está aprobada', 30, ['url' => '/ausencias']);
    ($this->notice)($this->ana, 'task.due', 'Tienes 2 tareas que vencen mañana', 120);
    ($this->notice)($this->ana, 'time.returned', 'Te han devuelto las horas de la semana del 21/09/2026', 600, ['url' => '/horas?semana=2026-W39']);
    ($this->notice)($this->ana, 'task.due', 'Tienes 1 tarea vencida', 1400);
    // Fuera: sin email por defecto (asignación y semana aprobada), leído o de hace más de 24 h.
    ($this->notice)($this->ana, 'task.assigned', 'Te han asignado «Maquetar»', 10);
    ($this->notice)($this->ana, 'time.approved', 'Tus horas están aprobadas', 10);
    ($this->notice)($this->ana, 'absence.rejected', 'Ya leída', 10, ['read_at' => now()]);
    ($this->notice)($this->ana, 'task.due', 'De anteayer', 24 * 60 + 5);
    // Y un evento que no está en el catálogo.
    ($this->notice)($this->ana, 'desconocido.evento', 'Nada', 10);

    $this->artisan('notifications:daily-digest')
        ->expectsOutputToContain('Resúmenes enviados: 1.')
        ->assertSuccessful();

    $digest = ($this->sentTo)($this->ana);

    expect($digest->hours)->toBe(24)
        ->and($digest->total())->toBe(4)
        ->and($digest->groups)->toBe([
            ['group' => 'tasks', 'total' => 2, 'items' => [
                ['title' => 'Tienes 2 tareas que vencen mañana', 'url' => '/mis-tareas'],
                ['title' => 'Tienes 1 tarea vencida', 'url' => '/mis-tareas'],
            ]],
            ['group' => 'time', 'total' => 1, 'items' => [
                ['title' => 'Te han devuelto las horas de la semana del 21/09/2026', 'url' => '/horas?semana=2026-W39'],
            ]],
            ['group' => 'absences', 'total' => 1, 'items' => [
                ['title' => 'Tu ausencia está aprobada', 'url' => '/ausencias'],
            ]],
        ]);

    // El resumen no marca nada como leído: los avisos siguen en la campana.
    expect($this->ana->unreadNotifications()->count())->toBe(8);
});

it('respeta el email de cada evento: lo desactivado no va y lo activado sí', function () {
    Notification::fake();
    $this->preferences->update($this->ana, ['task.due' => ['email' => false], 'task.assigned' => ['email' => true]], true);
    ($this->notice)($this->ana, 'task.due', 'Tienes 1 tarea vencida');
    ($this->notice)($this->ana, 'task.assigned', 'Te han asignado «Maquetar»');

    $this->artisan('notifications:daily-digest')->assertSuccessful();

    expect(($this->sentTo)($this->ana)->groups)->toBe([
        ['group' => 'tasks', 'total' => 1, 'items' => [['title' => 'Te han asignado «Maquetar»', 'url' => '/mis-tareas']]],
    ]);
});

it('no envía nada a quien no tiene avisos pendientes, ni sin el resumen activado', function () {
    Notification::fake();
    $quiet = User::factory()->employee()->create();
    $this->preferences->update($quiet, [], true);
    $withoutDigest = User::factory()->employee()->create();
    ($this->notice)($withoutDigest, 'task.due', 'Tienes 1 tarea vencida');
    // Todo lo de Ana está leído o es antiguo.
    ($this->notice)($this->ana, 'task.due', 'Leída', 60, ['read_at' => now()]);
    ($this->notice)($this->ana, 'task.due', 'Antigua', 25 * 60);

    $this->artisan('notifications:daily-digest')
        ->expectsOutputToContain('Resúmenes enviados: 0.')
        ->assertSuccessful();

    Notification::assertNothingSent();
});

it('los avisos obligatorios no van al resumen: ya llegaron por email', function () {
    Notification::fake();
    $admin = User::factory()->admin()->create();
    $this->preferences->update($admin, [], true);
    ($this->notice)($admin, 'system.disk_space', 'El disco está al 90 %');

    $this->artisan('notifications:daily-digest')->assertSuccessful();

    Notification::assertNotSentTo($admin, DailyDigestNotification::class);
});

it('solo a personas internas y activas', function () {
    Notification::fake();
    $inactive = User::factory()->employee()->inactive()->create();
    $client = User::factory()->portalOf(Client::factory()->create())->create();

    foreach ([$inactive, $client] as $user) {
        $user->forceFill(['notification_preferences' => ['events' => [], 'daily_digest' => true]])->save();
        ($this->notice)($user, 'task.due', 'Tienes 1 tarea vencida');
    }
    ($this->notice)($this->ana, 'task.due', 'Tienes 1 tarea vencida');

    $this->artisan('notifications:daily-digest')->assertSuccessful();

    Notification::assertSentTo($this->ana, DailyDigestNotification::class);
    Notification::assertNotSentTo($inactive, DailyDigestNotification::class);
    Notification::assertNotSentTo($client, DailyDigestNotification::class);
});

it('abarca las horas configuradas', function () {
    Notification::fake();
    config(['notifications.daily_digest.hours' => 2]);
    ($this->notice)($this->ana, 'task.due', 'Reciente', 100);
    ($this->notice)($this->ana, 'time.returned', 'De hace tres horas', 180);

    $this->artisan('notifications:daily-digest')->assertSuccessful();

    $digest = ($this->sentTo)($this->ana);
    expect($digest->hours)->toBe(2)
        ->and(array_column($digest->groups, 'group'))->toBe(['tasks']);
});

it('no repite el resumen si se ejecuta dos veces el mismo día; al día siguiente, otro', function () {
    Notification::fake();
    ($this->notice)($this->ana, 'task.due', 'Tienes 1 tarea vencida');

    $this->artisan('notifications:daily-digest')->assertSuccessful();
    $this->travelTo(CarbonImmutable::parse('2026-09-29 11:30', 'Europe/Madrid'));
    $this->artisan('notifications:daily-digest')->assertSuccessful();

    Notification::assertSentToTimes($this->ana, DailyDigestNotification::class, 1);
    expect(Cache::has(SendDailyDigest::claimKey($this->ana->id, '2026-09-29')))->toBeTrue();

    $this->travelTo(CarbonImmutable::parse('2026-09-30 08:00', 'Europe/Madrid'));
    ($this->notice)($this->ana, 'time.returned', 'Te han devuelto la semana');
    $this->artisan('notifications:daily-digest')->assertSuccessful();

    Notification::assertSentToTimes($this->ana, DailyDigestNotification::class, 2);
});

it('si no se puede encolar, suelta la reclamación para poder reintentarlo', function () {
    ($this->notice)($this->ana, 'task.due', 'Tienes 1 tarea vencida');
    Notification::shouldReceive('send')->andThrow(new RuntimeException('Cola caída'));

    expect(fn () => $this->artisan('notifications:daily-digest')->run())->toThrow(RuntimeException::class);
    expect(Cache::has(SendDailyDigest::claimKey($this->ana->id, '2026-09-29')))->toBeFalse();
});

it('va solo por email, por la cola mail', function () {
    Queue::fake();
    ($this->notice)($this->ana, 'task.due', 'Tienes 1 tarea vencida');

    $this->artisan('notifications:daily-digest')->assertSuccessful();

    Queue::assertPushedOn('mail', SendQueuedNotifications::class, fn (SendQueuedNotifications $job): bool => $job->notification instanceof DailyDigestNotification
        && $job->channels === ['mail']);
    Queue::assertPushed(SendQueuedNotifications::class, 1);

    $notification = new DailyDigestNotification([], 24);
    expect($notification)->toBeInstanceOf(ShouldQueue::class)
        ->and($notification->queue)->toBe('mail')
        ->and($notification->via($this->ana))->toBe(['mail']);
});

it('el email agrupa con la identidad de la empresa y enlaza a la campana y a las preferencias', function () {
    Setting::set('company_name', 'Audax Studio SL');
    config(['notifications.daily_digest.items_per_group' => 2]);
    Notification::fake();
    foreach (range(1, 5) as $n) {
        ($this->notice)($this->ana, 'task.due', "Tarea vencida {$n}", 10 * $n, ['url' => "/proyectos/3/tareas?tarea={$n}"]);
    }
    ($this->notice)($this->ana, 'absence.approved', 'Tu ausencia está aprobada', 5, ['url' => '/ausencias']);

    $this->artisan('notifications:daily-digest')->assertSuccessful();

    $digest = ($this->sentTo)($this->ana);
    $mail = $digest->toMail($this->ana);
    $html = ($this->html)($digest, $this->ana);

    expect($mail->subject)->toBe('Resumen diario: 6 avisos sin leer')
        ->and($mail->greeting)->toBe('Hola, Ana Ruiz:')
        ->and($mail->actionUrl)->toBe(url('/notificaciones?filtro=sin-leer'))
        ->and($html)->toContain('Estos son los 6 avisos de las últimas 24 horas que aún no has leído:')
        ->and($html)->toContain('<strong>Tareas (5)</strong>')
        ->and($html)->toContain('<a href="'.url('/proyectos/3/tareas?tarea=1').'">Tarea vencida 1</a>')
        ->and($html)->toContain('<a href="'.url('/proyectos/3/tareas?tarea=2').'">Tarea vencida 2</a>')
        ->and($html)->not->toContain('Tarea vencida 3')
        ->and($html)->toContain('<a href="'.url('/notificaciones?filtro=sin-leer').'">y 3 más</a>')
        ->and($html)->toContain('<strong>Ausencias (1)</strong>')
        ->and($html)->toContain('<a href="'.url('/ausencias').'">Tu ausencia está aprobada</a>')
        ->and(strpos($html, 'Tareas (5)'))->toBeLessThan(strpos($html, 'Ausencias (1)'))
        ->and($html)->toContain('Ver mis notificaciones')
        ->and($html)->toContain('Recibes este resumen porque lo tienes activado')
        ->and($html)->toContain('<a href="'.url('/ajustes/notificaciones').'">Cambiar mis preferencias de notificación</a>')
        ->and($html)->toContain('Audax Studio SL');

    // Con un solo aviso, en singular.
    $single = new DailyDigestNotification([['group' => 'time', 'total' => 1, 'items' => [['title' => 'Semana devuelta', 'url' => null]]]], 24);
    expect($single->toMail($this->ana)->subject)->toBe('Resumen diario: 1 aviso sin leer')
        ->and(($this->html)($single, $this->ana))->toContain('Este es el aviso de las últimas 24 horas que aún no has leído:')
        ->and(($this->html)($single, $this->ana))->toContain('<li>Semana devuelta</li>');
});

it('en el email, los títulos nunca se interpretan como HTML ni Markdown y los enlaces son de la app', function () {
    $this->ana->update(['name' => 'Ana [jefa](http://x) *R*']);
    $digest = new DailyDigestNotification([['group' => 'tasks', 'total' => 2, 'items' => [
        ['title' => "<b>Web</b> *urgente* [pincha](http://malo.example) d'Audax", 'url' => '/tareas/1'],
        ['title' => '- Revisar', 'url' => null],
    ]]], 24);

    $html = ($this->html)($digest, $this->ana);

    expect($html)->not->toContain('<b>Web</b>')
        ->and($html)->not->toContain('<em>urgente</em>')
        ->and($html)->not->toContain('href="http://malo.example"')
        ->and($html)->not->toContain('href="http://x"')
        ->and($html)->toContain("&lt;b&gt;Web&lt;/b&gt; *urgente* [pincha](http://malo.example) d'Audax</a>")
        ->and($html)->toContain('<li>- Revisar</li>')
        ->and($html)->toContain('Hola, Ana [jefa](http://x) *R*:');
});

it('guarda enlaces de la app y nunca direcciones externas', function () {
    Notification::fake();
    ($this->notice)($this->ana, 'task.due', 'Externa', 10, ['url' => 'https://malo.example/robo']);
    ($this->notice)($this->ana, 'task.due', 'Sin barra', 20, ['url' => '//malo.example']);

    $this->artisan('notifications:daily-digest')->assertSuccessful();

    expect(array_column(($this->sentTo)($this->ana)->groups[0]['items'], 'url'))->toBe([null, null]);
});

it('hace las mismas consultas con 5 personas que con 50', function () {
    Notification::fake();
    $count = 0;
    DB::listen(function (QueryExecuted $query) use (&$count): void {
        $count++;
    });
    $withNotices = function (iterable $users): void {
        foreach ($users as $user) {
            $this->preferences->update($user, [], true);
            foreach (['task.due', 'time.returned', 'absence.approved'] as $kind) {
                ($this->notice)($user, $kind, "Aviso {$kind}");
            }
        }
    };

    $withNotices([$this->ana, ...User::factory()->employee()->count(4)->create()]);
    $count = 0;
    $this->artisan('notifications:daily-digest')->assertSuccessful();
    $few = $count;

    $withNotices(User::factory()->employee()->count(45)->create());
    Cache::flush();
    $count = 0;
    $this->artisan('notifications:daily-digest')->assertSuccessful();
    $many = $count;

    Notification::assertSentTimes(DailyDigestNotification::class, 5 + 50);
    expect($few)->toBeLessThanOrEqual(3)
        ->and($many)->toBe($few);
});

it('se programa cada día a las 08:00 de Madrid, sin solaparse y en un solo servidor', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn (ScheduledEvent $event) => str_contains((string) $event->command, 'notifications:daily-digest'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 8 * * *')
        ->and($event->timezone)->toBe('Europe/Madrid')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->onOneServer)->toBeTrue()
        // El comando ya existe: la guarda del contrato lo deja pasar.
        ->and($event->filtersPass(app()))->toBeTrue();
});
