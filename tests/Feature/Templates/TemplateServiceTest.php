<?php

use App\Domain\Schedule\DependencyService;
use App\Domain\Templates\ProjectTemplateService;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/*
| Añadidos de la Fase 4 (G3) a ProjectTemplateService (D-058): normalize() rechaza los ciclos
| (directos e indirectos) y no repite dependencias; findCycle() dice qué tareas lo forman; stats()
| da las cifras de los listados; capture() cuenta los días desde la tarea más temprana si empieza
| antes que el proyecto.
*/

beforeEach(function () {
    $this->tasks = [
        ['ref' => 'a', 'title' => 'A', 'start_offset_days' => 0, 'duration_days' => 3],
        ['ref' => 'b', 'title' => 'B', 'parent_ref' => 'a', 'start_offset_days' => 1, 'duration_days' => 1],
        ['ref' => 'c', 'title' => 'C', 'start_offset_days' => 5, 'duration_days' => 10],
        ['ref' => 'd', 'title' => 'D', 'start_offset_days' => 20, 'is_milestone' => true],
    ];
});

it('rechaza ciclos directos e indirectos', function (array $dependencies) {
    expect(fn () => ProjectTemplateService::normalize(['tasks' => $this->tasks, 'dependencies' => $dependencies]))
        ->toThrow(ValidationException::class, 'las dependencias forman un ciclo');
})->with([
    'directo' => [[['from_ref' => 'a', 'to_ref' => 'c'], ['from_ref' => 'c', 'to_ref' => 'a']]],
    'indirecto' => [[['from_ref' => 'a', 'to_ref' => 'c'], ['from_ref' => 'c', 'to_ref' => 'd'], ['from_ref' => 'd', 'to_ref' => 'a']]],
]);

it('no repite la misma dependencia y admite caminos que no son ciclos', function () {
    $structure = ProjectTemplateService::normalize(['tasks' => $this->tasks, 'dependencies' => [
        ['from_ref' => 'a', 'to_ref' => 'c'],
        ['from_ref' => 'a', 'to_ref' => 'c'],
        ['from_ref' => 'a', 'to_ref' => 'd'],
        ['from_ref' => 'c', 'to_ref' => 'd'],
    ]]);

    expect($structure['dependencies'])->toBe([
        ['from_ref' => 'a', 'to_ref' => 'c'],
        ['from_ref' => 'a', 'to_ref' => 'd'],
        ['from_ref' => 'c', 'to_ref' => 'd'],
    ]);
});

it('findCycle devuelve las tareas del ciclo o null', function () {
    expect(ProjectTemplateService::findCycle([
        ['from_ref' => 'x', 'to_ref' => 'a'],
        ['from_ref' => 'a', 'to_ref' => 'b'],
        ['from_ref' => 'b', 'to_ref' => 'c'],
        ['from_ref' => 'c', 'to_ref' => 'a'],
    ]))->toBe(['a', 'b', 'c'])
        ->and(ProjectTemplateService::findCycle([['from_ref' => 'a', 'to_ref' => 'b'], ['from_ref' => 'a', 'to_ref' => 'c'], ['from_ref' => 'b', 'to_ref' => 'c']]))->toBeNull()
        ->and(ProjectTemplateService::findCycle([]))->toBeNull();
});

it('stats cuenta tareas, subtareas, hitos, dependencias y la duración total', function () {
    expect(ProjectTemplateService::stats(['tasks' => $this->tasks, 'dependencies' => [['from_ref' => 'a', 'to_ref' => 'c']]]))
        ->toBe(['tasks' => 4, 'subtasks' => 1, 'milestones' => 1, 'dependencies' => 1, 'duration_days' => 21])
        ->and(ProjectTemplateService::stats([]))->toBe(['tasks' => 0, 'subtasks' => 0, 'milestones' => 0, 'dependencies' => 0, 'duration_days' => 0]);
});

it('guardar como plantilla cuenta desde la tarea más temprana si empieza antes que el proyecto', function () {
    $admin = User::factory()->admin()->create();
    $project = Project::factory()->create(['start_date' => '2026-10-05']);
    $briefing = Task::factory()->create(['project_id' => $project->id, 'title' => 'Briefing', 'start_date' => '2026-10-01', 'due_date' => '2026-10-02']);
    $design = Task::factory()->create(['project_id' => $project->id, 'title' => 'Diseño', 'start_date' => '2026-10-03', 'due_date' => '2026-10-06']);
    Task::factory()->create(['project_id' => $project->id, 'title' => 'Entrega', 'is_milestone' => true, 'due_date' => '2026-10-10']);
    app(DependencyService::class)->link($briefing, $design, $admin);
    $service = app(ProjectTemplateService::class);

    $template = $service->capture($project, 'Con briefing previo', null, $admin);
    $tasks = collect($template->structure['tasks'])->keyBy('title');

    // Día 0 = 1 de octubre (el briefing), no el inicio del proyecto: nada se amontona en el día 0.
    expect($tasks['Briefing']['start_offset_days'])->toBe(0)
        ->and($tasks['Briefing']['duration_days'])->toBe(2)
        ->and($tasks['Diseño']['start_offset_days'])->toBe(2)
        ->and($tasks['Diseño']['duration_days'])->toBe(4)
        ->and($tasks['Entrega']['start_offset_days'])->toBe(9);

    // Al aplicarla, el diseño sigue empezando después del briefing (sin conflicto, D-057).
    $copies = collect($service->apply($template, Project::factory()->create(), CarbonImmutable::parse('2026-11-02'), $admin))->keyBy('title');
    expect($copies['Briefing']->due_date->toDateString())->toBe('2026-11-03')
        ->and($copies['Diseño']->start_date->toDateString())->toBe('2026-11-04')
        ->and($copies['Entrega']->due_date->toDateString())->toBe('2026-11-11');
});

it('guardar como plantilla sigue contando desde el inicio del proyecto si ninguna tarea empieza antes', function () {
    $admin = User::factory()->admin()->create();
    $project = Project::factory()->create(['start_date' => '2026-10-05']);
    Task::factory()->create(['project_id' => $project->id, 'title' => 'Diseño', 'start_date' => '2026-10-12', 'due_date' => '2026-10-16']);
    Task::factory()->create(['project_id' => $project->id, 'title' => 'Sin fechas']);

    $tasks = collect(app(ProjectTemplateService::class)->capture($project, 'Web', null, $admin)->structure['tasks'])->keyBy('title');

    expect($tasks['Diseño']['start_offset_days'])->toBe(7)
        ->and($tasks['Sin fechas']['start_offset_days'])->toBe(0);
});
