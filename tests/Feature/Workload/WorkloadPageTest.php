<?php

use App\Domain\Workload\WorkloadBoard;
use App\Models\Absence;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkSchedule;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Workload\Concerns\BuildsWorkloadScenario;

/*
| Vista «Carga» (SPEC §9, D-051, D-052): matriz por rol, horizontes, totales por departamento,
| motivo de los días grises, filtros y bandejas. Escenario en Concerns\BuildsWorkloadScenario.
*/

pest()->use(BuildsWorkloadScenario::class);

beforeEach(function () {
    $this->buildWorkloadScenario();

    $this->page = function (string $who, string $query = ''): array {
        $props = [];

        $this->actingAs($this->people[$who])
            ->get('/carga'.($query === '' ? '' : '?'.$query))
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$props) {
                $page->component('workload/index', false);
                $props = $page->toArray()['props'];
            });

        return $props;
    };
});

describe('quién ve qué (D-052)', function () {
    it('el admin ve a todas las personas internas activas, agrupadas por departamento y con «Sin departamento» al final', function () {
        $props = ($this->page)('ana');

        expect($this->workloadRowNames($props['matrix']))->toBe([
            'Marta Iglesias', 'Pablo Ruiz', 'Sergio Gómez',
            'Elena Empleada', 'Lucía Martín', 'Raúl Responsable',
            'Ana Admin',
        ])
            ->and(array_column(array_column($props['matrix']['groups'], 'department'), 'name'))->toBe(['Desarrollo', 'Diseño', null])
            ->and($props['filters']['sees_team'])->toBeTrue()
            ->and($props['filters']['sees_unassigned'])->toBeTrue()
            ->and(array_column($props['options']['departments'], 'name'))->toBe(['Desarrollo', 'Diseño'])
            ->and(array_column($props['people'], 'name'))->not->toContain('Olga Baja', 'Cliente Portal');
    });

    it('un responsable ve su departamento y a sí mismo', function () {
        $props = ($this->page)('raul');

        expect($this->workloadRowNames($props['matrix']))->toBe(['Elena Empleada', 'Lucía Martín', 'Raúl Responsable'])
            ->and(array_column($props['options']['departments'], 'name'))->toBe(['Diseño'])
            ->and(array_column($props['people'], 'name'))->toBe(['Elena Empleada', 'Lucía Martín', 'Raúl Responsable']);
    });

    it('un responsable que no pertenece a su departamento se ve a sí mismo aparte', function () {
        $this->people['raul']->forceFill(['department_id' => null])->save();

        $props = ($this->page)('raul');

        expect($this->workloadRowNames($props['matrix']))->toBe(['Elena Empleada', 'Lucía Martín', 'Raúl Responsable'])
            ->and(array_column(array_column($props['matrix']['groups'], 'department'), 'name'))->toBe(['Diseño', null]);
    });

    it('un empleado solo ve su fila, sin filtros de persona ni de departamento ni bandeja «Sin asignar»', function () {
        $lucia = $this->people['lucia']->id;
        $props = ($this->page)('elena', "persona[]={$lucia}&departamento[]={$this->departments['dev']->id}");

        expect($this->workloadRowNames($props['matrix']))->toBe(['Elena Empleada'])
            ->and($props['filters']['sees_team'])->toBeFalse()
            ->and($props['filters']['query'])->toBe(['horizonte' => 'semana-que-viene'])
            ->and($props['options']['departments'])->toBe([])
            ->and(array_column($props['people'], 'name'))->toBe(['Elena Empleada'])
            ->and($props['trays']['unassigned']['visible'])->toBeFalse()
            ->and($props['trays']['unassigned']['groups'])->toBe([]);
    });

    it('un gestor de proyecto que es empleado también ve solo su fila', function () {
        $props = ($this->page)('sergio');

        expect($this->workloadRowNames($props['matrix']))->toBe(['Sergio Gómez']);
    });

    it('los clientes y los invitados no entran', function () {
        $this->actingAs($this->people['client'])->get('/carga')->assertRedirect(route('portal.home'));
        auth()->logout();
        $this->get('/carga')->assertRedirect(route('login'));
    });
});

