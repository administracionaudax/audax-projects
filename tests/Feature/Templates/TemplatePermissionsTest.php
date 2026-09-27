<?php

use App\Models\Project;
use App\Models\ProjectTemplate;
use App\Models\RecurringTaskRule;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;

/*
| Permisos de plantillas (D-022, D-058) y tareas recurrentes (D-059):
| - /admin/plantillas y /admin/tareas-recurrentes, y crear, editar, desactivar, borrar, recuperar,
|   exportar e importar plantillas: solo admin,
| - aplicar al crear un proyecto: quien crea proyectos (admin y responsables),
| - aplicar y guardar como plantilla en Ajustes y gestionar las reglas de un proyecto: quien lo
|   gestiona (admin, responsables y sus gestores); un miembro o un empleado, no,
| - un cliente siempre va a su portal y un invitado al login.
*/

beforeEach(function () {
    TaskStatus::ensureDefaults();

    $this->owner = User::factory()->employee()->create();
    $this->project = Project::factory()->create(['owner_user_id' => $this->owner->id, 'start_date' => '2026-10-05']);
    Task::factory()->create(['project_id' => $this->project->id, 'title' => 'Existente']);

    $this->actors = [
        'admin' => User::factory()->admin()->create(),
        'department_manager' => User::factory()->departmentManager()->create(),
        'manager' => $this->owner,
        'member' => User::factory()->employee()->create(),
        'employee' => User::factory()->employee()->create(),
        'client' => User::factory()->client()->create(),
    ];
    $this->project->addMember($this->actors['member']);

    $this->structure = [
        'tasks' => [
            ['ref' => 'a', 'title' => 'Diseño', 'start_offset_days' => 0, 'duration_days' => 3],
            ['ref' => 'b', 'title' => 'Entrega', 'start_offset_days' => 3, 'is_milestone' => true],
        ],
        'dependencies' => [['from_ref' => 'a', 'to_ref' => 'b']],
    ];
    $this->template = ProjectTemplate::query()->create(['name' => 'Web', 'structure' => $this->structure]);
    $this->trashed = ProjectTemplate::query()->create(['name' => 'Vieja', 'structure' => $this->structure]);
    $this->trashed->delete();

    $this->rule = RecurringTaskRule::query()->create([
        'project_id' => $this->project->id, 'title' => 'Informe', 'frequency' => 'weekly', 'weekday' => 1,
        'starts_on' => '2026-10-05', 'last_generated_on' => '2026-10-05',
    ]);

    $this->ruleData = [
        'title' => 'Reunión de seguimiento', 'description' => null, 'task_type_id' => null, 'assignee_user_id' => null,
        'hour_bank_id' => null, 'estimated_minutes' => 60, 'priority' => 'normal', 'frequency' => 'weekly', 'interval' => 1,
        'weekday' => 3, 'month_day' => null, 'due_offset_days' => 0, 'starts_on' => '2026-10-05', 'ends_on' => null, 'is_active' => true,
    ];

    $this->as = function (string $actor): void {
        if ($actor !== 'guest') {
            $this->actingAs($this->actors[$actor]);
        }
    };
});

dataset('admin_area', [
    'invitado' => ['guest', 302],
    'admin' => ['admin', 200],
    'responsable' => ['department_manager', 403],
    'gestor del proyecto' => ['manager', 403],
    'miembro' => ['member', 403],
    'empleado no miembro' => ['employee', 403],
    'cliente' => ['client', 302],
]);

test('matriz de las páginas de administración de plantillas y tareas recurrentes', function (string $actor, int $status) {
    ($this->as)($actor);

    $this->get('/admin/plantillas')->assertStatus($status);
    $this->get('/admin/plantillas?papelera=1')->assertStatus($status);
    $this->get('/admin/plantillas/nueva')->assertStatus($status);
    $this->get("/admin/plantillas/nueva?desde={$this->template->id}")->assertStatus($status);
    $this->get("/admin/plantillas/{$this->template->id}/editar")->assertStatus($status);
    $this->get("/admin/plantillas/{$this->template->id}/exportar")->assertStatus($status);
    $this->get('/admin/tareas-recurrentes')->assertStatus($status);
})->with('admin_area');

