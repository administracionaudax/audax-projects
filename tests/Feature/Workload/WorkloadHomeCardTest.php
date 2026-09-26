<?php

use App\Models\Absence;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Workload\Concerns\BuildsWorkloadScenario;

/*
| «Mi carga» en Inicio (SPEC §5.1 y §9): esta semana (de hoy al domingo) y la que viene, frente a
| la capacidad, con el mismo reparto que la vista Carga. Solo lo mío (D-021). Es una prop diferida:
| no retrasa la primera carga de Inicio.
*/

pest()->use(BuildsWorkloadScenario::class);

beforeEach(function () {
    $this->buildWorkloadScenario();
});

it('no se calcula en la primera carga de Inicio: llega después como prop diferida', function () {
    $this->actingAs($this->people['elena'])
        ->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('home', false)
            ->missing('workload')
            ->loadDeferredProps(fn (Assert $reload) => $reload->has('workload.weeks', 2)));
});

it('mi carga de esta semana y de la que viene frente a mi capacidad, con mis vencidas y sin planificar', function () {
    $this->actingAs($this->people['elena'])
        ->get('/')
        ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $reload) => $reload
            ->where('workload.weeks.0', [
                'key' => 'semana-actual', 'from' => '2026-10-06', 'to' => '2026-10-11',
                // La vencida va hoy; de hoy al viernes, 4 días de 8 h.
                'planned' => 300, 'capacity' => 1920, 'reason' => null, 'reduced' => null,
            ])
            ->where('workload.weeks.1', [
                'key' => 'semana-que-viene', 'from' => '2026-10-12', 'to' => '2026-10-18',
                'planned' => 1800, 'capacity' => 1920, 'reason' => null,
                'reduced' => ['holidays' => 1, 'absence_days' => 0, 'partial_minutes' => 0, 'absence_label' => null],
            ])
            ->where('workload.overdue', 1)
            ->where('workload.unplanned', 1)));
});

it('solo lo mío: la carga de mis compañeros no aparece', function () {
    $this->actingAs($this->people['raul'])
        ->get('/')
        ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $reload) => $reload
            ->where('workload.weeks.1.planned', 0)
            ->where('workload.weeks.1.capacity', 1920)
            ->where('workload.overdue', 0)
            ->where('workload.unplanned', 0)));
});

it('una semana entera de vacaciones sale sin capacidad y con su motivo', function () {
    Absence::factory()->approved()->between('2026-10-12', '2026-10-18')->create(['user_id' => $this->people['raul']->id]);

    $this->actingAs($this->people['raul'])
        ->get('/')
        ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $reload) => $reload
            ->where('workload.weeks.1.capacity', 0)
            ->where('workload.weeks.1.reason', ['type' => 'mixed', 'label' => null])));

    // Sin el festivo, es solo la ausencia.
    DB::table('holidays')->delete();

    $this->actingAs($this->people['raul'])
        ->get('/')
        ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $reload) => $reload
            ->where('workload.weeks.1.reason', ['type' => 'absence', 'label' => 'Vacaciones'])));
});
