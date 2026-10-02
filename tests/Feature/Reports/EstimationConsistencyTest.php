<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\WorkSchedule;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

/*
| BIZ-03: la precisión de estimación de los resúmenes (proyecto, dirección, persona e Inicio) usa la
| regla de subtareas del SPEC §6, la misma de la tabla «Estimado frente a real» del proyecto: con
| todo completado en el periodo, sus horas estimadas y reales cuadran con la tabla.
|
| Proyecto con tres tareas de Ana, completadas esta semana (hoy, viernes 25/09/2026):
|  - A: estimada 300, con dos subtareas SIN estimar: 30 en A + 60 + 90 en las subtareas → 180 reales,
|  - B: estimada 120, sin subtareas: 100 reales,
|  - C: con dos subtareas estimadas (100 y 50; C no cuenta la suya, 999): 120 + 40 reales.
| Estimadas 300 + 120 + 150 = 570; reales 180 + 100 + 160 = 440.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-22 10:00', 'Europe/Madrid'));
    TaskStatus::ensureDefaults();
    $done = TaskStatus::query()->where('category', 'done')->value('id');

    $this->admin = User::factory()->admin()->create();
    $this->ana = User::factory()->employee()->create(['name' => 'Ana']);
    WorkSchedule::factory()->for($this->ana)->create(['valid_from' => '2026-01-01']);
    $this->project = Project::factory()->create(['code' => 'EST']);
    $this->project->addMember($this->ana);

    $task = fn (array $attributes = [], ?Task $parent = null): Task => ($parent === null ? Task::factory() : Task::factory()->subtaskOf($parent))
        ->create(['project_id' => $this->project->id, 'assignee_user_id' => $this->ana->id, ...$attributes]);
    $log = fn (Task $task, int $minutes) => TimeEntry::factory()->forTask($task)->on('2026-09-22')->minutes($minutes)->create(['user_id' => $this->ana->id]);

    $a = $task(['estimated_minutes' => 300]);
    $a1 = $task(['estimated_minutes' => null], $a);
    $a2 = $task(['estimated_minutes' => null], $a);
    $b = $task(['estimated_minutes' => 120]);
    $c = $task(['estimated_minutes' => 999]);
    $c1 = $task(['estimated_minutes' => 100], $c);
    $c2 = $task(['estimated_minutes' => 50], $c);

    foreach ([[$a, 30], [$a1, 60], [$a2, 90], [$b, 100], [$c1, 120], [$c2, 40]] as [$target, $minutes]) {
        $log($target, $minutes);
    }
    foreach ([$a, $a1, $a2, $b, $c, $c1, $c2] as $target) {
        $target->update(['status_id' => $done]);
    }

    $this->travelTo(CarbonImmutable::parse('2026-09-25 12:00', 'Europe/Madrid'));
    $this->week = '?periodo=semana&fecha=2026-09-21';
});

it('el resumen del proyecto cuadra con su tabla estimado frente a real', function () {
    $this->actingAs($this->admin)
        ->get("/informes/proyectos/{$this->project->id}{$this->week}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('estimates.totals.estimated_minutes', 570)
            ->where('estimates.totals.actual_minutes', 440)
            ->where('summary.estimation.estimated_minutes', 570)
            ->where('summary.estimation.actual_minutes', 440)
            // Unidades: A, B y las dos subtareas estimadas de C.
            ->where('summary.estimation.tasks', 4)
            ->where('summary.estimation.accuracy', 1.2955));
});

it('dirección, la persona e Inicio usan la misma precisión', function () {
    $expected = ['tasks' => 4, 'estimated_minutes' => 570, 'actual_minutes' => 440, 'accuracy' => 1.2955, 'deviation' => -0.2281];

    $this->actingAs($this->admin)->get("/informes/direccion{$this->week}")
        ->assertInertia(fn (Assert $page) => $page->where('summary.estimation', $expected));

    $this->actingAs($this->ana)->get("/informes/personas/{$this->ana->id}{$this->week}")
        ->assertInertia(fn (Assert $page) => $page->where('summary.estimation', $expected));

    // Inicio: el mes en curso (las tareas se completaron el 22/09).
    $this->actingAs($this->ana)->get('/')
        ->assertInertia(fn (Assert $page) => $page->where('indicators.estimation', $expected));
});
