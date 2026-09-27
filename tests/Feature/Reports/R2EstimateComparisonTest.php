<?php

use App\Domain\Reports\EstimateComparison;
use App\Domain\Reports\ReportFilters;
use App\Domain\Reports\ReportScope;
use App\Models\Department;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\TaskType;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;

/*
| Estimado frente a real de un proyecto (R2, SPEC §6 y §10.3), calculado a mano:
|  - A (Diseño, estimada 300, sin subtareas): 420 reales,
|  - B (Diseño, estimada 500, que NO cuenta) con B1 (Maquetación, 120) y B2 (sin tipo ni estimación):
|    60 directas en B + 150 en B1 + 30 en B2 → estimada 120 (la de sus subtareas), reales 240,
|  - C (Maquetación, estimada 200) con C1 (Diseño, sin estimación): como ninguna subtarea está
|    estimada, cuenta la de C → estimada 200, reales 100 (de C1),
|  - un hito (fuera) y 45 min imputados al proyecto en una tarea que ya está en otro proyecto.
| Totales: estimadas 300 + 120 + 200 = 620; reales 420 + 240 + 100 + 45 = 805.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-25 12:00', 'Europe/Madrid'));
    TaskStatus::ensureDefaults();

    $this->design = TaskType::factory()->create(['name' => 'Diseño']);
    $this->layout = TaskType::factory()->create(['name' => 'Maquetación']);
    $this->team = Department::factory()->create();
    $this->head = User::factory()->departmentManager()->create();
    $this->team->managers()->attach($this->head);
    $this->ana = User::factory()->employee()->create(['department_id' => $this->team->id]);
    $this->outsider = User::factory()->employee()->create();
    $this->admin = User::factory()->admin()->create();

    $this->project = Project::factory()->create();
    $this->a = Task::factory()->create(['project_id' => $this->project->id, 'title' => 'A', 'task_type_id' => $this->design->id, 'estimated_minutes' => 300]);
    $this->b = Task::factory()->create(['project_id' => $this->project->id, 'title' => 'B', 'task_type_id' => $this->design->id, 'estimated_minutes' => 500]);
    $this->b1 = Task::factory()->subtaskOf($this->b)->create(['title' => 'B1', 'task_type_id' => $this->layout->id, 'estimated_minutes' => 120]);
    $this->b2 = Task::factory()->subtaskOf($this->b)->create(['title' => 'B2', 'estimated_minutes' => null]);
    $this->c = Task::factory()->create(['project_id' => $this->project->id, 'title' => 'C', 'task_type_id' => $this->layout->id, 'estimated_minutes' => 200]);
    $this->c1 = Task::factory()->subtaskOf($this->c)->create(['title' => 'C1', 'task_type_id' => $this->design->id, 'estimated_minutes' => null]);
    Task::factory()->milestone()->create(['project_id' => $this->project->id, 'title' => 'Hito']);

    TimeEntry::factory()->forTask($this->a)->on('2025-12-01')->minutes(420)->create(['user_id' => $this->ana->id]);
    TimeEntry::factory()->forTask($this->b)->on('2026-09-01')->minutes(60)->create(['user_id' => $this->ana->id]);
    TimeEntry::factory()->forTask($this->b1)->on('2026-09-02')->minutes(150)->create(['user_id' => $this->outsider->id]);
    TimeEntry::factory()->forTask($this->b2)->on('2026-09-03')->minutes(30)->create(['user_id' => $this->ana->id]);
    TimeEntry::factory()->forTask($this->c1)->on('2026-09-04')->minutes(100)->create(['user_id' => $this->ana->id]);

    // Una tarea que se movió a otro proyecto: sus horas se quedan en este (SPEC §6).
    $moved = Task::factory()->create(['title' => 'Movida']);
    TimeEntry::factory()->forTask($moved)->on('2026-09-05')->minutes(45)->create(['user_id' => $this->ana->id, 'project_id' => $this->project->id]);

    $this->compare = fn (User $viewer, array $query = []) => app(EstimateComparison::class)->forProject(
        new ReportScope($viewer, ReportFilters::fromQuery(['periodo' => 'mes', 'fecha' => '2026-09-01', ...$query])->with(['projectIds' => [$this->project->id]])),
        $this->project,
    );
});

it('aplica la regla de subtareas y suma las horas de toda la vida del proyecto', function () {
    $result = ($this->compare)($this->admin);

    expect(array_map(fn (array $row): array => [$row['title'], $row['depth'], $row['estimated_minutes'], $row['actual_minutes'], $row['derived']], $result['tasks']))->toBe([
        // Desviación +120 (A y B empatan: primero la más antigua), después C (−100).
        ['A', 0, 300, 420, false],
        ['B', 0, 120, 240, true],
        ['B1', 1, 120, 150, false],
        ['B2', 1, null, 30, false],
        ['C', 0, 200, 100, false],
        ['C1', 1, null, 100, false],
    ])
        ->and($result['totals'])->toBe([
            'estimated_minutes' => 620, 'actual_minutes' => 805, 'other_minutes' => 45,
            'tasks' => 3, 'estimated_tasks' => 3, 'over_tasks' => 2,
        ]);
});

it('por tipo: la estimación cuenta donde vive y las horas, en el tipo de la tarea imputada', function () {
    $byType = ($this->compare)($this->admin)['by_type'];

    expect(array_map(fn (array $row): array => [$row['type']['name'] ?? null, $row['estimated_minutes'], $row['actual_minutes']], $byType))->toBe([
        ['Diseño', 300, 580],
        ['Maquetación', 320, 150],
        [null, 0, 30],
    ]);
});

it('las horas reales respetan quién ve qué (D-044) y los filtros de tipo acotan las tareas', function () {
    // El responsable no ve las 150 de B1 (de alguien de fuera de su equipo).
    $team = ($this->compare)($this->head);
    expect($team['totals']['actual_minutes'])->toBe(655)
        ->and(collect($team['tasks'])->firstWhere('title', 'B')['actual_minutes'])->toBe(90);

    $layout = ($this->compare)($this->admin, ['tipo' => [$this->layout->id]]);
    expect(array_column($layout['tasks'], 'title'))->toBe(['B1', 'C'])
        ->and($layout['totals']['actual_minutes'])->toBe(150)
        ->and($layout['totals']['estimated_minutes'])->toBe(320);
});
