<?php

use App\Enums\Role;
use App\Enums\TimeEntryStatus;
use App\Enums\TimesheetStatus;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Attachment;
use App\Models\Client;
use App\Models\CommentReaction;
use App\Models\Department;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\TimeEntry;
use App\Models\TimesheetPeriod;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Rendimiento de las páginas de la Fase 1 con datos realistas (DemoDataSeeder: 12 meses,
| ~9.000 entradas de horas, 15 proyectos, 11 bolsas).
|--------------------------------------------------------------------------
| Para cada página y endpoint JSON, y para admin, un responsable (Raúl, Diseño) y una empleada
| (Elena, Diseño):
|   1. el número de consultas cabe en un presupuesto por página (medido con DB::listen, con la
|      caché ya caliente como en producción: una petición de calentamiento antes de medir),
|   2. ninguna consulta se repite más de PERF_MAX_REPEATS veces (el síntoma de un N+1: la misma
|      SQL con distintos parámetros, una vez por fila),
|   3. el número de consultas NO crece con los datos: se añaden más proyectos, tareas, subtareas,
|      comentarios con reacciones y adjuntos, seguidores, horas, semanas enviadas, clientes y
|      notificaciones (dos rondas) y cada página debe hacer las mismas consultas (salvo
|      PERF_GROWTH_TOLERANCE, por las consultas de nombres de la actividad).
| El tercer punto es el que detecta un N+1 aunque los datos de ejemplo tengan pocas filas.
|
| PERF_REPORT=1 php -d memory_limit=1G vendor/bin/pest tests/Feature/Performance
|   imprime la tabla de consultas y tiempos (locales y orientativos) de cada página.
*/

const PERF_MAX_REPEATS = 4;

/**
 * Consultas de más que se toleran al crecer los datos. La actividad (resumen del proyecto y panel
 * de la tarea) carga a quien hizo cada cambio (una consulta por tipo de causer, si hay alguno) y
 * traduce ids a nombres con una consulta por tipo citado (estados, personas, bolsas, tipos y
 * proyectos, TaskActivityFeed): según qué cambios caigan entre los 50 últimos hay una más o una
 * menos. Cada ronda añade al menos 4 filas de cada relación, así que un N+1 crece más que esto.
 */
const PERF_GROWTH_TOLERANCE = 2;

/**
 * Presupuesto de consultas por página: lo medido con los datos de ejemplo y la caché caliente
 * (el máximo de los tres roles) más 3. Incluye las 2-3 consultas de las props
 * compartidas (temporizador, notificaciones sin leer y, si no es admin, si gestiona algún proyecto).
 *
 * @var array<string, int>
 */
const PERF_BUDGETS = [
    // Inicio suma «Mis próximos hitos» (F4): una consulta más. Con las Fases 2 y 3 juntas, la capacidad
    // descuenta festivos y ausencias y la clave de la caché de informes lleva el alcance de quien mira
    // (SEC-03): 2 consultas más, fijas, no por fila.
    // Al unir la Fase 6 con las 2 a 5, Inicio, Ajustes del proyecto y la ficha del cliente ya no
    // tienen margen para la consulta fija del chat: el total de mensajes sin leer de la prop
    // compartida `chat.unread` (una por página, no por fila).
    'home' => 16,
    'projects.index' => 13,
    'projects.create' => 9,
    'projects.show' => 23,
    'projects.settings' => 15,
    'projects.tasks' => 23,
    'projects.tasks.kanban' => 23,
    'projects.tasks.completed' => 22,
    'projects.tasks.panel' => 36,
    'projects.tasks.move-targets' => 14,
    'projects.files' => 13,
    'projects.time' => 21,
    'projects.hour-banks.index' => 17,
    'projects.hour-banks.show' => 34,
    'hour-banks.index' => 16,
    // + el responsable y el equipo de cada fila con la Weekly (D-232): proyectos con sus miembros,
    // suscripciones y personas, fijas para la página.
    'clients.index' => 10,
    'clients.show' => 16,
    'clients.options' => 7,
    // Mis tareas con filtros (D-143): + la tarea responsable, las opciones (proyectos con su cliente y
    // tipos) y los estados ya no en la caché del listado; fijas, no por fila (12 medidas + 3).
    'my-tasks.index' => 15,
    // La vista «Por días» (D-321) pide los festivos y ausencias de la semana: horarios, festivos y
    // ausencias de la persona, 3 consultas fijas (20 medidas + 3). La de otra persona ya tenía margen.
    'time.index' => 23,
    'time.index.person' => 25,
    'time.approvals.index' => 18,
    'time.locks.index' => 11,
    'time.locks.preview' => 18,
    'time.tasks' => 9,
    'time.tasks.search' => 9,
    'time.options' => 8,
    'notifications.index' => 8,
    'notifications.recent' => 8,
    'search' => 12,
    'admin.users.index' => 10,
    'admin.users.edit' => 14,
    'admin.users.deactivation' => 13,
    'admin.departments.index' => 10,
    'admin.task-types.index' => 7,
    'admin.statuses.index' => 6,
    'admin.settings.edit' => 6,
    'tasks.show' => 7,
];

