<?php

use App\Domain\Reports\Dimension;
use App\Domain\Reports\Metrics;
use App\Domain\Reports\ReportFilters;
use App\Domain\Reports\ReportScope;
use App\Domain\Time\Capacity;
use App\Domain\Time\CapacityPlan;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Random\Engine\Mt19937;
use Random\Randomizer;

/*
| PERF-05: la capacidad por tramos (CapacityPlan, aritmética) da EXACTAMENTE lo mismo que el
| cálculo de antes, día a día con Carbon. Se compara con esa implementación de referencia (copiada
| aquí tal cual era) en casos con cambios de horario (versiones solapadas, huecos, sin fin, al
| revés), festivos y ausencias parciales (los días que la Fase 3 descuenta, como $overrides), altas
| y bajas (Metrics: desde el alta o el primer horario y, de baja, hasta su última entrada).
*/

/**
 * Referencia: Capacity::days() de antes (día a día, del horario más reciente al más antiguo).
 *
 * @param  Collection<int, WorkSchedule>  $schedules
 * @param  list<int>  $default
 * @return array<string, int>
 */
function capacityReferenceDays(Collection $schedules, array $default, CarbonImmutable $from, CarbonImmutable $to): array
{
    $capacity = [];

    foreach (CarbonPeriod::create($from, $to) as $day) {
        $schedule = $schedules->first(fn (WorkSchedule $candidate): bool => $candidate->coversDate($day));
        $capacity[$day->toDateString()] = $schedule?->minutesFor($day) ?? $default[$day->dayOfWeekIso - 1];
    }

    return $capacity;
}

/**
 * Referencia sin base de datos: versiones como arrays (del más reciente al más antiguo) y los
 * festivos y ausencias como días con su capacidad.
 *
 * @param  list<array{from: int, to: int|null, week: list<int>}>  $versions
 * @param  list<int>  $default
 * @param  array<int, int>  $overrides
 * @return array<string, int>
 */
function capacityReferencePlain(array $versions, array $default, int $from, int $to, array $overrides): array
{
    $capacity = [];

    for ($day = $from; $day <= $to; $day++) {
        $weekday = (int) CarbonImmutable::parse(CapacityPlan::date($day))->dayOfWeekIso;
        $minutes = $default[$weekday - 1];

        foreach ($versions as $version) {
            if ($version['from'] <= $day && ($version['to'] === null || $version['to'] >= $day)) {
                $minutes = $version['week'][$weekday - 1];

                break;
            }
        }

        $capacity[CapacityPlan::date($day)] = $overrides[$day] ?? $minutes;
    }

    return $capacity;
}

it('los días, la fecha y el día de la semana de CapacityPlan son los del calendario', function () {
    foreach (['1969-12-31', '1970-01-01', '2024-02-29', '2026-03-29', '2026-10-25', '2026-12-31', '2027-01-01', '2099-12-31'] as $date) {
        $day = CapacityPlan::day($date);

        expect(CapacityPlan::date($day))->toBe($date)
            ->and(CapacityPlan::weekday($day))->toBe((int) CarbonImmutable::parse($date)->dayOfWeekIso)
            ->and(CapacityPlan::day(CapacityPlan::date($day + 1)))->toBe($day + 1);
    }
});

