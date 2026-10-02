<?php

use App\Domain\Privacy\PrivacyNotice;
use App\Models\Setting;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/*
| Privacidad (SPEC §15, D-075): /privacidad para la plantilla (texto, plazos y lectura),
| /admin/privacidad para el admin (texto con versión y retención) y los clientes siempre fuera.
*/

beforeEach(function () {
    $this->admin = User::factory()->admin()->create(['name' => 'Ana Admin']);
    $this->employee = User::factory()->employee()->create();
    $this->notice = app(PrivacyNotice::class);

    $this->form = fn (array $overrides = []): array => [
        'notice' => $this->notice->text(),
        'retention_login_events_months' => 12,
        'retention_read_notifications_months' => 6,
        'retention_activity_log_months' => 60,
        'retention_chat_messages_months' => null,
        'personal_data_export_days' => 7,
        'disk_warning_percent' => 85,
        'attachments_warning_gb' => null,
        ...$overrides,
    ];
});

test('matriz de permisos de las páginas de privacidad', function (string $actor, array $statuses) {
    if ($actor !== 'guest') {
        $this->actingAs(userWithRole($actor));
    }

    $requests = [
        ['get', '/privacidad'],
        ['post', '/privacidad/lectura'],
        ['get', '/admin/privacidad'],
        ['put', '/admin/privacidad'],
    ];

    foreach ($requests as $index => [$method, $path]) {
        $response = $this->{$method}($path, $method === 'put' ? ($this->form)() : []);
        $response->assertStatus($statuses[$index]);

        if ($actor === 'guest') {
            $response->assertRedirect(route('login'));
        }

        if ($actor === 'client') {
            $response->assertRedirect(route('portal.home'));
        }
    }
})->with([
    //                     /privacidad  lectura  /admin/privacidad  guardar
    'invitado' => ['guest', [302, 302, 302, 302]],
    'admin' => ['admin', [200, 302, 200, 302]],
    'responsable' => ['department_manager', [200, 302, 403, 403]],
    'empleado' => ['employee', [200, 302, 403, 403]],
    'cliente' => ['client', [302, 302, 302, 302]],
]);

test('/privacidad muestra el texto, su versión, la lectura pendiente y los plazos vigentes', function () {
    Setting::set('retention_chat_messages_months', 24);

    $this->actingAs($this->employee)
        ->get('/privacidad')
        ->assertInertia(fn (Assert $page) => $page
            ->component('privacy/show')
            ->where('notice.markdown', $this->notice->text())
            ->where('notice.version', 1)
            ->where('notice.is_draft', true)
            ->where('acknowledgement', ['needed' => true, 'version' => null, 'at' => null])
            ->where('retention', [
                ['type' => 'login_events', 'months' => 12],
                ['type' => 'read_notifications', 'months' => 6],
                ['type' => 'activity_log', 'months' => 60],
                ['type' => 'chat_messages', 'months' => 24],
            ])
            ->where('exportDays', 7)
            ->where('privacy.needs_acknowledgement', true));
});

test('«He leído la información» registra la lectura de la versión vigente y queda en la auditoría', function () {
    $this->travelTo('2026-10-05 08:30:00');

    $this->actingAs($this->employee)
        ->from('/privacidad')
        ->post('/privacidad/lectura')
        ->assertRedirect('/privacidad');

    $this->employee->refresh();

    expect($this->employee->privacy_acknowledged_version)->toBe(1)
        ->and($this->employee->privacy_acknowledged_at?->toIso8601ZuluString())->toBe('2026-10-05T08:30:00Z')
        ->and($this->notice->needsAcknowledgement($this->employee))->toBeFalse();

    $log = Activity::query()->where('log_name', 'privacy')->where('event', 'acknowledged')->sole();

    expect($log->causer_id)->toBe($this->employee->id)
        ->and($log->subject_id)->toBe($this->employee->id)
        ->and($log->properties['version'])->toBe(1);

    $this->actingAs($this->employee)
        ->get('/privacidad')
        ->assertInertia(fn (Assert $page) => $page
            ->where('acknowledgement', ['needed' => false, 'version' => 1, 'at' => '2026-10-05T08:30:00Z'])
            ->where('privacy.needs_acknowledgement', false));

    // Volver a pulsarlo no registra otra lectura.
    $this->actingAs($this->employee)->post('/privacidad/lectura')->assertRedirect('/privacidad');

    expect(Activity::query()->where('event', 'acknowledged')->count())->toBe(1);
});

test('/admin/privacidad muestra el texto con su versión, quién lo ha leído y los plazos editables', function () {
    $this->notice->acknowledge($this->employee);

    $this->actingAs($this->admin)
        ->get('/admin/privacidad')
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/privacy')
            ->where('notice.version', 1)
            ->where('notice.is_draft', true)
            ->where('notice.max_length', PrivacyNotice::MAX_LENGTH)
            ->where('readers', ['read' => 1, 'total' => 2])
            ->where('settings', [
                'retention_login_events_months' => 12,
                'retention_read_notifications_months' => 6,
                'retention_activity_log_months' => 60,
                'retention_chat_messages_months' => null,
                'personal_data_export_days' => 7,
                'disk_warning_percent' => 85,
                'attachments_warning_gb' => null,
            ])
            ->where('retention.0', ['type' => 'login_events', 'key' => 'retention_login_events_months', 'min' => 1, 'max' => 120, 'unlimited_allowed' => false])
            ->where('retention.2', ['type' => 'activity_log', 'key' => 'retention_activity_log_months', 'min' => 12, 'max' => 120, 'unlimited_allowed' => true]));
});

