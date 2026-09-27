<?php

use App\Domain\Admin\TaskStatusCatalog;
use App\Domain\Reports\ReportCache;
use App\Domain\Tasks\TaskMover;
use App\Domain\Time\TimeLockService;
use App\Enums\TimeEntryStatus;
use App\Models\Department;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\TaskType;
use App\Models\TimeEntry;
use App\Models\TimeEntryLock;
use App\Models\TimesheetPeriod;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Caché de los informes (D-046):
|  - PERF-01: la versión sube DESPUÉS del commit (DB::afterCommit), nunca a mitad de una
|    transacción, y con un incremento atómico (Cache::increment),
|  - INT-03: se invalida tras los servicios con actualizaciones masivas (bloquear y desbloquear
|    horas, estados de tarea, mover tareas) y al cambiar estados, tipos, departamentos, semanas,
|    bloqueos y miembros de proyecto,
|  - SEC-03: la clave depende del alcance de quien mira (roles, departamentos que dirige, proyectos
|    que gestiona y datos económicos): quitarle a alguien un departamento no le deja ver lo cacheado.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-25 12:00', 'Europe/Madrid'));
    TaskStatus::ensureDefaults();
    $this->admin = User::factory()->admin()->create();
    $this->department = Department::factory()->create(['name' => 'Diseño']);
    $this->ana = User::factory()->employee()->create(['name' => 'Ana', 'department_id' => $this->department->id]);
    $this->project = Project::factory()->create();
    $this->task = Task::factory()->create(['project_id' => $this->project->id, 'estimated_minutes' => 60, 'assignee_user_id' => $this->ana->id]);
    $this->entry = TimeEntry::factory()->forTask($this->task)->on('2026-09-22')->minutes(90)->status(TimeEntryStatus::Approved)
        ->create(['user_id' => $this->ana->id, 'hourly_rate_snapshot' => '50.00']);
});

it('sube la versión con un incremento atómico, también la primera vez (PERF-01)', function () {
    Cache::forget(ReportCache::VERSION_KEY);
    expect(ReportCache::version())->toBe(1);

    ReportCache::bump();
    ReportCache::bump();
    expect(ReportCache::version())->toBe(3);

    Cache::spy();
    ReportCache::bump();
    Cache::shouldHaveReceived('increment')->with(ReportCache::VERSION_KEY)->once();
});

it('dentro de una transacción no invalida hasta el commit, y no invalida si se deshace (PERF-01)', function () {
    $before = ReportCache::version();

    DB::transaction(function () use ($before): void {
        TimeEntry::factory()->forTask($this->task)->on('2026-09-23')->minutes(30)->create(['user_id' => $this->ana->id]);
        // Una petición que leyera ahora guardaría en caché datos sin confirmar con la versión nueva.
        expect(ReportCache::version())->toBe($before);
    });

    expect(ReportCache::version())->toBeGreaterThan($before);

    $committed = ReportCache::version();
    try {
        DB::transaction(function (): void {
            $this->task->update(['title' => 'Cambio que se deshace']);
            throw new RuntimeException('deshacer');
        });
    } catch (RuntimeException) {
    }

    expect(ReportCache::version())->toBe($committed);
});

it('se invalida al guardar o borrar estados, tipos, departamentos, semanas, bloqueos y miembros (INT-03)', function (Closure $write) {
    $before = ReportCache::version();

    $write($this);

    expect(ReportCache::version())->toBeGreaterThan($before);
})->with([
    'estado de tarea' => [fn ($t) => TaskStatus::query()->first()->update(['name' => 'Otro nombre'])],
    'tipo de tarea' => [fn ($t) => TaskType::factory()->create()],
    'departamento' => [fn ($t) => $t->department->update(['name' => 'Diseño y UX'])],
    'semana de horas' => [fn ($t) => TimesheetPeriod::query()->create(['user_id' => $t->ana->id, 'week_start' => '2026-09-21', 'status' => 'open'])],
    'bloqueo de horas' => [fn ($t) => TimeEntryLock::query()->create(['date_from' => '2026-09-01', 'date_to' => '2026-09-30', 'locked_by' => $t->admin->id, 'entries_count' => 0])],
    'miembro de proyecto' => [fn ($t) => $t->project->addMember($t->ana)],
    'responsables de un departamento' => [fn ($t) => User::forgetMemberships()],
]);

it('se invalida tras bloquear y desbloquear horas, aunque sean actualizaciones masivas (INT-03)', function () {
    $service = app(TimeLockService::class);

    $before = ReportCache::version();
    $lock = $service->lock($this->admin, null, $this->project, CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-30'), 'F-1');
    expect(ReportCache::version())->toBeGreaterThan($before)
        ->and($this->entry->fresh()->status)->toBe(TimeEntryStatus::Locked);

    $before = ReportCache::version();
    $service->unlock($this->admin, $lock);
    expect(ReportCache::version())->toBeGreaterThan($before);
});

it('tras pasar un estado a «done» (completed_at masivo), la precisión de estimación del informe cambia (INT-03)', function () {
    $status = TaskStatus::query()->where('category', 'in_progress')->orderBy('position')->firstOrFail();
    $this->task->update(['status_id' => $status->id]);
    $url = '/informes/personas/'.$this->ana->id.'?periodo=semana&fecha=2026-09-21';

    $this->actingAs($this->admin)->get($url)->assertInertia(fn (Assert $page) => $page->where('summary.estimation.tasks', 0));

    app(TaskStatusCatalog::class)->update($this->admin, $status, ['name' => $status->name, 'color' => $status->color, 'category' => 'done', 'is_default' => false]);

    $this->actingAs($this->admin)->get($url)->assertInertia(fn (Assert $page) => $page
        ->where('summary.estimation.tasks', 1)
        ->where('summary.estimation.actual_minutes', 90));
});

it('se invalida al mover una tarea a otro proyecto (INT-03)', function () {
    $target = Project::factory()->create();
    $before = ReportCache::version();

    app(TaskMover::class)->move($this->task, $target, null);

    expect(ReportCache::version())->toBeGreaterThan($before);
});

it('quien deja de dirigir un departamento no ve lo que vio cacheado, aunque nada invalide la caché (SEC-03)', function () {
    $head = User::factory()->departmentManager()->create(['name' => 'Raúl']);
    $this->department->managers()->attach($head);
    // Proyecto × semana: una tabla que también ve quien no dirige nada (la misma clave de caché).
    $url = '/informes/detalle?periodo=semana&fecha=2026-09-21&filas=proyecto&columnas=semana';

    $this->actingAs($head)->get($url)->assertInertia(fn (Assert $page) => $page->where('pivot.total', 90));

    // Se le quita el departamento sin eventos (como una sincronización del pivote) y sin invalidar.
    $version = ReportCache::version();
    DB::table('department_managers')->where('user_id', $head->id)->delete();
    expect(ReportCache::version())->toBe($version);

    $this->actingAs($head->fresh())->get($url)->assertInertia(fn (Assert $page) => $page->where('pivot.total', 0));
});

it('la clave depende del alcance de quien mira: roles, departamentos, proyectos y datos económicos (SEC-03)', function () {
    $user = User::factory()->departmentManager()->create();
    $key = fn (): string => ReportCache::viewerScope($user->fresh());

    $plain = $key();
    $this->department->managers()->attach($user);
    $managing = $key();
    $this->project->addMember($user, isManager: true);
    $projects = $key();
    $user->syncRoles(['admin']);

    expect(array_unique([$plain, $managing, $projects, $key()]))->toHaveCount(4);
});
