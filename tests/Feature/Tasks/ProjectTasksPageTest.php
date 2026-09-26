<?php

use App\Enums\TaskPriority;
use App\Models\Attachment;
use App\Models\Department;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\TaskStatus;
use App\Models\TaskType;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Pestaña Tareas del proyecto (SPEC §6): lista y kanban, filtros, subtareas, panel con recarga
| parcial y sin consultas N+1.
*/

beforeEach(function () {
    TaskStatus::ensureDefaults();
    Storage::fake('local');

    $this->user = userWithRole('employee');
    $this->project = Project::factory()->create();
    $this->project->addMember($this->user);
    $this->url = "/proyectos/{$this->project->id}/tareas";
    $this->todo = TaskStatus::defaultStatus();
    $this->done = TaskStatus::query()->where('category', 'done')->firstOrFail();
});

it('pinta la lista con las tareas raíz, sus subtareas, las horas imputadas y sin la descripción', function () {
    $parent = Task::factory()->create(['project_id' => $this->project->id, 'title' => 'Padre', 'description' => '<p>Largo</p>', 'estimated_minutes' => 600]);
    $child = Task::factory()->subtaskOf($parent)->create(['title' => 'Hija', 'estimated_minutes' => 90]);
    Task::factory()->subtaskOf($parent)->create(['title' => 'Hija sin estimación']);
    TimeEntry::factory()->forTask($parent)->minutes(30)->create();
    TimeEntry::factory()->forTask($child)->minutes(45)->create();

    $this->actingAs($this->user)
        ->get($this->url)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/tasks')
            ->where('view', 'list')
            ->where('project.id', $this->project->id)
            ->where('can.create', true)
            ->where('can.update', true)
            ->has('tasks', 1)
            ->where('tasks.0.title', 'Padre')
            ->missing('tasks.0.description')
            ->where('tasks.0.logged_minutes', 30)
            ->where('tasks.0.subtasks_count', 2)
            ->where('tasks.0.estimate_from_subtasks', true)
            ->where('tasks.0.effective_estimated_minutes', 90)
            ->has('tasks.0.subtasks', 2)
            ->where('tasks.0.subtasks.0.title', 'Hija')
            ->where('tasks.0.subtasks.0.logged_minutes', 45)
            ->has('statuses', 5)
            ->where('panel', null)
            ->missing('moveTargets'));
});

it('oculta las completadas salvo que se pidan', function () {
    Task::factory()->create(['project_id' => $this->project->id, 'title' => 'Abierta']);
    Task::factory()->completed()->create(['project_id' => $this->project->id, 'title' => 'Hecha']);

    $this->actingAs($this->user)->get($this->url)
        ->assertInertia(fn (Assert $page) => $page
            ->has('tasks', 1)
            ->where('tasks.0.title', 'Abierta')
            ->where('hiddenCompletedCount', 1)
            ->where('filters.completed', false));

    $this->actingAs($this->user)->get($this->url.'?completadas=1')
        ->assertInertia(fn (Assert $page) => $page
            ->has('tasks', 2)
            ->where('hiddenCompletedCount', 0)
            ->where('filters.completed', true));
});

it('filtra por responsable, «mis tareas», bolsa, tipo, prioridad y estado', function () {
    $other = User::factory()->employee()->create();
    $type = TaskType::factory()->create();
    $inProgress = TaskStatus::query()->where('category', 'in_progress')->orderBy('position')->firstOrFail();

    Task::factory()->assignedTo($this->user)->create(['project_id' => $this->project->id, 'title' => 'Mía']);
    Task::factory()->assignedTo($other)->create(['project_id' => $this->project->id, 'title' => 'De otra', 'task_type_id' => $type->id]);
    Task::factory()->create(['project_id' => $this->project->id, 'title' => 'Sin nadie', 'priority' => TaskPriority::Urgent, 'status_id' => $inProgress->id]);

    $titles = fn (string $query): array => $this->actingAs($this->user)->get($this->url.$query)
        ->viewData('page')['props']['tasks'];

    expect(array_column($titles('?mias=1'), 'title'))->toBe(['Mía'])
        ->and(array_column($titles("?responsable={$other->id}"), 'title'))->toBe(['De otra'])
        ->and(array_column($titles('?responsable=ninguno'), 'title'))->toBe(['Sin nadie'])
        ->and(array_column($titles("?tipo={$type->id}"), 'title'))->toBe(['De otra'])
        ->and(array_column($titles('?prioridad=urgent'), 'title'))->toBe(['Sin nadie'])
        ->and(array_column($titles("?estado={$inProgress->id}"), 'title'))->toBe(['Sin nadie']);
});

