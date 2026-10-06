<?php

use App\Domain\Weeklies\AppModules;
use App\Domain\Weeklies\Reminders\WeeklyReminders;
use App\Enums\AppModule;
use App\Enums\WeeklyReminderChannel;
use App\Models\Setting;
use App\Models\SuggestionBoard;
use App\Models\SuggestionPost;
use App\Models\User;
use App\Models\WeeklyCycle;
use App\Models\WeeklyReminderLog;
use App\Models\WeeklyReminderRule;
use App\Notifications\Suggestions\SuggestionReplied;
use App\Notifications\Suggestions\SuggestionStatusChanged;
use App\Notifications\Time\WeekSubmissionReminder;
use App\Notifications\Weeklies\WeeklyReminder;
use Carbon\CarbonImmutable;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Modo de prueba de los módulos (D-239, ajuste `modules_preview`): con un módulo apagado, los admins
| lo ven y lo usan como si estuviera encendido (rutas, navegación, búsqueda e Inicio); el resto de la
| plantilla no (404 y sin menú). Los procesos automáticos y los avisos a otras personas siguen
| usando el módulo encendido de verdad: en modo de prueba no hacen nada.
| Semana del 05/10/2026; hoy, viernes 09/10 a las 16:02 de Madrid.
*/

// Los de la Weekly; el plan del día (D-250) es un módulo aparte y sigue encendido.
const PREVIEW_ALL_OFF = ['weeklies' => false, 'project_status' => false, 'help' => false, 'suggestions' => false, 'assistant' => false];

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-09 16:02:00', 'Europe/Madrid'));
    $this->cycle = WeeklyCycle::factory()->active('2026-10-05')->create();
    $this->since = ['created_at' => '2026-09-01 08:00:00'];
    $this->admin = userWithRole('admin', ['name' => 'Admin', ...$this->since]);
    $this->ana = userWithRole('employee', ['name' => 'Ana', ...$this->since]);
    Setting::set('modules', PREVIEW_ALL_OFF);
    Setting::set('modules_preview', true);
});

it('el ajuste está apagado por defecto y solo cuenta para los admins', function () {
    Setting::query()->where('key', 'modules_preview')->delete();
    Setting::flushCache();

    expect(Setting::get('modules_preview'))->toBeFalse()
        ->and(AppModules::visibleTo($this->admin, AppModule::Weeklies))->toBeFalse();

    Setting::set('modules_preview', true);

    expect(AppModules::visibleTo($this->admin, AppModule::Weeklies))->toBeTrue()
        ->and(AppModules::previewing($this->admin, AppModule::Weeklies))->toBeTrue()
        ->and(AppModules::visibleTo($this->ana, AppModule::Weeklies))->toBeFalse()
        ->and(AppModules::visibleTo(null, AppModule::Weeklies))->toBeFalse()
        ->and(AppModules::enabled(AppModule::Weeklies))->toBeFalse()
        ->and(AppModules::previewedBy($this->admin))->toBe(array_keys(PREVIEW_ALL_OFF))
        ->and(AppModules::previewedBy($this->ana))->toBe([]);
});

it('en modo de prueba, el admin entra en las páginas de los módulos apagados, con el aviso', function (string $path) {
    $this->actingAs($this->admin)
        ->get($path)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('module_preview', true)
            ->where('config.modules.weeklies', true)
            ->where('config.modules.assistant', true)
            ->where('config.modules.help', true)
            ->where('config.modules_preview', array_keys(PREVIEW_ALL_OFF)));
})->with(['/weeklies', '/mi-espacio', '/equipo', '/ia', '/ayuda', '/weeklies/avisos']);

it('en modo de prueba, el resto de la plantilla sigue sin los módulos: 404 y sin menú', function (string $role) {
    $user = $role === 'collaborator' ? User::factory()->collaborator()->create() : userWithRole($role);

    foreach (['/weeklies', '/mi-espacio', '/equipo', '/ia', '/ayuda'] as $path) {
        expect($this->actingAs($user)->get($path)->status())->toBeIn([403, 404]);
    }

    if ($role !== 'collaborator') {
        $this->actingAs($user)->get('/weeklies')->assertNotFound();
    }

    $this->actingAs($user)
        ->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('module_preview', false)
            ->where('config.modules.weeklies', false)
            ->where('config.modules.help', false)
            ->where('config.modules_preview', [])
            ->where('weeklies.pending', 0));
})->with(['employee', 'department_manager', 'collaborator']);

