<?php

use App\Domain\Time\Capacity;
use App\Domain\Workload\WorkloadPlanner;
use App\Models\Absence;
use App\Models\Department;
use App\Models\Holiday;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\TaskType;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\WorkSchedule;
use Carbon\CarbonImmutable;

/*
| Carga planificada (SPEC §9) — aceptación de la Fase 3: festivos, ausencias parciales, tareas
| vencidas, tareas sin fecha de inicio y jornada parcial. Hoy es el martes 06/10/2026 (Madrid);
| el lunes 12/10 es festivo. Ana: 8 h de lunes a viernes. Pau: 6 h de lunes a miércoles.
*/

beforeEach(function () {
    $this->today = CarbonImmutable::parse('2026-10-06');
    $this->travelTo(CarbonImmutable::parse('2026-10-06 10:00', 'Europe/Madrid'));
    TaskStatus::ensureDefaults();

    $this->ana = User::factory()->employee()->create(['name' => 'Ana']);
    $this->pau = User::factory()->employee()->create(['name' => 'Pau']);
    WorkSchedule::factory()->for($this->ana)->create(['valid_from' => '2026-01-01']);
    WorkSchedule::factory()->for($this->pau)->create(['valid_from' => '2026-01-01', 'mon_minutes' => 360, 'tue_minutes' => 360, 'wed_minutes' => 360, 'thu_minutes' => 0, 'fri_minutes' => 0]);
    Holiday::factory()->create(['date' => '2026-10-12', 'name' => 'Fiesta Nacional de España']);

    $this->project = Project::factory()->create();
    $this->task = fn (User $who, array $attributes) => Task::factory()->create([
        'project_id' => $this->project->id, 'assignee_user_id' => $who->id, ...$attributes,
    ]);
    $this->plan = fn (string $from = '2026-10-05', string $to = '2026-10-18') => app(WorkloadPlanner::class)->plan(
        [$this->ana->id, $this->pau->id], CarbonImmutable::parse($from), CarbonImmutable::parse($to), $this->today,
    );
});

it('reparte el restante a partes iguales en los días laborables, con el resto en los primeros', function () {
    $task = ($this->task)($this->ana, ['estimated_minutes' => 1000, 'start_date' => '2026-10-06', 'due_date' => '2026-10-08']);

    $plan = ($this->plan)();

    expect($plan->contributions[$this->ana->id]['2026-10-06'][$task->id])->toBe(334)
        ->and($plan->loadOn($this->ana->id, '2026-10-07'))->toBe(333)
        ->and($plan->loadOn($this->ana->id, '2026-10-08'))->toBe(333)
        ->and($plan->loadBetween($this->ana->id, '2026-10-05', '2026-10-18'))->toBe(1000);
});

it('resta lo ya imputado a la tarea', function () {
    $task = ($this->task)($this->ana, ['estimated_minutes' => 600, 'start_date' => '2026-10-06', 'due_date' => '2026-10-07']);
    TimeEntry::factory()->forTask($task)->minutes(120)->on('2026-10-05')->create(['user_id' => $this->ana->id]);

    $plan = ($this->plan)();

    expect($plan->loadOn($this->ana->id, '2026-10-06'))->toBe(240)
        ->and($plan->loadOn($this->ana->id, '2026-10-07'))->toBe(240);
});

it('los festivos y los fines de semana no reciben carga', function () {
    ($this->task)($this->ana, ['estimated_minutes' => 600, 'start_date' => '2026-10-09', 'due_date' => '2026-10-13']);

    $plan = ($this->plan)();

    expect($plan->loadOn($this->ana->id, '2026-10-09'))->toBe(300)
        ->and($plan->loadOn($this->ana->id, '2026-10-10'))->toBe(0)
        ->and($plan->loadOn($this->ana->id, '2026-10-12'))->toBe(0)
        ->and($plan->loadOn($this->ana->id, '2026-10-13'))->toBe(300)
        ->and($plan->capacity[$this->ana->id]['2026-10-12'])->toBe(0);
});

it('una ausencia de día completo saca el día; una parcial lo deja con menos capacidad; las no aprobadas no cuentan', function () {
    Absence::factory()->approved()->between('2026-10-07', '2026-10-07')->create(['user_id' => $this->ana->id]);
    Absence::factory()->approved()->partial(240)->between('2026-10-08', '2026-10-08')->create(['user_id' => $this->ana->id]);
    Absence::factory()->between('2026-10-09', '2026-10-09')->create(['user_id' => $this->ana->id]); // solicitada
    ($this->task)($this->ana, ['estimated_minutes' => 900, 'start_date' => '2026-10-06', 'due_date' => '2026-10-09']);

    $plan = ($this->plan)();

    expect($plan->capacity[$this->ana->id]['2026-10-07'])->toBe(0)
        ->and($plan->capacity[$this->ana->id]['2026-10-08'])->toBe(240)
        ->and($plan->capacity[$this->ana->id]['2026-10-09'])->toBe(480)
        ->and($plan->loadOn($this->ana->id, '2026-10-06'))->toBe(300)
        ->and($plan->loadOn($this->ana->id, '2026-10-07'))->toBe(0)
        ->and($plan->loadOn($this->ana->id, '2026-10-08'))->toBe(300)
        ->and($plan->loadOn($this->ana->id, '2026-10-09'))->toBe(300);
});