/**
 * Páginas y endpoints GET de la Fase 1: etiqueta → [URL, cabeceras (recarga parcial de Inertia)].
 *
 * @return array<string, array{0: string, 1: array<string, string>}>
 */
function perfPages(): array
{
    $project = Project::query()->where('code', 'ARR-WEB')->sole();
    $bank = HourBank::query()->where('project_id', $project->id)->orderBy('id')->firstOrFail();
    $client = Client::query()->where('name', 'Bodegas Arrieta')->sole();
    $task = perfPanelTask();
    $elena = User::query()->where('email', 'empleado@example.com')->sole();
    $p = $project->id;
    $from = now()->subYear()->toDateString();
    $to = now()->toDateString();

    return [
        'home' => ['/', []],
        'projects.index' => ['/proyectos', []],
        'projects.create' => ['/proyectos/nuevo', []],
        'projects.show' => ["/proyectos/{$p}", []],
        'projects.settings' => ["/proyectos/{$p}/ajustes", []],
        'projects.tasks' => ["/proyectos/{$p}/tareas", []],
        'projects.tasks.kanban' => ["/proyectos/{$p}/tareas?vista=kanban", []],
        'projects.tasks.completed' => ["/proyectos/{$p}/tareas?completadas=1&agrupar=assignee", []],
        'projects.tasks.panel' => ["/proyectos/{$p}/tareas?tarea={$task->id}", perfPartial('projects/tasks', 'panel')],
        'projects.tasks.move-targets' => ["/proyectos/{$p}/tareas", perfPartial('projects/tasks', 'moveTargets')],
        'projects.files' => ["/proyectos/{$p}/archivos", []],
        'projects.time' => ["/proyectos/{$p}/horas", []],
        'projects.hour-banks.index' => ["/proyectos/{$p}/bolsas", []],
        'projects.hour-banks.show' => ["/proyectos/{$p}/bolsas/{$bank->id}", []],
        'hour-banks.index' => ['/bolsas', []],
        'clients.index' => ['/clientes', []],
        'clients.show' => ["/clientes/{$client->id}", []],
        'clients.options' => ['/clientes/opciones', []],
        'my-tasks.index' => ['/mis-tareas', []],
        'time.index' => ['/horas', []],
        'time.index.person' => ["/horas?persona={$elena->id}", []],
        'time.approvals.index' => ['/horas/aprobaciones', []],
        'time.locks.index' => ['/horas/bloqueo', []],
        'time.locks.preview' => ["/horas/bloqueo/vista-previa?project_id={$p}&date_from={$from}&date_to={$to}", []],
        'time.tasks' => ['/horas/tareas', []],
        'time.tasks.search' => ['/horas/tareas?q=dis', []],
        'time.options' => ['/horas/opciones', []],
        'notifications.index' => ['/notificaciones', []],
        'notifications.recent' => ['/notificaciones/recientes', []],
        'search' => ['/buscar?q=web', []],
        'admin.users.index' => ['/admin/usuarios', []],
        'admin.users.edit' => ["/admin/usuarios/{$elena->id}", []],
        'admin.users.deactivation' => ["/admin/usuarios/{$elena->id}/baja", []],
        'admin.departments.index' => ['/admin/departamentos', []],
        'admin.task-types.index' => ['/admin/tipos-de-tarea', []],
        'admin.statuses.index' => ['/admin/estados', []],
        'admin.settings.edit' => ['/admin/ajustes', []],
        'tasks.show' => ["/tareas/{$task->id}", []],
    ];
}

