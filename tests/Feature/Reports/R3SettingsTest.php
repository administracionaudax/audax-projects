<?php

use App\Models\Setting;
use App\Models\User;
use Database\Seeders\DefaultSettingsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Ajustes del resumen semanal (D-047) en /admin/ajustes: weekly_digest_enabled (sí),
| occupancy_low_threshold (70 %) y occupancy_high_threshold (110 %), con la baja menor que la alta.
*/

beforeEach(function () {
    $this->seed(DefaultSettingsSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->valid = [
        'company_name' => 'Audax Studio',
        'require_2fa' => false,
        'timer_rounding_minutes' => 1,
        'timer_warning_hours' => 10,
        'hour_bank_alert_thresholds' => [75, 90, 100],
        'allow_hour_bank_overage' => true,
        'require_timesheet_approval' => true,
        'allow_future_time_entries' => false,
        'time_entry_description_required' => false,
        'max_attachment_mb' => 50,
        'default_work_minutes' => [480, 480, 480, 480, 480, 0, 0],
        'weekly_digest_enabled' => true,
        'occupancy_low_threshold' => 70,
        'occupancy_high_threshold' => 110,
    ];
});

it('tiene los valores por defecto de D-047 y los muestra en la página de ajustes', function () {
    expect(Setting::DEFAULTS['weekly_digest_enabled'])->toBeTrue()
        ->and(Setting::DEFAULTS['occupancy_low_threshold'])->toBe(70)
        ->and(Setting::DEFAULTS['occupancy_high_threshold'])->toBe(110);

    $this->actingAs($this->admin)
        ->get('/admin/ajustes')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('settings.weekly_digest_enabled', true)
            ->where('settings.occupancy_low_threshold', 70)
            ->where('settings.occupancy_high_threshold', 110));
});

it('guarda los ajustes del resumen semanal', function () {
    $this->actingAs($this->admin)
        ->put('/admin/ajustes', [...$this->valid, 'weekly_digest_enabled' => false, 'occupancy_low_threshold' => 50, 'occupancy_high_threshold' => 125])
        ->assertSessionHasNoErrors();

    expect(Setting::get('weekly_digest_enabled'))->toBeFalse()
        ->and(Setting::get('occupancy_low_threshold'))->toBe(50)
        ->and(Setting::get('occupancy_high_threshold'))->toBe(125);
});

it('valida los umbrales de ocupación', function (array $changes, array $errors) {
    $this->actingAs($this->admin)
        ->put('/admin/ajustes', [...$this->valid, ...$changes])
        ->assertSessionHasErrors($errors);

    expect(Setting::get('occupancy_low_threshold'))->toBe(70)
        ->and(Setting::get('occupancy_high_threshold'))->toBe(110);
})->with([
    'baja igual que la alta' => [['occupancy_low_threshold' => 110], ['occupancy_low_threshold' => 'La ocupación baja tiene que ser menor que la alta.']],
    'baja por encima de la alta' => [['occupancy_low_threshold' => 120, 'occupancy_high_threshold' => 100], ['occupancy_low_threshold']],
    'baja de 0' => [['occupancy_low_threshold' => 0], ['occupancy_low_threshold' => 'Indica un porcentaje entero entre 1 y 300.']],
    'alta de 301' => [['occupancy_high_threshold' => 301], ['occupancy_high_threshold']],
    'decimal' => [['occupancy_low_threshold' => 70.5], ['occupancy_low_threshold']],
    'sin umbrales' => [['occupancy_low_threshold' => null, 'occupancy_high_threshold' => null], ['occupancy_low_threshold', 'occupancy_high_threshold']],
    'interruptor no booleano' => [['weekly_digest_enabled' => 'quizá'], ['weekly_digest_enabled']],
]);

it('solo quien gestiona los ajustes puede cambiarlos', function () {
    $head = User::factory()->departmentManager()->create();

    $this->actingAs($head)
        ->put('/admin/ajustes', [...$this->valid, 'weekly_digest_enabled' => false])
        ->assertForbidden();

    expect(Setting::get('weekly_digest_enabled'))->toBeTrue();
});
