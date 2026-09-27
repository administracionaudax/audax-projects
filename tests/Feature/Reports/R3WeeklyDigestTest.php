<?php

use App\Console\Commands\SendWeeklyDigest;
use App\Domain\Reports\WeeklyDigest;
use App\Enums\HourBankStatus;
use App\Models\Department;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\Setting;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Notifications\Reports\WeeklyDigestNotification;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Reports\R3Scenario;

/*
| Resumen semanal de productividad (SPEC §10, D-047): reports:weekly-digest los lunes a las 08:00
| de Madrid sobre la semana anterior (21 al 27/09/2026), a cada responsable (su equipo) y a cada
| admin (toda la agencia). Cifras calculadas a mano sobre R3Scenario más:
|  - Marta (Diseño, 8 h/día) imputa 9 h cada día laborable: 45:00 de 40:00 → 112,5 % (por encima del 110 %),
|  - Pedro (Diseño, 4 h/día) imputa 4:24 cada día: 22:00 de 20:00 → justo 110 % (no avisa),
|  - Ana: 11:00 de 40:00 (27,5 %), sin horas el lunes 21 y el viernes 25,
|  - Luis: 12:40 de 20:00 (63,3 %), sin horas el lunes 21 y el miércoles 23,
|  - la bolsa del escenario (Diseño) está agotada (700 de 600 min, 116,7 %); una de Marketing al 80 %,
|  - tareas vencidas: una de Ana (Diseño) y otra de la admin; no cuentan las completadas, las de
|    proyectos archivados ni las que vencen hoy.
*/

beforeEach(function () {
    $s = R3Scenario::build($this);
    $this->s = $s;

    $this->marta = User::factory()->employee()->create(['name' => 'Marta', 'department_id' => $s->design->id]);
    $this->pedro = User::factory()->employee()->create(['name' => 'Pedro', 'department_id' => $s->design->id]);
    WorkSchedule::factory()->for($this->marta)->create(['valid_from' => '2026-01-01']);
    WorkSchedule::factory()->for($this->pedro)->create(['valid_from' => '2026-01-01', 'mon_minutes' => 240, 'tue_minutes' => 240, 'wed_minutes' => 240, 'thu_minutes' => 240, 'fri_minutes' => 240]);
    foreach (['2026-09-21', '2026-09-22', '2026-09-23', '2026-09-24', '2026-09-25'] as $day) {
        TimeEntry::factory()->forTask($s->tmTask)->on($day)->minutes(540)->create(['user_id' => $this->marta->id]);
        TimeEntry::factory()->forTask($s->tmTask)->on($day)->minutes(264)->create(['user_id' => $this->pedro->id]);
    }

    // Bolsas: la del escenario pasa a Diseño (agotada); una de Marketing al 80 %; una sana.
    $s->bank->update(['department_id' => $s->design->id]);
    $this->marketing = Department::factory()->create(['name' => 'Marketing']);
    $this->marketingBank = HourBank::factory()->forDepartment($this->marketing)->create(['total_minutes' => 600, 'name' => 'Bolsa SEO']);
    $this->marketingBank->project->update(['code' => 'SEO']);
    TimeEntry::factory()->forTask(Task::factory()->inBank($this->marketingBank)->create())->on('2026-09-10')->minutes(480)->create(['user_id' => $s->admin->id]);
    $healthy = HourBank::factory()->forDepartment($s->design)->create(['total_minutes' => 6000]);
    TimeEntry::factory()->forTask(Task::factory()->inBank($healthy)->create())->on('2026-09-10')->minutes(600)->create(['user_id' => $s->ana->id]);

    // Tareas vencidas (hoy es el lunes 28).
    Task::factory()->assignedTo($s->ana)->create(['project_id' => $s->tm->id, 'title' => 'Revisar textos', 'due_date' => '2026-09-20']);
    Task::factory()->assignedTo($s->admin)->create(['project_id' => $s->tm->id, 'title' => 'Enviar factura', 'due_date' => '2026-09-01']);
    Task::factory()->assignedTo($s->ana)->completed()->create(['project_id' => $s->tm->id, 'due_date' => '2026-09-20']);
    Task::factory()->assignedTo($s->ana)->create(['project_id' => $s->tm->id, 'due_date' => '2026-09-28']);
    $archived = Project::factory()->archived()->create();
    Task::factory()->assignedTo($s->ana)->create(['project_id' => $archived->id, 'due_date' => '2026-09-01']);

    $this->travelTo(CarbonImmutable::parse('2026-09-28 08:00', 'Europe/Madrid'));
    $this->digest = fn (User $user): array => app(WeeklyDigest::class)->for($user, WeeklyDigest::previousWeek(CarbonImmutable::parse('2026-09-28')), CarbonImmutable::parse('2026-09-28'));
    // HTML del email sin los estilos en línea que añade la plantilla.
    $this->mailHtml = fn (WeeklyDigestNotification $notification, User $user): string => (string) preg_replace('/ style="[^"]*"/', '', (string) $notification->toMail($user)->render());
});