it('los tramos dan lo mismo que día a día con cambios de horario, festivos y ausencias parciales (500 casos al azar)', function () {
    $random = new Randomizer(new Mt19937(20260927));
    $week = fn (): array => array_map(fn (): int => $random->getInt(0, 3) === 0 ? 0 : $random->getInt(1, 10) * 48, range(1, 7));
    $base = CapacityPlan::day('2025-12-01');

    for ($case = 0; $case < 500; $case++) {
        $from = $base + $random->getInt(0, 400);
        $to = $from + $random->getInt(-3, 420);

        // Versiones: solapadas, con huecos, sin fin o al revés (fin antes del inicio), del más
        // reciente al más antiguo (como las carga Capacity).
        $versions = [];
        for ($i = 0, $count = $random->getInt(0, 5); $i < $count; $i++) {
            $start = $base + $random->getInt(-60, 500);
            $versions[] = ['from' => $start, 'to' => $random->getInt(0, 2) === 0 ? null : $start + $random->getInt(-5, 200), 'week' => $week()];
        }
        usort($versions, fn (array $a, array $b): int => $b['from'] <=> $a['from']);
        $default = $week();

        // Festivos (0) y ausencias parciales (la jornada menos unos minutos, nunca por debajo de 0).
        $overrides = [];
        for ($i = 0, $count = $random->getInt(0, 6); $i < $count; $i++) {
            $day = $from + $random->getInt(0, max($to - $from, 0));
            $overrides[$day] = $random->getInt(0, 1) === 0 ? 0 : $random->getInt(0, 480);
        }

        $plan = Capacity::plan($versions, $default, $from, $to, $overrides);
        $reference = $to < $from ? [] : capacityReferencePlain($versions, $default, $from, $to, $overrides);

        expect($plan->byDate())->toBe($reference, "caso {$case}")
            ->and($plan->total())->toBe(array_sum($reference), "caso {$case}");

        // Cualquier tramo del rango, con aritmética, suma lo mismo que sus días.
        if ($to >= $from) {
            $a = $from + $random->getInt(-10, $to - $from);
            $b = $a + $random->getInt(0, 120);
            $expected = array_sum(array_filter($reference, fn (string $date): bool => $date >= CapacityPlan::date($a) && $date <= CapacityPlan::date($b), ARRAY_FILTER_USE_KEY));

            expect($plan->total(CapacityPlan::date($a), CapacityPlan::date($b)))->toBe($expected, "caso {$case}, tramo");
        }
    }
});

it('Capacity::forRanges da lo mismo que día a día con los horarios de la base', function () {
    $versioned = User::factory()->create();
    WorkSchedule::factory()->for($versioned)->create(['valid_from' => '2025-11-01', 'valid_to' => '2026-06-30']);
    WorkSchedule::factory()->for($versioned)->intensive()->create(['valid_from' => '2026-07-01', 'valid_to' => '2026-08-31']);
    WorkSchedule::factory()->for($versioned)->create(['valid_from' => '2026-09-01', 'fri_minutes' => 300, 'sat_minutes' => 120]);
    // Una versión solapada (la más reciente manda) y otra con hueco hasta la siguiente.
    $overlapped = User::factory()->create();
    WorkSchedule::factory()->for($overlapped)->create(['valid_from' => '2026-01-01', 'valid_to' => '2026-12-31', 'mon_minutes' => 360]);
    WorkSchedule::factory()->for($overlapped)->create(['valid_from' => '2026-05-15', 'valid_to' => '2026-05-31', 'wed_minutes' => 0]);
    WorkSchedule::factory()->for($overlapped)->create(['valid_from' => '2027-02-01', 'tue_minutes' => 420]);
    $default = User::factory()->create();

    $ranges = [];
    foreach ([$versioned, $overlapped, $default] as $user) {
        foreach ([['2025-10-15', '2027-03-31'], ['2026-05-20', '2026-06-02'], ['2026-09-26', '2026-09-26'], ['2026-12-28', '2027-02-10']] as [$from, $to]) {
            $ranges[] = ['user_id' => $user->id, 'from' => CarbonImmutable::parse($from), 'to' => CarbonImmutable::parse($to)];
        }
    }

    $new = app(Capacity::class)->forRanges($ranges);

    foreach ($ranges as $index => $range) {
        $schedules = WorkSchedule::query()->where('user_id', $range['user_id'])->orderByDesc('valid_from')->get();

        expect($new[$index])->toBe(capacityReferenceDays($schedules, Capacity::defaultWeek(), $range['from'], $range['to']), "rango {$index}");
    }
});