it('una tarea vencida pone todo su restante hoy y queda marcada', function () {
    $task = ($this->task)($this->ana, ['estimated_minutes' => 300, 'start_date' => '2026-09-28', 'due_date' => '2026-10-02']);

    $plan = ($this->plan)();

    expect($plan->loadOn($this->ana->id, '2026-10-06'))->toBe(300)
        ->and($plan->overdue)->toBe([$task->id]);
});

it('sin fecha de inicio se reparte desde hoy hasta la entrega', function () {
    ($this->task)($this->ana, ['estimated_minutes' => 480, 'start_date' => null, 'due_date' => '2026-10-09']);

    $plan = ($this->plan)();

    expect($plan->loadOn($this->ana->id, '2026-10-05'))->toBe(0)
        ->and($plan->loadBetween($this->ana->id, '2026-10-06', '2026-10-09'))->toBe(480)
        ->and($plan->loadOn($this->ana->id, '2026-10-06'))->toBe(120);
});

it('una tarea empezada antes de hoy solo reparte desde hoy', function () {
    ($this->task)($this->ana, ['estimated_minutes' => 400, 'start_date' => '2026-10-01', 'due_date' => '2026-10-07']);

    $plan = ($this->plan)();

    expect($plan->loadOn($this->ana->id, '2026-10-05'))->toBe(0)
        ->and($plan->loadOn($this->ana->id, '2026-10-06'))->toBe(200)
        ->and($plan->loadOn($this->ana->id, '2026-10-07'))->toBe(200);
});

it('jornada parcial: solo los días en que la persona trabaja (y no el festivo)', function () {
    ($this->task)($this->pau, ['estimated_minutes' => 1200, 'start_date' => '2026-10-06', 'due_date' => '2026-10-16']);

    $plan = ($this->plan)();

    // Martes 6, miércoles 7, martes 13 y miércoles 14 (el lunes 12 es festivo; jueves y viernes, 0 h).
    expect(array_keys(array_filter($plan->load[$this->pau->id] ?? [])))->toBe(['2026-10-06', '2026-10-07', '2026-10-13', '2026-10-14'])
        ->and($plan->loadOn($this->pau->id, '2026-10-13'))->toBe(300);
});

it('si no hay ningún día con capacidad en el rango, todo va al primer día', function () {
    ($this->task)($this->pau, ['estimated_minutes' => 180, 'start_date' => '2026-10-08', 'due_date' => '2026-10-10']);

    expect(($this->plan)()->loadOn($this->pau->id, '2026-10-08'))->toBe(180);
});

it('con subtareas cuenta la carga de las subtareas y no la del padre', function () {
    $parent = ($this->task)($this->ana, ['estimated_minutes' => 1000, 'start_date' => '2026-10-06', 'due_date' => '2026-10-06']);
    Task::factory()->subtaskOf($parent)->create(['assignee_user_id' => $this->ana->id, 'estimated_minutes' => 200, 'due_date' => '2026-10-06']);
    Task::factory()->subtaskOf($parent)->create(['assignee_user_id' => $this->pau->id, 'estimated_minutes' => 300, 'due_date' => '2026-10-06']);

    $plan = ($this->plan)();

    expect($plan->loadOn($this->ana->id, '2026-10-06'))->toBe(200)
        ->and($plan->loadOn($this->pau->id, '2026-10-06'))->toBe(300);
});

it('lo que cae fuera de la vista no se pinta, pero cuenta para repartir', function () {
    ($this->task)($this->ana, ['estimated_minutes' => 800, 'start_date' => '2026-10-06', 'due_date' => '2026-10-15']);

    // Días laborables del 6 al 15 sin el festivo: 6, 7, 8, 9, 13, 14, 15 → 7 días. 800 / 7 = 114 y
    // sobran 2 minutos, que van a los dos primeros días: 115 + 115 + 114 + 114 en la vista.
    $plan = ($this->plan)('2026-10-05', '2026-10-11');

    expect($plan->loadBetween($this->ana->id, '2026-10-05', '2026-10-11'))->toBe(458)
        ->and($plan->loadOn($this->ana->id, '2026-10-13'))->toBe(0);
});

it('una entrega a más de un año reparte entre todos los días laborables hasta la entrega y solo pinta el primer año', function () {
    ($this->task)($this->ana, ['estimated_minutes' => 59940, 'start_date' => '2026-10-06', 'due_date' => '2028-10-06']);

    // Del martes 06/10/2026 al jueves 07/10/2027 (el tope: un año desde hoy) hay 262 días
    // laborables (sin el festivo del 12/10); del 08/10/2027 a la entrega, 261 más con su jornada de
    // lunes a viernes. 59.940 / 523 = 114 y sobran 318 minutos, que van a los primeros días: los 262
    // del primer año llevan 115. Antes se repartía todo en el primer año (~229 al día).
    $plan = ($this->plan)('2026-10-05', '2028-10-08');

    expect($plan->loadOn($this->ana->id, '2026-10-06'))->toBe(115)
        ->and($plan->loadOn($this->ana->id, '2026-10-12'))->toBe(0)
        ->and($plan->loadOn($this->ana->id, '2027-10-07'))->toBe(115)
        ->and($plan->loadOn($this->ana->id, '2027-10-08'))->toBe(0)
        ->and($plan->loadBetween($this->ana->id, '2026-10-05', '2028-10-08'))->toBe(262 * 115);
});