test('un cliente va a su portal y un invitado al login', function () {
    $this->get('/admin/plantillas')->assertRedirect(route('login'));
    $this->post("/proyectos/{$this->project->id}/plantilla/aplicar", [])->assertRedirect(route('login'));

    $this->actingAs($this->actors['client']);
    $this->get('/admin/plantillas')->assertRedirect(route('portal.home'));
    $this->get('/admin/tareas-recurrentes')->assertRedirect(route('portal.home'));
    $this->post("/proyectos/{$this->project->id}/tareas-recurrentes", $this->ruleData)->assertRedirect(route('portal.home'));
    $this->post("/proyectos/{$this->project->id}/plantilla/guardar", ['name' => 'X'])->assertRedirect(route('portal.home'));

    expect(RecurringTaskRule::query()->count())->toBe(1)
        ->and(ProjectTemplate::query()->count())->toBe(1);
});

test('crear, editar, activar, borrar, recuperar e importar plantillas: solo admin', function (string $actor, bool $allowed) {
    ($this->as)($actor);
    $status = $allowed ? 302 : 403;
    $valid = ['name' => 'Nueva', 'description' => null, 'is_active' => true, 'structure' => $this->structure];
    $file = UploadedFile::fake()->createWithContent('copia.json', (string) json_encode(['name' => 'Importada', 'structure' => $this->structure]));

    $this->post('/admin/plantillas', $valid)->assertStatus($status);
    $this->put("/admin/plantillas/{$this->template->id}", [...$valid, 'name' => 'Renombrada'])->assertStatus($status);
    $this->put("/admin/plantillas/{$this->template->id}/estado", ['is_active' => false])->assertStatus($status);
    $this->post("/admin/plantillas/{$this->trashed->id}/restaurar")->assertStatus($status);
    $this->post('/admin/plantillas/importar', ['file' => $file])->assertStatus($status);
    $this->delete("/admin/plantillas/{$this->template->id}")->assertStatus($status);

    expect(ProjectTemplate::query()->where('name', 'Nueva')->exists())->toBe($allowed)
        ->and(ProjectTemplate::query()->where('name', 'Importada')->exists())->toBe($allowed)
        ->and(ProjectTemplate::withTrashed()->find($this->template->id)->name === 'Renombrada')->toBe($allowed)
        ->and(ProjectTemplate::withTrashed()->find($this->template->id)->trashed())->toBe($allowed)
        ->and(ProjectTemplate::query()->whereKey($this->trashed->id)->exists())->toBe($allowed);
})->with([
    'admin' => ['admin', true],
    'responsable' => ['department_manager', false],
    'gestor del proyecto' => ['manager', false],
    'miembro' => ['member', false],
    'empleado no miembro' => ['employee', false],
]);

dataset('project_managers', [
    'admin' => ['admin', true],
    'responsable' => ['department_manager', true],
    'gestor del proyecto' => ['manager', true],
    'miembro' => ['member', false],
    'empleado no miembro' => ['employee', false],
]);

test('aplicar y guardar como plantilla en Ajustes: quien gestiona el proyecto', function (string $actor, bool $allowed) {
    ($this->as)($actor);
    $status = $allowed ? 302 : 403;

    $this->post("/proyectos/{$this->project->id}/plantilla/aplicar", [
        'template_id' => $this->template->id,
        'start_date' => '2026-10-05',
    ])->assertStatus($status)->assertSessionHasNoErrors();
    $this->post("/proyectos/{$this->project->id}/plantilla/guardar", ['name' => 'Desde el proyecto'])
        ->assertStatus($status)
        ->assertSessionHasNoErrors();

    expect($this->project->tasks()->count())->toBe($allowed ? 3 : 1)
        ->and(ProjectTemplate::query()->where('name', 'Desde el proyecto')->exists())->toBe($allowed);
})->with('project_managers');

