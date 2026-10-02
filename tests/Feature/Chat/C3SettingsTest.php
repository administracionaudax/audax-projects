<?php

use App\Models\Setting;
use Database\Seeders\DefaultSettingsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Ajuste de la duración máxima de los audios del chat (SPEC §12: por defecto 5 minutos) y los límites
| que recibe el navegador en la prop compartida `config`.
*/

beforeEach(function () {
    $this->seed(DefaultSettingsSeeder::class);
    $this->admin = userWithRole('admin');
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
        // Resumen semanal (Fase 2, D-047): obligatorios en el formulario de ajustes.
        'weekly_digest_enabled' => true,
        'occupancy_low_threshold' => 70,
        'occupancy_high_threshold' => 110,
    ];
});

it('por defecto un audio dura como máximo 5 minutos', function () {
    expect(Setting::get('max_audio_seconds'))->toBe(300);

    $this->actingAs($this->admin)
        ->get('/admin/ajustes')
        ->assertInertia(fn (Assert $page) => $page->where('settings.max_audio_seconds', 300));
});

it('el admin cambia la duración máxima de los audios', function () {
    $this->actingAs($this->admin)
        ->put('/admin/ajustes', [...$this->valid, 'max_audio_seconds' => 120])
        ->assertSessionHasNoErrors();

    expect(Setting::get('max_audio_seconds'))->toBe(120);
});

it('si no se envía, se conserva la guardada', function () {
    Setting::set('max_audio_seconds', 600);

    $this->actingAs($this->admin)->put('/admin/ajustes', $this->valid)->assertSessionHasNoErrors();

    expect(Setting::get('max_audio_seconds'))->toBe(600);
});

it('entre 30 segundos y 10 minutos (el tiempo máximo del transcriptor)', function (mixed $value) {
    $this->actingAs($this->admin)
        ->put('/admin/ajustes', [...$this->valid, 'max_audio_seconds' => $value])
        ->assertSessionHasErrors('max_audio_seconds');

    expect(Setting::get('max_audio_seconds'))->toBe(300);
})->with([29, 601, 'mucho', null]);

it('el navegador recibe los límites de los audios y los adjuntos en config', function () {
    Setting::set('max_audio_seconds', 180);
    Setting::set('max_attachment_mb', 20);

    $this->actingAs(userWithRole('employee'))
        ->get('/')
        ->assertInertia(fn (Assert $page) => $page
            ->where('config.max_audio_seconds', 180)
            ->where('config.max_attachment_mb', 20));
});