it('una tarea raíz aparece si alguna de sus subtareas cumple el filtro', function () {
    $parent = Task::factory()->create(['project_id' => $this->project->id, 'title' => 'Padre ajeno']);
    Task::factory()->subtaskOf($parent)->assignedTo($this->user)->create(['title' => 'Mi subtarea']);

    $this->actingAs($this->user)->get($this->url.'?mias=1')
        ->assertInertia(fn (Assert $page) => $page
            ->has('tasks', 1)
            ->where('tasks.0.title', 'Padre ajeno')
            ->where('tasks.0.subtasks.0.title', 'Mi subtarea'));
});

it('filtra por bolsa en un proyecto de bolsas y ordena primero las bolsas abiertas del departamento del usuario', function () {
    $department = Department::factory()->create();
    $this->user->update(['department_id' => $department->id]);
    $project = Project::factory()->hourBank()->create();
    $project->addMember($this->user);
    $closed = HourBank::factory()->closed()->create(['project_id' => $project->id, 'name' => 'Cerrada']);
    $other = HourBank::factory()->create(['project_id' => $project->id, 'name' => 'General']);
    $mine = HourBank::factory()->forDepartment($department)->create(['project_id' => $project->id, 'name' => 'De mi departamento']);
    Task::factory()->inBank($other)->create(['title' => 'En general']);
    Task::factory()->inBank($mine)->create(['title' => 'En la mía']);

    $this->actingAs($this->user)->get("/proyectos/{$project->id}/tareas?bolsa={$mine->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->has('tasks', 1)
            ->where('tasks.0.title', 'En la mía')
            ->where('banks.0.id', $mine->id)
            ->where('banks.1.id', $other->id)
            ->where('banks.2.id', $closed->id)
            ->where('banks.2.is_open', false)
            ->where('currentUser.department_id', $department->id));
});

it('la vista kanban se elige con ?vista=kanban y ordena por posición', function () {
    Task::factory()->create(['project_id' => $this->project->id, 'title' => 'Segunda', 'position' => 1]);
    Task::factory()->create(['project_id' => $this->project->id, 'title' => 'Primera', 'position' => 0]);

    $this->actingAs($this->user)->get($this->url.'?vista=kanban')
        ->assertInertia(fn (Assert $page) => $page
            ->where('view', 'kanban')
            ->where('tasks.0.title', 'Primera')
            ->where('tasks.1.title', 'Segunda'));
});

it('lista a las personas internas activas, primero los miembros', function () {
    $outsider = User::factory()->employee()->create(['name' => 'Aaron Externo']);
    User::factory()->employee()->inactive()->create(['name' => 'Baja']);
    User::factory()->client()->create(['name' => 'Cliente']);

    $users = $this->actingAs($this->user)->get($this->url)->viewData('page')['props']['users'];
    $names = array_column($users, 'name');

    expect($names)->toContain($this->user->name, $outsider->name)
        ->not->toContain('Baja')
        ->not->toContain('Cliente')
        ->and($users[0]['is_member'])->toBeTrue()
        ->and(collect($users)->firstWhere('id', $outsider->id)['is_member'])->toBeFalse()
        ->and(array_search($outsider->name, $names, true))->toBeGreaterThan(array_search($this->user->name, $names, true));
});

it('abre el panel con ?tarea= y lo recarga de forma parcial sin la lista', function () {
    $task = Task::factory()->assignedTo($this->user)->create([
        'project_id' => $this->project->id,
        'title' => 'Con panel',
        'description' => '<p>Detalle</p>',
        'created_by' => $this->user->id,
    ]);
    $task->watchers()->attach($this->user->id);

    $this->actingAs($this->user)
        ->get($this->url."?tarea={$task->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('panel.task.id', $task->id)
            ->where('panel.task.description', '<p>Detalle</p>')
            ->where('panel.is_watching', true)
            ->where('panel.can.update', true)
            ->where('panel.can.delete', true)
            ->where('panel.can.comment', true)
            ->where('panel.can.move', true)
            ->where('panel.delete_blocked', null)
            ->has('panel.activity', 1)
            ->where('panel.activity.0.event', 'created')
            ->reloadOnly('panel', fn (Assert $reload) => $reload
                ->where('panel.task.title', 'Con panel')
                ->missing('tasks')
                ->missing('users')));
});

it('no abre el panel de una tarea de otro proyecto ni de una borrada', function () {
    $foreign = Task::factory()->create();
    $deleted = Task::factory()->create(['project_id' => $this->project->id]);
    $deleted->delete();

    foreach ([$foreign->id, $deleted->id, 999999] as $id) {
        $this->actingAs($this->user)->get($this->url."?tarea={$id}")
            ->assertInertia(fn (Assert $page) => $page->where('panel', null));
    }
});

