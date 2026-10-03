<?php

use App\Domain\Tasks\MyTaskList;
use App\Domain\Tasks\MyTaskSections;
use App\Models\Client;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\TaskType;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Mis tareas (SPEC §6, D-037 y D-143): mis tareas y aquellas en las que he imputado en los últimos
| 30 días, ordenadas por «Imputadas recientemente» o por vencimiento (con las secciones), prioridad,
| proyecto, creación o actualización; filtros en la URL y paginación por cursor.
| «Hoy» es el miércoles 23/09/2026 (semana del lunes 21 al domingo 27).
*/

beforeEach(function () {
    TaskStatus::ensureDefaults();
    $this->travelTo(CarbonImmutable::parse('2026-09-23 10:00:00', 'Europe/Madrid'));

    $this->user = userWithRole('employee');
    $this->project = Project::factory()->create(['name' => 'Web Hoteles', 'code' => 'HOTEL']);
    $this->make = fn (string $title, array $attributes = []): Task => Task::factory()->assignedTo($this->user)->create([
        'project_id' => $this->project->id,
        'title' => $title,
        ...$attributes,
    ]);
    $this->log = fn (Task $task, string $date, ?User $user = null, ?string $createdAt = null): TimeEntry => tap(
        TimeEntry::factory()->forTask($task)->on($date)->create(['user_id' => ($user ?? $this->user)->id]),
        function (TimeEntry $entry) use ($createdAt): void {
            if ($createdAt !== null) {
                DB::table('time_entries')->where('id', $entry->id)->update(['created_at' => $createdAt]);
            }
        },
    );
    $this->props = fn (string $query = ''): array => $this->actingAs($this->user)->get('/mis-tareas'.$query)->assertOk()->viewData('page')['props'];
    $this->titles = fn (string $query = ''): array => array_column(($this->props)($query)['tasks'], 'title');
});

it('ordena por «Imputadas recientemente» por defecto: mi última fecha, después la última registrada y después el resto', function () {
    $old = ($this->make)('Imputada hace días', ['due_date' => '2026-09-24']);
    $recent = ($this->make)('Imputada ayer');
    $tie = ($this->make)('Imputada ayer, registrada después');
    ($this->make)('Sin horas, vence pronto', ['due_date' => '2026-09-25']);
    ($this->make)('Sin horas ni fecha');

    ($this->log)($old, '2026-09-15');
    ($this->log)($recent, '2026-09-22', createdAt: '2026-09-22 09:00:00');
    ($this->log)($tie, '2026-09-22', createdAt: '2026-09-22 18:00:00');
    // Las horas de otra persona no cuentan para mi orden.
    ($this->log)($old, '2026-09-23', User::factory()->employee()->create());

    $props = ($this->props)();

    expect(array_column($props['tasks'], 'title'))->toBe([
        'Imputada ayer, registrada después',
        'Imputada ayer',
        'Imputada hace días',
        'Sin horas, vence pronto',
        'Sin horas ni fecha',
    ])
        ->and($props['filters']['sort'])->toBe('logged')
        ->and($props['tasks'][0]['my_last_logged_on'])->toBe('2026-09-22')
        ->and($props['tasks'][3]['my_last_logged_on'])->toBeNull();
});