it('las métricas de capacidad dan lo mismo que antes con altas, bajas y cambios de horario', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-25 12:00', 'Europe/Madrid'));
    $admin = User::factory()->admin()->create(['created_at' => '2025-01-01 08:00']);
    $task = Task::factory()->create();

    // Alta a mitad de año con horario desde antes; alta sin horario; de baja con horas hasta el 14/08;
    // de baja sin horas en el periodo (no cuenta); horario con cambios en verano.
    $early = User::factory()->employee()->create(['created_at' => '2026-03-10 09:00']);
    WorkSchedule::factory()->for($early)->create(['valid_from' => '2026-01-01', 'valid_to' => '2026-06-30']);
    WorkSchedule::factory()->for($early)->intensive()->create(['valid_from' => '2026-07-01', 'valid_to' => '2026-08-31']);
    WorkSchedule::factory()->for($early)->create(['valid_from' => '2026-09-01', 'fri_minutes' => 300]);
    $newcomer = User::factory()->employee()->create(['created_at' => '2026-09-02 10:00']);
    $gone = User::factory()->employee()->inactive()->create(['created_at' => '2025-06-01 09:00']);
    WorkSchedule::factory()->for($gone)->create(['valid_from' => '2025-06-01', 'mon_minutes' => 240]);
    TimeEntry::factory()->forTask($task)->on('2026-08-14')->minutes(60)->create(['user_id' => $gone->id]);
    User::factory()->employee()->inactive()->create(['created_at' => '2025-06-01 09:00']);

    $metrics = app(Metrics::class);
    foreach ([['periodo' => 'anio'], ['periodo' => 'trimestre', 'fecha' => '2026-07-01'], ['periodo' => 'mes'], ['periodo' => 'rango', 'desde' => '2026-02-15', 'hasta' => '2026-10-10']] as $query) {
        $scope = new ReportScope($admin, ReportFilters::fromQuery($query));
        $reference = capacityReferenceByPerson($scope);
        $label = json_encode($query);

        expect(app(Metrics::class)->capacityByPerson($scope))->toBe($reference, "{$label} por persona")
            ->and($metrics->capacityTotalsByPerson($scope))->toBe(array_map('array_sum', $reference), "{$label} totales")
            ->and($metrics->capacityTotal($scope))->toBe(array_sum(array_map('array_sum', $reference)), "{$label} total")
            ->and($metrics->elapsedCapacityByPerson($scope))->toBe(array_map(
                fn (array $days): int => array_sum(array_filter($days, fn (string $date): bool => $date < LocalTime::todayString(), ARRAY_FILTER_USE_KEY)),
                $reference,
            ), "{$label} hasta ayer");

        foreach ([Dimension::Day, Dimension::Week, Dimension::Month] as $bucket) {
            $byBucket = [];
            foreach ($reference as $days) {
                foreach ($days as $date => $minutes) {
                    $key = Metrics::bucketOf($bucket, $date);
                    $byBucket[$key] = ($byBucket[$key] ?? 0) + $minutes;
                }
            }

            foreach ($metrics->series($scope, $bucket) as $point) {
                expect($point['capacity_minutes'])->toBe($byBucket[$point['bucket']] ?? 0, "{$label} {$bucket->value} {$point['bucket']}");
            }
        }
    }
});

/**
 * Referencia: Metrics::computeCapacityByPerson de antes (rangos de alta y baja) con los días de
 * capacityReferenceDays.
 *
 * @return array<int, array<string, int>>
 */
function capacityReferenceByPerson(ReportScope $scope): array
{
    $f = $scope->filters;
    $byPerson = [];

    foreach ($scope->people() as $person) {
        $joined = $person->created_at !== null ? LocalTime::dateOf($person->created_at) : $f->from->toDateString();
        $first = WorkSchedule::query()->where('user_id', $person->id)->min('valid_from');
        $start = max($f->from->toDateString(), $first !== null ? min($joined, substr((string) $first, 0, 10)) : $joined);
        $last = TimeEntry::query()->where('user_id', $person->id)->whereBetween('date', [$f->from->toDateString(), $f->to->toDateString()])->max('date');
        $end = $person->is_active ? $f->to->toDateString() : ($last !== null ? substr((string) $last, 0, 10) : null);

        if ($end === null || $start > $end) {
            continue;
        }

        $schedules = WorkSchedule::query()->where('user_id', $person->id)->orderByDesc('valid_from')->get();
        $byPerson[$person->id] = capacityReferenceDays($schedules, Capacity::defaultWeek(), CarbonImmutable::parse($start), CarbonImmutable::parse($end));
    }

    return $byPerson;
}
