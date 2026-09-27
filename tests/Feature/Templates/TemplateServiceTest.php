<?php

use App\Domain\Templates\ProjectTemplateService;
use Illuminate\Validation\ValidationException;

/*
| Añadidos de la Fase 4 (G3) a ProjectTemplateService (D-058): normalize() rechaza los ciclos
| (directos e indirectos) y no repite dependencias; findCycle() dice qué tareas lo forman; stats()
| da las cifras de los listados.
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