it('sin el modo de prueba, el admin tampoco ve los módulos apagados', function () {
    Setting::set('modules_preview', false);

    $this->actingAs($this->admin)->get('/weeklies')->assertNotFound();
    $this->actingAs($this->admin)->get('/ayuda')->assertNotFound();
});

it('con los módulos encendidos todo sigue como antes, sin aviso de prueba', function () {
    Setting::set('modules', array_map(fn (): bool => true, PREVIEW_ALL_OFF));

    foreach ([$this->admin, $this->ana] as $user) {
        $this->actingAs($user)
            ->get('/weeklies')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('module_preview', false)
                ->where('config.modules.weeklies', true)
                ->where('config.modules_preview', []));
    }
});

it('la búsqueda global ofrece las páginas de la Weekly al admin en modo de prueba, no al resto', function () {
    $titles = fn (User $user): array => collect($this->actingAs($user)->getJson('/buscar?q=weekly')->assertOk()->json('results'))
        ->where('type', 'page')->pluck('title')->all();

    expect($titles($this->admin))->toContain('Mi espacio', 'Weeklies', 'Equipo', 'Asistente IA', 'Ayuda')
        ->and($titles($this->ana))->toBe([]);

    Setting::set('modules_preview', false);
    expect($titles($this->admin))->toBe([]);
});

it('Inicio y el contador de «Mi espacio» cuentan la weekly del admin en modo de prueba', function () {
    $this->actingAs($this->admin)
        ->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('weeklies.pending', 1)
            ->loadDeferredProps(fn (Assert $reload) => $reload->whereNot('weekly', null)));

    $this->actingAs($this->ana)
        ->get('/')
        ->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps(fn (Assert $reload) => $reload->where('weekly', null)));
});

it('el admin activa y desactiva el modo de prueba en los ajustes', function () {
    Setting::set('modules_preview', false);

    $this->actingAs($this->admin)
        ->get('/admin/ajustes')
        ->assertInertia(fn (Assert $page) => $page->where('settings.modules_preview', false));

    $payload = fn (bool $preview): array => [
        ...collect(Setting::DEFAULTS)->only([
            'company_name', 'require_2fa', 'timer_rounding_minutes', 'timer_warning_hours', 'hour_bank_alert_thresholds',
            'allow_hour_bank_overage', 'require_timesheet_approval', 'allow_future_time_entries', 'time_entry_description_required',
            'max_attachment_mb', 'default_work_minutes', 'weekly_digest_enabled', 'occupancy_low_threshold', 'occupancy_high_threshold',
        ])->all(),
        'modules_preview' => $preview,
    ];

    $this->actingAs($this->admin)->put('/admin/ajustes', $payload(true))->assertSessionHasNoErrors();
    expect(Setting::get('modules_preview'))->toBeTrue();

    $this->actingAs($this->admin)->put('/admin/ajustes', $payload(false))->assertSessionHasNoErrors();
    expect(Setting::get('modules_preview'))->toBeFalse();

    $this->actingAs($this->ana)->put('/admin/ajustes', $payload(true))->assertForbidden();
});

it('en modo de prueba los procesos programados no hacen nada', function () {
    Notification::fake();
    WeeklyReminderRule::query()->create(['channel' => WeeklyReminderChannel::Email, 'day_of_week' => 5, 'time' => '16:00', 'enabled' => true]);

    $this->artisan('weeklies:remind')->expectsOutputToContain('desactivado')->assertSuccessful();

    $this->cycle->forceFill(['status' => 'closed', 'closed_at' => now()])->save();
    $this->artisan('weeklies:open-week')->expectsOutputToContain('desactivado')->assertSuccessful();
    expect(WeeklyCycle::query()->active()->exists())->toBeFalse();

    // El recordatorio de los viernes: solo el de las horas, sin la weekly.
    $this->artisan('time:remind-week')->assertSuccessful();
    Notification::assertNotSentTo($this->ana, WeeklyReminder::class);
    Notification::assertSentTo($this->ana, WeekSubmissionReminder::class, fn (WeekSubmissionReminder $notification) => $notification->weekly === null);
    expect(WeeklyReminderLog::query()->count())->toBe(0);
});

