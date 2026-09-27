<?php

use App\Enums\PortalEntryVisibility;
use App\Enums\TimeEntryStatus;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\TaskDependency;
use App\Models\TaskStatus;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Proyectos en el portal (SPEC §11, D-064): la vista del proyecto (tareas y estados) y el Gantt de
| solo lectura, solo si el equipo los abre. Sin comentarios, adjuntos, personas por tarea ni datos
| económicos; las horas por tarea, solo si se enseñan y solo las que ve el cliente. Aislamiento: un
| proyecto de otro cliente, uno sin abrir o un Gantt sin abrir dan 404.
*/

beforeEach(function () {
    TaskStatus::ensureDefaults();
    $this->client = Client::factory()->create();
    $this->user = User::factory()->portalOf($this->client)->create();
    $this->worker = userWithRole('employee', ['name' => 'Elena Empleada']);

    $this->project = Project::factory()->create([
        'client_id' => $this->client->id,
        'name' => 'Web corporativa',
        'code' => 'LUR-WEB',
        'hourly_rate' => '75.00',
        'fixed_price_amount' => '9000.00',
        'start_date' => '2026-09-01',
        'due_date' => '2026-12-18',
        'portal_project_visible' => true,
        'portal_gantt_visible' => true,
    ]);
    $this->closed = Project::factory()->create(['client_id' => $this->client->id, 'name' => 'Campañas']);
    $this->foreign = Project::factory()->create(['portal_project_visible' => true, 'portal_gantt_visible' => true]);

    $this->design = Task::factory()->assignedTo($this->worker)->create([
        'project_id' => $this->project->id,
        'title' => 'Diseño de la home',
        'description' => 'Nota interna: el cliente paga tarde',
        'estimated_minutes' => 600,
        'start_date' => '2026-09-07',
        'due_date' => '2026-09-18',
        'position' => 1,
    ]);
    $this->subtask = Task::factory()->subtaskOf($this->design)->assignedTo($this->worker)->create(['title' => 'Maqueta móvil', 'position' => 2]);
    $this->launch = Task::factory()->milestone()->create(['project_id' => $this->project->id, 'title' => 'Lanzamiento', 'due_date' => '2026-10-30', 'position' => 3]);
    $this->done = Task::factory()->completed()->create(['project_id' => $this->project->id, 'title' => 'Briefing', 'position' => 0, 'completed_at' => '2026-09-05 10:00:00']);
    TaskDependency::query()->create(['predecessor_task_id' => $this->design->id, 'successor_task_id' => $this->launch->id]);
    TaskComment::factory()->create(['task_id' => $this->design->id, 'body' => '<p>Comentario interno secreto</p>']);

    $this->entry = fn (Task $task, int $minutes, TimeEntryStatus $status) => TimeEntry::factory()
        ->forTask($task)
        ->create(['user_id' => $this->worker->id, 'minutes' => $minutes, 'status' => $status, 'date' => '2026-09-10', 'description' => 'Horas de Elena']);
});

test('el cliente ve las tareas y estados de un proyecto abierto, sin comentarios, personas ni importes', function () {
    $response = $this->actingAs($this->user)
        ->get("/portal/proyectos/{$this->project->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('portal/projects/show')
            ->where('project', [
                'id' => $this->project->id,
                'code' => 'LUR-WEB',
                'name' => 'Web corporativa',
                'status' => 'active',
                'start_date' => '2026-09-01',
                'due_date' => '2026-12-18',
            ])
            ->has('tasks', 4)
            ->where('tasks.0.title', 'Briefing')
            ->where('tasks.0.is_completed', true)
            ->where('tasks.1.title', 'Diseño de la home')
            ->where('tasks.1.subtasks_count', 1)
            ->where('tasks.1.due_date', '2026-09-18')
            ->where('tasks.2.title', 'Maqueta móvil')
            ->where('tasks.2.depth', 1)
            ->where('tasks.2.parent_task_id', $this->design->id)
            ->where('tasks.3.title', 'Lanzamiento')
            ->where('tasks.3.is_milestone', true)
            ->where('tasks.1.status.name', TaskStatus::defaultStatus()->name)
            ->missing('tasks.1.minutes')
            ->missing('tasks.1.assignee')
            ->missing('tasks.1.description')
            ->where('showHours', false)
            ->where('totals.tasks', 4)
            ->where('totals.done', 1)
            ->where('totals.milestones', 1)
            ->where('totals.minutes', null)
            ->where('gantt', true));

    $content = (string) $response->getContent();
    expect($content)->not->toContain('Elena')
        ->not->toContain('Nota interna')
        ->not->toContain('Comentario interno')
        ->not->toContain('75.00')
        ->not->toContain('9000')
        ->not->toContain('hourly_rate')
        ->not->toContain('fixed_price');
});

