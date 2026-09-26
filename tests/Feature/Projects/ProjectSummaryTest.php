<?php

use App\Domain\Projects\ProjectActivityFeed;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\TimeEntry;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Resumen del proyecto (SPEC §6): horas estimadas (efectivas, con subtareas) frente a reales,
| presupuesto, tareas, bolsas abiertas con comprometidas, equipo y actividad reciente legible
| (quién, qué y cuándo) sin datos económicos para quien no tiene view-financials.
*/

beforeEach(function () {
    $this->owner = userWithRole('employee', ['name' => 'Laura Gómez']);
    $this->project = Project::factory()->hourBank()->create([
        'owner_user_id' => $this->owner->id,
        'budget_minutes' => 20 * 60,
    ]);
    $this->viewer = userWithRole('employee');
});

test('horas estimadas: la estimación efectiva de las tareas raíz (la de un padre con subtareas es su suma)', function () {
    $bank = HourBank::factory()->hours(50)->create(['project_id' => $this->project->id]);

    $parent = Task::factory()->inBank($bank)->create(['estimated_minutes' => 600]);
    Task::factory()->subtaskOf($parent)->create(['estimated_minutes' => 120]);
    Task::factory()->subtaskOf($parent)->create(['estimated_minutes' => 60]);
    Task::factory()->subtaskOf($parent)->create(['estimated_minutes' => null]);
    Task::factory()->inBank($bank)->create(['estimated_minutes' => 90]);
    Task::factory()->inBank($bank)->completed()->create(['estimated_minutes' => 30]);

    TimeEntry::factory()->forTask($parent)->minutes(45)->create();
    TimeEntry::factory()->forTask(Task::factory()->inBank($bank)->create())->minutes(15)->create();

    $this->actingAs($this->viewer)
        ->get("/proyectos/{$this->project->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/show')
            ->where('summary.estimated_minutes', 180 + 90 + 30)
            ->where('summary.logged_minutes', 60)
            ->where('summary.budget_minutes', 1200)
            ->where('summary.total_tasks', 7)
            ->where('summary.open_tasks', 6));
});

test('muestra las bolsas abiertas con sus horas comprometidas; no las cerradas', function () {
    $open = HourBank::factory()->hours(10)->create(['project_id' => $this->project->id, 'name' => 'Abierta']);
    HourBank::factory()->closed()->create(['project_id' => $this->project->id, 'name' => 'Cerrada']);
    Task::factory()->inBank($open)->create(['estimated_minutes' => 240]);

    $this->actingAs($this->viewer)
        ->get("/proyectos/{$this->project->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->has('hourBanks', 1)
            ->where('hourBanks.0.name', 'Abierta')
            ->where('hourBanks.0.committed_minutes', 240)
            ->where('hourBanks.0.consumed_pct', 0)
            ->missing('hourBanks.0.price_amount'));
});

test('el equipo: gestores (principal primero en los datos) y número de miembros', function () {
    $co = userWithRole('employee', ['name' => 'Ana Co']);
    $this->project->addMember($co, true);
    $this->project->addMember(userWithRole('employee'));

    $this->actingAs($this->viewer)
        ->get("/proyectos/{$this->project->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->has('managers', 2)
            ->where('membersCount', 3)
            ->where('project.owner.name', 'Laura Gómez'));
});

test('actividad reciente: quién, qué y cuándo, en español y de lo más reciente a lo más antiguo', function () {
    $this->actingAs($this->owner);

    $bank = HourBank::factory()->create(['project_id' => $this->project->id, 'name' => 'Bolsa T4']);
    $task = Task::factory()->inBank($bank)->create(['title' => 'Maquetar la home']);
    TaskStatus::ensureDefaults();
    $done = TaskStatus::query()->where('category', 'done')->firstOrFail();
    $task->update(['status_id' => $done->id]);
    $task->update(['assignee_user_id' => $this->owner->id]);
    $this->project->update(['name' => 'Otro nombre']);

    $this->actingAs($this->viewer)
        ->get("/proyectos/{$this->project->id}")
        ->assertInertia(function (Assert $page) use ($done) {
            $items = $page->toArray()['props']['activity'];
            $texts = array_column($items, 'text');

            expect($texts[0])->toBe('modificó el proyecto: nombre')
                ->and($texts[1])->toBe('asignó la tarea «Maquetar la home» a Laura Gómez')
                ->and($texts[2])->toBe("movió la tarea «Maquetar la home» a «{$done->name}»")
                ->and($texts)->toContain('creó la tarea «Maquetar la home»')
                ->and($texts)->toContain('creó la bolsa «Bolsa T4»')
                ->and($items[0]['actor']['name'])->toBe('Laura Gómez')
                ->and($items[0]['created_at'])->not->toBeNull();

            $taskItem = collect($items)->firstWhere('text', 'creó la tarea «Maquetar la home»');
            expect($taskItem['url'])->toContain('/tareas?tarea=');
        });
});

test('sin view-financials, la actividad no deja ver cambios económicos', function () {
    $this->actingAs(userWithRole('admin'));
    $this->project->update(['hourly_rate' => '80.00']);
    $this->project->update(['hourly_rate' => '90.00', 'name' => 'Renombrado']);

    $feed = app(ProjectActivityFeed::class);

    $employeeTexts = array_column($feed->latest($this->project, $this->viewer), 'text');
    $adminTexts = array_column($feed->latest($this->project, userWithRole('admin')), 'text');

    expect($employeeTexts)->toContain('modificó el proyecto: nombre')
        ->and(implode(' ', $employeeTexts))->not->toContain('tarifa')
        ->and($adminTexts)->toContain('modificó el proyecto: tarifa')
        ->and($adminTexts)->toContain('modificó el proyecto: nombre, tarifa');
});

test('la actividad incluye los cambios de miembros y de estado de las bolsas', function () {
    $admin = userWithRole('admin');
    $member = userWithRole('employee', ['name' => 'Marc Puig']);
    $bank = HourBank::factory()->hours(1)->create(['project_id' => $this->project->id, 'name' => 'Bolsa pequeña']);

    $this->actingAs($admin)->post("/proyectos/{$this->project->id}/miembros", ['user_id' => $member->id]);
    $this->actingAs($admin)->post("/proyectos/{$this->project->id}/bolsas/{$bank->id}/cerrar");

    $texts = array_column(app(ProjectActivityFeed::class)->latest($this->project, $admin), 'text');

    expect($texts)->toContain('añadió a Marc Puig al proyecto')
        ->and($texts)->toContain('cambió el estado de la bolsa «Bolsa pequeña» a «Cerrada»');
});

test('como mucho 15 elementos de actividad', function () {
    $this->actingAs($this->owner);
    Task::factory()->count(20)->create(['project_id' => $this->project->id]);

    expect(app(ProjectActivityFeed::class)->latest($this->project, $this->viewer))->toHaveCount(15);
});

test('sin N+1: el resumen hace las mismas consultas con más tareas, bolsas y actividad', function () {
    $queries = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->viewer)->get("/proyectos/{$this->project->id}")->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    $this->actingAs($this->owner);
    $seed = function (): void {
        $bank = HourBank::factory()->create(['project_id' => $this->project->id]);
        $parent = Task::factory()->inBank($bank)->create(['estimated_minutes' => 60]);
        Task::factory()->subtaskOf($parent)->count(2)->create(['estimated_minutes' => 30]);
        TimeEntry::factory()->forTask($parent)->minutes(30)->create();
    };

    $seed();
    $queries();
    $few = $queries();

    $seed();
    $seed();
    $seed();
    $many = $queries();

    expect($many)->toBe($few);
});
