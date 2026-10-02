<?php

use App\Domain\Time\Capacity;
use App\Models\Setting;
use Database\Seeders\DefaultSettingsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Ajustes generales (SPEC §14, Setting::DEFAULTS): se muestran todos, se validan y se guardan
| invalidando la caché.
*/

beforeEach(function () {
    $this->seed(DefaultSettingsSeeder::class);
    $this->admin = userWithRole('admin');
    $this->valid = [
        'company_name' => 'Audax Studio SL',
        'require_2fa' => true,
        'timer_rounding_minutes' => 15,
        'timer_warning_hours' => 8,
        'hour_bank_alert_thresholds' => [100, 80, 50],
        'allow_hour_bank_overage' => false,
        'require_timesheet_approval' => false,
        'allow_future_time_entries' => true,
        'time_entry_description_required' => true,
        'max_attachment_mb' => 20,
        'default_work_minutes' => [450, 450, 450, 450, 360, 0, 0],
        'weekly_digest_enabled' => false,
        'occupancy_low_threshold' => 60,
        'occupancy_high_threshold' => 120,
    ];
});

test('muestra todos los ajustes con sus valores y el límite de subida del servidor', function () {
    $this->actingAs($this->admin)
        ->get('/admin/ajustes')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/settings')
            ->where('settings', Setting::DEFAULTS)
            ->where('roundings', [1, 5, 10, 15, 30])
            ->has('serverUploadLimitMb'));
});

test('guarda todos los ajustes, con los umbrales ordenados, y la caché se renueva', function () {
    // Se lee antes para que quede en caché.
    expect(Setting::get('company_name'))->toBe('Audax Studio');

    $this->actingAs($this->admin)
        ->put('/admin/ajustes', $this->valid)
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.type', 'success');

    expect(Setting::get('company_name'))->toBe('Audax Studio SL')
        ->and(Setting::get('require_2fa'))->toBeTrue()
        ->and(Setting::get('timer_rounding_minutes'))->toBe(15)
        ->and(Setting::get('timer_warning_hours'))->toBe(8)
        ->and(Setting::get('hour_bank_alert_thresholds'))->toBe([50, 80, 100])
        ->and(Setting::get('allow_hour_bank_overage'))->toBeFalse()
        ->and(Setting::get('require_timesheet_approval'))->toBeFalse()
        ->and(Setting::get('allow_future_time_entries'))->toBeTrue()
        ->and(Setting::get('time_entry_description_required'))->toBeTrue()
        ->and(Setting::get('max_attachment_mb'))->toBe(20)
        ->and(Capacity::defaultWeek())->toBe([450, 450, 450, 450, 360, 0, 0])
        ->and(Setting::query()->count())->toBe(count(Setting::DEFAULTS));

    // Con la verificación en dos pasos obligatoria, este admin (sin 2FA) tiene que configurarla ya.
    $this->actingAs($this->admin)->get('/admin/ajustes')->assertRedirect();

    // Las props compartidas (config) ya ven los umbrales nuevos.
    Setting::set('require_2fa', false);
    $this->actingAs($this->admin)
        ->get('/admin/ajustes')
        ->assertInertia(fn (Assert $page) => $page->where('config.hour_bank_thresholds', [50, 80, 100]));
});

test('valida cada ajuste', function (array $changes, array $errors) {
    $this->actingAs($this->admin)
        ->put('/admin/ajustes', [...$this->valid, ...$changes])
        ->assertSessionHasErrors($errors);

    expect(Setting::get('company_name'))->toBe('Audax Studio');
})->with([
    'sin nombre' => [['company_name' => ''], ['company_name']],
    'redondeo no admitido' => [['timer_rounding_minutes' => 7], ['timer_rounding_minutes']],
    'aviso de 0 horas' => [['timer_warning_hours' => 0], ['timer_warning_hours']],
    'aviso de 25 horas' => [['timer_warning_hours' => 25], ['timer_warning_hours']],
    'sin umbrales' => [['hour_bank_alert_thresholds' => []], ['hour_bank_alert_thresholds']],
    'seis umbrales' => [['hour_bank_alert_thresholds' => [10, 20, 30, 40, 50, 60]], ['hour_bank_alert_thresholds']],
    'umbral repetido' => [['hour_bank_alert_thresholds' => [75, 75]], ['hour_bank_alert_thresholds.1']],
    'umbral de 0' => [['hour_bank_alert_thresholds' => [0, 90]], ['hour_bank_alert_thresholds.0']],
    'umbral de 201' => [['hour_bank_alert_thresholds' => [90, 201]], ['hour_bank_alert_thresholds.1']],
    'umbral decimal' => [['hour_bank_alert_thresholds' => [75.5]], ['hour_bank_alert_thresholds.0']],
    'adjuntos de 0 MB' => [['max_attachment_mb' => 0], ['max_attachment_mb']],
    'adjuntos de 201 MB' => [['max_attachment_mb' => 201], ['max_attachment_mb']],
    'jornada de 6 días' => [['default_work_minutes' => [480, 480, 480, 480, 480, 0]], ['default_work_minutes']],
    'día de más de 24 h' => [['default_work_minutes' => [480, 480, 1500, 480, 480, 0, 0]], ['default_work_minutes.2']],
    'interruptor no booleano' => [['require_2fa' => 'quizá'], ['require_2fa']],
]);

test('el recordatorio de los viernes se activa y desactiva desde los ajustes; sin enviarlo, se conserva', function () {
    expect(Setting::get('week_reminder_enabled'))->toBeTrue();
    // Sin exigir el doble factor: el admin del test no lo tiene.
    $valid = [...$this->valid, 'require_2fa' => false];

    $this->actingAs($this->admin)
        ->put('/admin/ajustes', [...$valid, 'week_reminder_enabled' => false])
        ->assertSessionHasNoErrors();

    expect(Setting::get('week_reminder_enabled'))->toBeFalse();

    $this->actingAs($this->admin)->put('/admin/ajustes', $valid)->assertSessionHasNoErrors();

    expect(Setting::get('week_reminder_enabled'))->toBeFalse();

    $this->actingAs($this->admin)
        ->put('/admin/ajustes', [...$valid, 'week_reminder_enabled' => 'quizá'])
        ->assertSessionHasErrors('week_reminder_enabled');
});