it('resume la semana anterior del equipo de un responsable, como a mano', function () {
    $s = $this->s;
    $digest = ($this->digest)($s->head);

    expect($digest['scope'])->toBe('team')
        ->and($digest['from'])->toBe('2026-09-21')
        ->and($digest['to'])->toBe('2026-09-27')
        ->and($digest['unlogged'])->toBe([
            ['user_id' => $s->ana->id, 'name' => 'Ana', 'days' => ['2026-09-21', '2026-09-25']],
            ['user_id' => $s->luis->id, 'name' => 'Luis', 'days' => ['2026-09-21', '2026-09-23']],
        ])
        ->and($digest['high'])->toBe([
            ['user_id' => $this->marta->id, 'name' => 'Marta', 'occupancy' => 1.125, 'logged_minutes' => 2700, 'capacity_minutes' => 2400],
        ])
        ->and($digest['low'])->toBe([
            ['user_id' => $s->ana->id, 'name' => 'Ana', 'occupancy' => 0.275, 'logged_minutes' => 660, 'capacity_minutes' => 2400],
            ['user_id' => $s->luis->id, 'name' => 'Luis', 'occupancy' => 0.6333, 'logged_minutes' => 760, 'capacity_minutes' => 1200],
        ])
        ->and($digest['banks'])->toBe([
            ['id' => $s->bank->id, 'project_id' => $s->bank->project_id, 'name' => 'BOL · Bolsa anual', 'consumed_pct' => 116.67, 'remaining_minutes' => 0, 'overage_minutes' => 100],
        ])
        ->and($digest['overdue_count'])->toBe(1)
        ->and($digest['overdue'][0])->toMatchArray(['title' => 'Revisar textos', 'project' => 'TM', 'assignee' => 'Ana', 'due_date' => '2026-09-20'])
        ->and($digest['thresholds'])->toBe(['low' => 70, 'high' => 110]);
});

it('a un admin le resume toda la agencia', function () {
    $s = $this->s;
    $digest = ($this->digest)($s->admin);
    $ids = fn (string $section): array => array_column($digest[$section], 'user_id');

    expect($digest['scope'])->toBe('agency')
        ->and($ids('high'))->toBe([$this->marta->id])
        ->and($ids('low'))->toContain($s->ana->id, $s->luis->id)
        ->and($ids('unlogged'))->toContain($s->ana->id, $s->luis->id)->not->toContain($this->marta->id, $this->pedro->id)
        ->and(array_column($digest['banks'], 'name'))->toBe(['BOL · Bolsa anual', 'SEO · Bolsa SEO'])
        ->and($digest['overdue_count'])->toBe(2)
        ->and(array_column($digest['overdue'], 'title'))->toBe(['Enviar factura', 'Revisar textos']);
});

it('respeta los umbrales de ocupación configurados', function () {
    Setting::set('occupancy_low_threshold', 60);
    Setting::set('occupancy_high_threshold', 120);

    $digest = ($this->digest)($this->s->head);

    expect($digest['high'])->toBe([])
        ->and(array_column($digest['low'], 'name'))->toBe(['Ana'])
        ->and($digest['thresholds'])->toBe(['low' => 60, 'high' => 120]);
});

it('envía el resumen a responsables y admins activos, y a nadie más', function () {
    Notification::fake();
    $s = $this->s;
    $inactiveAdmin = User::factory()->admin()->inactive()->create();

    $this->artisan('reports:weekly-digest')->assertSuccessful();

    Notification::assertSentTo($s->head, WeeklyDigestNotification::class, fn (WeeklyDigestNotification $n) => $n->digest['scope'] === 'team'
        && array_column($n->digest['high'], 'name') === ['Marta']);
    Notification::assertSentTo($s->admin, WeeklyDigestNotification::class, fn (WeeklyDigestNotification $n) => $n->digest['scope'] === 'agency');

    foreach ([$s->ana, $s->luis, $s->manager, $s->client, $inactiveAdmin, $this->marta] as $user) {
        Notification::assertNotSentTo($user, WeeklyDigestNotification::class);
    }
});

