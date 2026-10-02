<?php

use App\Domain\Workload\WorkloadBoard;
use App\Domain\Workload\WorkloadFilters;
use App\Domain\Workload\WorkloadHorizon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Workload\Concerns\BuildsWorkloadScenario;

/*
| «Carga futura» del informe de departamento: la carga planificada de las próximas cuatro semanas
| de sus personas, la misma que la vista Carga con ese departamento (D-051), como prop diferida.
*/

pest()->use(BuildsWorkloadScenario::class);

beforeEach(function () {
    $this->buildWorkloadScenario();
});

it('llega como prop diferida, por semanas desde hoy, con las personas del departamento y el enlace a Carga', function () {
    $design = $this->departments['design'];

    $this->actingAs($this->people['raul'])
        ->get("/informes/departamentos/{$design->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('reports/department', false)
            ->missing('future_load')
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->has('future_load.columns', 4)
                ->where('future_load.columns.0.from', '2026-10-06')
                ->where('future_load.columns.0.to', '2026-10-11')
                ->where('future_load.columns.3.to', '2026-11-01')
                ->where('future_load.url', "/carga?horizonte=4-semanas&departamento={$design->id}")
                ->where('future_load.people', fn ($people): bool => collect($people)->isNotEmpty()
                    && collect($people)->every(fn (array $person): bool => count($person['cells']) === 4)
                    && collect($people)->pluck('name')->doesntContain('Pablo Ruiz'))
                ->has('future_load.totals', 4)));
});

it('cuadra con la vista Carga filtrada por el departamento, con las vacaciones descontadas', function () {
    $design = $this->departments['design'];
    $matrix = WorkloadBoard::for($this->people['ana'], new WorkloadFilters(WorkloadHorizon::FourWeeks, [$design->id]))->matrix();
    $group = collect($matrix['groups'])->firstWhere('department.id', $design->id);
    $weeks = [['2026-10-06', '2026-10-11'], ['2026-10-12', '2026-10-18'], ['2026-10-19', '2026-10-25'], ['2026-10-26', '2026-11-01']];

    // Lo que enseña /carga día a día, sumado por semanas.
    $expected = array_map(fn (array $person): array => [
        'id' => $person['id'],
        'name' => $person['name'],
        'cells' => array_map(function (array $week) use ($matrix, $person): array {
            $cell = ['planned' => 0, 'capacity' => 0];
            foreach ($matrix['columns'] as $index => $column) {
                if ($column['from'] >= $week[0] && $column['to'] <= $week[1]) {
                    $cell['planned'] += $person['cells'][$index]['planned'];
                    $cell['capacity'] += $person['cells'][$index]['capacity'];
                }
            }

            return $cell;
        }, $weeks),
        'total' => $person['total'],
    ], $group['people']);

    $this->actingAs($this->people['ana'])
        ->get("/informes/departamentos/{$design->id}")
        ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $reload) => $reload
            ->where('future_load.people', $expected)
            ->where('future_load.total', $group['total'])));

    // La segunda semana de Lucía pierde 3 días: el festivo del 12/10 y sus vacaciones del 13 y el 14.
    $lucia = collect($expected)->firstWhere('id', $this->people['lucia']->id);
    expect($lucia['cells'][1]['capacity'])->toBe($lucia['cells'][2]['capacity'] - 3 * 480);
});