/**
 * La tarea cuyo panel se mide: la primera tarea raíz de ARR-WEB («Diseño de la home»).
 */
function perfPanelTask(): Task
{
    return Task::query()
        ->whereHas('project', fn ($query) => $query->where('code', 'ARR-WEB'))
        ->whereNull('parent_task_id')
        ->orderBy('id')
        ->firstOrFail();
}

/**
 * Cabeceras de una recarga parcial de Inertia (solo esa prop).
 *
 * @return array<string, string>
 */
function perfPartial(string $component, string $prop): array
{
    return [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
        'X-Inertia-Partial-Component' => $component,
        'X-Inertia-Partial-Data' => $prop,
    ];
}

/**
 * Estado esperado de cada página por rol (lo que no está aquí responde 200).
 *
 * @return array<string, int>
 */
function perfExpectedStatuses(string $email): array
{
    $admin = ['admin.users.index', 'admin.users.edit', 'admin.users.deactivation', 'admin.departments.index', 'admin.task-types.index', 'admin.statuses.index', 'admin.settings.edit'];
    $lock = ['time.locks.index', 'time.locks.preview'];

    return ['tasks.show' => 302] + match ($email) {
        'admin@example.com' => [],
        'responsable@example.com' => array_fill_keys([...$admin, ...$lock], 403),
        default => array_fill_keys([...$admin, ...$lock, 'projects.create', 'projects.settings', 'hour-banks.index', 'time.approvals.index'], 403),
    };
}

/**
 * Hace la petición dos veces (la primera calienta las cachés de ajustes, permisos y estados, como
 * en producción) y mide la segunda: consultas, repeticiones de la misma SQL y tiempo.
 *
 * Las dos peticiones van con el reloj parado: la medida es «con la caché caliente», y una caché con
 * caducidad no puede vencer entre el calentamiento y la medida. Sin esto, la lista compartida del
 * contador de la Weekly (MyWeeklyStatus, 5 minutos, D-187) caducaba a mitad del test en el
 * servidor (PostgreSQL con prioridad mínima, scripts/heavy.sh: entre la primera página y la segunda
 * ronda pasan allí más de 5 minutos) y, si vencía justo entre las dos peticiones de una página, la
 * medida pagaba sus cinco consultas: «mis tareas» pasaba de 12 a 17 sin que la página ni el
 * contador dependieran de los datos (se reproduce en local adelantando el reloj 301 s entre las
 * dos). En producción esas cinco consultas se hacen una vez cada 5 minutos para toda la plantilla
 * (o tras un envío, una exención o una ausencia), no en cada petición.
 *
 * @param  array<string, string>  $headers
 * @return array{status: int, total: int, repeats: int, repeated: string, ms: float, shapes: array<string, int>}
 */
function perfMeasure(TestCase $test, User $user, string $url, array $headers): array
{
    return $test->freezeTime(fn (): array => perfMeasureFrozen($test, $user, $url, $headers));
}

/**
 * @param  array<string, string>  $headers
 * @return array{status: int, total: int, repeats: int, repeated: string, ms: float, shapes: array<string, int>}
 */
function perfMeasureFrozen(TestCase $test, User $user, string $url, array $headers): array
{
    // Las cabeceras van en cada get(): withHeaders() se quedaría para las peticiones siguientes.
    $test->actingAs($user)->get($url, $headers);

    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $start = hrtime(true);
    $response = $test->actingAs($user)->get($url, $headers);
    $ms = (hrtime(true) - $start) / 1e6;

    app('events')->forget(QueryExecuted::class);

    $counts = array_count_values($queries);
    arsort($counts);

    return [
        'status' => $response->getStatusCode(),
        'total' => count($queries),
        'repeats' => $counts === [] ? 0 : (int) reset($counts),
        'repeated' => Str::limit((string) array_key_first($counts), 200),
        'ms' => $ms,
        // Forma de cada consulta (sin las listas de ids ni de ?): para explicar qué consulta crece.
        'shapes' => array_count_values(array_map(fn (string $sql): string => (string) preg_replace('/in \\([\\d?, ]+\\)/', 'in (…)', $sql), $queries)),
    ];
}

/**
 * @return array<string, array{status: int, total: int, repeats: int, repeated: string, ms: float, shapes: array<string, int>}>
 */
