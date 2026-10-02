<?php

use App\Enums\PersonalDataExportStatus;
use App\Models\Absence;
use App\Models\Client;
use App\Models\HourBank;
use App\Models\LoginEvent;
use App\Models\PersonalDataExport;
use App\Models\Project;
use App\Models\Setting;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\TaskStatus;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

/*
| Retención (SPEC §15, D-075): app:prune-data borra por lotes lo anterior a cada plazo (registros de
| acceso, notificaciones LEÍDAS, auditoría y mensajes del chat, en ChatRetentionTest), caduca las exportaciones vencidas y da por fallidas
| las atascadas. NUNCA borra horas, bolsas, tareas, proyectos ni clientes.
*/

beforeEach(function () {
    TaskStatus::ensureDefaults();
    Storage::fake('local');
    $this->travelTo('2026-10-05 01:10:00');

    $this->user = User::factory()->employee()->create();
    $this->at = fn (string $value): CarbonImmutable => CarbonImmutable::parse($value, 'UTC');

    // created_at no es asignable en LoginEvent (registro inmutable): se fuerza para los tests.
    $this->login = fn (string $when): LoginEvent => LoginEvent::query()->forceCreate([
        'user_id' => $this->user->id,
        'email' => $this->user->email,
        'ip_address' => '10.0.0.1',
        'user_agent' => 'Firefox',
        'succeeded' => true,
        'created_at' => ($this->at)($when),
    ]);

    $this->notification = fn (string $created, ?string $read): string => tap((string) Str::uuid(), fn (string $id) => DB::table('notifications')->insert([
        'id' => $id,
        'type' => 'test',
        'notifiable_type' => $this->user->getMorphClass(),
        'notifiable_id' => $this->user->id,
        'data' => json_encode(['kind' => 'task.assigned', 'title' => 'Aviso', 'body' => null, 'url' => null, 'icon' => null]),
        'read_at' => $read === null ? null : ($this->at)($read),
        'created_at' => ($this->at)($created),
        'updated_at' => ($this->at)($created),
    ]));

    $this->activity = fn (string $when): Activity => Activity::query()->create([
        'log_name' => 'projects',
        'description' => 'updated',
        'event' => 'updated',
        'created_at' => ($this->at)($when),
    ]);
});

test('borra lo anterior a cada plazo y conserva lo demás', function () {
    $oldLogin = ($this->login)('2025-10-04 23:00:00');
    $recentLogin = ($this->login)('2025-10-05 02:00:00');

    // Notificaciones: leídas hace más de 6 meses → fuera; leídas hace menos → se quedan; sin leer → nunca.
    $oldRead = ($this->notification)('2026-01-10 10:00:00', '2026-04-04 10:00:00');
    $recentRead = ($this->notification)('2026-01-10 10:00:00', '2026-04-06 10:00:00');
    $unread = ($this->notification)('2019-01-10 10:00:00', null);

    $oldActivity = ($this->activity)('2021-10-04 12:00:00');
    $recentActivity = ($this->activity)('2021-10-06 12:00:00');

    $this->artisan('app:prune-data')->assertSuccessful();

    expect(LoginEvent::query()->pluck('id')->all())->toBe([$recentLogin->id])
        ->and(DB::table('notifications')->pluck('id')->sort()->values()->all())->toBe(collect([$recentRead, $unread])->sort()->values()->all())
        ->and(Activity::query()->pluck('id')->all())->toBe([$recentActivity->id]);

    expect(LoginEvent::query()->find($oldLogin->id))->toBeNull()
        ->and(DB::table('notifications')->where('id', $oldRead)->exists())->toBeFalse()
        ->and(Activity::query()->find($oldActivity->id))->toBeNull();
});

test('«sin límite» no borra nada y los plazos salen de los ajustes', function () {
    Setting::set('retention_activity_log_months', null);
    Setting::set('retention_login_events_months', 1);
    ($this->activity)('2000-01-01 00:00:00');
    $kept = ($this->login)('2026-09-06 00:00:00');
    ($this->login)('2026-09-04 00:00:00');

    $this->artisan('app:prune-data')->assertSuccessful();

    expect(Activity::query()->count())->toBe(1)
        ->and(LoginEvent::query()->pluck('id')->all())->toBe([$kept->id]);
});

test('borra por lotes hasta terminar', function () {
    config(['privacy.prune_batch_size' => 2]);

    foreach (range(1, 5) as $day) {
        ($this->login)("2024-01-0{$day} 10:00:00");
    }
    $kept = ($this->login)('2026-09-01 10:00:00');

    $this->artisan('app:prune-data')->assertSuccessful();

    expect(LoginEvent::query()->pluck('id')->all())->toBe([$kept->id]);
});