test('las horas por tarea solo si se enseñan, y solo las aprobadas y bloqueadas (o también las enviadas)', function () {
    ($this->entry)($this->design, 120, TimeEntryStatus::Approved);
    ($this->entry)($this->design, 60, TimeEntryStatus::Locked);
    ($this->entry)($this->design, 45, TimeEntryStatus::Submitted);
    ($this->entry)($this->design, 500, TimeEntryStatus::Draft);
    ($this->entry)($this->subtask, 30, TimeEntryStatus::Approved);
    ($this->entry)($this->launch, 15, TimeEntryStatus::Submitted);

    $this->actingAs($this->user)
        ->get("/portal/proyectos/{$this->project->id}")
        ->assertInertia(fn (Assert $page) => $page->where('showHours', false)->missing('tasks.1.minutes'));

    $this->project->update(['portal_show_task_hours' => true]);

    // La tarea con subtareas suma las suyas y las de sus subtareas; el total no cuenta dos veces.
    $this->actingAs($this->user)
        ->get("/portal/proyectos/{$this->project->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('showHours', true)
            ->where('tasks.0.minutes', 0)
            ->where('tasks.1.minutes', 210)
            ->where('tasks.2.minutes', 30)
            ->where('tasks.3.minutes', 0)
            ->where('totals.minutes', 210));

    $this->client->update(['portal_entry_visibility' => PortalEntryVisibility::Submitted]);

    $this->actingAs($this->user)
        ->get("/portal/proyectos/{$this->project->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('tasks.1.minutes', 255)
            ->where('tasks.3.minutes', 15)
            ->where('totals.minutes', 270));
});

test('el Gantt de solo lectura lleva tareas, hitos y dependencias, sin responsables, horas ni edición', function () {
    ($this->entry)($this->design, 120, TimeEntryStatus::Approved);

    $response = $this->actingAs($this->user)
        ->get("/portal/proyectos/{$this->project->id}/gantt")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('portal/projects/gantt')
            ->has('tasks', 4)
            ->where('tasks.1.title', 'Diseño de la home')
            ->where('tasks.1.assignee', null)
            ->where('tasks.1.estimated_minutes', null)
            ->where('tasks.1.logged_minutes', 0)
            ->where('tasks.1.can', ['update' => false])
            ->where('tasks.3.is_milestone', true)
            ->has('dependencies', 1)
            ->where('dependencies.0.predecessor_task_id', $this->design->id)
            ->where('dependencies.0.successor_task_id', $this->launch->id)
            ->where('range.start', '2026-08-25')
            ->where('preferences', ['scale' => 'week', 'color' => 'status'])
            ->where('view', true)
            ->has('statuses'));

    expect((string) $response->getContent())->not->toContain('Elena')->not->toContain('Nota interna');

    // Escala en la URL; los colores siempre por estado.
    $this->actingAs($this->user)
        ->get("/portal/proyectos/{$this->project->id}/gantt?escala=mes&color=responsable")
        ->assertInertia(fn (Assert $page) => $page->where('preferences', ['scale' => 'month', 'color' => 'status']));
});

