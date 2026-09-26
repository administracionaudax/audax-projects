<?php

use App\Domain\Time\Capacity;
use App\Models\Setting;
use App\Models\User;
use App\Models\WorkSchedule;
use Carbon\CarbonImmutable;

it('sin horario usa la jornada por defecto (lunes a viernes 8 h)', function () {
    $user = User::factory()->create();

    $week = app(Capacity::class)->forRange($user, CarbonImmutable::parse('2026-09-21'), CarbonImmutable::parse('2026-09-27'));

    expect(array_values($week))->toBe([480, 480, 480, 480, 480, 0, 0])
        ->and(array_key_first($week))->toBe('2026-09-21');
});

it('respeta el ajuste default_work_minutes', function () {
    Setting::set('default_work_minutes', [420, 420, 420, 420, 360, 0, 0]);
    $user = User::factory()->create();

    expect(app(Capacity::class)->onDate($user, CarbonImmutable::parse('2026-09-25')))->toBe(360);
});

it('usa el horario vigente en cada fecha (versionado)', function () {
    $user = User::factory()->create();
    WorkSchedule::factory()->for($user)->create(['valid_from' => '2026-01-01', 'valid_to' => '2026-06-30']);
    WorkSchedule::factory()->for($user)->intensive()->create(['valid_from' => '2026-07-01', 'valid_to' => '2026-08-31']);
    WorkSchedule::factory()->for($user)->create(['valid_from' => '2026-09-01', 'fri_minutes' => 300]);

    $capacity = app(Capacity::class);

    expect($capacity->onDate($user, CarbonImmutable::parse('2026-06-26')))->toBe(480)
        ->and($capacity->onDate($user, CarbonImmutable::parse('2026-07-03')))->toBe(360)
        ->and($capacity->onDate($user, CarbonImmutable::parse('2026-07-02')))->toBe(420)
        ->and($capacity->onDate($user, CarbonImmutable::parse('2026-09-25')))->toBe(300)
        ->and($capacity->onDate($user, CarbonImmutable::parse('2026-09-26')))->toBe(0);
});