test('NUNCA borra horas, bolsas, tareas, proyectos, clientes ni nada fuera de sus tablas', function () {
    // Datos de hace más de diez años, más antiguos que cualquier plazo posible.
    $this->travelTo('2014-03-03 10:00:00');
    $client = Client::factory()->create();
    $project = Project::factory()->create(['client_id' => $client->id]);
    $bank = HourBank::factory()->create(['project_id' => $project->id]);
    $task = Task::factory()->create(['project_id' => $project->id, 'hour_bank_id' => $bank->id]);
    TimeEntry::factory()->forTask($task)->create(['user_id' => $this->user->id, 'date' => '2014-03-03']);
    Absence::factory()->create(['user_id' => $this->user->id, 'start_date' => '2014-03-10', 'end_date' => '2014-03-12']);
    TaskComment::factory()->create(['task_id' => $task->id, 'user_id' => $this->user->id]);
    $client->delete();
    $this->travelTo('2026-10-05 01:10:00');

    // Los plazos más cortos que se pueden fijar.
    Setting::set('retention_login_events_months', 1);
    Setting::set('retention_read_notifications_months', 1);
    Setting::set('retention_activity_log_months', 12);
    Setting::set('retention_chat_messages_months', 1);

    $pruned = ['login_events', 'notifications', 'activity_log', 'personal_data_exports', 'cache', 'cache_locks', 'sessions'];
    $tables = array_values(array_diff(array_column(Schema::getTables(), 'name'), $pruned));
    $counts = fn (): array => collect($tables)->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all();

    $before = $counts();
    expect($before['time_entries'])->toBe(1)
        ->and($before['hour_banks'])->toBe(1)
        ->and($before['tasks'])->toBe(1)
        ->and($before['projects'])->toBe(1)
        ->and($before['clients'])->toBe(1);

    $this->artisan('app:prune-data')->assertSuccessful();

    expect($counts())->toBe($before)
        ->and(Client::withTrashed()->count())->toBe(1)
        ->and(Activity::query()->count())->toBe(0);
});

test('caduca las exportaciones vencidas (borra el fichero) y da por fallidas las atascadas', function () {
    $expired = PersonalDataExport::factory()->ready()->create(['subject_user_id' => $this->user->id, 'expires_at' => now()->subMinute()]);
    $valid = PersonalDataExport::factory()->ready()->create(['subject_user_id' => $this->user->id, 'expires_at' => now()->addDay()]);
    Storage::disk('local')->put((string) $expired->path, 'zip');
    Storage::disk('local')->put((string) $valid->path, 'zip');

    $stuck = PersonalDataExport::factory()->create(['subject_user_id' => $this->user->id, 'status' => PersonalDataExportStatus::Processing, 'created_at' => now()->subHours(25)]);
    $recent = PersonalDataExport::factory()->create(['subject_user_id' => $this->user->id, 'status' => PersonalDataExportStatus::Pending, 'created_at' => now()->subHour()]);

    $this->artisan('app:prune-data')->assertSuccessful();

    expect($expired->refresh()->status)->toBe(PersonalDataExportStatus::Expired)
        ->and($expired->path)->toBeNull()
        ->and($valid->refresh()->status)->toBe(PersonalDataExportStatus::Ready)
        ->and($stuck->refresh()->status)->toBe(PersonalDataExportStatus::Failed)
        ->and($stuck->error)->toContain('app:prune-data')
        ->and($recent->refresh()->status)->toBe(PersonalDataExportStatus::Pending)
        ->and(Storage::disk('local')->allFiles('exports/personal-data'))->toBe([(string) $valid->path]);
});

test('deja un resumen en el log con lo borrado de cada tipo', function () {
    Setting::set('retention_chat_messages_months', 12);
    ($this->login)('2020-01-01 00:00:00');
    Log::spy();

    $this->artisan('app:prune-data')->assertSuccessful();

    Log::shouldHaveReceived('info')->once()->withArgs(fn (string $message, array $context): bool => $message === 'app:prune-data'
        && $context['login_events'] === 1
        && $context['read_notifications'] === 0
        && $context['activity_log'] === 0
        && $context['chat_messages'] === 0
        && $context['expired_exports'] === 0
        && $context['stuck_exports'] === 0);
});

test('se programa cada día a las 03:10 de Madrid, sin solaparse', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn (ScheduledEvent $event): bool => str_contains((string) $event->command, 'app:prune-data'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('10 3 * * *')
        ->and($event->timezone)->toBe('Europe/Madrid')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->filtersPass(app()))->toBeTrue();
});
