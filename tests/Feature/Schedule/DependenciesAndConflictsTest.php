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
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Support\Facades\DB;
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

it('enlaza con la fila del proyecto bloqueada, antes de buscar el ciclo: dos enlaces a la vez no pueden cerrar uno', function () {
    $a = ($this->make)('A', null, '2026-10-05');
    $b = ($this->make)('B', null, '2026-10-06');
    $connection = DB::connection();

    // SQLite no bloquea filas y su gramática omite FOR UPDATE: aquí se deja ver como comentario.
    // En PostgreSQL (CI y servidor) es el FOR UPDATE de verdad.
    if ($connection->getDriverName() === 'sqlite') {
        $connection->setQueryGrammar(new class($connection) extends SQLiteGrammar
        {
            /**
             * @param  bool|string  $value
             */
            protected function compileLock(Builder $query, $value): string
            {
                return $value === true ? '/* for update */' : '';
            }
        });
    }

    $outside = $connection->transactionLevel();
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries, $outside): void {
        $queries[] = ['sql' => $query->sql, 'bindings' => $query->bindings, 'in_transaction' => $query->connection->transactionLevel() > $outside];
    });

    $this->links->link($a, $b);

    $lock = array_find_key($queries, fn (array $query): bool => str_contains($query['sql'], 'from "projects"') && str_contains($query['sql'], 'for update'));
    $firstRead = array_find_key($queries, fn (array $query): bool => str_contains($query['sql'], 'from "task_dependencies"'));

    expect($lock)->not->toBeNull()
        ->and($queries[$lock]['bindings'])->toBe([$this->project->id])
        ->and($queries[$lock]['in_transaction'])->toBeTrue()
        ->and($firstRead)->not->toBeNull()
        ->and($lock)->toBeLessThan($firstRead)
        ->and($queries[$firstRead]['in_transaction'])->toBeTrue();
});

it('con un ciclo en la base (datos dañados), la propuesta termina sin dar vueltas ni proponer fechas absurdas', function () {
    $a = ($this->make)('A', '2026-10-05', '2026-10-07');
    $b = ($this->make)('B', '2026-10-08', '2026-10-09');
    $c = ($this->make)('C', '2026-10-10', '2026-10-12');
    // A mano, sin DependencyService: A → B → A y B → C → B.
    foreach ([[$a, $b], [$b, $a], [$b, $c], [$c, $b]] as [$from, $to]) {
        TaskDependency::query()->create(['predecessor_task_id' => $from->id, 'successor_task_id' => $to->id]);
    }

    $proposal = app(ScheduleConflicts::class)->proposeShift($a, CarbonImmutable::parse('2026-10-05'), CarbonImmutable::parse('2026-10-10'));

    // La tarea movida no se propone y cada sucesora sale una vez, con fechas de octubre.
    expect($proposal)->toHaveCount(2)
        ->and($proposal[0])->toMatchArray(['task_id' => $b->id, 'new_start_date' => '2026-10-11', 'new_due_date' => '2026-10-12', 'shift_days' => 3, 'predecessor_id' => $a->id])
        ->and($proposal[1])->toMatchArray(['task_id' => $c->id, 'new_start_date' => '2026-10-13', 'new_due_date' => '2026-10-15', 'shift_days' => 3, 'predecessor_id' => $b->id]);
});

it('la cascada sigue por todos los caminos: una sucesora que llega por dos ramas acaba detrás de la más tardía', function () {
    $a = ($this->make)('A', '2026-10-01', '2026-10-05');
    $b = ($this->make)('B', '2026-10-06', '2026-10-06');
    $c = ($this->make)('C', '2026-10-06', '2026-10-08');
    $d = ($this->make)('D', '2026-10-07', '2026-10-07');
    $e = ($this->make)('E', '2026-10-09', '2026-10-10');
    $f = ($this->make)('F', '2026-10-08', '2026-10-09');
    // A → B → D → F y A → C → E → D: D depende de B y de E.
    foreach ([[$a, $b], [$a, $c], [$b, $d], [$c, $e], [$e, $d], [$d, $f]] as [$from, $to]) {
        $this->links->link($from, $to);
    }

    $proposal = collect(app(ScheduleConflicts::class)->proposeShift($a, CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-10')))
        ->mapWithKeys(fn (array $item): array => [$item['title'] => [$item['new_start_date'], $item['new_due_date']]])
        ->all();

    expect($proposal)->toEqual([
        'B' => ['2026-10-11', '2026-10-11'],
        'C' => ['2026-10-11', '2026-10-13'],
        'E' => ['2026-10-14', '2026-10-15'],
        'D' => ['2026-10-16', '2026-10-16'],
        'F' => ['2026-10-17', '2026-10-18'],
    ]);
});

it('mover una tarea antes no propone nada, aunque ya hubiera un conflicto (D-057)', function () {
    // B ya empieza antes de que acabe A.
    $a = ($this->make)('A', '2026-10-05', '2026-10-10');
    $b = ($this->make)('B', '2026-10-08', '2026-10-09');
    $this->links->link($a, $b);
    $conflicts = app(ScheduleConflicts::class);

    expect($conflicts->proposeShift($a, CarbonImmutable::parse('2026-10-04'), CarbonImmutable::parse('2026-10-09')))->toBe([])
        // Con la misma entrega (solo cambia el inicio), tampoco.
        ->and($conflicts->proposeShift($a, CarbonImmutable::parse('2026-10-07'), CarbonImmutable::parse('2026-10-10')))->toBe([])
        // Más tarde, sí: B pasa detrás de la entrega nueva.
        ->and($conflicts->proposeShift($a, CarbonImmutable::parse('2026-10-05'), CarbonImmutable::parse('2026-10-11')))->toHaveCount(1)
        ->and($conflicts->proposeShift($a, CarbonImmutable::parse('2026-10-05'), CarbonImmutable::parse('2026-10-11'))[0])
        ->toMatchArray(['task_id' => $b->id, 'new_start_date' => '2026-10-12', 'new_due_date' => '2026-10-13']);
});

it('un conflicto que ya había más abajo no lo propone un cambio que no llega hasta él', function () {
    $a = ($this->make)('A', '2026-10-01', '2026-10-05');
    $b = ($this->make)('B', '2026-10-10', '2026-10-12');
    // C ya empieza antes de que acabe B.
    $c = ($this->make)('C', '2026-10-11', '2026-10-13');
    $this->links->link($a, $b);
    $this->links->link($b, $c);

    expect(app(ScheduleConflicts::class)->proposeShift($a, CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-06')))->toBe([]);
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