function perfMeasureAll(TestCase $test, User $user): array
{
    $results = [];

    foreach (perfPages() as $label => [$url, $headers]) {
        $user->refresh();
        $results[$label] = perfMeasure($test, $user, $url, $headers);
    }

    return $results;
}

/**
 * Más datos de todo tipo alrededor de las páginas medidas (se puede llamar varias veces).
 */
function perfGrow(int $round): void
{
    $project = Project::query()->where('code', 'ARR-WEB')->sole();
    $design = Department::query()->where('name', 'Diseño')->sole();
    $bank = HourBank::query()->where('project_id', $project->id)->orderBy('id')->firstOrFail();
    $client = Client::query()->where('name', 'Bodegas Arrieta')->sole();
    $task = perfPanelTask();
    $raul = User::query()->where('email', 'responsable@example.com')->sole();
    $elena = User::query()->where('email', 'empleado@example.com')->sole();
    $admin = User::query()->where('email', 'admin@example.com')->sole();
    $monday = now()->startOfWeek()->toDateString();
    $weeksAgo = fn (int $weeks): string => now()->subWeeks($weeks + 4 * ($round - 1))->startOfWeek()->toDateString();

    // Personas nuevas de Diseño (equipo de Raúl), miembros del proyecto.
    $people = collect(range(1, 4))->map(function () use ($design, $project): User {
        $user = User::factory()->withRole(Role::Employee)->create(['department_id' => $design->id]);
        $project->addMember($user);

        return $user;
    });

    // Proyectos nuevos del cliente con Raúl de gestor, Elena y el equipo, bolsa, tareas y horas.
    foreach (range(1, 4) as $i) {
        $newProject = Project::factory()->hourBank()->create([
            'client_id' => $client->id,
            'owner_user_id' => $raul->id,
            'code' => "PERF-{$round}-{$i}",
        ]);
        $newProject->addMember($raul, true);
        $newProject->addMember($elena);
        $people->each(fn (User $user) => $newProject->addMember($user));
        $newBank = HourBank::factory()->hours(100)->create(['project_id' => $newProject->id]);

        foreach (range(0, 2) as $j) {
            $newTask = Task::factory()->inBank($newBank)->assignedTo($elena)->create(['due_date' => now()->addDays($j)->toDateString()]);
            TimeEntry::factory()->forTask($newTask)->on($monday)->minutes(30)->create(['user_id' => $elena->id]);
            TimeEntry::factory()->forTask($newTask)->on($monday)->minutes(30)->create(['user_id' => $people[$j]->id]);
        }
    }

    // Tareas raíz con subtareas en ARR-WEB, horas enviadas y semanas pendientes de aprobar.
    foreach ($people as $index => $user) {
        $root = Task::factory()->inBank($bank)->assignedTo($user)->create(['estimated_minutes' => 60]);

        foreach (range(1, 2) as $k) {
            $subtask = Task::factory()->subtaskOf($root)->assignedTo($people[($index + $k) % 4])->create(['estimated_minutes' => 30]);
            TimeEntry::factory()->forTask($subtask)->on($weeksAgo(1))->minutes(15)->status(TimeEntryStatus::Submitted)->create(['user_id' => $user->id]);
        }

        TimeEntry::factory()->forTask($root)->on($weeksAgo(2))->minutes(45)->status(TimeEntryStatus::Submitted)->create(['user_id' => $user->id]);
        TimesheetPeriod::factory()->status(TimesheetStatus::Submitted)->create(['user_id' => $user->id, 'week_start' => $weeksAgo(1)]);
        TimesheetPeriod::factory()->status(TimesheetStatus::Submitted)->create(['user_id' => $user->id, 'week_start' => $weeksAgo(2)]);
        Task::factory()->inBank($bank)->assignedTo($elena)->create(['due_date' => now()->toDateString()]);
    }

    // Panel de la tarea: subtareas, horas de otras personas, seguidores, comentarios con
    // reacciones y adjuntos, adjuntos de la tarea y actividad (cambios de título y responsable).
    foreach ($people as $index => $user) {
        Task::factory()->subtaskOf($task)->assignedTo($user)->create(['estimated_minutes' => 15]);
        TimeEntry::factory()->forTask($task)->on($monday)->minutes(20)->create(['user_id' => $user->id]);
        $task->watchers()->syncWithoutDetaching([$user->id]);

        $comment = TaskComment::factory()->create(['task_id' => $task->id, 'user_id' => $user->id]);
        foreach ($people->take(2) as $reactor) {
            CommentReaction::query()->create(['task_comment_id' => $comment->id, 'user_id' => $reactor->id, 'emoji' => CommentReaction::EMOJIS[$index % 2]]);
        }

        Attachment::factory()->create(['attachable_type' => $comment->getMorphClass(), 'attachable_id' => $comment->id, 'project_id' => $project->id, 'user_id' => $user->id]);
        Attachment::factory()->create(['attachable_type' => $task->getMorphClass(), 'attachable_id' => $task->id, 'project_id' => $project->id, 'user_id' => $user->id]);
        $task->update(['title' => "Diseño de la home ({$round}.{$index})", 'assignee_user_id' => $user->id]);
    }

    // Más clientes y notificaciones (leídas y sin leer).
    Client::factory()->count(4)->create();
    foreach ([$admin, $raul, $elena] as $user) {
        foreach (range(1, 5) as $n) {
            DB::table('notifications')->insert([
                'id' => (string) Str::uuid(),
                'type' => 'perf',
                'notifiable_type' => $user->getMorphClass(),
                'notifiable_id' => $user->id,
                'data' => json_encode(['kind' => 'task.assigned', 'title' => "Aviso {$n}", 'url' => '/']),
                'read_at' => $n % 2 === 0 ? now() : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}

/**
 * Con PERF_REPORT=1, imprime la tabla de consultas y tiempos.
 *
 * @param  array<string, array{status: int, total: int, repeats: int, repeated: string, ms: float, shapes: array<string, int>}>  $results
 */
function perfReport(string $title, array $results): void
{
    if (! getenv('PERF_REPORT')) {
        return;
    }

    $lines = ["\n=== {$title}"];
    foreach ($results as $label => $result) {
        $lines[] = sprintf('%-30s %3d %4d q (máx. %d rep.) %7.1f ms', $label, $result['status'], $result['total'], $result['repeats'], $result['ms']);
    }

    fwrite(STDERR, implode("\n", $lines)."\n");
}

dataset('roles', [
    'admin' => 'admin@example.com',
    'responsable' => 'responsable@example.com',
    'empleada' => 'empleado@example.com',
]);

test('cada página de la Fase 1 cabe en su presupuesto de consultas, sin consultas repetidas por fila', function (string $email) {
    $this->seed(DatabaseSeeder::class);
    $user = User::query()->where('email', $email)->sole();

    $results = perfMeasureAll($this, $user);
    perfReport($email, $results);

    $expected = perfExpectedStatuses($email);
    $problems = [];

    foreach ($results as $label => $result) {
        if ($result['status'] !== ($expected[$label] ?? 200)) {
            $problems[] = "{$label}: estado {$result['status']} (se esperaba ".($expected[$label] ?? 200).')';
        }

        if ($result['total'] > PERF_BUDGETS[$label]) {
            $problems[] = "{$label}: {$result['total']} consultas (presupuesto ".PERF_BUDGETS[$label].')';
        }

        if ($result['repeats'] > PERF_MAX_REPEATS) {
            $problems[] = "{$label}: la misma consulta {$result['repeats']} veces: {$result['repeated']}";
        }
    }

    expect($problems)->toBe([]);
})->with('roles');

test('el número de consultas de cada página no crece con los datos (sin N+1)', function (string $email) {
    $this->seed(DatabaseSeeder::class);
    $user = User::query()->where('email', $email)->sole();

    // Una primera ronda para que existan filas de todas las relaciones (comentarios, reacciones,
    // adjuntos, subtareas…): las cargas condicionales («si hay alguna, cárgalas») ya salen aquí.
    // PERF_GROWTH_TOLERANCE cubre las consultas de nombres de la actividad (una por tipo citado).
    perfGrow(1);
    $before = perfMeasureAll($this, $user);

    // Otra ronda con más filas de todo: las consultas deben ser las mismas.
    perfGrow(2);
    $after = perfMeasureAll($this, $user);

    perfReport("{$email} (antes de crecer)", $before);
    perfReport("{$email} (después de crecer)", $after);

    $grew = [];
    foreach ($before as $label => $result) {
        $now = $after[$label];

        if ($now['status'] !== $result['status'] || $now['total'] > $result['total'] + PERF_GROWTH_TOLERANCE) {
            $more = [];
            foreach ($now['shapes'] as $shape => $count) {
                if ($count > ($result['shapes'][$shape] ?? 0)) {
                    $more[] = ($result['shapes'][$shape] ?? 0)." → {$count}: ".Str::limit($shape, 200);
                }
            }

            $grew[] = "{$label}: {$result['total']} → {$now['total']} consultas (estado {$result['status']} → {$now['status']}); crecen: ".implode(' | ', $more);
        }
    }

    expect($grew)->toBe([]);
})->with('roles');

/**
 * 25 personas de Diseño × 4 semanas enviadas × 20 entradas (4 al día): 100 semanas pendientes
 * (el límite de la página) y 2.000 entradas.
 */
function perfManyPendingWeeks(): void
{
    $design = Department::query()->where('name', 'Diseño')->sole();
    $task = perfPanelTask();
    $rows = [];

    foreach (range(1, 25) as $i) {
        $person = User::factory()->withRole(Role::Employee)->create(['department_id' => $design->id]);

        foreach (range(2, 5) as $weeks) {
            $monday = now()->subWeeks($weeks)->startOfWeek();
            TimesheetPeriod::factory()->status(TimesheetStatus::Submitted)->create(['user_id' => $person->id, 'week_start' => $monday->toDateString()]);

            foreach (range(0, 19) as $k) {
                $rows[] = [
                    'user_id' => $person->id, 'task_id' => $task->id, 'project_id' => $task->project_id, 'hour_bank_id' => null,
                    'date' => $monday->copy()->addDays($k % 5)->toDateString(), 'minutes' => 20, 'overage_minutes' => 0,
                    'is_billable' => true, 'status' => TimeEntryStatus::Submitted->value, 'created_at' => now(), 'updated_at' => now(),
                ];
            }
        }
    }

    foreach (array_chunk($rows, 200) as $chunk) {
        DB::table('time_entries')->insert($chunk);
    }
}

test('aprobaciones: consultas constantes con 100 semanas pendientes y 2.000 entradas', function () {
    $this->seed(DatabaseSeeder::class);
    perfManyPendingWeeks();

    foreach (['admin@example.com', 'responsable@example.com'] as $email) {
        $result = perfMeasure($this, User::query()->where('email', $email)->sole(), '/horas/aprobaciones', []);
        perfReport("{$email}: aprobaciones con 100 semanas pendientes", ['time.approvals.index' => $result]);

        expect($result['status'])->toBe(200)
            ->and($result['total'])->toBeLessThanOrEqual(PERF_BUDGETS['time.approvals.index'])
            ->and($result['repeats'])->toBeLessThanOrEqual(PERF_MAX_REPEATS);
    }
});

// PERF-01: la carga inicial solo lleva los totales de cada semana (sumados en la base de datos); las
// entradas se piden al desplegar el detalle (GET /horas/aprobaciones/{period}/entradas). Antes, con
// 100 semanas × 20 entradas, la página tardaba ~1,8 s en local y generaba ~1 MB de HTML.
test('aprobaciones responde en menos de 1 s y sin el detalle de las entradas con 100 semanas pendientes (SPEC §15)', function () {
    $this->seed(DatabaseSeeder::class);
    perfManyPendingWeeks();

    foreach (['admin@example.com', 'responsable@example.com'] as $email) {
        $user = User::query()->where('email', $email)->sole();
        // Calienta las cachés (y la sesión al cambiar de persona, como perfMeasure).
        $this->actingAs($user)->get('/horas/aprobaciones');

        $start = hrtime(true);
        $response = $this->actingAs($user)->get('/horas/aprobaciones');
        $ms = (hrtime(true) - $start) / 1e6;
        $bytes = strlen((string) $response->getContent());
        perfReport("{$email}: aprobaciones ({$bytes} bytes)", ['time.approvals.index' => [
            'status' => $response->getStatusCode(), 'total' => 0, 'repeats' => 0, 'repeated' => '', 'ms' => $ms, 'shapes' => [],
        ]]);

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->has('pending', 100)
            ->where('pending.0.entries_count', 20)
            ->missing('pending.0.entries'));

        // Holgura sobre lo medido (~150 ms y ~150 KB): el objetivo del SPEC es menos de 1 s.
        $limit = perfTimeLimit(1000);
        expect($limit === null || $ms < $limit)->toBeTrue("{$ms} ms (límite {$limit} ms)")
            ->and($bytes)->toBeLessThan(300_000);
    }
});
