<?php

use App\Domain\Time\Capacity;
use App\Models\Absence;
use App\Models\Holiday;
use App\Models\User;
use App\Models\WorkSchedule;
use Carbon\CarbonImmutable;
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