it('incluye las tareas no asignadas a mí con horas mías de los últimos 30 días y las marca', function () {
    $other = User::factory()->employee()->create(['name' => 'Otra Persona']);
    $logged = Task::factory()->assignedTo($other)->create(['project_id' => $this->project->id, 'title' => 'De otra, con mis horas']);
    $unassigned = Task::factory()->create(['project_id' => $this->project->id, 'title' => 'Sin responsable, con mis horas']);
    $stale = Task::factory()->assignedTo($other)->create(['project_id' => $this->project->id, 'title' => 'Horas de hace dos meses']);
    Task::factory()->assignedTo($other)->create(['project_id' => $this->project->id, 'title' => 'De otra, sin mis horas']);
    ($this->make)('Mía');

    ($this->log)($logged, '2026-09-20');
    ($this->log)($unassigned, '2026-08-24');
    ($this->log)($stale, '2026-07-20');

    $tasks = collect(($this->props)()['tasks'])->keyBy('title');

    expect($tasks->keys()->all())->toBe(['De otra, con mis horas', 'Sin responsable, con mis horas', 'Mía'])
        ->and($tasks['De otra, con mis horas']['assigned_to_me'])->toBeFalse()
        ->and($tasks['De otra, con mis horas']['assignee']['name'])->toBe('Otra Persona')
        ->and($tasks['Sin responsable, con mis horas']['assigned_to_me'])->toBeFalse()
        ->and($tasks['Mía']['assigned_to_me'])->toBeTrue();
});

it('solo enseña tareas abiertas de proyectos sin archivar (también subtareas)', function () {
    $other = User::factory()->employee()->create();
    ($this->make)('Mía');
    Task::factory()->assignedTo($other)->create(['project_id' => $this->project->id, 'title' => 'De otra persona']);
    Task::factory()->completed()->assignedTo($this->user)->create(['project_id' => $this->project->id, 'title' => 'Completada']);
    Task::factory()->assignedTo($this->user)->create(['project_id' => Project::factory()->archived()->create()->id, 'title' => 'Archivada']);
    $parent = Task::factory()->create(['project_id' => $this->project->id, 'title' => 'Padre']);
    Task::factory()->subtaskOf($parent)->assignedTo($this->user)->create(['title' => 'Subtarea mía']);

    expect(($this->titles)())->toEqualCanonicalizing(['Mía', 'Subtarea mía'])
        ->and(($this->titles)('?hechas=1'))->toEqualCanonicalizing(['Mía', 'Subtarea mía', 'Completada']);
});

it('ordena por vencimiento con las secciones de siempre', function () {
    ($this->make)('Vencida', ['due_date' => '2026-09-22']);
    ($this->make)('Vence hoy', ['due_date' => '2026-09-23']);
    ($this->make)('Empieza hoy', ['start_date' => '2026-09-23', 'due_date' => '2026-10-10']);
    ($this->make)('Vence el domingo', ['due_date' => '2026-09-27']);
    ($this->make)('Vence el lunes que viene', ['due_date' => '2026-09-28']);
    ($this->make)('Solo con inicio futuro', ['start_date' => '2026-09-30']);
    ($this->make)('Sin fecha');

    $tasks = ($this->props)('?orden=vencimiento')['tasks'];

    expect(array_map(fn (array $task): array => [$task['section'], $task['title']], $tasks))->toBe([
        [MyTaskSections::OVERDUE, 'Vencida'],
        [MyTaskSections::TODAY, 'Vence hoy'],
        [MyTaskSections::TODAY, 'Empieza hoy'],
        [MyTaskSections::THIS_WEEK, 'Vence el domingo'],
        [MyTaskSections::UPCOMING, 'Vence el lunes que viene'],
        [MyTaskSections::UPCOMING, 'Solo con inicio futuro'],
        [MyTaskSections::NO_DATE, 'Sin fecha'],
    ]);
});

it('usa «hoy» de Madrid aunque en UTC sea otro día', function () {
    // 00:30 del jueves 24 en Madrid = 22:30 del miércoles 23 en UTC.
    $this->travelTo(CarbonImmutable::parse('2026-09-24 00:30:00', 'Europe/Madrid'));
    ($this->make)('Vence el 23', ['due_date' => '2026-09-23']);
    ($this->make)('Vence el 24', ['due_date' => '2026-09-24']);

    $tasks = ($this->props)('?orden=vencimiento')['tasks'];

    expect(array_column($tasks, 'section', 'title'))->toBe([
        'Vence el 23' => MyTaskSections::OVERDUE,
        'Vence el 24' => MyTaskSections::TODAY,
    ])->and(($this->titles)('?vence=hoy'))->toBe(['Vence el 24']);
});

