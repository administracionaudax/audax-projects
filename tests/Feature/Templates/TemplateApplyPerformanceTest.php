<?php

use App\Domain\Templates\ProjectTemplateService;
use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectTemplate;
use App\Models\Task;
use App\Models\TaskDependency;
use App\Models\TaskStatus;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Aplicar una plantilla en el límite (500 tareas y casi 2000 dependencias, D-058) cabe en una
| petición: las tareas se crean con TaskWriter (unas pocas consultas cada una) y las dependencias
| se insertan de golpe, sin releer las del proyecto por cada una (antes, O(D²): minutos).
| Se mide en el servicio, en «Aplicar plantilla» de Ajustes y en el alta de proyecto.
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    TaskStatus::ensureDefaults();
    $this->manager = User::factory()->departmentManager()->create();
    $this->project = Project::factory()->create(['owner_user_id' => $this->manager->id, 'start_date' => '2026-10-05']);

    // 100 tareas de primer nivel con 4 subtareas cada una; cada tarea depende de las 4 anteriores.
    $tasks = [];
    $dependencies = [];
    for ($i = 0; $i < ProjectTemplateService::MAX_TASKS; $i++) {
        $root = intdiv($i, 5) * 5;
        $tasks[] = [
            'ref' => "t{$i}",
            'parent_ref' => $i === $root ? null : "t{$root}",
            'title' => "Tarea {$i}",
            'estimated_minutes' => 60,
            'start_offset_days' => $i,
            'duration_days' => 2,
        ];
        for ($back = 1; $back <= 4 && $i - $back >= 0; $back++) {
            $dependencies[] = ['from_ref' => 't'.($i - $back), 'to_ref' => "t{$i}"];
        }
    }
    $this->template = ProjectTemplate::query()->create(['name' => 'Grande', 'structure' => ['tasks' => $tasks, 'dependencies' => $dependencies]]);
    $this->dependencyCount = count($dependencies);

    $this->count = function (callable $callback): array {
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $callback();

        return [
            'total' => count($queries),
            'dependencies' => count(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'task_dependencies'))),
        ];
    };
});

it('aplica una plantilla de 500 tareas y 1990 dependencias con las dependencias de golpe', function () {
    expect($this->dependencyCount)->toBe(1990);

    $measured = ($this->count)(fn () => app(ProjectTemplateService::class)->apply(
        $this->template, $this->project, $this->project->start_date->toImmutable(), $this->manager,
    ));

    expect(Task::query()->where('project_id', $this->project->id)->count())->toBe(500)
        ->and(TaskDependency::query()->count())->toBe(1990)
        // Las dependencias: unos pocos INSERT por lotes, nunca una consulta (o varias) por cada una.
        ->and($measured['dependencies'])->toBeLessThanOrEqual(20)
        // Las tareas: las de TaskWriter (medido: menos de 9 por tarea) y unas pocas más.
        ->and($measured['total'])->toBeLessThanOrEqual(500 * 10 + 60);

    // Cada dependencia une las tareas que tocan, con quién la creó.
    $last = Task::query()->where('project_id', $this->project->id)->where('title', 'Tarea 499')->sole();
    expect(TaskDependency::query()->where('successor_task_id', $last->id)->count())->toBe(4)
        ->and(TaskDependency::query()->whereNull('created_by')->exists())->toBeFalse()
        ->and(TaskDependency::query()->whereNull('created_at')->exists())->toBeFalse();
});

it('«Aplicar plantilla» en Ajustes con la plantilla más grande cabe en su presupuesto', function () {
    $measured = ($this->count)(fn () => $this->actingAs($this->manager)
        ->post("/proyectos/{$this->project->id}/plantilla/aplicar", [
            'template_id' => $this->template->id,
            'start_date' => '2026-10-05',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect("/proyectos/{$this->project->id}/ajustes"));

    expect(TaskDependency::query()->count())->toBe(1990)
        ->and($measured['dependencies'])->toBeLessThanOrEqual(20)
        ->and($measured['total'])->toBeLessThanOrEqual(500 * 10 + 150);
});

it('el alta de proyecto desde la plantilla más grande cabe en su presupuesto', function () {
    $measured = ($this->count)(fn () => $this->actingAs($this->manager)
        ->post('/proyectos', [
            'name' => 'Proyecto grande',
            'client_id' => Client::factory()->create()->id,
            'billing_type' => 'time_and_materials',
            'status' => 'active',
            'color' => '#0171FF',
            'template_id' => $this->template->id,
            'template_start' => '2026-10-05',
        ])
        ->assertSessionHasNoErrors());

    $project = Project::query()->where('name', 'Proyecto grande')->sole();
    expect(Task::query()->where('project_id', $project->id)->count())->toBe(500)
        ->and(TaskDependency::query()->count())->toBe(1990)
        ->and($measured['dependencies'])->toBeLessThanOrEqual(20)
        ->and($measured['total'])->toBeLessThanOrEqual(500 * 10 + 150);
});