it('no envía nada si no hay nada que contar', function () {
    Notification::fake();
    $s = $this->s;
    // Un departamento en orden: Olga imputa justo su jornada y no hay bolsas ni tareas vencidas.
    $quiet = Department::factory()->create();
    $boss = User::factory()->departmentManager()->create();
    $quiet->managers()->attach($boss);
    $olga = User::factory()->employee()->create(['department_id' => $quiet->id]);
    WorkSchedule::factory()->for($olga)->create(['valid_from' => '2026-01-01']);
    foreach (['2026-09-21', '2026-09-22', '2026-09-23', '2026-09-24', '2026-09-25'] as $day) {
        TimeEntry::factory()->forTask($s->tmTask)->on($day)->minutes(480)->create(['user_id' => $olga->id]);
    }
    // Y un responsable de un departamento vacío.
    $empty = Department::factory()->create();
    $lonely = User::factory()->departmentManager()->create();
    $empty->managers()->attach($lonely);

    expect(WeeklyDigest::isEmpty(($this->digest)($boss)))->toBeTrue()
        ->and(WeeklyDigest::isEmpty(($this->digest)($lonely)))->toBeTrue();

    $this->artisan('reports:weekly-digest')->assertSuccessful();

    Notification::assertNotSentTo($boss, WeeklyDigestNotification::class);
    Notification::assertNotSentTo($lonely, WeeklyDigestNotification::class);
    Notification::assertSentTo($s->head, WeeklyDigestNotification::class);
});

it('un responsable de un departamento sin personas recibe solo sus bolsas en riesgo', function () {
    Notification::fake();
    $mara = User::factory()->departmentManager()->create(['name' => 'Mara']);
    $this->marketing->managers()->attach($mara);

    $this->artisan('reports:weekly-digest')->assertSuccessful();

    Notification::assertSentTo($mara, WeeklyDigestNotification::class, fn (WeeklyDigestNotification $n) => array_column($n->digest['banks'], 'name') === ['SEO · Bolsa SEO']
        && $n->digest['unlogged'] === [] && $n->digest['overdue_count'] === 0);
});

it('no envía nada con el ajuste desactivado', function () {
    Notification::fake();
    Setting::set('weekly_digest_enabled', false);

    $this->artisan('reports:weekly-digest')
        ->expectsOutputToContain('desactivado')
        ->assertSuccessful();

    Notification::assertNothingSent();
});

it('no repite el resumen si el comando se ejecuta dos veces la misma semana', function () {
    Notification::fake();

    $this->artisan('reports:weekly-digest')->assertSuccessful();
    $this->artisan('reports:weekly-digest')->assertSuccessful();

    Notification::assertSentToTimes($this->s->head, WeeklyDigestNotification::class, 1);
    expect(Cache::has(SendWeeklyDigest::claimKey($this->s->head->id, '2026-09-21')))->toBeTrue();

    // La semana siguiente, otra vez.
    $this->travelTo(CarbonImmutable::parse('2026-10-05 08:00', 'Europe/Madrid'));
    $this->artisan('reports:weekly-digest')->assertSuccessful();
    Notification::assertSentToTimes($this->s->head, WeeklyDigestNotification::class, 2);
});

