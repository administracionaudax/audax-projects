<?php

use App\Domain\Time\Capacity;
use App\Models\Setting;
use App\Models\User;
use App\Models\WorkSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

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

it('forRanges calcula varias personas y rangos con una sola consulta, igual que forRange', function () {
    $versioned = User::factory()->create();
    WorkSchedule::factory()->for($versioned)->intensive()->create(['valid_from' => '2026-07-01', 'valid_to' => '2026-08-31']);
    WorkSchedule::factory()->for($versioned)->create(['valid_from' => '2026-09-01', 'fri_minutes' => 300]);
    $default = User::factory()->create();
    $capacity = app(Capacity::class);

    $ranges = [
        ['user_id' => $versioned->id, 'from' => CarbonImmutable::parse('2026-08-31'), 'to' => CarbonImmutable::parse('2026-09-06')],
        ['user_id' => $default->id, 'from' => CarbonImmutable::parse('2026-09-21'), 'to' => CarbonImmutable::parse('2026-09-27')],
        ['user_id' => $versioned->id, 'from' => CarbonImmutable::parse('2026-07-06'), 'to' => CarbonImmutable::parse('2026-07-12')],
    ];

    DB::enableQueryLog();
    $result = $capacity->forRanges($ranges);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBeLessThanOrEqual(2) // horarios (y, como mucho, el ajuste de la jornada por defecto)
        ->and($result)->toHaveCount(3)
        ->and(array_values($result[0]))->toBe([420, 480, 480, 480, 300, 0, 0])
        ->and(array_values($result[1]))->toBe([480, 480, 480, 480, 480, 0, 0])
        ->and(array_values($result[2]))->toBe([420, 420, 420, 420, 360, 0, 0])
        ->and($capacity->forRanges([]))->toBe([]);

    foreach ($ranges as $index => $range) {
        $user = User::query()->findOrFail($range['user_id']);
        expect($result[$index])->toBe($capacity->forRange($user, $range['from'], $range['to']));
    }
});
