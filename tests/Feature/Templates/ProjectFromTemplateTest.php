<?php

use App\Enums\HourBankStatus;
use App\Models\Client;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\ProjectTemplate;
use App\Models\Task;
use App\Models\TaskDependency;
use App\Models\TaskStatus;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Crear un proyecto desde una plantilla (SPEC §6, D-058): en el alta se elige la plantilla, el día
| 1 (por defecto el inicio del proyecto o hoy) y, si es de bolsas, su primera bolsa; el proyecto,
| la bolsa y las tareas, subtareas, hitos y dependencias se crean todo o nada.
*/

beforeEach(function () {
    TaskStatus::ensureDefaults();
    $this->manager = User::factory()->departmentManager()->create();
    $this->client = Client::factory()->create();
    $this->template = ProjectTemplate::query()->create(['name' => 'Web', 'structure' => [
        'tasks' => [
            ['ref' => 'dis', 'title' => 'Diseño', 'start_offset_days' => 0, 'duration_days' => 5, 'estimated_minutes' => 600],
            ['ref' => 'home', 'parent_ref' => 'dis', 'title' => 'Home', 'start_offset_days' => 1, 'duration_days' => 2, 'estimated_minutes' => 240],
            ['ref' => 'dev', 'title' => 'Desarrollo', 'start_offset_days' => 7, 'duration_days' => 10],
            ['ref' => 'go', 'title' => 'Publicación', 'start_offset_days' => 17, 'is_milestone' => true],
        ],
        'dependencies' => [['from_ref' => 'dis', 'to_ref' => 'dev'], ['from_ref' => 'dev', 'to_ref' => 'go']],
    ]]);
    $this->data = fn (array $overrides = []): array => [
        'name' => 'Web de Acme',
        'client_id' => $this->client->id,
        'billing_type' => 'time_and_materials',
        'status' => 'active',
        'color' => '#0171FF',
        'template_id' => $this->template->id,
        ...$overrides,
    ];
    $this->bank = [
        'name' => 'Bolsa inicial 40h',
        'department_id' => null,
        'total_minutes' => 2400,
        'start_date' => '2026-10-01',
        'end_date' => null,
        'overage_policy' => 'inherit',
    ];
    $this->actingAs($this->manager);
});

it('el alta ofrece las plantillas activas y los datos de la primera bolsa', function () {
    ProjectTemplate::query()->create(['name' => 'Inactiva', 'structure' => $this->template->structure, 'is_active' => false]);
    ProjectTemplate::query()->create(['name' => 'Borrada', 'structure' => $this->template->structure])->delete();

    $this->get('/proyectos/nuevo')->assertInertia(fn (Assert $page) => $page
        ->component('projects/create')
        ->has('templates', 1)
        ->where('templates.0.name', 'Web')
        ->where('templates.0.stats', ['tasks' => 4, 'subtasks' => 1, 'milestones' => 1, 'dependencies' => 2, 'duration_days' => 18])
        ->has('departments')
        ->where('overageDefault', 'allow'));
});

it('crea el proyecto con las tareas, subtareas, hito y dependencias desde el día elegido', function () {
    $this->post('/proyectos', ($this->data)(['template_start' => '2026-10-05', 'start_date' => '2026-09-01']))
        ->assertSessionHasNoErrors()
        ->assertRedirect()
        ->assertInertiaFlash('toast.message', 'Proyecto creado con 4 tareas de la plantilla «Web».');

    $project = Project::query()->where('name', 'Web de Acme')->sole();
    $tasks = $project->tasks()->get()->keyBy('title');

    expect($tasks)->toHaveCount(4)
        ->and($tasks['Diseño']->start_date->toDateString())->toBe('2026-10-05')
        ->and($tasks['Diseño']->due_date->toDateString())->toBe('2026-10-09')
        ->and($tasks['Home']->parent_task_id)->toBe($tasks['Diseño']->id)
        ->and($tasks['Home']->start_date->toDateString())->toBe('2026-10-06')
        ->and($tasks['Desarrollo']->start_date->toDateString())->toBe('2026-10-12')
        ->and($tasks['Publicación']->is_milestone)->toBeTrue()
        ->and($tasks['Publicación']->start_date)->toBeNull()
        ->and($tasks['Publicación']->due_date->toDateString())->toBe('2026-10-22')
        // Sin personas: quien crea el proyecto no queda de responsable de nada.
        ->and($tasks->whereNotNull('assignee_user_id'))->toHaveCount(0)
        ->and(TaskDependency::query()->whereIn('successor_task_id', $tasks->modelKeys())->count())->toBe(2)
        ->and($project->isManagedBy($this->manager))->toBeTrue();
});