it('va por email (cola mail) y a la campana, con el texto en español y la empresa', function () {
    $s = $this->s;
    Setting::set('company_name', 'Audax Studio SL');
    $notification = new WeeklyDigestNotification(($this->digest)($s->head));

    expect($notification->via($s->head))->toBe(['mail', 'database'])
        ->and($notification->viaQueues()['mail'])->toBe('mail')
        ->and($notification->toArray($s->head))->toBe([
            'kind' => 'reports.weekly_digest',
            'title' => 'Resumen semanal del 21/09 al 27/09',
            'body' => '2 personas con días sin imputar · 3 personas fuera de los umbrales de ocupación · 1 bolsa en riesgo · 1 tarea vencida',
            'url' => '/informes/detalle?periodo=semana&fecha=2026-09-21&filas=persona&columnas=proyecto',
            'icon' => 'gauge',
        ]);

    $mail = $notification->toMail($s->head);
    $html = ($this->mailHtml)($notification, $s->head);

    expect($mail->subject)->toBe('Resumen semanal de productividad: del 21/09 al 27/09')
        ->and($html)->toContain('Hola, Raúl:')
        ->and($html)->toContain('tu equipo en la semana del 21/09 al 27/09')
        ->and($html)->toContain('<strong>Días sin imputar</strong>')
        ->and($html)->toContain('<li>Ana: lunes 21/09, viernes 25/09</li>')
        ->and($html)->toContain('<li>Luis: lunes 21/09, miércoles 23/09</li>')
        ->and($html)->toContain('Ocupación por encima del 110 %')
        ->and($html)->toContain('<li>Marta: 113 % (45:00 de 40:00)</li>')
        ->and($html)->toContain('Ocupación por debajo del 70 %')
        ->and($html)->toContain('<li>Ana: 28 % (11:00 de 40:00)</li>')
        ->and($html)->toContain('<li>BOL · Bolsa anual: 117 % consumido, con 1:40 de exceso</li>')
        ->and($html)->toContain('Tareas vencidas (1)')
        ->and($html)->toContain('«Revisar textos» (TM), de Ana, vencía el 20/09/2026')
        ->and($html)->toContain('Ver el informe de la semana')
        ->and($html)->toContain('/informes/detalle?periodo=semana&amp;fecha=2026-09-21&amp;filas=persona&amp;columnas=proyecto')
        ->and($html)->toContain('Audax Studio SL');
});

it('en el email, los nombres nunca se interpretan como HTML ni Markdown', function () {
    $s = $this->s;
    $s->ana->update(['name' => '<b>Ana</b> *la* [jefa](http://x)']);
    $html = ($this->mailHtml)(new WeeklyDigestNotification(($this->digest)($s->head)), $s->head);

    expect($html)->not->toContain('<b>Ana</b>')
        ->and($html)->not->toContain('<em>la</em>')
        ->and($html)->not->toContain('href="http://x"')
        ->and($html)->toContain('<li>&lt;b&gt;Ana&lt;/b&gt; *la* [jefa](http://x): lunes 21/09, viernes 25/09</li>');
});

it('cita como mucho 10 elementos por sección y resume el resto', function () {
    $s = $this->s;
    foreach (range(1, 12) as $n) {
        Task::factory()->assignedTo($s->luis)->create(['project_id' => $s->tm->id, 'title' => "Vencida {$n}", 'due_date' => '2026-09-15']);
    }

    $digest = ($this->digest)($s->head);
    $html = ($this->mailHtml)(new WeeklyDigestNotification($digest), $s->head);

    expect($digest['overdue_count'])->toBe(13)
        ->and($digest['overdue'])->toHaveCount(13)
        ->and(substr_count($html, 'vencía el'))->toBe(WeeklyDigestNotification::ITEMS_IN_MAIL)
        ->and($html)->toContain('Tareas vencidas (13)')
        ->and($html)->toContain('<li>y 3 más</li>');
});

it('se programa los lunes a las 08:00 de Madrid, sin solaparse y en un solo servidor', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn (ScheduledEvent $event) => str_contains((string) $event->command, 'reports:weekly-digest'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 8 * * 1')
        ->and($event->timezone)->toBe('Europe/Madrid')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->onOneServer)->toBeTrue()
        ->and($event->filtersPass(app()))->toBeTrue();

    // Con el ajuste desactivado, el programador ni siquiera lo lanza.
    Setting::set('weekly_digest_enabled', false);
    expect($event->filtersPass(app()))->toBeFalse();
});

it('solo reciben admins y responsables de algún departamento', function () {
    $s = $this->s;
    $roleOnly = User::factory()->departmentManager()->create();

    expect(WeeklyDigest::receives($s->admin))->toBeTrue()
        ->and(WeeklyDigest::receives($s->head))->toBeTrue()
        ->and(WeeklyDigest::receives($roleOnly))->toBeFalse()
        ->and(WeeklyDigest::receives($s->ana))->toBeFalse()
        ->and(WeeklyDigest::receives($s->client))->toBeFalse()
        ->and(HourBankStatus::from($s->bank->status->value))->toBe(HourBankStatus::Exhausted);
});
