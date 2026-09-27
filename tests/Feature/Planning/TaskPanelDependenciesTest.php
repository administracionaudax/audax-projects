<?php

use App\Domain\Planning\TaskDependencyList;
use App\Http\Controllers\Planning\DependencyCandidatesController;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskDependency;
use App\Models\TaskStatus;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Sección «Dependencias» del panel de la tarea (D-056, D-062): predecesoras («Depende de») y
| sucesoras («Bloquea a») con la marca de conflicto de D-057, sin tareas en la papelera, y el
| buscador de tareas candidatas para «Añadir».
*/

beforeEach(function () {
    TaskStatus::ensureDefaults();

    $this->member = userWithRole('employee');
    $this->project = Project::factory()->create();
    $this->project->addMember($this->member);
    $this->task = fn (string $title, ?string $start = null, ?string $due = null, array $attributes = []): Task => Task::factory()->create([
        'project_id' => $this->project->id, 'title' => $title, 'start_date' => $start, 'due_date' => $due, ...$attributes,
    ]);
    $this->link = fn (Task $predecessor, Task $successor): TaskDependency => TaskDependency::query()->create([
        'predecessor_task_id' => $predecessor->id, 'successor_task_id' => $successor->id,
    ]);
    $this->panel = fn (Task $task) => $this->actingAs($this->member)
        ->get("/proyectos/{$this->project->id}/tareas?tarea={$task->id}");
});

it('el panel trae de qué tareas depende y a cuáles bloquea, con su dependencia y el conflicto de fechas', function () {
    $design = ($this->task)('Diseño', '2026-10-01', '2026-10-09');
    $copy = ($this->task)('Textos', null, '2026-10-12');
    $build = ($this->task)('Maquetación', '2026-10-09', '2026-10-20');
    $review = ($this->task)('Revisión', '2026-10-21', '2026-10-22');
    $launch = ($this->task)('Publicación', null, '2026-10-20', ['is_milestone' => true]);
    $designToBuild = ($this->link)($design, $build);
    $copyToBuild = ($this->link)($copy, $build);
    ($this->link)($build, $review);
    ($this->link)($build, $launch);

    ($this->panel)($build)->assertInertia(fn (Assert $page) => $page
        ->has('panel.dependencies.predecessors', 2)
        // Diseño acaba el 09/10 y Maquetación empieza ese mismo día: conflicto.
        ->where('panel.dependencies.predecessors.0.dependency_id', $designToBuild->id)
        ->where('panel.dependencies.predecessors.0.task.title', 'Diseño')
        ->where('panel.dependencies.predecessors.0.conflict', true)
        // Textos vence el 12/10, después de que empiece Maquetación: conflicto.
        ->where('panel.dependencies.predecessors.1.dependency_id', $copyToBuild->id)
        ->where('panel.dependencies.predecessors.1.task.title', 'Textos')
        ->where('panel.dependencies.predecessors.1.conflict', true)
        ->has('panel.dependencies.successors', 2)
        // El hito vence el mismo día que acaba Maquetación: conflicto; Revisión empieza después: no.
        ->where('panel.dependencies.successors.0.task.title', 'Publicación')
        ->where('panel.dependencies.successors.0.task.is_milestone', true)
        ->where('panel.dependencies.successors.0.conflict', true)
        ->where('panel.dependencies.successors.1.task.title', 'Revisión')
        ->where('panel.dependencies.successors.1.task.start_date', '2026-10-21')
        ->where('panel.dependencies.successors.1.conflict', false));

    ($this->panel)($review)->assertInertia(fn (Assert $page) => $page
        ->has('panel.dependencies.predecessors', 1)
        ->where('panel.dependencies.predecessors.0.task.id', $build->id)
        ->has('panel.dependencies.successors', 0));
});

it('sin fechas no hay conflicto', function (?string $predecessorDue, ?string $successorStart, ?string $successorDue, bool $conflict) {
    $predecessor = new Task(['due_date' => $predecessorDue]);
    $successor = new Task(['start_date' => $successorStart, 'due_date' => $successorDue]);

    expect(TaskDependencyList::conflict($predecessor, $successor))->toBe($conflict);
})->with([
    'la predecesora no tiene entrega' => [null, '2026-10-05', '2026-10-06', false],
    'la sucesora no tiene fechas' => ['2026-10-05', null, null, false],
    'sin inicio, cuenta la entrega de la sucesora' => ['2026-10-05', null, '2026-10-05', true],
    'empieza el día siguiente' => ['2026-10-05', '2026-10-06', '2026-10-08', false],
    'empieza antes' => ['2026-10-05', '2026-10-01', '2026-10-08', true],
]);

it('las tareas en la papelera no salen en las dependencias del panel', function () {
    $a = ($this->task)('A', null, '2026-10-05');
    $b = ($this->task)('B', null, '2026-10-08');
    $c = ($this->task)('C', null, '2026-10-12');
    ($this->link)($a, $b);
    ($this->link)($b, $c);
    $c->delete();

    ($this->panel)($b)->assertInertia(fn (Assert $page) => $page
        ->has('panel.dependencies.predecessors', 1)
        ->where('panel.dependencies.predecessors.0.task.title', 'A')
        ->has('panel.dependencies.successors', 0));
});