it('más allá del año cuenta los días que trabaja según su jornada vigente (sin festivos ni ausencias)', function () {
    // Pau trabaja de lunes a miércoles. Del 06/10/2026 al 07/10/2027: 157 días (sin el lunes 12/10);
    // del 08/10/2027 al 31/12/2027: 36 lunes, martes y miércoles más. 11.580 / 193 = 60 exactos.
    ($this->task)($this->pau, ['estimated_minutes' => 11580, 'start_date' => '2026-10-06', 'due_date' => '2027-12-31']);
    // Una ausencia más allá del año no se conoce aún para el reparto: no cambia nada.
    Absence::factory()->approved()->between('2027-11-01', '2027-11-30')->create(['user_id' => $this->pau->id]);

    $plan = ($this->plan)();

    expect($plan->loadOn($this->pau->id, '2026-10-06'))->toBe(60)
        ->and($plan->loadOn($this->pau->id, '2026-10-07'))->toBe(60)
        ->and($plan->loadOn($this->pau->id, '2026-10-08'))->toBe(0)
        ->and($plan->loadOn($this->pau->id, '2026-10-13'))->toBe(60);
});

it('una tarea que empieza después del año no pinta nada', function () {
    ($this->task)($this->ana, ['estimated_minutes' => 6000, 'start_date' => '2027-11-01', 'due_date' => '2027-12-31']);

    expect(($this->plan)('2026-10-05', '2027-12-31')->load)->toBe([]);
});

it('sin estimación o sin entrega van a «Sin planificar»; sin responsable, a «Sin asignar» por departamento', function () {
    $design = Department::factory()->create();
    $marketing = Department::factory()->create();
    $noEstimate = ($this->task)($this->ana, ['estimated_minutes' => null, 'due_date' => '2026-10-09']);
    $noDates = ($this->task)($this->pau, ['estimated_minutes' => 120, 'due_date' => null]);
    $bank = HourBank::factory()->forDepartment($design)->create(['project_id' => Project::factory()->hourBank()]);
    $byBank = Task::factory()->inBank($bank)->create(['assignee_user_id' => null, 'estimated_minutes' => 300]);
    $byType = Task::factory()->create(['project_id' => $this->project->id, 'assignee_user_id' => null, 'estimated_minutes' => 60,
        'task_type_id' => TaskType::factory()->create(['department_id' => $marketing->id])->id]);
    $orphan = Task::factory()->create(['project_id' => $this->project->id, 'assignee_user_id' => null]);

    $plan = ($this->plan)();

    expect($plan->unplanned)->toBe([
        ['task_id' => $noEstimate->id, 'user_id' => $this->ana->id, 'reason' => 'no_estimate'],
        ['task_id' => $noDates->id, 'user_id' => $this->pau->id, 'reason' => 'no_dates'],
    ])
        ->and($plan->unassigned[$design->id])->toBe([['task_id' => $byBank->id, 'remaining_minutes' => 300]])
        ->and($plan->unassigned[$marketing->id])->toBe([['task_id' => $byType->id, 'remaining_minutes' => 60]])
        ->and($plan->unassigned[''])->toBe([['task_id' => $orphan->id, 'remaining_minutes' => 0]]);
});

it('no cuentan los hitos, las tareas completadas ni las de proyectos archivados', function () {
    ($this->task)($this->ana, ['estimated_minutes' => 300, 'due_date' => '2026-10-07', 'is_milestone' => true]);
    Task::factory()->completed()->create(['project_id' => $this->project->id, 'assignee_user_id' => $this->ana->id, 'estimated_minutes' => 300, 'due_date' => '2026-10-07']);
    $archived = Project::factory()->archived()->create();
    Task::factory()->create(['project_id' => $archived->id, 'assignee_user_id' => $this->ana->id, 'estimated_minutes' => 300, 'due_date' => '2026-10-07']);

    expect(($this->plan)()->load)->toBe([]);
});

it('la capacidad explica por qué un día vale 0 (festivo o ausencia)', function () {
    Absence::factory()->approved()->partial(120)->between('2026-10-13', '2026-10-13')->create(['user_id' => $this->ana->id, 'type' => 'training']);

    $details = app(Capacity::class)->details($this->ana, CarbonImmutable::parse('2026-10-12'), CarbonImmutable::parse('2026-10-13'));

    expect($details['2026-10-12'])->toBe(['base' => 480, 'minutes' => 0, 'holiday' => 'Fiesta Nacional de España', 'absence' => null])
        ->and($details['2026-10-13'])->toBe(['base' => 480, 'minutes' => 360, 'holiday' => null, 'absence' => ['type' => 'training', 'partial_minutes' => 120]]);
});