it('ordena por prioridad, proyecto, creación y actualización', function () {
    $zeta = Project::factory()->create(['code' => 'ZETA']);
    $alfa = Project::factory()->create(['code' => 'ALFA']);
    ($this->make)('Baja', ['priority' => 'low', 'project_id' => $zeta->id]);
    $this->travel(1)->minutes();
    ($this->make)('Urgente', ['priority' => 'urgent', 'project_id' => $alfa->id]);
    $this->travel(1)->minutes();
    $normal = ($this->make)('Normal', ['priority' => 'normal']);
    $this->travel(1)->minutes();
    $normal->update(['title' => 'Normal editada']);

    expect(($this->titles)('?orden=prioridad'))->toBe(['Urgente', 'Normal editada', 'Baja'])
        ->and(($this->titles)('?orden=proyecto'))->toBe(['Urgente', 'Normal editada', 'Baja'])
        ->and(($this->titles)('?orden=creacion'))->toBe(['Normal editada', 'Urgente', 'Baja'])
        ->and(($this->titles)('?orden=actualizacion'))->toBe(['Normal editada', 'Urgente', 'Baja'])
        // Un orden que no existe vuelve al de por defecto.
        ->and(($this->props)('?orden=raro')['filters']['sort'])->toBe('logged');
});

it('filtra por texto en el título, el código del proyecto y el cliente', function () {
    $client = Client::factory()->create(['name' => 'Hoteles Mediterráneo']);
    $acme = Project::factory()->create(['code' => 'ACME', 'client_id' => $client->id]);
    ($this->make)('Diseño de la portada');
    ($this->make)('Maquetar fichas', ['project_id' => $acme->id]);

    expect(($this->titles)('?q=portada'))->toBe(['Diseño de la portada'])
        ->and(($this->titles)('?q=acme'))->toBe(['Maquetar fichas'])
        ->and(($this->titles)('?q=Hoteles%20Medit'))->toBe(['Maquetar fichas'])
        ->and(($this->titles)('?q=nada'))->toBe([])
        // Los comodines de LIKE se buscan como texto.
        ->and(($this->titles)('?q=%25'))->toBe([]);
});

it('filtra por proyecto y por cliente (varios)', function () {
    $clientA = Client::factory()->create();
    $clientB = Client::factory()->create();
    $a = Project::factory()->create(['client_id' => $clientA->id]);
    $b = Project::factory()->create(['client_id' => $clientB->id]);
    ($this->make)('En A', ['project_id' => $a->id]);
    ($this->make)('En B', ['project_id' => $b->id]);
    ($this->make)('En Hoteles');

    expect(($this->titles)("?proyecto={$a->id},{$b->id}"))->toEqualCanonicalizing(['En A', 'En B'])
        ->and(($this->titles)("?proyecto[]={$a->id}"))->toBe(['En A'])
        ->and(($this->titles)("?cliente={$clientB->id}"))->toBe(['En B'])
        // Ids que no son números se ignoran.
        ->and(($this->titles)('?proyecto=abc'))->toHaveCount(3);
});

it('filtra por estado (varios), incluye las hechas a petición y por prioridad y tipo', function () {
    $doing = TaskStatus::query()->where('name', 'En curso')->sole();
    $done = TaskStatus::query()->where('category', 'done')->sole();
    $design = TaskType::factory()->create(['name' => 'Diseño']);
    ($this->make)('Por hacer');
    ($this->make)('En curso', ['status_id' => $doing->id, 'priority' => 'high', 'task_type_id' => $design->id]);
    Task::factory()->completed()->assignedTo($this->user)->create(['project_id' => $this->project->id, 'title' => 'Hecha']);

    expect(($this->titles)("?estado={$doing->id}"))->toBe(['En curso'])
        // Elegir un estado «hecho» ya incluye las completadas.
        ->and(($this->titles)("?estado={$done->id}"))->toBe(['Hecha'])
        ->and(($this->titles)('?prioridad=high'))->toBe(['En curso'])
        ->and(($this->titles)('?prioridad=inventada'))->toHaveCount(2)
        ->and(($this->titles)("?tipo={$design->id}"))->toBe(['En curso']);
});

