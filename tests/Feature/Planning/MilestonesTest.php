<?php

use App\Domain\Planning\UpcomingMilestones;
use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Hitos (SPEC §5.1 y §6, D-062): la casilla «Hito» del panel (reglas de TaskWriter), «Próximos
| hitos» del resumen del proyecto y «Mis próximos hitos» de Inicio. "Hoy" es el martes 13/10/2026.
*/

beforeEach(function () {
    TaskStatus::ensureDefaults();
    $this->travelTo(CarbonImmutable::parse('2026-10-13 10:00:00', 'Europe/Madrid'));

    $this->user = userWithRole('employee', ['created_at' => '2026-01-01 08:00:00']);
    $this->project = Project::factory()->create(['code' => 'ACME-WEB', 'name' => 'Web de Acme']);
    $this->project->addMember($this->user);
    $this->milestone = fn (string $title, ?string $due, array $attributes = [], ?Project $project = null): Task => Task::factory()->milestone()->create([
        'project_id' => ($project ?? $this->project)->id, 'title' => $title, 'due_date' => $due, ...$attributes,
    ]);
    $this->done = TaskStatus::query()->where('category', 'done')->firstOrFail();
});

describe('casilla «Hito» del panel', function () {
    beforeEach(function () {
        $this->update = fn (Task $task, array $data) => $this->actingAs($this->user)
            ->from("/proyectos/{$task->project_id}/tareas?tarea={$task->id}")
            ->patch("/tareas/{$task->id}", $data);
    });

    it('al marcarla, la tarea pierde la estimación y el inicio: su única fecha es la entrega', function () {
        $task = Task::factory()->create([
            'project_id' => $this->project->id, 'start_date' => '2026-10-05', 'due_date' => '2026-10-09', 'estimated_minutes' => 240,
        ]);

        ($this->update)($task, ['is_milestone' => true])->assertSessionHasNoErrors();

        $task->refresh();
        expect($task->is_milestone)->toBeTrue()
            ->and($task->estimated_minutes)->toBeNull()
            ->and($task->start_date)->toBeNull()
            ->and($task->due_date->toDateString())->toBe('2026-10-09');
    });

    it('si solo tenía inicio, esa fecha pasa a ser la entrega', function () {
        $task = Task::factory()->create(['project_id' => $this->project->id, 'start_date' => '2026-10-05']);

        ($this->update)($task, ['is_milestone' => true])->assertSessionHasNoErrors();

        expect($task->fresh()->start_date)->toBeNull()
            ->and($task->fresh()->due_date->toDateString())->toBe('2026-10-05');
    });

    it('un hito no recupera el inicio ni la estimación al editarlo (también al reprogramarlo)', function () {
        $milestone = ($this->milestone)('Entrega', '2026-10-09');

        ($this->update)($milestone, ['start_date' => '2026-10-01', 'estimated_minutes' => 60])->assertSessionHasNoErrors();
        expect($milestone->fresh()->start_date)->toBeNull()
            ->and($milestone->fresh()->estimated_minutes)->toBeNull();

        $this->actingAs($this->user)
            ->post("/tareas/{$milestone->id}/reprogramar", ['start_date' => '2026-10-10', 'due_date' => '2026-10-12'])
            ->assertSessionHasNoErrors();
        expect($milestone->fresh()->start_date)->toBeNull()
            ->and($milestone->fresh()->due_date->toDateString())->toBe('2026-10-12');
    });

    it('al desmarcarla, vuelve a admitir inicio y estimación', function () {
        $milestone = ($this->milestone)('Entrega', '2026-10-09');

        ($this->update)($milestone, ['is_milestone' => false, 'start_date' => '2026-10-05', 'estimated_minutes' => 60])->assertSessionHasNoErrors();

        expect($milestone->fresh()->is_milestone)->toBeFalse()
            ->and($milestone->fresh()->start_date->toDateString())->toBe('2026-10-05')
            ->and($milestone->fresh()->estimated_minutes)->toBe(60);
    });

    it('una tarea con horas no puede ser hito', function () {
        $task = Task::factory()->create(['project_id' => $this->project->id, 'start_date' => '2026-10-05', 'due_date' => '2026-10-09']);
        TimeEntry::factory()->forTask($task)->create();

        ($this->update)($task, ['is_milestone' => true])
            ->assertSessionHasErrors(['is_milestone' => __('tasks.errors.milestone_with_time')]);

        expect($task->fresh()->is_milestone)->toBeFalse()
            ->and($task->fresh()->start_date->toDateString())->toBe('2026-10-05');
    });

    it('un hito nuevo se crea sin inicio', function () {
        $this->actingAs($this->user)
            ->post("/proyectos/{$this->project->id}/tareas", [
                'title' => 'Lanzamiento', 'is_milestone' => true, 'start_date' => '2026-10-20', 'due_date' => '2026-10-22', 'estimated_minutes' => 60,
            ])
            ->assertSessionHasNoErrors();

        $milestone = Task::query()->where('title', 'Lanzamiento')->sole();
        expect($milestone->start_date)->toBeNull()
            ->and($milestone->due_date->toDateString())->toBe('2026-10-22')
            ->and($milestone->estimated_minutes)->toBeNull();
    });
});