test('gestionar las tareas recurrentes del proyecto: quien lo gestiona', function (string $actor, bool $allowed) {
    ($this->as)($actor);
    $status = $allowed ? 302 : 403;
    $base = "/proyectos/{$this->project->id}/tareas-recurrentes";

    $this->post($base, $this->ruleData)->assertStatus($status);
    $this->put("{$base}/{$this->rule->id}", [...$this->ruleData, 'title' => 'Informe semanal'])->assertStatus($status);
    $this->put("{$base}/{$this->rule->id}/estado", ['is_active' => false])->assertStatus($status);

    expect(RecurringTaskRule::query()->where('title', 'Reunión de seguimiento')->exists())->toBe($allowed)
        ->and($this->rule->fresh()->title === 'Informe semanal')->toBe($allowed)
        ->and($this->rule->fresh()->is_active)->toBe(! $allowed);

    $this->delete("{$base}/{$this->rule->id}")->assertStatus($status);
    expect(RecurringTaskRule::query()->whereKey($this->rule->id)->exists())->toBe(! $allowed);
})->with('project_managers');

test('alta de proyecto desde plantilla: quien crea proyectos', function (string $actor, bool $allowed) {
    ($this->as)($actor);

    $response = $this->post('/proyectos', [
        'name' => 'Web nueva', 'client_id' => $this->project->client_id, 'billing_type' => 'time_and_materials',
        'status' => 'active', 'color' => '#0171FF', 'template_id' => $this->template->id, 'template_start' => '2026-11-02',
    ]);

    if ($allowed) {
        $response->assertRedirect()->assertSessionHasNoErrors();
        expect(Project::query()->where('name', 'Web nueva')->sole()->tasks()->count())->toBe(2);
    } else {
        $response->assertForbidden();
        expect(Project::query()->where('name', 'Web nueva')->exists())->toBeFalse();
    }
})->with([
    'admin' => ['admin', true],
    'responsable' => ['department_manager', true],
    'gestor de un proyecto' => ['manager', false],
    'empleado' => ['employee', false],
]);

test('matriz de las políticas', function () {
    $can = fn (string $actor, string $ability, mixed $arguments) => Gate::forUser($this->actors[$actor])->allows($ability, $arguments);
    $inactive = ProjectTemplate::query()->create(['name' => 'Inactiva', 'structure' => $this->structure, 'is_active' => false]);

    foreach (['admin' => true, 'department_manager' => true, 'manager' => false, 'member' => false, 'employee' => false, 'client' => false] as $actor => $expected) {
        expect($can($actor, 'viewAny', ProjectTemplate::class))->toBe($expected, "viewAny {$actor}")
            ->and($can($actor, 'apply', $this->template))->toBe($expected, "apply {$actor}")
            ->and($can($actor, 'apply', $inactive))->toBeFalse();
    }

    foreach (['admin' => true, 'department_manager' => true, 'manager' => true, 'member' => false, 'employee' => false, 'client' => false] as $actor => $expected) {
        expect($can($actor, 'applyToProject', [$this->template, $this->project]))->toBe($expected, "applyToProject {$actor}")
            ->and($can($actor, 'capture', [ProjectTemplate::class, $this->project]))->toBe($expected, "capture {$actor}")
            ->and($can($actor, 'manage', [RecurringTaskRule::class, $this->project]))->toBe($expected, "manage {$actor}")
            ->and($can($actor, 'update', $this->rule))->toBe($expected, "rule {$actor}")
            ->and($can($actor, 'applyToProject', [$inactive, $this->project]))->toBeFalse();
    }

    foreach (['admin' => true, 'department_manager' => false, 'manager' => false, 'member' => false, 'employee' => false, 'client' => false] as $actor => $expected) {
        expect($can($actor, 'create', ProjectTemplate::class))->toBe($expected, "create {$actor}")
            ->and($can($actor, 'update', $this->template))->toBe($expected, "update {$actor}")
            ->and($can($actor, 'delete', $this->template))->toBe($expected, "delete {$actor}")
            ->and($can($actor, 'restore', $this->trashed))->toBe($expected, "restore {$actor}")
            ->and($can($actor, 'viewAny', RecurringTaskRule::class))->toBe($expected, "recurring viewAny {$actor}")
            ->and($can($actor, 'forceDelete', $this->trashed))->toBeFalse();
    }

    // Un admin desactivado no puede nada (Gate::before).
    $this->actors['admin']->forceFill(['is_active' => false])->save();
    expect($can('admin', 'create', ProjectTemplate::class))->toBeFalse();
});
