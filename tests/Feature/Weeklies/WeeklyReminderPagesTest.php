<?php

use App\Enums\WeeklyReminderChannel;
use App\Enums\WeeklyReminderStatus;
use App\Enums\WeeklyReminderTemplate;
use App\Models\Setting;
use App\Models\User;
use App\Models\WeeklyCycle;
use App\Models\WeeklyExemption;
use App\Models\WeeklyReminderLog;
use App\Models\WeeklyReminderRule;
use App\Models\WeeklySubmission;
use App\Notifications\Weeklies\WeeklyDeadlineChanged;
use App\Notifications\Weeklies\WeeklyReminder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/*
| «Avisos de la Weekly» (10.5, F-037 y F-101 a F-110, D-199 a D-201): la pestaña /weeklies/avisos
| para quien gestiona la Weekly, las reglas, las plantillas (con «Restaurar»), la weekly del viernes,
| el envío manual, «Recordar» desde el resumen y desde Equipo, el plazo cambiado y el registro.
| Semana del 05/10/2026; hoy, miércoles 07/10 a las 10:00 de Madrid.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00:00', 'Europe/Madrid'));
    $this->cycle = WeeklyCycle::factory()->active('2026-10-05')->create();
    $this->since = ['created_at' => '2026-09-01 08:00:00'];
    $this->manager = userWithRole('department_manager', ['name' => 'Marta', ...$this->since]);
    $this->ana = userWithRole('employee', ['name' => 'Ana', ...$this->since]);
    $this->luis = userWithRole('employee', ['name' => 'Luis', ...$this->since]);
    // Marta ya ha enviado la suya: las pendientes son Ana y Luis.
    WeeklySubmission::factory()->submitted()->create(['weekly_cycle_id' => $this->cycle->id, 'user_id' => $this->manager->id]);
    $this->payload = fn (array $overrides = []): array => [
        'rules' => [
            ['id' => null, 'channel' => 'email', 'day_of_week' => 4, 'time' => '10:00', 'enabled' => true],
        ],
        'templates' => __('weeklies.templates'),
        'friday_reminder' => true,
        ...$overrides,
    ];
});

it('solo quien gestiona la Weekly entra en los avisos y puede enviar o recordar', function (string $role, int $status) {
    $user = $role === 'collaborator' ? User::factory()->collaborator()->create() : userWithRole($role);

    // Las acciones vuelven atrás (302) a quien puede.
    $action = $status === 200 ? 302 : $status;

    $this->actingAs($user)->get('/weeklies/avisos')->assertStatus($status);
    $this->actingAs($user)->putJson('/weeklies/avisos', ($this->payload)())->assertStatus($action);
    $this->actingAs($user)->postJson('/weeklies/avisos/enviar', ['recipients' => 'pending', 'template' => 'manual', 'channels' => ['app']])->assertStatus($action);
    $this->actingAs($user)->postJson("/weeklies/{$this->cycle->id}/recordar", ['user_id' => $this->ana->id])->assertStatus($action);
})->with([
    'admin' => ['admin', 200],
    'responsable' => ['department_manager', 200],
    'empleado' => ['employee', 403],
    'colaborador externo' => ['collaborator', 403],
]);

it('la página trae las reglas, las plantillas, las pendientes, el registro y lo que se puede hacer', function () {
    WeeklyReminderRule::query()->create(['channel' => WeeklyReminderChannel::Push, 'day_of_week' => 5, 'time' => '14:00', 'position' => 1]);
    WeeklyReminderRule::query()->create(['channel' => WeeklyReminderChannel::Email, 'day_of_week' => 5, 'time' => '16:00', 'position' => 0]);
    WeeklyReminderLog::query()->create(['weekly_cycle_id' => $this->cycle->id, 'user_id' => $this->ana->id, 'recipient_name' => 'Ana', 'recipient_email' => 'ana@example.com', 'template' => 'manual', 'channel' => 'email', 'trigger_key' => 'manual:1', 'status' => 'failed', 'error' => 'SMTP', 'sent_by' => $this->manager->id]);
    WeeklyReminderLog::query()->create(['weekly_cycle_id' => $this->cycle->id, 'user_id' => $this->luis->id, 'template' => 'automatic', 'channel' => 'app', 'trigger_key' => 'rule:1', 'status' => 'sent']);
    Setting::set('weekly_email_templates', ['manual' => ['subject' => 'Ojo, {nombre}', 'body' => 'Falta tu weekly']]);

    $this->actingAs($this->manager)
        ->get('/weeklies/avisos')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('weeklies/reminders')
            ->where('cycle.id', $this->cycle->id)
            ->where('cycle.number', 'W41-26')
            ->where('rules.0.time', '16:00')
            ->where('rules.0.channel', 'email')
            ->where('rules.1.channel', 'push')
            ->where('templates.manual', ['subject' => 'Ojo, {nombre}', 'body' => 'Falta tu weekly', 'is_default' => false])
            ->where('templates.automatic.is_default', true)
            ->where('defaults.manual.subject', 'Recordatorio: weekly pendiente')
            ->where('variables', ['nombre', 'semana', 'week_label', 'weekly_url'])
            ->where('friday', ['weekly' => true, 'hours' => true])
            ->where('pending', fn ($pending): bool => collect($pending)->pluck('name')->all() === ['Ana', 'Luis'])
            ->has('logs.data', 2)
            ->where('logs.data.0.recipient_name', null)
            ->where('logs.data.1.status', 'failed')
            ->where('logs.data.1.error', 'SMTP')
            ->where('logs.data.1.sent_by_name', 'Marta')
            ->where('logs.data.1.cycle_number', 'W41-26')
            ->where('logs.meta.total', 2)
            ->where('push_available', false)
            ->where('can.send', true));

    $this->actingAs($this->manager)
        ->get('/weeklies/avisos?estado=failed&plantilla=manual')
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters', ['template' => 'manual', 'status' => 'failed'])
            ->has('logs.data', 1));

    $this->actingAs($this->manager)
        ->get('/weeklies/avisos?estado=raro')
        ->assertInertia(fn (Assert $page) => $page->where('filters.status', null)->has('logs.data', 2));
});

it('guardar las reglas crea, cambia y borra (con su orden y en la auditoría)', function () {
    $keep = WeeklyReminderRule::query()->create(['channel' => WeeklyReminderChannel::Email, 'day_of_week' => 5, 'time' => '16:00']);
    $gone = WeeklyReminderRule::query()->create(['channel' => WeeklyReminderChannel::Push, 'day_of_week' => 5, 'time' => '14:00']);

    $this->actingAs($this->manager)
        ->from('/weeklies/avisos')
        ->put('/weeklies/avisos', ($this->payload)(['rules' => [
            ['id' => null, 'channel' => 'app', 'day_of_week' => 4, 'time' => '09:30', 'enabled' => true],
            ['id' => $keep->id, 'channel' => 'email', 'day_of_week' => 5, 'time' => '17:15', 'enabled' => false],
        ]]))
        ->assertSessionHasNoErrors()
        ->assertRedirect('/weeklies/avisos')
        ->assertInertiaFlash('toast.message', __('weeklies.reminders.saved'));

    $rules = WeeklyReminderRule::query()->orderBy('position')->get();

    expect($rules)->toHaveCount(2)
        ->and($rules[0]->channel)->toBe(WeeklyReminderChannel::App)
        ->and($rules[0]->time)->toBe('09:30')
        ->and($rules[1]->id)->toBe($keep->id)
        ->and($rules[1]->time)->toBe('17:15')
        ->and($rules[1]->enabled)->toBeFalse()
        ->and(WeeklyReminderRule::query()->find($gone->id))->toBeNull()
        ->and(Activity::query()->where('log_name', 'weekly_reminder_rules')->pluck('event')->all())->toContain('created', 'updated', 'deleted');
});

it('valida las reglas: hora HH:MM de 00:00 a 23:59, día ISO, canal y como mucho 20', function (array $rule, string $field) {
    $this->actingAs($this->manager)
        ->putJson('/weeklies/avisos', ($this->payload)(['rules' => [[...['id' => null, 'channel' => 'email', 'day_of_week' => 4, 'time' => '10:00', 'enabled' => true], ...$rule]]]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);
})->with([
    'hora 24:00' => [['time' => '24:00'], 'rules.0.time'],
    'hora 9:00' => [['time' => '9:00'], 'rules.0.time'],
    'día 0 (domingo de WeeklySync)' => [['day_of_week' => 0], 'rules.0.day_of_week'],
    'día 8' => [['day_of_week' => 8], 'rules.0.day_of_week'],
    'canal sms' => [['channel' => 'sms'], 'rules.0.channel'],
]);

it('como mucho 20 reglas', function () {
    $rules = array_fill(0, 21, ['id' => null, 'channel' => 'app', 'day_of_week' => 1, 'time' => '09:00', 'enabled' => true]);

    $this->actingAs($this->manager)->putJson('/weeklies/avisos', ($this->payload)(['rules' => $rules]))
        ->assertJsonValidationErrors(['rules' => __('weeklies.reminders.validation.rules_max', ['max' => 20])]);
});

it('las plantillas guardan solo lo cambiado; restaurar por defecto la quita del ajuste; queda en la auditoría', function () {
    $templates = __('weeklies.templates');
    $templates['manual'] = ['subject' => "  Hola {nombre}\r\n", 'body' => "Envía la {semana}\r\n\r\nGracias.   "];

    $this->actingAs($this->manager)->put('/weeklies/avisos', ($this->payload)(['templates' => $templates]))->assertSessionHasNoErrors();

    // Los espacios de los extremos los quita TrimStrings; los saltos, a los de Unix.
    expect(Setting::get('weekly_email_templates'))->toBe(['manual' => ['subject' => 'Hola {nombre}', 'body' => "Envía la {semana}\n\nGracias."]]);
    $log = Activity::query()->where('log_name', 'weekly-reminders')->where('event', 'templates_updated')->sole();
    expect($log->properties['templates'])->toBe(['manual'])
        ->and($log->causer_id)->toBe($this->manager->id);

    // Restaurar: se guarda la de por defecto y el ajuste vuelve a null.
    $this->actingAs($this->manager)->put('/weeklies/avisos', ($this->payload)())->assertSessionHasNoErrors();

    expect(Setting::get('weekly_email_templates'))->toBeNull();

    // Sin asunto o con un cuerpo demasiado largo, no.
    $broken = __('weeklies.templates');
    $broken['automatic'] = ['subject' => '', 'body' => str_repeat('a', 5001)];
    $this->actingAs($this->manager)->putJson('/weeklies/avisos', ($this->payload)(['templates' => $broken]))
        ->assertJsonValidationErrors(['templates.automatic.subject', 'templates.automatic.body']);
});

it('la weekly del recordatorio de los viernes se activa y desactiva aquí, con auditoría', function () {
    $this->actingAs($this->manager)->put('/weeklies/avisos', ($this->payload)(['friday_reminder' => false]))->assertSessionHasNoErrors();

    expect(Setting::get('weekly_friday_reminder'))->toBeFalse()
        ->and(Activity::query()->where('event', 'friday_updated')->sole()->properties['weekly_friday_reminder'])->toBeFalse();
});

it('el envío manual va a todas las pendientes o a las elegidas, con su plantilla y canales (F-109)', function () {
    Notification::fake();

    $this->actingAs($this->manager)
        ->post('/weeklies/avisos/enviar', ['recipients' => 'pending', 'template' => 'manual', 'channels' => ['app', 'email']])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', trans_choice('weeklies.reminders.sent', 2, ['count' => 2]));

    Notification::assertSentTo($this->ana, WeeklyReminder::class, fn (WeeklyReminder $notification, array $channels) => $channels === ['database', 'mail'] && $notification->template === 'manual');
    Notification::assertSentTo($this->luis, WeeklyReminder::class);
    Notification::assertNotSentTo($this->manager, WeeklyReminder::class);

    expect(WeeklyReminderLog::query()->where('sent_by', $this->manager->id)->count())->toBe(4)
        ->and(Activity::query()->where('event', 'reminders_sent')->sole()->properties['notified'])->toBe(2);

    // Dos clics en el mismo minuto no mandan dos.
    $this->actingAs($this->manager)->post('/weeklies/avisos/enviar', ['recipients' => 'pending', 'template' => 'manual', 'channels' => ['app', 'email']])
        ->assertInertiaFlash('toast.message', trans_choice('weeklies.reminders.sent', 0));
    Notification::assertSentToTimes($this->ana, WeeklyReminder::class, 1);

    // A las elegidas: solo las pendientes (Marta ya la envió), con la plantilla automática.
    $this->travel(2)->minutes();
    $this->actingAs($this->manager)
        ->post('/weeklies/avisos/enviar', ['recipients' => 'users', 'user_ids' => [$this->luis->id, $this->manager->id], 'template' => 'automatic', 'channels' => ['app']])
        ->assertInertiaFlash('toast.message', trans_choice('weeklies.reminders.sent', 1, ['count' => 1]));

    Notification::assertSentToTimes($this->luis, WeeklyReminder::class, 2);
    Notification::assertNotSentTo($this->manager, WeeklyReminder::class);
});

it('el envío manual valida personas, plantilla y canales, y pide una semana activa', function () {
    $this->actingAs($this->manager)->postJson('/weeklies/avisos/enviar', ['recipients' => 'users', 'user_ids' => [], 'template' => 'manual', 'channels' => []])
        ->assertJsonValidationErrors(['user_ids', 'channels']);
    $this->actingAs($this->manager)->postJson('/weeklies/avisos/enviar', ['recipients' => 'pending', 'template' => 'weekly_closed', 'channels' => ['app']])
        ->assertJsonValidationErrors(['template']);
    $this->actingAs($this->manager)->postJson('/weeklies/avisos/enviar', ['recipients' => 'pending', 'template' => 'manual', 'channels' => ['sms']])
        ->assertJsonValidationErrors(['channels.0']);

    $this->cycle->forceFill(['status' => 'closed'])->save();
    $this->actingAs($this->manager)->postJson('/weeklies/avisos/enviar', ['recipients' => 'pending', 'template' => 'manual', 'channels' => ['app']])
        ->assertJsonValidationErrors(['recipients' => __('weeklies.reminders.no_active')]);
});

it('«Recordar» a una persona pendiente: llega con la plantilla manual; a quien no lo necesita, no (F-037 y F-110)', function () {
    Notification::fake();
    $exempt = userWithRole('employee', ['name' => 'Exenta', ...$this->since]);
    WeeklyExemption::factory()->create(['weekly_cycle_id' => $this->cycle->id, 'user_id' => $exempt->id]);

    $this->actingAs($this->manager)
        ->post("/weeklies/{$this->cycle->id}/recordar", ['user_id' => $this->ana->id])
        ->assertInertiaFlash('toast.message', __('weeklies.reminders.reminded', ['name' => 'Ana']));

    Notification::assertSentTo($this->ana, WeeklyReminder::class, fn (WeeklyReminder $notification, array $channels) => $notification->template === 'manual' && $channels === ['database', 'mail']);
    expect(WeeklyReminderLog::query()->where('user_id', $this->ana->id)->pluck('channel')->map->value->sort()->values()->all())->toBe(['app', 'email']);

    // Otro clic en el mismo minuto: ya enviado.
    $this->actingAs($this->manager)->post("/weeklies/{$this->cycle->id}/recordar", ['user_id' => $this->ana->id])
        ->assertInertiaFlash('toast.message', __('weeklies.reminders.duplicate'));

    foreach ([$exempt, $this->manager] as $person) {
        $this->actingAs($this->manager)->post("/weeklies/{$this->cycle->id}/recordar", ['user_id' => $person->id])
            ->assertInertiaFlash('toast.message', __('weeklies.reminders.not_needed', ['name' => $person->name]));
        Notification::assertNotSentTo($person, WeeklyReminder::class);
    }

    $this->actingAs($this->manager)->postJson("/weeklies/{$this->cycle->id}/recordar", ['user_id' => 999999])->assertJsonValidationErrors(['user_id']);

    // Con la semana cerrada, no.
    $this->cycle->forceFill(['status' => 'closed'])->save();
    $this->actingAs($this->manager)->postJson("/weeklies/{$this->cycle->id}/recordar", ['user_id' => $this->luis->id])->assertForbidden();
});

it('«Recordar» sale en el resumen de /weeklies y en Equipo solo para quien gestiona', function () {
    $this->actingAs($this->manager)->get('/weeklies')
        ->assertInertia(fn (Assert $page) => $page->where('can.remind', true)->where('can.reminders', true));
    $this->actingAs($this->ana)->get('/weeklies')
        ->assertInertia(fn (Assert $page) => $page->where('can.remind', false)->where('can.reminders', false));

    $this->actingAs($this->manager)->get('/equipo')->assertInertia(fn (Assert $page) => $page->where('can.remind', true));
    $this->actingAs($this->ana)->get('/equipo')->assertInertia(fn (Assert $page) => $page->where('can.remind', false));
    $this->actingAs($this->manager)->get("/equipo/{$this->ana->id}")->assertInertia(fn (Assert $page) => $page->where('can.remind', true));
    $this->actingAs($this->luis)->get("/equipo/{$this->ana->id}")->assertInertia(fn (Assert $page) => $page->where('can.remind', false));

    $this->cycle->forceFill(['status' => 'closed'])->save();
    $this->actingAs($this->manager)->get('/weeklies')->assertInertia(fn (Assert $page) => $page->where('can.remind', false));
    $this->actingAs($this->manager)->get('/equipo')->assertInertia(fn (Assert $page) => $page->where('can.remind', false));
});

it('cambiar el plazo avisa a quien aún debe enviar la weekly, una vez por fecha', function () {
    Notification::fake();

    $this->actingAs($this->manager)
        ->put("/weeklies/{$this->cycle->id}/plazo", ['deadline_date' => '2026-10-13'])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', trans_choice('weeklies.flash.deadline_updated_notified', 2, ['count' => 2]));

    Notification::assertSentTo($this->ana, WeeklyDeadlineChanged::class, fn (WeeklyDeadlineChanged $notice, array $channels) => $channels === ['database']
        && str_contains((string) $notice->body($this->ana), 'martes 13 de octubre'));
    Notification::assertNotSentTo($this->manager, WeeklyDeadlineChanged::class);

    $logs = WeeklyReminderLog::query()->where('template', WeeklyReminderTemplate::Deadline->value)->get();
    expect($logs)->toHaveCount(2)
        ->and($logs->first()->trigger_key)->toBe("deadline:{$this->cycle->id}:2026-10-13")
        ->and($logs->pluck('status')->unique()->all())->toBe([WeeklyReminderStatus::Queued]);

    // La misma fecha otra vez: plazo actualizado sin avisos.
    $this->actingAs($this->manager)->put("/weeklies/{$this->cycle->id}/plazo", ['deadline_date' => '2026-10-13'])
        ->assertInertiaFlash('toast.message', __('weeklies.flash.deadline_updated'));
    Notification::assertSentToTimes($this->ana, WeeklyDeadlineChanged::class, 1);
});

it('los cambios de los avisos se filtran en la auditoría visible', function () {
    $this->actingAs($this->manager)->put('/weeklies/avisos', ($this->payload)())->assertSessionHasNoErrors();

    $admin = userWithRole('admin');
    $this->actingAs($admin)
        ->get('/admin/auditoria?entidad=weekly_reminder')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.entidad', 'weekly_reminder')
            ->where('entries', fn ($entries): bool => collect($entries)->isNotEmpty()));
});