describe('«Próximos hitos» del resumen del proyecto', function () {
    it('los 5 siguientes sin completar por entrega, más los vencidos destacados y los que no tienen fecha', function () {
        ($this->milestone)('Vencido hace una semana', '2026-10-06');
        ($this->milestone)('Vencido ayer', '2026-10-12');
        ($this->milestone)('Hoy', '2026-10-13');
        foreach (['2026-10-20' => 'Uno', '2026-10-15' => 'Dos', '2026-11-30' => 'Seis', '2026-11-02' => 'Cuatro', '2026-10-28' => 'Tres', '2027-01-10' => 'Siete'] as $due => $title) {
            ($this->milestone)($title, $due);
        }
        ($this->milestone)('Sin fecha', null);
        ($this->milestone)('Completado', '2026-10-14', ['status_id' => $this->done->id]);
        ($this->milestone)('Borrado', '2026-10-14')->delete();
        Task::factory()->create(['project_id' => $this->project->id, 'title' => 'No es hito', 'due_date' => '2026-10-14']);
        ($this->milestone)('De otro proyecto', '2026-10-14', [], Project::factory()->create());

        $this->actingAs(userWithRole('employee'))
            ->get("/proyectos/{$this->project->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('projects/show')
                ->where('milestones.today', '2026-10-13')
                ->where('milestones.overdue', fn ($items) => array_column($items->all(), 'title') === ['Vencido hace una semana', 'Vencido ayer'])
                ->where('milestones.overdue.0.is_overdue', true)
                ->where('milestones.overdue.0.days', -7)
                ->where('milestones.overdue_total', 2)
                ->where('milestones.upcoming', fn ($items) => array_column($items->all(), 'title') === ['Hoy', 'Dos', 'Uno', 'Tres', 'Cuatro'])
                ->where('milestones.upcoming.0.is_overdue', false)
                ->where('milestones.upcoming.0.days', 0)
                ->where('milestones.upcoming.1.due_date', '2026-10-15')
                ->where('milestones.upcoming.1.project_id', $this->project->id)
                ->where('milestones.undated_count', 1));
    });

    it('enseña como mucho 10 vencidos, con el total', function () {
        foreach (range(1, 12) as $day) {
            ($this->milestone)("Vencido {$day}", sprintf('2026-09-%02d', $day));
        }

        $data = app(UpcomingMilestones::class)->forProject($this->project);

        expect($data['overdue'])->toHaveCount(UpcomingMilestones::PROJECT_OVERDUE)
            ->and($data['overdue'][0]['title'])->toBe('Vencido 1')
            ->and($data['overdue_total'])->toBe(12)
            ->and($data['upcoming'])->toBe([]);
    });

    it('sin hitos, listas vacías', function () {
        $this->actingAs($this->user)
            ->get("/proyectos/{$this->project->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('milestones.overdue', [])
                ->where('milestones.upcoming', [])
                ->where('milestones.overdue_total', 0)
                ->where('milestones.undated_count', 0));
    });
});