it('sin día elegido cuenta desde el inicio del proyecto y, si no tiene, desde hoy', function () {
    $this->post('/proyectos', ($this->data)(['name' => 'Con inicio', 'start_date' => '2026-11-02']))->assertSessionHasNoErrors();
    $withStart = Project::query()->where('name', 'Con inicio')->sole();
    expect($withStart->tasks()->where('title', 'Diseño')->sole()->start_date->toDateString())->toBe('2026-11-02');

    $this->travelTo('2026-12-14 10:00:00');
    $this->post('/proyectos', ($this->data)(['name' => 'Sin inicio', 'start_date' => null]))->assertSessionHasNoErrors();
    $withoutStart = Project::query()->where('name', 'Sin inicio')->sole();
    expect($withoutStart->tasks()->where('title', 'Diseño')->sole()->start_date->toDateString())->toBe('2026-12-14');
});

it('un proyecto de bolsas desde plantilla crea su primera bolsa y todas las tareas van a ella', function () {
    $this->post('/proyectos', ($this->data)(['billing_type' => 'hour_bank', 'template_start' => '2026-10-05']))
        ->assertSessionHasErrors(['hour_bank' => 'Elige la bolsa a la que irán las tareas de la plantilla.']);
    expect(Project::query()->where('name', 'Web de Acme')->exists())->toBeFalse();

    $this->post('/proyectos', ($this->data)(['billing_type' => 'hour_bank', 'template_start' => '2026-10-05', 'hour_bank' => $this->bank]))
        ->assertSessionHasNoErrors();

    $project = Project::query()->where('name', 'Web de Acme')->sole();
    $bank = HourBank::query()->where('project_id', $project->id)->sole();

    expect($bank->name)->toBe('Bolsa inicial 40h')
        ->and($bank->total_minutes)->toBe(2400)
        ->and($bank->status)->toBe(HourBankStatus::Active)
        ->and($project->tasks()->where('hour_bank_id', $bank->id)->count())->toBe(4);
});

it('valida los datos de la primera bolsa junto a sus campos', function () {
    $this->post('/proyectos', ($this->data)(['billing_type' => 'hour_bank', 'hour_bank' => [...$this->bank, 'name' => '', 'total_minutes' => 0]]))
        ->assertSessionHasErrors(['hour_bank.name', 'hour_bank.total_minutes']);

    expect(Project::query()->count())->toBe(0);
});

it('sin plantilla un proyecto de bolsas se crea como hasta ahora, sin bolsa', function () {
    $this->post('/proyectos', ($this->data)(['billing_type' => 'hour_bank', 'template_id' => null]))->assertSessionHasNoErrors();

    $project = Project::query()->where('name', 'Web de Acme')->sole();
    expect($project->tasks()->count())->toBe(0)
        ->and(HourBank::query()->count())->toBe(0);
});

it('una plantilla desactivada o en la papelera no se aplica y no se crea nada', function (string $state) {
    $state === 'inactiva' ? $this->template->forceFill(['is_active' => false])->save() : $this->template->delete();

    $this->post('/proyectos', ($this->data)())
        ->assertSessionHasErrors(['template_id' => 'Esa plantilla ya no está disponible. Elige otra.']);

    expect(Project::query()->count())->toBe(0);
})->with(['inactiva', 'en la papelera']);

it('todo o nada: si la plantilla no se puede aplicar, no se crea el proyecto ni la bolsa', function () {
    // Una estructura guardada antes de validar los ciclos (o editada a mano en la base).
    $broken = ProjectTemplate::query()->create(['name' => 'Rota', 'structure' => [
        'tasks' => [['ref' => 'a', 'title' => 'A'], ['ref' => 'b', 'title' => 'B']],
        'dependencies' => [['from_ref' => 'a', 'to_ref' => 'b'], ['from_ref' => 'b', 'to_ref' => 'a']],
    ]]);

    $this->post('/proyectos', ($this->data)(['template_id' => $broken->id, 'billing_type' => 'hour_bank', 'hour_bank' => $this->bank]))
        ->assertSessionHasErrors('template_id');

    expect(Project::query()->count())->toBe(0)
        ->and(HourBank::query()->count())->toBe(0)
        ->and(Task::query()->count())->toBe(0);
});
