<?php

use App\Models\WorkSchedule;
use Spatie\Activitylog\Models\Activity;

/*
| Jornadas con el margen de entrada, la comida prevista y la temporada de verano (D-336), desde la
| ficha del usuario (WorkScheduleController). Una versión nueva no reescribe el pasado.
*/

beforeEach(function () {
    $this->travelTo(madridAt('2026-10-07 12:00'));
    $this->admin = userWithRole('admin');
    $this->user = userWithRole('employee');
});

it('guarda el margen de entrada, la comida y el verano en la versión nueva', function () {
    $this->actingAs($this->admin)->post("/admin/usuarios/{$this->user->id}/jornadas", [
        'valid_from' => '2026-10-07',
        'week' => [480, 480, 480, 480, 480, 0, 0],
        'start_time_from' => '08:00',
        'start_time_to' => '10:00',
        'expected_pause_minutes' => 60,
        'summer' => true,
        'summer_starts_on' => '07-01',
        'summer_ends_on' => '08-31',
        'summer_week' => [420, 420, 420, 420, 420, 0, 0],
        'summer_expected_pause_minutes' => 0,
    ])->assertSessionHasNoErrors();

    $schedule = WorkSchedule::query()->where('user_id', $this->user->id)->sole();

    expect(substr((string) $schedule->start_time_from, 0, 5))->toBe('08:00')
        ->and(substr((string) $schedule->start_time_to, 0, 5))->toBe('10:00')
        ->and($schedule->expected_pause_minutes)->toBe(60)
        ->and($schedule->summer_week)->toBe([420, 420, 420, 420, 420, 0, 0])
        ->and($schedule->inSummer('2027-07-15'))->toBeTrue()
        ->and($schedule->minutesFor(madridAt('2027-07-15')))->toBe(420);
});

it('valida el margen y las fechas del verano', function (array $data, string $error) {
    $this->actingAs($this->admin)->post("/admin/usuarios/{$this->user->id}/jornadas", [
        'valid_from' => '2026-10-07',
        'week' => [480, 480, 480, 480, 480, 0, 0],
        ...$data,
    ])->assertSessionHasErrors($error);
})->with([
    'final antes del inicio' => [['start_time_from' => '10:00', 'start_time_to' => '08:00'], 'start_time_to'],
    'solo el inicio' => [['start_time_from' => '08:00'], 'start_time_to'],
    'verano sin fechas' => [['summer' => true, 'summer_week' => [420, 420, 420, 420, 420, 0, 0]], 'summer_starts_on'],
    'verano con fecha mal escrita' => [['summer' => true, 'summer_starts_on' => '1/7', 'summer_ends_on' => '08-31', 'summer_week' => [420, 420, 420, 420, 420, 0, 0]], 'summer_starts_on'],
    'comida de más de 4 h' => [['expected_pause_minutes' => 300], 'expected_pause_minutes'],
]);

it('sin verano, sus columnas quedan vacías y la capacidad no cambia', function () {
    $this->actingAs($this->admin)->post("/admin/usuarios/{$this->user->id}/jornadas", [
        'valid_from' => '2026-10-07',
        'week' => [480, 480, 480, 480, 480, 0, 0],
        'summer' => false,
        'summer_starts_on' => '07-01',
    ])->assertSessionHasNoErrors();

    $schedule = WorkSchedule::query()->where('user_id', $this->user->id)->sole();

    expect($schedule->summer_starts_on)->toBeNull()
        ->and($schedule->hasSummer())->toBeFalse()
        ->and($schedule->minutesFor(madridAt('2027-07-15')))->toBe(480);
});

it('los cambios de jornada quedan en la auditoría', function () {
    WorkSchedule::factory()->for($this->user)->create();

    expect(Activity::query()->where('log_name', 'work_schedules')->count())->toBe(1);
});