describe('horizontes', function () {
    it('por defecto abre la semana que viene, sin el fin de semana si nadie trabaja', function () {
        $props = ($this->page)('ana');

        expect($props['horizon'])->toMatchArray(['key' => 'semana-que-viene', 'from' => '2026-10-12', 'to' => '2026-10-18', 'by_week' => false, 'today' => '2026-10-06'])
            ->and(array_column($props['matrix']['columns'], 'key'))->toBe(['2026-10-12', '2026-10-13', '2026-10-14', '2026-10-15', '2026-10-16']);
    });

    it('la semana actual va de hoy al domingo y marca hoy', function () {
        $props = ($this->page)('ana', 'horizonte=semana-actual');

        expect(array_column($props['matrix']['columns'], 'key'))->toBe(['2026-10-06', '2026-10-07', '2026-10-08', '2026-10-09'])
            ->and($props['matrix']['columns'][0]['today'])->toBeTrue()
            ->and($props['matrix']['columns'][1]['today'])->toBeFalse();
    });

    it('las próximas 4 semanas van de hoy al domingo de la cuarta semana, por días', function () {
        $props = ($this->page)('ana', 'horizonte=4-semanas');

        expect($props['horizon'])->toMatchArray(['from' => '2026-10-06', 'to' => '2026-11-01', 'by_week' => false])
            ->and($props['matrix']['columns'])->toHaveCount(4 + 3 * 5);
    });

    it('los próximos 3 meses van por semanas (13), la primera desde hoy', function () {
        $props = ($this->page)('ana', 'horizonte=3-meses');
        $columns = $props['matrix']['columns'];

        expect($props['horizon'])->toMatchArray(['from' => '2026-10-06', 'to' => '2027-01-03', 'by_week' => true])
            ->and($columns)->toHaveCount(13)
            ->and($columns[0])->toMatchArray(['from' => '2026-10-06', 'to' => '2026-10-11', 'today' => true])
            ->and($columns[1])->toMatchArray(['from' => '2026-10-12', 'to' => '2026-10-18', 'today' => false])
            ->and($columns[12]['to'])->toBe('2027-01-03')
            // Semana del 12/10 de Elena: 30 h planificadas frente a 32 h (el lunes es festivo).
            ->and($this->workloadCell($props['matrix'], 'elena', '2026-10-12'))->toMatchArray(['planned' => 1800, 'capacity' => 1920, 'reduced' => ['holidays' => 1, 'absence_days' => 0, 'partial_minutes' => 0, 'absence_label' => null]]);
    });

    it('un horizonte desconocido abre el de por defecto', function () {
        expect(($this->page)('ana', 'horizonte=siempre')['horizon']['key'])->toBe('semana-que-viene');
    });

    it('un domingo, la semana actual enseña el domingo (no laborable) en vez de quedarse sin columnas', function () {
        $this->travelTo(CarbonImmutable::parse('2026-10-11 10:00', 'Europe/Madrid'));

        $matrix = ($this->page)('sergio', 'horizonte=semana-actual')['matrix'];

        expect($matrix['columns'])->toBe([['key' => '2026-10-11', 'from' => '2026-10-11', 'to' => '2026-10-11', 'today' => true, 'weekend' => true]])
            ->and($this->workloadCell($matrix, 'sergio', '2026-10-11'))->toMatchArray(['planned' => 0, 'capacity' => 0, 'reason' => ['type' => 'off', 'label' => null]]);
    });

    it('un sábado, la semana actual enseña el sábado y el domingo', function () {
        $this->travelTo(CarbonImmutable::parse('2026-10-10 18:00', 'Europe/Madrid'));

        $columns = ($this->page)('sergio', 'horizonte=semana-actual')['matrix']['columns'];

        expect(array_column($columns, 'key'))->toBe(['2026-10-10', '2026-10-11'])
            ->and(array_column($columns, 'weekend'))->toBe([true, true]);
    });

    it('enseña el sábado si alguien de las filas trabaja ese día', function () {
        WorkSchedule::factory()->for($this->people['pablo'])->create(['valid_from' => '2026-01-01', 'sat_minutes' => 240]);

        expect(array_column(($this->page)('ana')['matrix']['columns'], 'key'))->toContain('2026-10-17')
            ->and(array_column(($this->page)('raul')['matrix']['columns'], 'key'))->not->toContain('2026-10-17');
    });
});

