<?php

use App\Enums\HourBankStatus;
use App\Enums\TimeEntryStatus;
use App\Models\HourBank;
use App\Models\Task;
use App\Models\TaskType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Portal\BanksScenario;

/*
| Presupuesto de consultas de las páginas de bolsas del portal (P1; SPEC §19: sin N+1). El inicio,
| el detalle y el PDF hacen las MISMAS consultas con el escenario pequeño que con muchas más bolsas,
| renovaciones, entradas, personas, tareas y tipos, y no pasan de un techo fijo.
*/

beforeEach(function () {
    $this->s = BanksScenario::build($this);
    $this->queries = 0;
    DB::listen(function (): void {
        $this->queries++;
    });

    // Consultas de una petición, con las cachés ya calientes (ajustes, permisos).
    $this->measure = function (string $path): int {
        $this->actingAs($this->s->portal)->get($path)->assertOk();
        $this->queries = 0;
        $this->actingAs($this->s->portal)->get($path)->assertOk();

        return $this->queries;
    };
});

it('el inicio, el detalle y el PDF no crecen con los datos (sin N+1) y no pasan de su techo', function () {
    $s = $this->s;
    $paths = [
        'inicio' => '/portal',
        'detalle' => "/portal/bolsas/{$s->b1->id}",
        'pdf' => "/portal/bolsas/{$s->b1->id}/pdf",
    ];

    $before = array_map(fn (string $path): int => ($this->measure)($path), $paths);

    // Muchos más datos: 5 personas, 5 tipos y 25 tareas con 50 entradas más en B1, y una cadena de
    // 6 renovaciones más en NAN-MKT con sus horas.
    $people = User::factory()->employee()->count(5)->create();
    $types = TaskType::factory()->count(5)->create();
    foreach (range(0, 24) as $i) {
        $task = Task::factory()->inBank($s->b1)->create(['task_type_id' => $types[$i % 5]->id]);
        foreach ([1, 2] as $n) {
            BanksScenario::entry($task, $people[($i + $n) % 5], sprintf('2026-%02d-%02d', 8 + $n, 1 + $i), 15, TimeEntryStatus::Approved, "Extra {$i}.{$n}");
        }
    }
    $previous = $s->b2;
    foreach (range(1, 6) as $i) {
        $previous->forceFill(['status' => HourBankStatus::Renewed])->save();
        $bank = HourBank::factory()->create(['project_id' => $s->mkt->id, 'name' => "Renovación {$i}", 'renewed_from_id' => $previous->id, 'start_date' => '2026-10-01']);
        $task = Task::factory()->inBank($bank)->create(['task_type_id' => $types[$i % 5]->id]);
        BanksScenario::entry($task, $people[$i % 5], '2026-10-02', 30, TimeEntryStatus::Approved, "Renovación {$i}");
        $previous = $bank;
    }

    $after = array_map(fn (string $path): int => ($this->measure)($path), $paths);
    $chain = ($this->measure)("/portal/bolsas/{$previous->id}");

    // Hoy: inicio 6 (cliente, bolsas, proyectos, cifras, horas del mes y una de las props
    // compartidas), detalle 11 (+ recuento, página de entradas, tareas, tipos, personas y meses) y
    // PDF 8.
    expect($after)->toBe($before)
        ->and($chain)->toBe($before['detalle'])
        ->and($before['inicio'])->toBeLessThanOrEqual(8)
        ->and($before['detalle'])->toBeLessThanOrEqual(13)
        ->and($before['pdf'])->toBeLessThanOrEqual(10);
});
