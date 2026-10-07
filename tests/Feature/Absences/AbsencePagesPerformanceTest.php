<?php

use App\Enums\AbsenceStatus;
use App\Enums\AbsenceType;
use App\Enums\Role;
use App\Models\Absence;
use App\Models\Department;
use App\Models\Holiday;
use App\Models\User;
use App\Models\WorkSchedule;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Rendimiento de las páginas de festivos y ausencias con datos realistas (DemoDataSeeder) y
| ausencias y festivos añadidos alrededor (como en tests/Feature/Performance, sin funciones
| globales: las utilidades son closures del test).
|--------------------------------------------------------------------------
| Para admin, un responsable (Raúl, Diseño) y una empleada (Elena, Diseño):
|   1. las consultas de cada página caben en su presupuesto (medidas con la caché caliente) y
|      ninguna SQL se repite más de 4 veces (síntoma de N+1),
|   2. el número de consultas NO crece al añadir personas, ausencias de todo tipo y festivos.
*/

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);

    /** Presupuesto: lo medido (máximo de los tres roles) más 3. */
    $this->budgets = [
        // +1 fija con la Fase 6: el total sin leer del chat (prop compartida `chat.unread`).
        // +1 fija con la Fase 11, R3 (D-366): los días especiales del calendario (Capacity), una vez.
        'home' => 17,
        'absences.index' => 10,
        'absences.team' => 17,
        'absences.team.month' => 17,
        'absences.team.pending' => 6,
        'admin.holidays' => 6,
    ];

    $this->pages = [
        'home' => '/',
        'absences.index' => '/ausencias',
        'absences.team' => '/ausencias/equipo',
        'absences.team.month' => '/ausencias/equipo?mes='.now('Europe/Madrid')->addMonth()->format('Y-m'),
        'absences.team.pending' => '/ausencias/equipo/pendientes',
        'admin.holidays' => '/admin/festivos',
    ];

    $this->expectedStatus = fn (string $email, string $label): int => match (true) {
        $email === 'empleado@example.com' && str_starts_with($label, 'absences.team') => 403,
        $email !== 'admin@example.com' && $label === 'admin.holidays' => 403,
        default => 200,
    };

    // Mide la segunda petición (la primera calienta las cachés de ajustes, permisos y estados).
    $this->measure = function (User $user, string $url): array {
        $this->actingAs($user)->get($url);

        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $response = $this->actingAs($user)->get($url);
        app('events')->forget(QueryExecuted::class);

        $counts = array_count_values($queries);
        arsort($counts);

        return [
            'status' => $response->getStatusCode(),
            'total' => count($queries),
            'repeats' => $counts === [] ? 0 : (int) reset($counts),
            'repeated' => Str::limit((string) array_key_first($counts), 200),
        ];
    };

    $this->measureAll = function (User $user): array {
        $results = [];
        foreach ($this->pages as $label => $url) {
            $user->refresh();
            $results[$label] = ($this->measure)($user, $url);
        }

        return $results;
    };

    // Más personas en Diseño (con jornada propia) y ausencias de todo tipo y estado alrededor de
    // hoy, del mes que viene y de las personas medidas; más festivos del año.
    $this->grow = function (int $round): void {
        $design = Department::query()->where('name', 'Diseño')->sole();
        $marketing = Department::query()->where('name', '!=', 'Diseño')->orderBy('id')->firstOrFail();
        $today = CarbonImmutable::parse(now('Europe/Madrid')->toDateString());
        $measured = User::query()->whereIn('email', ['admin@example.com', 'responsable@example.com', 'empleado@example.com'])->get();

        $people = collect(range(1, 4))->map(function (int $i) use ($design, $marketing): User {
            $user = User::factory()->withRole(Role::Employee)->create(['department_id' => $i % 2 === 0 ? $design->id : $marketing->id]);
            WorkSchedule::factory()->for($user)->create(['valid_from' => '2026-01-01']);

            return $user;
        });

        foreach ([...$people->all(), ...$measured->all()] as $index => $user) {
            $offset = 40 * $round + 3 * $index;
            $start = $today->addDays($offset);
            $statuses = [AbsenceStatus::Requested, AbsenceStatus::Approved, AbsenceStatus::Rejected, AbsenceStatus::Cancelled];

            foreach ($statuses as $k => $status) {
                Absence::factory()->for($user)->create([
                    'type' => AbsenceType::cases()[$k % 5],
                    'status' => $status,
                    'start_date' => $start->addDays($k * 2)->toDateString(),
                    'end_date' => $start->addDays($k * 2 + 1)->toDateString(),
                    'approved_by' => $status === AbsenceStatus::Requested ? null : $measured->first()?->id,
                ]);
            }

            // Una aprobada en curso y una solicitud parcial dentro del mes que viene.
            Absence::factory()->for($user)->approved()->create([
                'start_date' => $today->subDays(1 + $round)->toDateString(),
                'end_date' => $today->addDays(1)->toDateString(),
                'approved_by' => $measured->first()?->id,
            ]);
            Absence::factory()->for($user)->partial(90)->create([
                'start_date' => $today->addMonth()->startOfMonth()->addDays($index + 10 * $round)->toDateString(),
                'end_date' => $today->addMonth()->startOfMonth()->addDays($index + 10 * $round)->toDateString(),
            ]);
        }

        foreach (range(1, 3) as $n) {
            Holiday::query()->firstOrCreate(
                ['date' => $today->addMonth()->startOfMonth()->addDays(3 * $n + $round)->toDateString()],
                ['name' => "Festivo {$round}.{$n}"],
            );
        }
    };
});

dataset('absence roles', [
    'admin' => 'admin@example.com',
    'responsable' => 'responsable@example.com',
    'empleada' => 'empleado@example.com',
]);

test('cada página de ausencias cabe en su presupuesto de consultas, sin consultas repetidas por fila', function (string $email) {
    ($this->grow)(1);
    $user = User::query()->where('email', $email)->sole();

    $problems = [];
    $results = ($this->measureAll)($user);

    // PERF_REPORT=1: imprime las consultas medidas de cada página.
    if (getenv('PERF_REPORT')) {
        foreach ($results as $label => $result) {
            fwrite(STDERR, sprintf("\n%-24s %s %3d %3d q (máx. %d rep.)", $label, $email, $result['status'], $result['total'], $result['repeats']));
        }
    }

    foreach ($results as $label => $result) {
        $expected = ($this->expectedStatus)($email, $label);

        if ($result['status'] !== $expected) {
            $problems[] = "{$label}: estado {$result['status']} (se esperaba {$expected})";
        }

        if ($result['total'] > $this->budgets[$label]) {
            $problems[] = "{$label}: {$result['total']} consultas (presupuesto {$this->budgets[$label]})";
        }

        if ($result['repeats'] > 4) {
            $problems[] = "{$label}: la misma consulta {$result['repeats']} veces: {$result['repeated']}";
        }
    }

    expect($problems)->toBe([]);
})->with('absence roles');

test('el número de consultas de las páginas de ausencias no crece con los datos (sin N+1)', function (string $email) {
    $user = User::query()->where('email', $email)->sole();

    ($this->grow)(1);
    $before = ($this->measureAll)($user);

    ($this->grow)(2);
    $after = ($this->measureAll)($user);

    $grew = [];
    foreach ($before as $label => $result) {
        if ($after[$label]['total'] > $result['total']) {
            $grew[] = "{$label}: {$result['total']} → {$after[$label]['total']} consultas";
        }
    }

    expect($grew)->toBe([]);
})->with('absence roles');