describe('celdas y totales', function () {
    it('cada celda lleva lo planificado frente a la capacidad; cada persona, su total', function () {
        $matrix = ($this->page)('ana')['matrix'];

        expect($this->workloadCell($matrix, 'elena', '2026-10-13'))->toBe(['planned' => 600, 'capacity' => 480, 'reason' => null, 'reduced' => null, 'overdue' => false])
            ->and($this->workloadCell($matrix, 'elena', '2026-10-15'))->toMatchArray(['planned' => 300, 'capacity' => 480])
            ->and($this->workloadCell($matrix, 'lucia', '2026-10-15'))->toMatchArray(['planned' => 240, 'capacity' => 480])
            ->and($this->workloadCell($matrix, 'pablo', '2026-10-13'))->toMatchArray(['planned' => 240, 'capacity' => 480]);

        $design = collect($matrix['groups'])->firstWhere('department.name', 'Diseño');
        $elena = collect($design['people'])->firstWhere('name', 'Elena Empleada');

        expect($elena['total'])->toBe(['planned' => 1800, 'capacity' => 1920]);
    });

    it('totales por departamento y día, y de toda la vista', function () {
        $matrix = ($this->page)('ana')['matrix'];
        $design = collect($matrix['groups'])->firstWhere('department.name', 'Diseño');
        $dev = collect($matrix['groups'])->firstWhere('department.name', 'Desarrollo');

        // 13/10: Elena 10 h (8 h de capacidad) + Lucía de vacaciones + Raúl sin carga (8 h).
        expect($design['totals'][1])->toBe(['planned' => 600, 'capacity' => 960])
            ->and($design['totals'][0])->toBe(['planned' => 0, 'capacity' => 0])
            // Elena 1800 / 1920 + Lucía 480 / 960 + Raúl 0 / 1920.
            ->and($design['total'])->toBe(['planned' => 2280, 'capacity' => 4800])
            // Pablo 960 / 1680 (4 h de formación el 15) + Marta y Sergio sin carga (1920 cada uno).
            ->and($dev['total'])->toBe(['planned' => 960, 'capacity' => 5520])
            ->and($matrix['totals'][1])->toBe(['planned' => 840, 'capacity' => 2880])
            ->and($matrix['total'])->toBe(['planned' => 3240, 'capacity' => 12240]);
    });

    it('los días grises explican el motivo: festivo, ausencia o día no laborable; las ausencias parciales, lo que restan', function () {
        WorkSchedule::factory()->for($this->people['sergio'])->create(['valid_from' => '2026-01-01', 'fri_minutes' => 0]);
        $matrix = ($this->page)('ana')['matrix'];

        expect($this->workloadCell($matrix, 'elena', '2026-10-12'))->toMatchArray(['planned' => 0, 'capacity' => 0, 'reason' => ['type' => 'holiday', 'label' => 'Fiesta Nacional de España']])
            ->and($this->workloadCell($matrix, 'lucia', '2026-10-13'))->toMatchArray(['capacity' => 0, 'reason' => ['type' => 'absence', 'label' => 'Vacaciones']])
            ->and($this->workloadCell($matrix, 'sergio', '2026-10-16'))->toMatchArray(['capacity' => 0, 'reason' => ['type' => 'off', 'label' => null]])
            ->and($this->workloadCell($matrix, 'pablo', '2026-10-15'))->toMatchArray([
                'planned' => 240,
                'capacity' => 240,
                'reason' => null,
                'reduced' => ['holidays' => 0, 'absence_days' => 0, 'partial_minutes' => 240, 'absence_label' => 'Formación externa'],
            ]);
    });

    it('una semana entera de vacaciones sale gris con su motivo en el horizonte de 3 meses', function () {
        Absence::factory()->approved()->between('2026-10-19', '2026-10-25')->create(['user_id' => $this->people['raul']->id]);

        $matrix = ($this->page)('ana', 'horizonte=3-meses')['matrix'];

        expect($this->workloadCell($matrix, 'raul', '2026-10-19'))->toMatchArray(['planned' => 0, 'capacity' => 0, 'reason' => ['type' => 'absence', 'label' => 'Vacaciones']])
            ->and($this->workloadCell($matrix, 'lucia', '2026-10-12'))->toMatchArray(['capacity' => 960, 'reduced' => ['holidays' => 1, 'absence_days' => 2, 'partial_minutes' => 0, 'absence_label' => 'Vacaciones']]);
    });

    it('la celda de hoy avisa de que lleva tareas vencidas', function () {
        $matrix = ($this->page)('ana', 'horizonte=semana-actual')['matrix'];

        expect($this->workloadCell($matrix, 'elena', '2026-10-06'))->toMatchArray(['planned' => 300, 'overdue' => true])
            ->and($this->workloadCell($matrix, 'lucia', '2026-10-06'))->toMatchArray(['planned' => 0, 'overdue' => false]);
    });

    it('la persona que mira va marcada y cada persona lleva su carga del horizonte para elegir a quién asignar', function () {
        $props = ($this->page)('raul');

        expect(collect($props['people'])->firstWhere('name', 'Elena Empleada'))->toMatchArray(['planned' => 1800, 'capacity' => 1920, 'department' => 'Diseño', 'is_me' => false])
            ->and(collect($props['people'])->firstWhere('name', 'Raúl Responsable')['is_me'])->toBeTrue();
    });
});

