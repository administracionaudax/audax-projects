<?php

use App\Domain\Schedule\DependencyService;
use App\Domain\Schedule\ScheduleConflicts;
use App\Domain\Schedule\ScheduleShifter;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskDependency;
use App\Models\TaskStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/*
| Dependencias fin-inicio y conflictos al mover (SPEC §6.1, D-056, D-057; aceptación de la F4:
| no se pueden crear ciclos).
*/

beforeEach(function () {
    TaskStatus::ensureDefaults();
    $this->links = app(DependencyService::class);
    $this->project = Project::factory()->create();
    $this->make = fn (string $title, ?string $start, ?string $due, array $extra = []) => Task::factory()->create([
        'project_id' => $this->project->id, 'title' => $title, 'start_date' => $start, 'due_date' => $due, ...$extra,
    ]);
});

it('enlaza fin-inicio dentro del proyecto y no duplica', function () {
    $a = ($this->make)('Diseño', '2026-10-05', '2026-10-07');
    $b = ($this->make)('Maquetación', '2026-10-08', '2026-10-09');

    $first = $this->links->link($a, $b);
    $again = $this->links->link($a, $b);

    expect($again->id)->toBe($first->id)
        ->and(TaskDependency::query()->count())->toBe(1)
        ->and($first->type)->toBe('finish_to_start');
});

it('rechaza enlazar una tarea consigo misma o con otro proyecto', function () {
    $a = ($this->make)('A', null, '2026-10-07');
    $other = Task::factory()->create();

    expect(fn () => $this->links->link($a, $a))->toThrow(ValidationException::class)
        ->and(fn () => $this->links->link($a, $other))->toThrow(ValidationException::class);
});

it('no se pueden crear ciclos, ni directos ni indirectos', function () {
    $a = ($this->make)('A', null, '2026-10-05');
    $b = ($this->make)('B', null, '2026-10-06');
    $c = ($this->make)('C', null, '2026-10-07');
    $this->links->link($a, $b);
    $this->links->link($b, $c);

    expect(fn () => $this->links->link($b, $a))->toThrow(ValidationException::class, 'ciclo')
        ->and(fn () => $this->links->link($c, $a))->toThrow(ValidationException::class, 'ciclo')
        ->and(TaskDependency::query()->count())->toBe(2);

    // Un atajo sin ciclo sí vale (A → C).
    expect($this->links->link($a, $c)->exists)->toBeTrue();
});

it('un hito puede depender de tareas y al revés', function () {
    $task = ($this->make)('Desarrollo', '2026-10-05', '2026-10-09');
    $milestone = ($this->make)('Entrega', null, '2026-10-10', ['is_milestone' => true]);
    $after = ($this->make)('Formación', '2026-10-12', '2026-10-12');

    $this->links->link($task, $milestone);
    $this->links->link($milestone, $after);

    expect(TaskDependency::query()->count())->toBe(2);
});

it('propone desplazar las sucesoras en conflicto en cascada, conservando su duración', function () {
    $a = ($this->make)('A', '2026-10-05', '2026-10-07');
    $b = ($this->make)('B', '2026-10-08', '2026-10-09');
    $c = ($this->make)('C', '2026-10-12', '2026-10-14');
    $free = ($this->make)('Libre', '2026-10-20', '2026-10-21');
    $this->links->link($a, $b);
    $this->links->link($b, $c);
    $this->links->link($a, $free);

    // A pasa a acabar el 13: B (empieza el 8) debe empezar el 14; C, justo después de B.
    $proposal = app(ScheduleConflicts::class)->proposeShift($a, CarbonImmutable::parse('2026-10-05'), CarbonImmutable::parse('2026-10-13'));

    expect($proposal)->toHaveCount(2)
        ->and($proposal[0])->toMatchArray(['task_id' => $b->id, 'new_start_date' => '2026-10-14', 'new_due_date' => '2026-10-15', 'shift_days' => 6, 'predecessor_id' => $a->id])
        ->and($proposal[1])->toMatchArray(['task_id' => $c->id, 'new_start_date' => '2026-10-16', 'new_due_date' => '2026-10-18', 'predecessor_id' => $b->id]);
});

it('sin conflicto no propone nada; las sucesoras sin fechas se ignoran', function () {
    $a = ($this->make)('A', '2026-10-05', '2026-10-07');
    $later = ($this->make)('B', '2026-10-15', '2026-10-16');
    $undated = ($this->make)('Sin fechas', null, null);
    $this->links->link($a, $later);
    $this->links->link($a, $undated);

    expect(app(ScheduleConflicts::class)->proposeShift($a, CarbonImmutable::parse('2026-10-05'), CarbonImmutable::parse('2026-10-09')))->toBe([]);
});

it('un hito sucesor se desplaza por su fecha de entrega', function () {
    $a = ($this->make)('A', '2026-10-05', '2026-10-07');
    $milestone = ($this->make)('Hito', null, '2026-10-08', ['is_milestone' => true]);
    $this->links->link($a, $milestone);

    $proposal = app(ScheduleConflicts::class)->proposeShift($a, null, CarbonImmutable::parse('2026-10-09'));

    expect($proposal[0])->toMatchArray(['new_start_date' => null, 'new_due_date' => '2026-10-10']);
});

it('aplicar la propuesta cambia las fechas solo con confirmación y con permiso sobre cada tarea', function () {
    $manager = User::factory()->employee()->create();
    $this->project->addMember($manager, isManager: true);
    $a = ($this->make)('A', '2026-10-05', '2026-10-07');
    $b = ($this->make)('B', '2026-10-08', '2026-10-09');
    $this->links->link($a, $b);
    $proposal = app(ScheduleConflicts::class)->proposeShift($a, CarbonImmutable::parse('2026-10-05'), CarbonImmutable::parse('2026-10-10'));

    // Proponer no cambia nada.
    expect($b->fresh()->start_date->toDateString())->toBe('2026-10-08');

    expect(app(ScheduleShifter::class)->apply($manager, $this->project->id, $proposal))->toBe(1)
        ->and($b->fresh()->start_date->toDateString())->toBe('2026-10-11')
        ->and($b->fresh()->due_date->toDateString())->toBe('2026-10-12');

    $stranger = User::factory()->employee()->create();
    expect(fn () => app(ScheduleShifter::class)->apply($stranger, $this->project->id, $proposal))
        ->toThrow(AuthorizationException::class);
});
