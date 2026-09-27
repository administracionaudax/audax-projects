<?php

use App\Models\HourBank;
use App\Models\Project;
use App\Models\ProjectTemplate;
use App\Models\Task;
use App\Models\TaskDependency;
use App\Models\TaskStatus;
use App\Models\TaskType;
use App\Models\TimeEntry;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Sección «Plantilla» de los Ajustes del proyecto (D-058): aplicar una plantilla (añade tareas y no
| toca las que hay; bolsa abierta obligatoria en proyectos de bolsas) y guardar el proyecto como
| plantilla (sin personas, horas ni estados). Sus datos llegan como prop diferida.
*/

beforeEach(function () {
    TaskStatus::ensureDefaults();
    $this->owner = User::factory()->employee()->create();
    $this->project = Project::factory()->create(['owner_user_id' => $this->owner->id, 'start_date' => '2026-10-05']);
    $this->template = ProjectTemplate::query()->create(['name' => 'Lanzamiento', 'structure' => [
        'tasks' => [
            ['ref' => 'a', 'title' => 'Preparar', 'start_offset_days' => 0, 'duration_days' => 2],
            ['ref' => 'b', 'title' => 'Publicar', 'start_offset_days' => 2, 'is_milestone' => true],
        ],
        'dependencies' => [['from_ref' => 'a', 'to_ref' => 'b']],
    ]]);
    $this->actingAs($this->owner);
});

it('Ajustes carga la sección como prop diferida con las plantillas activas', function () {
    ProjectTemplate::query()->create(['name' => 'Inactiva', 'structure' => $this->template->structure, 'is_active' => false]);
    Task::factory()->count(2)->create(['project_id' => $this->project->id]);

    $this->get("/proyectos/{$this->project->id}/ajustes")->assertInertia(fn (Assert $page) => $page
        ->component('projects/settings')
        ->missing('templating')
        ->missing('recurring')
        ->loadDeferredProps('planning', fn (Assert $reload) => $reload
            ->has('templating.templates', 1)
            ->where('templating.templates.0.name', 'Lanzamiento')
            ->where('templating.templates.0.stats.tasks', 2)
            ->where('templating.default_start', '2026-10-05')
            ->where('templating.task_count', 2)
            ->where('templating.uses_hour_banks', false)
            ->where('templating.banks', [])
            ->where('templating.archived', false)
            ->where('templating.max_tasks', 500)
            ->has('recurring.rules')));
});

it('aplica una plantilla desde una fecha sin tocar las tareas que ya hay', function () {
    $existing = Task::factory()->create(['project_id' => $this->project->id, 'title' => 'Ya estaba', 'due_date' => '2026-10-20']);

    $this->from("/proyectos/{$this->project->id}/ajustes")
        ->post("/proyectos/{$this->project->id}/plantilla/aplicar", ['template_id' => $this->template->id, 'start_date' => '2026-11-02'])
        ->assertSessionHasNoErrors()
        ->assertRedirect("/proyectos/{$this->project->id}/ajustes")
        ->assertInertiaFlash('toast.message', 'Se han creado 2 tareas de la plantilla «Lanzamiento».');

    $tasks = $this->project->tasks()->get()->keyBy('title');
    expect($tasks)->toHaveCount(3)
        ->and($tasks['Ya estaba']->due_date->toDateString())->toBe('2026-10-20')
        ->and($tasks['Ya estaba']->updated_at->equalTo($existing->updated_at))->toBeTrue()
        ->and($tasks['Preparar']->start_date->toDateString())->toBe('2026-11-02')
        ->and($tasks['Publicar']->due_date->toDateString())->toBe('2026-11-04')
        ->and(TaskDependency::query()->where('predecessor_task_id', $tasks['Preparar']->id)->where('successor_task_id', $tasks['Publicar']->id)->exists())->toBeTrue();

    // Aplicarla otra vez añade otra copia: es una acción explícita, no una sincronización.
    $this->post("/proyectos/{$this->project->id}/plantilla/aplicar", ['template_id' => $this->template->id, 'start_date' => '2026-12-07'])->assertSessionHasNoErrors();
    expect($this->project->tasks()->count())->toBe(5);
});