describe('filtros', function () {
    it('departamento y persona acotan las filas', function () {
        $byDepartment = ($this->page)('ana', "departamento[]={$this->departments['dev']->id}");
        $byPerson = ($this->page)('ana', "persona[]={$this->people['lucia']->id}&persona[]={$this->people['pablo']->id}");

        expect($this->workloadRowNames($byDepartment['matrix']))->toBe(['Marta Iglesias', 'Pablo Ruiz', 'Sergio Gómez'])
            ->and($byDepartment['filters']['query'])->toBe(['horizonte' => 'semana-que-viene', 'departamento' => [$this->departments['dev']->id]])
            ->and($this->workloadRowNames($byPerson['matrix']))->toBe(['Pablo Ruiz', 'Lucía Martín']);
    });

    it('cliente y proyecto acotan las tareas que cuentan', function () {
        $byProject = ($this->page)('ana', "proyecto[]={$this->projects['web']->id}")['matrix'];
        $byClient = ($this->page)('ana', "cliente[]={$this->projects['app']->client_id}")['matrix'];

        expect($this->workloadCell($byProject, 'elena', '2026-10-13')['planned'])->toBe(300)
            ->and($this->workloadCell($byProject, 'pablo', '2026-10-13')['planned'])->toBe(0)
            ->and($this->workloadCell($byClient, 'elena', '2026-10-13')['planned'])->toBe(300)
            ->and($this->workloadCell($byClient, 'pablo', '2026-10-13')['planned'])->toBe(240);
    });

    it('ofrece los clientes y proyectos no archivados', function () {
        $options = ($this->page)('elena')['options'];

        expect(array_column($options['projects'], 'name'))->toBe(['APP · App de citas', 'WEB · Web corporativa'])
            ->and(array_column($options['clients'], 'name'))->toBe(['Acme', 'Beta']);
    });
});