test('guardar un texto nuevo lo guarda tal cual, sube la versión y vuelve a pedir la lectura', function () {
    $this->notice->acknowledge($this->employee);
    $markdown = "## Texto revisado\n\nCon <script>alert(1)</script> y [un enlace](javascript:alert(1)).";

    $this->actingAs($this->admin)
        ->from('/admin/privacidad')
        ->put('/admin/privacidad', ($this->form)(['notice' => $markdown]))
        ->assertRedirect('/admin/privacidad')
        ->assertSessionHasNoErrors();

    // El markdown se guarda tal cual: se sanea al pintarlo en el navegador.
    expect(Setting::get('privacy_notice'))->toBe($markdown)
        ->and($this->notice->version())->toBe(2)
        ->and($this->notice->isDraft())->toBeFalse()
        ->and($this->notice->needsAcknowledgement($this->employee->refresh()))->toBeTrue();

    expect(Activity::query()->where('log_name', 'privacy')->where('description', 'privacy_notice.updated')->count())->toBe(1);

    // Guardar lo mismo (con saltos de línea de Windows) no crea otra versión.
    $this->actingAs($this->admin)
        ->put('/admin/privacidad', ($this->form)(['notice' => str_replace("\n", "\r\n", $markdown)]))
        ->assertSessionHasNoErrors();

    expect($this->notice->version())->toBe(2);
});

test('los plazos de retención se guardan con sus límites y sus cambios quedan en la auditoría', function () {
    $this->actingAs($this->admin)
        ->put('/admin/privacidad', ($this->form)([
            'retention_login_events_months' => 24,
            'retention_activity_log_months' => null,
            'retention_chat_messages_months' => 36,
            'personal_data_export_days' => 14,
            'disk_warning_percent' => 90,
            'attachments_warning_gb' => 200,
        ]))
        ->assertSessionHasNoErrors();

    expect(Setting::get('retention_login_events_months'))->toBe(24)
        ->and(Setting::get('retention_activity_log_months'))->toBeNull()
        ->and(Setting::get('retention_chat_messages_months'))->toBe(36)
        ->and(Setting::get('personal_data_export_days'))->toBe(14)
        ->and(Setting::get('disk_warning_percent'))->toBe(90)
        ->and(Setting::get('attachments_warning_gb'))->toBe(200)
        // El texto no ha cambiado: sigue la versión 1.
        ->and($this->notice->version())->toBe(1);

    $retention = Activity::query()->where('log_name', 'privacy')->where('description', 'retention.updated')->sole();
    $storage = Activity::query()->where('log_name', 'settings')->where('description', 'settings.updated')->sole();

    expect($retention->causer_id)->toBe($this->admin->id)
        ->and($retention->properties['old'])->toBe([
            'retention_login_events_months' => 12,
            'retention_activity_log_months' => 60,
            'retention_chat_messages_months' => null,
            'personal_data_export_days' => 7,
        ])
        ->and($retention->properties['attributes'])->toBe([
            'retention_login_events_months' => 24,
            'retention_activity_log_months' => null,
            'retention_chat_messages_months' => 36,
            'personal_data_export_days' => 14,
        ])
        ->and($storage->properties['attributes'])->toBe(['disk_warning_percent' => 90, 'attachments_warning_gb' => 200]);

    // Guardar sin cambios no deja nada en la auditoría.
    $this->actingAs($this->admin)->put('/admin/privacidad', ($this->form)([
        'retention_login_events_months' => 24,
        'retention_activity_log_months' => null,
        'retention_chat_messages_months' => 36,
        'personal_data_export_days' => 14,
        'disk_warning_percent' => 90,
        'attachments_warning_gb' => 200,
    ]))->assertSessionHasNoErrors();

    expect(Activity::query()->whereIn('log_name', ['privacy', 'settings'])->count())->toBe(2);
});

test('valida el texto y los límites de cada plazo; «sin límite» solo donde se admite', function (array $overrides, array $errors) {
    $this->actingAs($this->admin)
        ->put('/admin/privacidad', ($this->form)($overrides))
        ->assertSessionHasErrors($errors);

    expect(Activity::query()->count())->toBe(0);
})->with([
    'texto vacío' => [['notice' => ''], ['notice']],
    'texto demasiado largo' => [['notice' => str_repeat('a', PrivacyNotice::MAX_LENGTH + 1)], ['notice']],
    'accesos sin límite' => [['retention_login_events_months' => null], ['retention_login_events_months']],
    'notificaciones sin límite' => [['retention_read_notifications_months' => ''], ['retention_read_notifications_months']],
    'auditoría por debajo del mínimo' => [['retention_activity_log_months' => 11], ['retention_activity_log_months']],
    'accesos por encima del máximo' => [['retention_login_events_months' => 121], ['retention_login_events_months']],
    'chat en cero' => [['retention_chat_messages_months' => 0], ['retention_chat_messages_months']],
    'días de descarga' => [['personal_data_export_days' => 31], ['personal_data_export_days']],
    'aviso de disco' => [['disk_warning_percent' => 100], ['disk_warning_percent']],
    'aviso de adjuntos' => [['attachments_warning_gb' => 0], ['attachments_warning_gb']],
]);

test('los clientes del portal nunca ven el aviso de privacidad', function () {
    $client = userWithRole('client');

    $this->actingAs($client)->get('/portal')->assertInertia(fn (Assert $page) => $page->missing('privacy'));
    $this->actingAs($client)->get('/privacidad')->assertRedirect(route('portal.home'));
});

test('el borrador de tests/fixtures/privacy-draft.json es el de lang/es/privacy.php (lo pinta Vitest)', function () {
    $fixture = json_decode((string) file_get_contents(base_path('tests/fixtures/privacy-draft.json')), true);

    expect($fixture['markdown'])->toBe(__('privacy.default_notice'));
});