it('filtra por vencimiento: vencidas, hoy, esta semana, sin fecha y rango', function () {
    ($this->make)('Vencida', ['due_date' => '2026-09-20']);
    ($this->make)('Hoy', ['due_date' => '2026-09-23']);
    ($this->make)('Domingo', ['due_date' => '2026-09-27']);
    ($this->make)('Octubre', ['due_date' => '2026-10-15']);
    ($this->make)('Sin fecha');

    expect(($this->titles)('?vence=vencidas'))->toBe(['Vencida'])
        ->and(($this->titles)('?vence=hoy'))->toBe(['Hoy'])
        ->and(($this->titles)('?vence=semana&orden=vencimiento'))->toBe(['Hoy', 'Domingo'])
        ->and(($this->titles)('?vence=sin_fecha'))->toBe(['Sin fecha'])
        ->and(($this->titles)('?vence=rango&desde=2026-09-27&hasta=2026-10-15&orden=vencimiento'))->toBe(['Domingo', 'Octubre'])
        ->and(($this->titles)('?vence=rango&desde=2026-10-01&orden=vencimiento'))->toBe(['Octubre'])
        // Las fechas al revés se ordenan; las que no son fechas se ignoran.
        ->and(($this->titles)('?vence=rango&desde=2026-10-15&hasta=2026-09-27&orden=vencimiento'))->toBe(['Domingo', 'Octubre'])
        ->and(($this->props)('?vence=rango&desde=ayer')['filters']['from'])->toBeNull();
});

it('pagina por cursor de 50 en 50 sin repetir ni saltarse tareas', function () {
    foreach (range(1, 120) as $i) {
        ($this->make)(sprintf('Tarea %03d', $i), ['due_date' => CarbonImmutable::parse('2026-09-01')->addDays($i % 40)->toDateString()]);
    }
    foreach (Task::query()->orderBy('id')->limit(30)->get() as $index => $task) {
        ($this->log)($task, CarbonImmutable::parse('2026-09-22')->subDays($index % 5)->toDateString());
    }

    foreach (['', '?orden=vencimiento', '?orden=prioridad', '?orden=proyecto', '?orden=creacion', '?orden=actualizacion'] as $query) {
        $seen = [];
        $first = ($this->props)($query);
        $seen = [...$seen, ...array_column($first['tasks'], 'id')];
        expect($first['tasks'])->toHaveCount(MyTaskList::PER_PAGE)->and($first['cursor'])->toBeNull();

        $cursor = $first['next_cursor'];
        $pages = 1;
        while ($cursor !== null) {
            $separator = $query === '' ? '?' : '&';
            $page = ($this->props)($query.$separator.'cursor='.urlencode($cursor));
            expect($page['cursor'])->toBe($cursor);
            $seen = [...$seen, ...array_column($page['tasks'], 'id')];
            $cursor = $page['next_cursor'];
            $pages++;
        }

        expect($pages)->toBe(3)
            ->and($seen)->toHaveCount(120)
            ->and(array_unique($seen))->toHaveCount(120);
    }
});