describe('bandejas', function () {
    it('«Sin planificar» lleva las tareas de las filas sin estimación o sin entrega, con lo que les falta', function () {
        $trays = ($this->page)('ana')['trays'];

        expect($trays['unplanned']['total'])->toBe(2)
            ->and(collect($trays['unplanned']['tasks'])->map(fn (array $task) => [$task['title'], $task['assignee']['name'], $task['missing']])->all())->toBe([
                ['Textos legales', 'Elena Empleada', ['estimate']],
                ['Notificaciones push', 'Pablo Ruiz', ['due_date']],
            ]);
    });

    it('«Sin planificar» del responsable solo lleva las de su equipo', function () {
        $trays = ($this->page)('raul')['trays'];

        expect(array_column($trays['unplanned']['tasks'], 'title'))->toBe(['Textos legales']);
    });

    it('«Sin asignar» va por departamento (el del tipo o la bolsa), con las vencidas señaladas', function () {
        $trays = ($this->page)('ana')['trays'];
        $groups = $trays['unassigned']['groups'];

        expect($trays['unassigned']['total'])->toBe(3)
            ->and(array_column(array_column($groups, 'department'), 'name'))->toBe(['Desarrollo', 'Diseño', null])
            ->and($groups[0]['tasks'][0])->toMatchArray(['title' => 'Corregir login', 'overdue' => true, 'remaining_minutes' => 180])
            ->and($groups[0]['remaining_minutes'])->toBe(180)
            ->and($groups[1]['tasks'][0])->toMatchArray(['title' => 'Banner de campaña', 'overdue' => false]);
    });

    it('un responsable ve «Sin asignar» solo de sus departamentos y puede asignarlas a su equipo', function () {
        $trays = ($this->page)('raul')['trays'];
        $task = $trays['unassigned']['groups'][0]['tasks'][0];

        expect(array_column(array_column($trays['unassigned']['groups'], 'department'), 'name'))->toBe(['Diseño'])
            ->and($task['title'])->toBe('Banner de campaña')
            ->and($task['can_edit'])->toBeTrue()
            ->and($task['assignee_ids'])->toEqualCanonicalizing([$this->people['elena']->id, $this->people['lucia']->id, $this->people['raul']->id]);
    });

    it('las tareas «cajón» de los proyectos internos (sin estimación) no van a las bandejas; las internas con estimación, sí', function () {
        $internal = Project::factory()->internal()->create(['code' => 'INTERNO']);
        Task::factory()->create(['project_id' => $internal->id, 'assignee_user_id' => null, 'title' => 'Reuniones', 'estimated_minutes' => null, 'due_date' => null]);
        Task::factory()->create(['project_id' => $internal->id, 'assignee_user_id' => $this->people['elena']->id, 'title' => 'Formación', 'estimated_minutes' => null, 'due_date' => null]);
        Task::factory()->create(['project_id' => $internal->id, 'assignee_user_id' => null, 'title' => 'Preparar el taller', 'estimated_minutes' => 240, 'due_date' => '2026-10-20']);

        $trays = ($this->page)('ana')['trays'];
        $unassigned = collect($trays['unassigned']['groups'])->flatMap(fn (array $group) => array_column($group['tasks'], 'title'))->all();

        expect($unassigned)->toContain('Preparar el taller')
            ->not->toContain('Reuniones')
            ->and($trays['unassigned']['total'])->toBe(4)
            ->and(array_column($trays['unplanned']['tasks'], 'title'))->not->toContain('Formación')
            ->and($trays['unplanned']['total'])->toBe(2);
    });

    it('las bandejas ordenan antes de cortar: una vencida nunca se queda fuera aunque haya más de las que se pintan', function () {
        $limit = WorkloadBoard::TRAY_LIMIT;
        $designType = $this->tasks['unassigned_design']->task_type_id;
        $unassigned = fn (string $title, string $due) => Task::factory()->create(['project_id' => $this->projects['web']->id, 'assignee_user_id' => null, 'task_type_id' => $designType, 'title' => $title, 'estimated_minutes' => 60, 'due_date' => $due]);

        // Más de las que se pintan, con entrega en noviembre: sin estimar de Elena y sin asignar de
        // Diseño. Las vencidas, de otra persona (Pablo) y creadas antes y después: sea cual sea el orden
        // en que las devuelva la base de datos, sin ordenar antes alguna quedaría detrás del corte.
        $unassigned('Banner vencido antes', '2026-10-03');
        Task::factory()->count($limit + 5)->create(['project_id' => $this->projects['web']->id, 'assignee_user_id' => null, 'task_type_id' => $designType, 'estimated_minutes' => 60, 'due_date' => '2026-11-20']);
        Task::factory()->count($limit + 5)->create(['project_id' => $this->projects['web']->id, 'assignee_user_id' => $this->people['elena']->id, 'estimated_minutes' => null, 'due_date' => '2026-11-20']);
        $unassigned('Banner vencido después', '2026-10-04');
        Task::factory()->create(['project_id' => $this->projects['web']->id, 'assignee_user_id' => $this->people['pablo']->id, 'title' => 'Vencida de Pablo', 'estimated_minutes' => null, 'due_date' => '2026-10-02']);

        $trays = ($this->page)('ana')['trays'];
        $design = collect($trays['unassigned']['groups'])->firstWhere('department.name', 'Diseño');
        $shownUnassigned = array_sum(array_map(fn (array $group): int => count($group['tasks']), $trays['unassigned']['groups']));

        expect($trays['unplanned']['total'])->toBe($limit + 5 + 3)
            ->and($trays['unplanned']['tasks'])->toHaveCount($limit)
            ->and(array_column(array_slice($trays['unplanned']['tasks'], 0, 2), 'title'))->toBe(['Vencida de Pablo', 'Textos legales'])
            ->and($trays['unplanned']['tasks'][0]['overdue'])->toBeTrue()
            ->and($trays['unassigned']['total'])->toBe($limit + 5 + 5)
            ->and($shownUnassigned)->toBe($limit)
            ->and($design['total'])->toBe($limit + 5 + 3)
            ->and(array_column(array_slice($design['tasks'], 0, 3), 'title'))->toBe(['Banner vencido antes', 'Banner vencido después', 'Banner de campaña'])
            ->and(collect($trays['unassigned']['groups'])->firstWhere('department.name', 'Desarrollo')['tasks'][0]['title'])->toBe('Corregir login');
    });

    it('con filtro de departamento, «Sin asignar» solo lleva ese departamento', function () {
        $trays = ($this->page)('ana', "departamento[]={$this->departments['design']->id}")['trays'];

        expect(array_column(array_column($trays['unassigned']['groups'], 'department'), 'name'))->toBe(['Diseño']);
    });
});

it('el menú lleva a la vista y la página ya no es la provisional', function () {
    $this->actingAs(User::query()->findOrFail($this->people['elena']->id))
        ->get(route('workload.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('workload/index')->missing('section'));
});