it('en un proyecto de bolsas pide una bolsa abierta del proyecto', function () {
    $project = Project::factory()->hourBank()->create(['owner_user_id' => $this->owner->id]);
    $open = HourBank::factory()->create(['project_id' => $project->id]);
    $closed = HourBank::factory()->closed()->create(['project_id' => $project->id]);
    $other = HourBank::factory()->create();
    $url = "/proyectos/{$project->id}/plantilla/aplicar";
    $data = ['template_id' => $this->template->id, 'start_date' => '2026-10-05'];

    $this->post($url, $data)->assertSessionHasErrors(['hour_bank_id' => 'Elige la bolsa a la que irán las tareas de la plantilla.']);
    $this->post($url, [...$data, 'hour_bank_id' => $closed->id])->assertSessionHasErrors(['hour_bank_id' => 'Elige una bolsa abierta de este proyecto.']);
    $this->post($url, [...$data, 'hour_bank_id' => $other->id])->assertSessionHasErrors('hour_bank_id');
    expect($project->tasks()->count())->toBe(0);

    $this->post($url, [...$data, 'hour_bank_id' => $open->id])->assertSessionHasNoErrors();
    expect($project->tasks()->where('hour_bank_id', $open->id)->count())->toBe(2);

    $this->get("/proyectos/{$project->id}/ajustes")->assertInertia(fn (Assert $page) => $page
        ->loadDeferredProps('planning', fn (Assert $reload) => $reload
            ->where('templating.uses_hour_banks', true)
            ->where('templating.banks', [['id' => $open->id, 'name' => $open->name]])));
});

it('no aplica una plantilla desactivada ni en un proyecto archivado', function () {
    $inactive = ProjectTemplate::query()->create(['name' => 'Inactiva', 'structure' => $this->template->structure, 'is_active' => false]);
    $url = "/proyectos/{$this->project->id}/plantilla/aplicar";

    $this->post($url, ['template_id' => $inactive->id, 'start_date' => '2026-10-05'])
        ->assertSessionHasErrors(['template_id' => 'Esa plantilla ya no está disponible. Elige otra.']);
    $this->post($url, ['template_id' => $this->template->id])->assertSessionHasErrors('start_date');

    $this->project->forceFill(['status' => 'archived'])->save();
    $this->post($url, ['template_id' => $this->template->id, 'start_date' => '2026-10-05'])
        ->assertSessionHasErrors(['template_id' => 'El proyecto está archivado: recupéralo antes de añadirle tareas.']);

    expect($this->project->tasks()->count())->toBe(0);
});

it('guarda el proyecto como plantilla sin personas, horas ni estados', function () {
    $type = TaskType::factory()->create();
    $done = TaskStatus::query()->where('category', 'done')->firstOrFail();
    $design = Task::factory()->assignedTo($this->owner)->create([
        'project_id' => $this->project->id, 'title' => 'Diseño', 'task_type_id' => $type->id, 'status_id' => $done->id,
        'start_date' => '2026-10-07', 'due_date' => '2026-10-09', 'estimated_minutes' => 480, 'priority' => 'high',
    ]);
    $milestone = Task::factory()->milestone()->create(['project_id' => $this->project->id, 'title' => 'Entrega', 'due_date' => '2026-10-12']);
    TaskDependency::query()->create(['predecessor_task_id' => $design->id, 'successor_task_id' => $milestone->id]);
    TimeEntry::factory()->forTask($design)->minutes(90)->create(['user_id' => $this->owner->id]);

    $this->post("/proyectos/{$this->project->id}/plantilla/guardar", ['name' => '  Web estándar  ', 'description' => 'Para webs pequeñas'])
        ->assertSessionHasNoErrors()
        ->assertRedirect("/proyectos/{$this->project->id}/ajustes")
        ->assertInertiaFlash('toast.message', 'Plantilla «Web estándar» guardada con 2 tareas.');

    $template = ProjectTemplate::query()->where('name', 'Web estándar')->sole();
    $tasks = collect($template->structure['tasks'])->keyBy('title');

    expect($template->description)->toBe('Para webs pequeñas')
        ->and($template->is_active)->toBeTrue()
        ->and($template->created_by)->toBe($this->owner->id)
        ->and($tasks['Diseño'])->toMatchArray([
            'task_type_id' => $type->id, 'priority' => 'high', 'estimated_minutes' => 480,
            'start_offset_days' => 2, 'duration_days' => 3, 'is_milestone' => false,
        ])
        ->and($tasks['Entrega']['is_milestone'])->toBeTrue()
        ->and($tasks['Entrega']['start_offset_days'])->toBe(7)
        ->and($template->structure['dependencies'])->toHaveCount(1)
        ->and(json_encode($template->structure))->not->toContain('assignee')
        ->and(json_encode($template->structure))->not->toContain('status');
});

it('no guarda como plantilla un proyecto sin tareas y pide el nombre', function () {
    $this->post("/proyectos/{$this->project->id}/plantilla/guardar", ['name' => ''])->assertSessionHasErrors('name');
    $this->post("/proyectos/{$this->project->id}/plantilla/guardar", ['name' => 'Vacía'])
        ->assertSessionHasErrors(['capture' => 'El proyecto no tiene tareas que guardar como plantilla.']);

    expect(ProjectTemplate::query()->count())->toBe(1);
});
