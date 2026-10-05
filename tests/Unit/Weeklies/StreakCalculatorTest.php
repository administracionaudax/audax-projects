<?php

use App\Domain\Weeklies\StreakCalculator;
use App\Domain\Weeklies\StreakWeek;
use App\Enums\WeeklyCycleStatus;
use Carbon\CarbonImmutable;

/*
| Rachas (D-150, F-099), portadas de ws:components/MemberDashboard.tsx:40-80: semanas seguidas a
| tiempo; las exentas no la rompen y la activa tampoco hasta que pasa el plazo.
*/

/**
 * Semana que acaba el viernes $friday. $submitted: hora de Madrid del envío o null.
 */
function streakWeek(string $friday, ?string $submitted, bool $exempt = false, bool $active = false, bool $required = true, ?string $deadline = null): StreakWeek
{
    return new StreakWeek(
        endDate: CarbonImmutable::parse($friday, 'Europe/Madrid'),
        deadlineDate: CarbonImmutable::parse($deadline ?? $friday, 'Europe/Madrid'),
        status: $active ? WeeklyCycleStatus::Active : WeeklyCycleStatus::Closed,
        exempt: $exempt,
        submittedAt: $submitted === null ? null : CarbonImmutable::parse($submitted, 'Europe/Madrid'),
        required: $required,
    );
}

beforeEach(function () {
    $this->streaks = new StreakCalculator;
    $this->now = CarbonImmutable::parse('2026-10-08 12:00', 'Europe/Madrid');
});

it('cuenta las semanas seguidas enviadas a tiempo', function () {
    $weeks = [
        streakWeek('2026-09-18', '2026-09-18 10:00'),
        streakWeek('2026-10-02', '2026-10-02 23:59'),
        streakWeek('2026-09-25', '2026-09-24 10:00'),
    ];

    expect($this->streaks->streak($weeks, $this->now))->toBe(3);
});

it('un envío con retraso corta la racha', function () {
    $weeks = [
        streakWeek('2026-10-02', '2026-10-02 10:00'),
        streakWeek('2026-09-25', '2026-09-26 09:00'),
        streakWeek('2026-09-18', '2026-09-18 10:00'),
    ];

    expect($this->streaks->streak($weeks, $this->now))->toBe(1);
});

it('una semana vencida sin enviar corta la racha', function () {
    $weeks = [
        streakWeek('2026-10-02', null),
        streakWeek('2026-09-25', '2026-09-25 10:00'),
    ];

    expect($this->streaks->streak($weeks, $this->now))->toBe(0);
});

it('una semana exenta no rompe la racha ni suma', function () {
    $weeks = [
        streakWeek('2026-10-02', '2026-10-02 10:00'),
        streakWeek('2026-09-25', null, exempt: true),
        streakWeek('2026-09-18', '2026-09-18 10:00'),
        // Exenta y enviada con retraso: tampoco cuenta.
        streakWeek('2026-09-11', '2026-09-14 10:00', exempt: true),
        streakWeek('2026-09-04', '2026-09-04 10:00'),
    ];

    expect($this->streaks->streak($weeks, $this->now))->toBe(3);
});

it('la semana activa sin enviar no rompe la racha hasta que pasa el plazo', function () {
    $weeks = [
        streakWeek('2026-10-09', null, active: true),
        streakWeek('2026-10-02', '2026-10-02 10:00'),
    ];

    expect($this->streaks->streak($weeks, $this->now))->toBe(1)
        ->and($this->streaks->streak($weeks, CarbonImmutable::parse('2026-10-09 23:59', 'Europe/Madrid')))->toBe(1)
        ->and($this->streaks->streak($weeks, CarbonImmutable::parse('2026-10-10 00:00:01', 'Europe/Madrid')))->toBe(0);
});

it('la semana activa enviada a tiempo suma y con retraso corta', function () {
    $onTime = [streakWeek('2026-10-09', '2026-10-08 10:00', active: true), streakWeek('2026-10-02', '2026-10-02 10:00')];
    $late = [streakWeek('2026-10-09', '2026-10-10 10:00', active: true), streakWeek('2026-10-02', '2026-10-02 10:00')];

    expect($this->streaks->streak($onTime, $this->now))->toBe(2)
        ->and($this->streaks->streak($late, CarbonImmutable::parse('2026-10-10 11:00', 'Europe/Madrid')))->toBe(0);
});

it('un plazo ampliado cuenta como a tiempo', function () {
    $weeks = [streakWeek('2026-10-02', '2026-10-05 10:00', deadline: '2026-10-05')];

    expect($this->streaks->streak($weeks, $this->now))->toBe(1);
});

it('las semanas anteriores al alta no cuentan', function () {
    $weeks = [
        streakWeek('2026-10-02', '2026-10-02 10:00'),
        streakWeek('2026-09-25', null, required: false),
        streakWeek('2026-09-18', null, required: false),
    ];

    expect($this->streaks->streak($weeks, $this->now))->toBe(1);
});

it('sin semanas, racha cero', function () {
    expect($this->streaks->streak([], $this->now))->toBe(0)
        ->and($this->streaks->summary([], $this->now))->toBe(['submitted' => 0, 'on_time' => 0, 'streak' => 0]);
});

it('resume envíos, puntualidad y racha para el perfil', function () {
    $weeks = [
        streakWeek('2026-10-09', null, active: true),
        streakWeek('2026-10-02', '2026-10-02 10:00'),
        streakWeek('2026-09-25', '2026-09-28 10:00'),
        streakWeek('2026-09-18', '2026-09-18 10:00'),
        streakWeek('2026-09-11', null, exempt: true),
        streakWeek('2026-09-04', null),
        streakWeek('2026-08-28', '2026-08-28 10:00', required: false),
    ];

    expect($this->streaks->summary($weeks, $this->now))->toBe(['submitted' => 3, 'on_time' => 2, 'streak' => 1]);
});
