<?php

use App\Domain\DayPlan\DayPlanCalendar;
use App\Domain\Notifications\NotificationPreferences;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\DefaultSettingsSeeder;

/*
| Ajustes del plan del día en /admin/ajustes (D-252 y D-253) y su aviso en las preferencias (D-073).
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
        'weekly_digest_enabled' => true,
        'occupancy_low_threshold' => 70,
        'occupancy_high_threshold' => 110,
    ];
});

it('por defecto: módulo encendido, hora límite 08:30, recordatorio y un día con jornada hacia atrás', function () {
    expect(Setting::get('modules')['day_plan'])->toBeTrue()
        ->and(DayPlanCalendar::deadlineTime())->toBe('08:30')
        ->and(Setting::get('day_plan_reminder_enabled'))->toBeTrue()
        ->and(DayPlanCalendar::editableDays())->toBe(1);
});

it('guarda la hora límite, el recordatorio, los días que se cierran y el módulo', function () {
    $this->actingAs($this->admin)
        ->put('/admin/ajustes', [...$this->valid, 'day_plan_deadline' => '09:15', 'day_plan_reminder_enabled' => false, 'day_plan_editable_days' => 3, 'modules' => ['day_plan' => false]])
        ->assertSessionHasNoErrors();

    expect(Setting::get('day_plan_deadline'))->toBe('09:15')
        ->and(Setting::get('day_plan_reminder_enabled'))->toBeFalse()
        ->and(DayPlanCalendar::editableDays())->toBe(3)
        ->and(Setting::get('modules')['day_plan'])->toBeFalse()
        ->and(Setting::get('modules')['weeklies'])->toBeTrue();
});

it('valida la hora límite y los días', function (array $data, string $field) {
    $this->actingAs($this->admin)->put('/admin/ajustes', [...$this->valid, ...$data])->assertSessionHasErrors($field);
})->with([
    'hora mal escrita' => [['day_plan_deadline' => '8.30'], 'day_plan_deadline'],
    'hora imposible' => [['day_plan_deadline' => '25:00'], 'day_plan_deadline'],
    'demasiados días' => [['day_plan_editable_days' => 6], 'day_plan_editable_days'],
]);

it('el aviso sale en las preferencias de la plantilla, no de un colaborador ni con el módulo apagado', function () {
    $groups = fn (User $user): array => array_column(app(NotificationPreferences::class)->forUser($user)['groups'], 'key');
    $employee = userWithRole('employee');

    expect($groups($employee))->toContain('day_plan')
        ->and($groups(User::factory()->collaborator()->create()))->not->toContain('day_plan');

    Setting::set('modules', ['day_plan' => false]);
    expect($groups($employee))->not->toContain('day_plan');
});
