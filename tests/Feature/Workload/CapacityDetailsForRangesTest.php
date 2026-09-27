<?php

use App\Domain\Time\Capacity;
use App\Models\Absence;
use App\Models\Holiday;
use App\Models\User;
use App\Models\WorkSchedule;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;

/*
| Capacity::detailsForRanges (añadido por la vista Carga): el mismo detalle que details(), para
| varias personas a la vez y con un número fijo de consultas.
*/

beforeEach(function () {
    $this->ana = User::factory()->employee()->create();
    $this->pau = User::factory()->employee()->create();
    WorkSchedule::factory()->for($this->pau)->create(['valid_from' => '2026-01-01', 'fri_minutes' => 240]);
    Holiday::factory()->create(['date' => '2026-10-12', 'name' => 'Fiesta Nacional de España']);
    Absence::factory()->approved()->between('2026-10-13', '2026-10-14')->create(['user_id' => $this->ana->id]);
    Absence::factory()->approved()->partial(120)->between('2026-10-16', '2026-10-16')->create(['user_id' => $this->pau->id, 'type' => 'training']);
    Absence::factory()->between('2026-10-15', '2026-10-15')->create(['user_id' => $this->pau->id]);
    $this->from = CarbonImmutable::parse('2026-10-12');
    $this->to = CarbonImmutable::parse('2026-10-18');
});

it('da lo mismo que details() para cada persona y rango, en el mismo orden', function () {
    $capacity = app(Capacity::class);

    $details = $capacity->detailsForRanges([
        ['user_id' => $this->pau->id, 'from' => $this->from, 'to' => $this->to],
        ['user_id' => $this->ana->id, 'from' => $this->from, 'to' => CarbonImmutable::parse('2026-10-14')],
    ]);

    expect($details[0])->toBe($capacity->details($this->pau, $this->from, $this->to))
        ->and($details[1])->toBe($capacity->details($this->ana, $this->from, CarbonImmutable::parse('2026-10-14')))
        ->and($details[1]['2026-10-13'])->toBe(['base' => 480, 'minutes' => 0, 'holiday' => null, 'absence' => ['type' => 'vacation', 'partial_minutes' => null]])
        ->and($details[0]['2026-10-16'])->toBe(['base' => 240, 'minutes' => 120, 'holiday' => null, 'absence' => ['type' => 'training', 'partial_minutes' => 120]])
        ->and($details[0]['2026-10-15']['absence'])->toBeNull()
        ->and($capacity->detailsForRanges([]))->toBe([]);
});

it('usa las mismas consultas con 2 personas que con 20', function () {
    $capacity = app(Capacity::class);
    $count = function (array $users) use ($capacity): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $capacity->detailsForRanges(array_map(fn (User $user): array => ['user_id' => $user->id, 'from' => $this->from, 'to' => $this->to], $users));
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $count([$this->ana]); // Calienta la caché de ajustes (jornada por defecto).
    $few = $count([$this->ana, $this->pau]);
    $many = $count([$this->ana, $this->pau, ...User::factory()->employee()->count(18)->create()->all()]);

    expect($many)->toBe($few)->and($few)->toBe(3);
});

it('da día a día lo mismo que comparar con coversDate(), minutesFor() y covers() en dos años (cambios de hora, de año y de horario)', function () {
    $user = User::factory()->employee()->create();
    WorkSchedule::factory()->for($user)->create(['valid_from' => '2026-01-01', 'valid_to' => '2026-06-30', 'mon_minutes' => 420, 'fri_minutes' => 300]);
    WorkSchedule::factory()->for($user)->create(['valid_from' => '2026-07-01', 'valid_to' => null, 'wed_minutes' => 0, 'sat_minutes' => 240]);
    Holiday::factory()->create(['date' => '2026-03-29', 'name' => 'Cambio de hora (prueba)']);
    Holiday::factory()->create(['date' => '2027-01-01', 'name' => 'Año Nuevo']);
    Absence::factory()->approved()->between('2026-10-23', '2026-10-27')->create(['user_id' => $user->id]);
    Absence::factory()->approved()->partial(90)->between('2026-12-31', '2026-12-31')->create(['user_id' => $user->id, 'type' => 'training']);
    Absence::factory()->approved()->partial(600)->between('2027-03-26', '2027-03-26')->create(['user_id' => $user->id, 'type' => 'training']);

    $from = CarbonImmutable::parse('2025-12-15');
    $to = CarbonImmutable::parse('2027-12-31');
    $details = app(Capacity::class)->detailsForRanges([['user_id' => $user->id, 'from' => $from, 'to' => $to]])[0];

    // Referencia: el cálculo directo con los métodos de los modelos, día a día.
    $schedules = WorkSchedule::query()->where('user_id', $user->id)->orderByDesc('valid_from')->get();
    $holidays = Holiday::query()->get()->mapWithKeys(fn (Holiday $holiday) => [$holiday->date->toDateString() => $holiday->name])->all();
    $absences = Absence::query()->approved()->where('user_id', $user->id)->get();
    $default = Capacity::defaultWeek();
    $expected = [];

    foreach (CarbonPeriod::create($from, $to) as $day) {
        $date = $day->toDateString();
        $schedule = $schedules->first(fn (WorkSchedule $candidate) => $candidate->coversDate($day));
        $base = $schedule?->minutesFor($day) ?? $default[$day->dayOfWeekIso - 1];
        $minutes = isset($holidays[$date]) ? 0 : $base;
        $absence = null;

        foreach ($absences as $candidate) {
            if ($candidate->covers($date)) {
                $absence ??= ['type' => $candidate->type->value, 'partial_minutes' => $candidate->partial_minutes];
                $minutes = $candidate->partial_minutes === null ? 0 : max($minutes - $candidate->partial_minutes, 0);
            }
        }

        $expected[$date] = ['base' => $base, 'minutes' => $minutes, 'holiday' => $holidays[$date] ?? null, 'absence' => $absence];
    }

    expect($details)->toHaveCount(747)
        ->and($details)->toBe($expected)
        ->and($details['2026-10-25'])->toMatchArray(['minutes' => 0, 'absence' => ['type' => 'vacation', 'partial_minutes' => null]])
        ->and($details['2026-12-31'])->toMatchArray(['base' => 480, 'minutes' => 390])
        ->and($details['2027-03-26'])->toMatchArray(['base' => 480, 'minutes' => 0]);
});

it('calcula un año de 20 personas en poco tiempo (la vista Carga lo pide hasta la entrega más lejana)', function () {
    $users = User::factory()->employee()->count(20)->create();
    foreach ($users as $user) {
        WorkSchedule::factory()->for($user)->create(['valid_from' => '2026-01-01']);
        Absence::factory()->approved()->between('2026-11-02', '2026-11-06')->create(['user_id' => $user->id]);
    }

    $capacity = app(Capacity::class);
    $ranges = array_map(fn (User $user): array => ['user_id' => $user->id, 'from' => CarbonImmutable::parse('2026-10-01'), 'to' => CarbonImmutable::parse('2027-10-01')], $users->all());
    $capacity->detailsForRanges($ranges);

    $start = hrtime(true);
    $capacity->forRanges($ranges);
    $ms = (hrtime(true) - $start) / 1e6;

    // Antes, con CarbonPeriod y los casts de fecha, ~0,5 ms por persona y día (más de 3 s aquí).
    expect($ms)->toBeLessThan(500);
});