it('ignora un cursor manipulado o de otro orden', function () {
    foreach (range(1, 60) as $i) {
        ($this->make)("Tarea {$i}");
    }
    $next = ($this->props)()['next_cursor'];
    $forged = base64_encode((string) json_encode(['sort_logged_on' => "x' OR 1=1", 'sort_logged_at' => '1900', 'sort_due' => '9999-12-31', 'id' => 1, '_pointsToNextItems' => true]));

    expect(($this->props)('?orden=prioridad&cursor='.urlencode((string) $next))['cursor'])->toBeNull()
        ->and(($this->props)('?cursor='.urlencode($forged))['cursor'])->toBeNull()
        ->and(($this->props)('?cursor=basura')['tasks'])->toHaveCount(MyTaskList::PER_PAGE);
});

it('incluye proyecto, bolsa, tarea padre y las opciones de los filtros', function () {
    $client = Client::factory()->create(['name' => 'ACME S.L.']);
    $project = Project::factory()->hourBank()->create(['code' => 'ACME', 'client_id' => $client->id]);
    $bank = HourBank::factory()->create(['project_id' => $project->id, 'name' => 'Bolsa Q4']);
    Task::factory()->inBank($bank)->assignedTo($this->user)->create(['title' => 'En bolsa']);
    ($this->make)('En Hoteles');

    $this->actingAs($this->user)->get('/mis-tareas?q=bolsa')
        ->assertInertia(fn (Assert $page) => $page
            ->component('my-tasks/index')
            ->where('today', '2026-09-23')
            ->where('filters.q', 'bolsa')
            ->where('tasks.0.title', 'En bolsa')
            ->where('tasks.0.project.code', 'ACME')
            ->where('tasks.0.hour_bank.name', 'Bolsa Q4')
            ->where('tasks.0.parent', null)
            ->has('statuses', 5)
            ->has('options.projects', 2)
            ->where('options.clients.0.name', 'ACME S.L.')
            ->where('options.priorities', ['low', 'normal', 'high', 'urgent']));
});

it('un colaborador externo solo ve las de sus proyectos, sin horas de todos', function () {
    $sara = User::factory()->collaborator()->create();
    $own = Project::factory()->withMembers([$sara])->create(['code' => 'FARO']);
    $foreign = Project::factory()->create(['code' => 'NIEBLA']);
    $mine = Task::factory()->assignedTo($sara)->create(['project_id' => $own->id, 'title' => 'Faro']);
    Task::factory()->assignedTo($sara)->create(['project_id' => $foreign->id, 'title' => 'Niebla']);
    ($this->log)($mine, '2026-09-22', $sara);

    $props = $this->actingAs($sara)->get('/mis-tareas')->assertOk()->viewData('page')['props'];

    expect(array_column($props['tasks'], 'title'))->toBe(['Faro'])
        ->and($props['tasks'][0]['logged_minutes'])->toBeNull()
        ->and(array_column($props['options']['projects'], 'code'))->toBe(['FARO']);
});

it('cabe en su presupuesto de consultas y no hace N+1', function () {
    $count = function (string $query = ''): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->user)->get('/mis-tareas'.$query)->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };
    $seed = function (int $count): void {
        foreach (range(1, $count) as $i) {
            $client = Client::factory()->create();
            $bank = HourBank::factory()->create(['project_id' => Project::factory()->hourBank()->create(['client_id' => $client->id])->id]);
            $parent = Task::factory()->inBank($bank)->create(['assignee_user_id' => User::factory()->employee()->create()->id]);
            $task = Task::factory()->subtaskOf($parent)->assignedTo($this->user)->create(['due_date' => '2026-09-2'.($i % 9)]);
            ($this->log)($task, '2026-09-2'.($i % 3));
            ($this->log)($parent, '2026-09-1'.($i % 9));
        }
    };

    $count();
    $seed(2);
    $few = $count();
    $seed(6);

    expect($count())->toBe($few)
        ->and($count('?orden=vencimiento&q=a&vence=semana'))->toBe($few)
        // Presupuesto: sesión y props compartidas, la página, sus 4 relaciones y la suma de horas
        // (en la misma consulta), estados, opciones (proyectos con su cliente) y tipos.
        ->and($few)->toBeLessThanOrEqual(16);
});