test('el Gantt se abre aparte: con la vista cerrada sigue abierto, y cerrado da 404 aunque la vista esté abierta', function () {
    $this->project->update(['portal_project_visible' => false]);

    $this->actingAs($this->user)->get("/portal/proyectos/{$this->project->id}")->assertNotFound();
    $this->actingAs($this->user)
        ->get("/portal/proyectos/{$this->project->id}/gantt")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('view', false));

    $this->project->update(['portal_project_visible' => true, 'portal_gantt_visible' => false]);

    $this->actingAs($this->user)
        ->get("/portal/proyectos/{$this->project->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('gantt', false));
    $this->actingAs($this->user)->get("/portal/proyectos/{$this->project->id}/gantt")->assertNotFound();
});

test('aislamiento: proyectos sin abrir, de otro cliente, archivados en la papelera o inexistentes dan 404', function () {
    $trashed = Project::factory()->create(['client_id' => $this->client->id, 'portal_project_visible' => true, 'portal_gantt_visible' => true]);
    $trashed->delete();

    foreach ([$this->closed->id, $this->foreign->id, $trashed->id, 999999] as $id) {
        $this->actingAs($this->user)->get("/portal/proyectos/{$id}")->assertNotFound();
        $this->actingAs($this->user)->get("/portal/proyectos/{$id}/gantt")->assertNotFound();
    }
});

test('la lista de proyectos del portal enseña solo los abiertos de su cliente, con su avance', function () {
    $ganttOnly = Project::factory()->create(['client_id' => $this->client->id, 'name' => 'Aaa Intranet', 'portal_gantt_visible' => true]);

    $this->actingAs($this->user)
        ->get('/portal/proyectos')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('portal/projects/index')
            ->has('projects', 2)
            ->where('projects.0.id', $ganttOnly->id)
            ->where('projects.0.view', false)
            ->where('projects.0.gantt', true)
            ->where('projects.0.progress', null)
            ->where('projects.1.id', $this->project->id)
            ->where('projects.1.progress', ['done' => 1, 'total' => 4])
            ->where('portal.projects.1.id', $this->project->id)
            ->where('portal.company.name', 'Audax Studio')
            ->where('portal.company.logo', null));
});

test('un cliente desactivado recibe 403 y un usuario revocado vuelve al login', function () {
    $this->client->update(['is_active' => false]);

    $this->actingAs($this->user)->get("/portal/proyectos/{$this->project->id}")->assertForbidden();
    $this->actingAs($this->user)->get('/portal/proyectos')->assertForbidden();

    $this->client->update(['is_active' => true]);
    $this->user->update(['is_active' => false]);

    $this->actingAs($this->user->fresh())->get("/portal/proyectos/{$this->project->id}")->assertRedirect(route('login'));
});

test('los internos no entran en las rutas del portal y un invitado va al login', function () {
    $this->get("/portal/proyectos/{$this->project->id}")->assertRedirect(route('login'));
    $this->get('/portal/proyectos')->assertRedirect(route('login'));

    $this->actingAs(userWithRole('admin'))->get("/portal/proyectos/{$this->project->id}")->assertForbidden();
    $this->actingAs(userWithRole('employee'))->get("/portal/proyectos/{$this->project->id}/gantt")->assertForbidden();
});

test('las páginas del portal no crecen en consultas con el número de tareas (N+1)', function () {
    $count = function (string $url): int {
        $queries = 0;
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries++;
        });

        $this->actingAs($this->user->fresh())->get($url)->assertOk();

        return $queries;
    };

    $this->project->update(['portal_show_task_hours' => true]);
    // Primero calienta las cachés (roles, ajustes, estados) para medir solo las consultas de la página.
    $count("/portal/proyectos/{$this->project->id}");
    $count("/portal/proyectos/{$this->project->id}/gantt");
    $showBefore = $count("/portal/proyectos/{$this->project->id}");
    $ganttBefore = $count("/portal/proyectos/{$this->project->id}/gantt");

    Task::factory()->count(12)->create(['project_id' => $this->project->id, 'start_date' => '2026-09-10', 'due_date' => '2026-09-20']);
    Task::factory()->count(3)->subtaskOf($this->launch)->create();

    expect($count("/portal/proyectos/{$this->project->id}"))->toBe($showBefore)
        ->and($count("/portal/proyectos/{$this->project->id}/gantt"))->toBe($ganttBefore);
});