it('en modo de prueba, «Recordar» y el envío manual del admin no avisan a nadie', function () {
    Notification::fake();

    $this->actingAs($this->admin)
        ->post("/weeklies/{$this->cycle->id}/recordar", ['user_id' => $this->ana->id])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Modo de prueba: no se avisa a nadie.');

    $this->actingAs($this->admin)
        ->post('/weeklies/avisos/enviar', ['recipients' => 'pending', 'template' => 'manual', 'channels' => ['app', 'email']])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Modo de prueba: no se avisa a nadie.');

    Notification::assertNotSentTo($this->ana, WeeklyReminder::class);
    expect(WeeklyReminderLog::query()->where('user_id', $this->ana->id)->count())->toBe(0);
});

it('en modo de prueba, cerrar la semana o cambiar el plazo no avisa al equipo', function () {
    Notification::fake();
    $reminders = app(WeeklyReminders::class);

    $this->actingAs($this->admin)
        ->put("/weeklies/{$this->cycle->id}/plazo", ['deadline_date' => '2026-10-12'])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Plazo actualizado. Modo de prueba: no se avisa a nadie.');

    $this->cycle->forceFill(['status' => 'closed', 'closed_at' => now()])->save();

    expect($reminders->notifyClosed($this->cycle)->notified)->toBe(0);
    Notification::assertNothingSent();
});

it('en modo de prueba, las sugerencias no avisan a nadie', function () {
    Storage::fake('local');
    Notification::fake();
    $post = SuggestionPost::factory()->create([
        'suggestion_board_id' => SuggestionBoard::query()->where('slug', 'sugerencias')->firstOrFail()->id,
        'author_id' => $this->ana->id,
    ]);

    $this->actingAs($this->admin)->post("/ayuda/sugerencias/{$post->id}/comentarios", ['body' => '<p>Lo miramos</p>'])->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->put("/ayuda/sugerencias/{$post->id}/estado", ['status' => 'planned'])->assertSessionHasNoErrors();

    Notification::assertNotSentTo($this->ana, SuggestionReplied::class);
    Notification::assertNotSentTo($this->ana, SuggestionStatusChanged::class);
});

it('con los módulos encendidos, «Recordar» y las sugerencias sí avisan', function () {
    Notification::fake();
    Setting::set('modules', array_map(fn (): bool => true, PREVIEW_ALL_OFF));
    Storage::fake('local');
    $post = SuggestionPost::factory()->create([
        'suggestion_board_id' => SuggestionBoard::query()->where('slug', 'sugerencias')->firstOrFail()->id,
        'author_id' => $this->ana->id,
    ]);

    $this->actingAs($this->admin)->post("/weeklies/{$this->cycle->id}/recordar", ['user_id' => $this->ana->id])->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post("/ayuda/sugerencias/{$post->id}/comentarios", ['body' => '<p>Lo miramos</p>'])->assertSessionHasNoErrors();

    Notification::assertSentTo($this->ana, WeeklyReminder::class);
    Notification::assertSentTo($this->ana, SuggestionReplied::class);
});

it('los canales en tiempo real de la Weekly y la ayuda siguen el modo de prueba', function () {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb' => [
            'driver' => 'reverb',
            'key' => 'clave-de-prueba',
            'secret' => 'secreto-de-prueba',
            'app_id' => 'audax-tests',
            'options' => ['host' => '127.0.0.1', 'port' => 18080, 'scheme' => 'http', 'useTLS' => false],
            'client_options' => [],
        ],
    ]);
    app(BroadcastManager::class)->purge('reverb');
    require base_path('routes/channels.php');

    $authorize = fn (User $user, string $channel): int => $this->actingAs($user)
        ->postJson('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => "private-{$channel}"])
        ->status();

    expect($authorize($this->admin, 'weeklies'))->toBe(200)
        ->and($authorize($this->admin, "weeklies.{$this->cycle->id}"))->toBe(200)
        ->and($authorize($this->admin, 'help'))->toBe(200)
        ->and($authorize($this->ana, 'weeklies'))->toBe(403)
        ->and($authorize($this->ana, "weeklies.{$this->cycle->id}"))->toBe(403)
        ->and($authorize($this->ana, 'help'))->toBe(403);

    Setting::set('modules_preview', false);
    expect($authorize($this->admin, 'weeklies'))->toBe(403);

    Setting::set('modules', array_map(fn (): bool => true, PREVIEW_ALL_OFF));
    expect($authorize($this->ana, 'weeklies'))->toBe(200)
        ->and($authorize($this->ana, 'help'))->toBe(200);
});