it('quien no puede editar ve las dependencias en solo lectura', function () {
    $a = ($this->task)('A', null, '2026-10-05');
    $b = ($this->task)('B', null, '2026-10-08');
    ($this->link)($a, $b);

    $this->actingAs(userWithRole('employee'))
        ->get("/proyectos/{$this->project->id}/tareas?tarea={$b->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('panel.can.update', false)
            ->has('panel.dependencies.predecessors', 1));
});

it('el panel de dependencias es una sola consulta, aunque haya muchas', function () {
    $center = ($this->task)('Centro', '2026-10-10', '2026-10-12');
    $list = app(TaskDependencyList::class);

    $count = function () use ($center, $list): int {
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });
        $list->for($center);
        app('events')->forget(QueryExecuted::class);

        return $queries;
    };

    foreach (range(1, 5) as $i) {
        ($this->link)(($this->task)("Antes {$i}", null, '2026-10-0'.$i), $center);
        ($this->link)($center, ($this->task)("Después {$i}", null, '2026-10-2'.$i));
    }

    expect($count())->toBe(1)
        ->and($list->for($center)['predecessors'])->toHaveCount(5)
        ->and($list->for($center)['successors'])->toHaveCount(5);
});

describe('buscador de tareas candidatas', function () {
    beforeEach(function () {
        $this->url = fn (Task $task, string $query = ''): string => "/tareas/{$task->id}/dependencias/candidatas".($query === '' ? '' : '?buscar='.urlencode($query));
    });

    it('devuelve las tareas del proyecto menos ella misma, las de la papelera y las de otros proyectos; primero las abiertas por entrega', function () {
        $done = TaskStatus::query()->where('category', 'done')->firstOrFail();
        $self = ($this->task)('Maquetación', null, '2026-10-10');
        $parent = ($this->task)('Diseño', null, '2026-10-20');
        Task::factory()->subtaskOf($parent)->create(['title' => 'Bocetos', 'due_date' => '2026-10-05']);
        ($this->task)('Sin fecha');
        ($this->task)('Hecha', null, '2026-10-01', ['status_id' => $done->id]);
        ($this->task)('Borrada', null, '2026-10-02')->delete();
        Task::factory()->create(['title' => 'De otro proyecto']);

        $this->actingAs($this->member)
            ->getJson(($this->url)($self))
            ->assertOk()
            ->assertJsonPath('tasks.*.title', ['Bocetos', 'Diseño', 'Sin fecha', 'Hecha'])
            ->assertJsonPath('tasks.0.parent_title', 'Diseño')
            ->assertJsonPath('tasks.0.due_date', '2026-10-05')
            ->assertJsonPath('tasks.3.is_completed', true);
    });

    it('busca por título sin distinguir mayúsculas y como mucho devuelve 30', function () {
        $self = ($this->task)('Maquetación');
        ($this->task)('Diseño de la HOME');
        ($this->task)('Textos');
        Task::factory()->count(DependencyCandidatesController::LIMIT + 5)->create(['project_id' => $this->project->id]);

        $this->actingAs($this->member)->getJson(($this->url)($self, 'home'))
            ->assertJsonPath('tasks.*.title', ['Diseño de la HOME']);
        $this->actingAs($this->member)->getJson(($this->url)($self))
            ->assertJsonCount(DependencyCandidatesController::LIMIT, 'tasks');
        $this->actingAs($this->member)->getJson(($this->url)($self, str_repeat('x', 101)))
            ->assertUnprocessable();
    });

    it('solo quien puede editar la tarea; clientes al portal e invitados al login', function (string $actor, int|string $expected) {
        $self = ($this->task)('Maquetación');
        ($this->task)('Diseño');
        $manager = userWithRole('employee');
        $this->project->addMember($manager, isManager: true);
        $users = [
            'admin' => userWithRole('admin'),
            'responsable' => userWithRole('department_manager'),
            'gestor' => $manager,
            'miembro' => $this->member,
            'no miembro' => userWithRole('employee'),
            'cliente' => userWithRole('client'),
        ];

        if ($actor !== 'invitado') {
            $this->actingAs($users[$actor]);
        }

        $response = $this->get(($this->url)($self));

        match ($expected) {
            'login' => $response->assertRedirect(route('login')),
            'portal' => $response->assertRedirect(route('portal.home')),
            default => $response->assertStatus($expected),
        };
    })->with([
        'admin' => ['admin', 200],
        'responsable' => ['responsable', 200],
        'gestor del proyecto' => ['gestor', 200],
        'miembro' => ['miembro', 200],
        'empleado no miembro' => ['no miembro', 403],
        'cliente' => ['cliente', 'portal'],
        'invitado' => ['invitado', 'login'],
    ]);

    it('una tarea en la papelera no tiene candidatas', function () {
        $self = ($this->task)('Maquetación');
        $self->delete();

        $this->actingAs($this->member)->getJson(($this->url)($self))->assertNotFound();
    });
});