describe('«Mis próximos hitos» de Inicio', function () {
    it('los de mis proyectos activos, vencidos y de los próximos 30 días, por entrega y con su proyecto', function () {
        $other = Project::factory()->create(['code' => 'BETA']);
        $other->addMember($this->user);
        $notMine = Project::factory()->create();
        $archived = Project::factory()->archived()->create();
        $archived->addMember($this->user);

        ($this->milestone)('Vencido', '2026-09-30');
        ($this->milestone)('En 30 días', '2026-11-12', [], $other);
        ($this->milestone)('Hoy', '2026-10-13');
        ($this->milestone)('En 31 días', '2026-11-13');
        ($this->milestone)('Sin fecha', null);
        ($this->milestone)('Completado', '2026-10-14', ['status_id' => $this->done->id]);
        ($this->milestone)('No soy miembro', '2026-10-14', [], $notMine);
        ($this->milestone)('Archivado', '2026-10-14', [], $archived);
        Task::factory()->assignedTo($this->user)->create(['project_id' => $this->project->id, 'title' => 'Tarea normal', 'due_date' => '2026-10-14']);

        $this->actingAs($this->user)
            ->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('home', false)
                ->where('milestones', fn ($items) => array_column($items->all(), 'title') === ['Vencido', 'Hoy', 'En 30 días'])
                ->where('milestones.0.is_overdue', true)
                ->where('milestones.0.project.code', 'ACME-WEB')
                ->where('milestones.0.project.name', 'Web de Acme')
                ->where('milestones.2.project.code', 'BETA')
                ->where('milestones.2.days', 30));
    });

    it('como mucho 8, los más urgentes primero', function () {
        foreach (range(1, 10) as $day) {
            ($this->milestone)("Hito {$day}", sprintf('2026-10-%02d', 10 + $day));
        }

        $this->actingAs($this->user)
            ->get('/')
            ->assertInertia(fn (Assert $page) => $page
                ->has('milestones', UpcomingMilestones::HOME_LIMIT)
                ->where('milestones.0.title', 'Hito 1')
                ->where('milestones.7.title', 'Hito 8'));
    });

    it('solo lo mío: los hitos de un proyecto del que no soy miembro no salen aunque sea admin', function () {
        $admin = userWithRole('admin');
        ($this->milestone)('De Acme', '2026-10-14');

        $this->actingAs($admin)->get('/')->assertInertia(fn (Assert $page) => $page->where('milestones', []));
        $this->actingAs($this->user)->get('/')->assertInertia(fn (Assert $page) => $page->has('milestones', 1));
    });

    it('solo los proyectos planificados o activos: los en pausa, completados y archivados, fuera', function () {
        $planned = Project::factory()->create(['code' => 'PLAN', 'status' => ProjectStatus::Planned]);
        $onHold = Project::factory()->create(['status' => ProjectStatus::OnHold]);
        $completed = Project::factory()->create(['status' => ProjectStatus::Completed]);
        foreach ([$planned, $onHold, $completed] as $project) {
            $project->addMember($this->user);
        }

        ($this->milestone)('Activo', '2026-10-15');
        ($this->milestone)('Planificado', '2026-10-16', [], $planned);
        ($this->milestone)('En pausa', '2026-10-14', [], $onHold);
        ($this->milestone)('Completado sin cerrar', '2026-10-01', [], $completed);

        $this->actingAs($this->user)
            ->get('/')
            ->assertInertia(fn (Assert $page) => $page
                ->where('milestones', fn ($items) => array_column($items->all(), 'title') === ['Activo', 'Planificado']));
    });

    it('los vencidos antiguos no desplazan a los próximos: el de mañana sale aunque haya 8 vencidos', function () {
        foreach (range(1, 8) as $month) {
            ($this->milestone)("Vencido {$month}", sprintf('2026-%02d-10', $month));
        }
        ($this->milestone)('Mañana', '2026-10-14');

        $this->actingAs($this->user)
            ->get('/')
            ->assertInertia(fn (Assert $page) => $page
                ->has('milestones', UpcomingMilestones::HOME_LIMIT)
                // Los 7 vencidos más recientes (del más atrasado al más reciente) y el de mañana.
                ->where('milestones', fn ($items) => array_column($items->all(), 'title') === [
                    'Vencido 2', 'Vencido 3', 'Vencido 4', 'Vencido 5', 'Vencido 6', 'Vencido 7', 'Vencido 8', 'Mañana',
                ])
                ->where('milestones.7.is_overdue', false)
                ->where('milestones.7.days', 1));
    });

    it('si los próximos llenan la tarjeta, los vencidos ocupan como mucho 3 huecos (los más recientes)', function () {
        foreach (range(1, 5) as $day) {
            ($this->milestone)("Vencido {$day}", sprintf('2026-10-%02d', $day));
        }
        foreach (range(1, 10) as $day) {
            ($this->milestone)("Próximo {$day}", sprintf('2026-10-%02d', 13 + $day));
        }

        $titles = array_column(app(UpcomingMilestones::class)->forUser($this->user), 'title');

        expect($titles)->toBe([
            'Vencido 3', 'Vencido 4', 'Vencido 5', 'Próximo 1', 'Próximo 2', 'Próximo 3', 'Próximo 4', 'Próximo 5',
        ]);
    });

    it('son dos consultas acotadas, aunque tenga muchos proyectos e hitos', function () {
        foreach (range(1, 6) as $i) {
            $project = Project::factory()->create();
            $project->addMember($this->user);
            ($this->milestone)("Hito {$i}", '2026-10-2'.$i, [], $project);
        }

        $user = User::query()->findOrFail($this->user->id);
        $milestones = app(UpcomingMilestones::class);
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });
        $items = $milestones->forUser($user);
        app('events')->forget(QueryExecuted::class);

        expect($queries)->toBe(2)->and($items)->toHaveCount(6);
    });
});