it('el panel muestra solo las horas que el usuario puede ver (D-021)', function () {
    $task = Task::factory()->create(['project_id' => $this->project->id]);
    $colleague = User::factory()->employee()->create();
    TimeEntry::factory()->forTask($task)->minutes(60)->create(['user_id' => $this->user->id]);
    TimeEntry::factory()->forTask($task)->minutes(120)->create(['user_id' => $colleague->id]);

    $this->actingAs($this->user)->get($this->url."?tarea={$task->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->has('panel.time_entries', 1)
            ->where('panel.time_entries.0.user_id', $this->user->id)
            ->where('panel.time_visible_minutes', 60)
            ->where('panel.task.logged_minutes', 180)
            ->where('panel.has_time', true)
            ->where('panel.can.delete', false)
            ->where('panel.delete_blocked', 'has_time'));

    $this->actingAs(userWithRole('admin'))->get($this->url."?tarea={$task->id}")
        ->assertInertia(fn (Assert $page) => $page->has('panel.time_entries', 2));
});

it('envía los proyectos destino solo cuando se piden (mover)', function () {
    $target = Project::factory()->hourBank()->create(['name' => 'Destino']);
    $target->addMember($this->user);
    HourBank::factory()->create(['project_id' => $target->id]);
    HourBank::factory()->closed()->create(['project_id' => $target->id]);
    Project::factory()->create(['name' => 'Ajeno']);
    Project::factory()->archived()->create()->addMember($this->user);

    $this->actingAs($this->user)->get($this->url)
        ->assertInertia(fn (Assert $page) => $page
            ->missing('moveTargets')
            ->reloadOnly('moveTargets', fn (Assert $reload) => $reload
                ->has('moveTargets', 1)
                ->where('moveTargets.0.name', 'Destino')
                ->where('moveTargets.0.uses_hour_banks', true)
                ->has('moveTargets.0.banks', 1)));
});

it('no hace consultas N+1: el número de consultas no crece con las tareas', function () {
    $assignees = User::factory()->employee()->count(3)->create();

    $seed = function (int $count) use ($assignees): void {
        foreach (range(1, $count) as $i) {
            $task = Task::factory()->assignedTo($assignees[$i % 3])->create(['project_id' => $this->project->id]);
            $sub = Task::factory()->subtaskOf($task)->assignedTo($assignees[($i + 1) % 3])->create();
            TimeEntry::factory()->forTask($sub)->create();
            TaskComment::factory()->create(['task_id' => $task->id]);
            Attachment::factory()->create(['attachable_id' => $task->id, 'project_id' => $this->project->id]);
        }
    };

    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->user)->get($this->url.'?completadas=1')->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    // La primera petición calienta las cachés de roles, permisos y ajustes: no cuenta.
    $count();
    $seed(2);
    $few = $count();
    $seed(8);
    $many = $count();

    expect($many)->toBe($few);
});

it('el panel con varios comentarios, reacciones y adjuntos no hace N+1', function () {
    $task = Task::factory()->create(['project_id' => $this->project->id]);

    $count = function () use ($task): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->user)->get($this->url."?tarea={$task->id}")->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $seed = function (int $count) use ($task): void {
        foreach (range(1, $count) as $i) {
            $author = User::factory()->employee()->create();
            $comment = TaskComment::factory()->create(['task_id' => $task->id, 'user_id' => $author->id]);
            $comment->reactions()->create(['user_id' => $author->id, 'emoji' => '👍']);
            Attachment::factory()->create(['attachable_type' => TaskComment::class, 'attachable_id' => $comment->id, 'project_id' => $this->project->id]);
            Attachment::factory()->create(['attachable_id' => $task->id, 'project_id' => $this->project->id]);
            Task::factory()->subtaskOf($task)->assignedTo($author)->create();
        }
    };

    $count();
    $seed(2);
    $few = $count();
    $seed(6);

    expect($count())->toBe($few);
});

it('ignora parámetros de la URL mal formados (arrays o valores desconocidos)', function () {
    Task::factory()->create(['project_id' => $this->project->id]);

    $this->actingAs($this->user)
        ->get($this->url.'?prioridad[]=urgent&agrupar[]=tipo&responsable[]=1&estado=abc&vista=otra&tarea[]=1')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('view', 'list')
            ->where('filters.priority', null)
            ->where('filters.group', 'status')
            ->where('filters.assignee', null)
            ->where('filters.status', null)
            ->where('panel', null)
            ->has('tasks', 1));
});
