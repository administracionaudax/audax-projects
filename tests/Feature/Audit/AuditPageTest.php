<?php

use App\Domain\Audit\AuditLog;
use App\Models\Absence;
use App\Models\Client;
use App\Models\Holiday;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/*
| Auditoría visible (SPEC §14 y §15, D-074): /admin/auditoria solo para el admin, filtros en la URL
| (entidad, persona, acción y fechas de Madrid), detalle con el antes y el después legible, enlace a
| la entidad si sigue existiendo, paginación por cursor y un número de consultas que no crece con
| las filas.
*/

beforeEach(function () {
    TaskStatus::ensureDefaults();

    $this->admin = User::factory()->admin()->create(['name' => 'Ana Admin']);
    $this->employee = User::factory()->employee()->create(['name' => 'Elena Empleada']);

    // Entrada de la auditoría con los datos justos (created_at y demás se pueden fijar).
    $this->entry = fn (array $attributes = []): Activity => Activity::query()->create([
        'log_name' => 'projects',
        'description' => 'updated',
        'event' => 'updated',
        ...$attributes,
    ]);

    $this->page = fn (string $query = ''): array => $this->actingAs($this->admin)
        ->get('/admin/auditoria'.($query === '' ? '' : "?{$query}"))
        ->assertOk()
        ->viewData('page')['props'];
});

test('solo el admin entra en la auditoría y en su exportación', function (string $actor, int $status) {
    if ($actor !== 'guest') {
        $this->actingAs(userWithRole($actor));
    }

    foreach (['/admin/auditoria', '/admin/auditoria/exportar'] as $path) {
        $response = $this->get($path)->assertStatus($status);

        if ($actor === 'guest') {
            $response->assertRedirect(route('login'));
        }

        if ($actor === 'client') {
            $response->assertRedirect(route('portal.home'));
        }
    }
})->with([
    'invitado' => ['guest', 302],
    'admin' => ['admin', 200],
    'responsable' => ['department_manager', 403],
    'empleado' => ['employee', 403],
    'cliente' => ['client', 302],
]);

test('un responsable con el permiso manage-users tampoco entra: es solo del rol admin', function () {
    $manager = userWithRole('department_manager');
    $manager->givePermissionTo('manage-users', 'manage-settings', 'view-financials');

    $this->actingAs($manager)->get('/admin/auditoria')->assertForbidden();
    $this->actingAs($manager)->get('/admin/auditoria/exportar')->assertForbidden();
});

test('muestra quién cambió qué, con los valores legibles, los importes y el enlace a la entidad', function () {
    $this->actingAs($this->admin);
    $project = Project::factory()->create(['name' => 'Web corporativa', 'code' => 'WEB', 'hourly_rate' => '60.00']);
    $done = TaskStatus::query()->where('category', 'done')->orderBy('position')->firstOrFail();
    $task = Task::factory()->create(['project_id' => $project->id, 'title' => 'Maquetar la portada']);

    $project->update(['hourly_rate' => '1275.50']);
    $task->update(['status_id' => $done->id, 'assignee_user_id' => $this->employee->id, 'due_date' => '2026-10-05', 'estimated_minutes' => 150]);

    $props = ($this->page)();
    $entries = collect($props['entries']);

    $taskUpdate = $entries->first(fn (array $entry): bool => $entry['entity']['key'] === 'task' && $entry['event'] === 'updated');
    $changes = collect($taskUpdate['changes'])->keyBy('field');

    expect($taskUpdate['causer'])->toBe(['id' => $this->admin->id, 'name' => 'Ana Admin'])
        ->and($taskUpdate['entity']['label'])->toBe('Tarea')
        ->and($taskUpdate['event_label'])->toBe('Cambio')
        ->and($taskUpdate['subject'])->toBe(['label' => 'Maquetar la portada', 'url' => "/tareas/{$task->id}", 'deleted' => false])
        ->and($changes['status_id'])->toMatchArray(['label' => 'Estado', 'from' => 'Por hacer', 'to' => $done->name])
        ->and($changes['assignee_user_id'])->toMatchArray(['label' => 'Responsable', 'from' => null, 'to' => 'Elena Empleada'])
        ->and($changes['due_date']['to'])->toBe('05/10/2026')
        ->and($changes['estimated_minutes']['to'])->toBe('2:30');

    // Los campos económicos se ven: solo lo ve el admin (D-074).
    $projectUpdate = $entries->first(fn (array $entry): bool => $entry['entity']['key'] === 'project' && $entry['event'] === 'updated');

    expect($projectUpdate['subject'])->toBe(['label' => 'WEB · Web corporativa', 'url' => "/proyectos/{$project->id}", 'deleted' => false])
        ->and($projectUpdate['changes'])->toBe([
            ['field' => 'hourly_rate', 'label' => 'Tarifa por hora', 'from' => '60,00 €', 'to' => '1.275,50 €'],
        ]);

    // Al crear solo salen los campos con valor.
    $projectCreated = $entries->first(fn (array $entry): bool => $entry['entity']['key'] === 'project' && $entry['event'] === 'created');
    $created = collect($projectCreated['changes'])->keyBy('field');

    expect($projectCreated['event_label'])->toBe('Alta')
        ->and($created['name'])->toMatchArray(['from' => null, 'to' => 'Web corporativa'])
        ->and($created['billing_type']['to'])->toBe('Por horas')
        ->and($created)->not->toHaveKey('description');
});

test('lo borrado se nombra con lo que guardó la auditoría y ya no enlaza', function () {
    $this->actingAs($this->admin);
    $holiday = Holiday::factory()->create(['name' => 'Fiesta local', 'date' => '2026-11-09']);
    $holidayId = $holiday->id;
    $holiday->delete();

    ($this->entry)([
        'log_name' => 'holidays',
        'event' => 'holiday_deleted',
        'description' => 'Festivo eliminado',
        'subject_type' => Holiday::class,
        'subject_id' => $holidayId,
        'causer_type' => User::class,
        'causer_id' => $this->admin->id,
        'properties' => ['date' => '2026-11-09', 'name' => 'Fiesta local'],
    ]);

    $project = Project::factory()->create(['name' => 'Archivado', 'code' => 'ARC']);
    $project->delete();

    $entries = collect(($this->page)()['entries']);
    $deletedHoliday = $entries->firstWhere('event', 'holiday_deleted');
    $trashedProject = $entries->first(fn (array $entry): bool => $entry['entity']['key'] === 'project' && $entry['event'] === 'deleted');

    expect($deletedHoliday['subject'])->toBe(['label' => 'Fiesta local', 'url' => null, 'deleted' => true])
        ->and($deletedHoliday['event_label'])->toBe('Borrado')
        ->and(collect($deletedHoliday['changes'])->pluck('to', 'field')->all())->toBe(['date' => '09/11/2026', 'name' => 'Fiesta local'])
        // En la papelera: se nombra, pero no enlaza.
        ->and($trashedProject['subject'])->toBe(['label' => 'ARC · Archivado', 'url' => null, 'deleted' => true]);
});

test('filtra por entidad, persona, acción y fechas de Madrid; lo que no se entiende se ignora', function () {
    $project = Project::factory()->create();
    $task = Task::factory()->create(['project_id' => $project->id]);
    Activity::query()->delete();

    $utc = fn (string $value): CarbonImmutable => CarbonImmutable::parse($value, 'UTC');
    $a = ($this->entry)(['log_name' => 'projects', 'subject_type' => Project::class, 'subject_id' => $project->id, 'causer_type' => User::class, 'causer_id' => $this->admin->id, 'event' => 'created', 'created_at' => $utc('2026-09-30 21:59:00')]);
    // 23:30 del 30 en UTC ya es el 1 de octubre en Madrid.
    $b = ($this->entry)(['log_name' => 'tasks', 'subject_type' => Task::class, 'subject_id' => $task->id, 'causer_type' => User::class, 'causer_id' => $this->employee->id, 'event' => 'updated', 'created_at' => $utc('2026-09-30 23:30:00')]);
    $c = ($this->entry)(['log_name' => 'tasks', 'subject_type' => Task::class, 'subject_id' => $task->id, 'causer_type' => null, 'causer_id' => null, 'event' => 'deleted', 'created_at' => $utc('2026-10-01 21:59:00')]);
    $d = ($this->entry)(['log_name' => 'holidays', 'causer_type' => User::class, 'causer_id' => $this->admin->id, 'event' => 'holidays_imported', 'description' => 'Festivos importados', 'properties' => ['created' => 3], 'created_at' => $utc('2026-10-02 08:00:00')]);

    $ids = fn (string $query): array => array_column(($this->page)($query)['entries'], 'id');

    expect($ids(''))->toBe([$d->id, $c->id, $b->id, $a->id])
        ->and($ids('entidad=task'))->toBe([$c->id, $b->id])
        ->and($ids('entidad=holiday'))->toBe([$d->id])
        ->and($ids("persona={$this->employee->id}"))->toBe([$b->id])
        ->and($ids('persona=sistema'))->toBe([$c->id])
        ->and($ids('accion=deleted'))->toBe([$c->id])
        // Los festivos importados cuentan como altas.
        ->and($ids('accion=created'))->toBe([$d->id, $a->id])
        ->and($ids('desde=2026-10-01&hasta=2026-10-01'))->toBe([$c->id, $b->id])
        ->and($ids('hasta=2026-09-30'))->toBe([$a->id])
        // Al revés, se intercambian.
        ->and($ids('desde=2026-10-02&hasta=2026-10-01'))->toBe([$d->id, $c->id, $b->id])
        ->and($ids("entidad=task&persona={$this->employee->id}&accion=updated&desde=2026-10-01"))->toBe([$b->id]);

    $props = ($this->page)('entidad=nada&persona=abc&accion=borrar-todo&desde=2026-13-45&hasta=ayer');

    expect(array_column($props['entries'], 'id'))->toBe([$d->id, $c->id, $b->id, $a->id])
        ->and($props['filters'])->toBe(['entidad' => null, 'persona' => null, 'accion' => null, 'desde' => null, 'hasta' => null]);

    // Una persona que no es de la plantilla (un cliente del portal) tampoco filtra.
    $client = User::factory()->portalOf(Client::factory()->create())->create();
    expect(($this->page)("persona={$client->id}")['filters']['persona'])->toBeNull();
});

test('los filtros llegan a la página con sus opciones y a la URL del CSV', function () {
    $this->actingAs($this->admin)
        ->get("/admin/auditoria?entidad=task&persona={$this->employee->id}&accion=updated&desde=2026-09-01")
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/audit')
            ->where('filters', ['entidad' => 'task', 'persona' => (string) $this->employee->id, 'accion' => 'updated', 'desde' => '2026-09-01', 'hasta' => null])
            ->where('exportUrl', "/admin/auditoria/exportar?entidad=task&persona={$this->employee->id}&accion=updated&desde=2026-09-01")
            ->where('options.entities.0', ['value' => 'project', 'label' => 'Proyecto'])
            ->where('options.actions.0', ['value' => 'created', 'label' => 'Altas', 'basic' => true])
            ->has('options.people', 2)
            ->where('options.people.0', ['id' => $this->admin->id, 'name' => 'Ana Admin', 'is_active' => true]));
});

test('pagina por cursor de lo más reciente a lo más antiguo', function () {
    Activity::query()->delete();
    $created = collect(range(1, AuditLog::PER_PAGE + 5))->map(fn (int $i): Activity => ($this->entry)(['description' => "cambio {$i}"]));

    $first = ($this->page)();

    expect($first['entries'])->toHaveCount(AuditLog::PER_PAGE)
        ->and($first['entries'][0]['id'])->toBe($created->last()->id)
        ->and($first['pagination']['prev'])->toBeNull()
        ->and($first['pagination']['next'])->toStartWith('/admin/auditoria?')
        ->and($first['pagination']['next'])->toContain('cursor=');

    $second = $this->actingAs($this->admin)->get($first['pagination']['next'])->assertOk()->viewData('page')['props'];

    expect(array_column($second['entries'], 'id'))->toBe($created->take(5)->reverse()->pluck('id')->values()->all())
        ->and($second['pagination']['next'])->toBeNull()
        ->and($second['pagination']['prev'])->not->toBeNull();
});

test('los cambios de ajustes, festivos, semanas y privacidad se leen sin ids', function () {
    $this->actingAs($this->admin)->put('/admin/ajustes', [
        'company_name' => 'Audax Studio',
        'require_2fa' => false,
        'timer_rounding_minutes' => 15,
        'timer_warning_hours' => 10,
        'hour_bank_alert_thresholds' => [75, 90, 100],
        'allow_hour_bank_overage' => true,
        'require_timesheet_approval' => true,
        'allow_future_time_entries' => false,
        'time_entry_description_required' => false,
        'max_attachment_mb' => 50,
        'default_work_minutes' => [480, 480, 480, 480, 420, 0, 0],
        'weekly_digest_enabled' => true,
        'occupancy_low_threshold' => 70,
        'occupancy_high_threshold' => 110,
    ])->assertSessionHasNoErrors();

    ($this->entry)([
        'log_name' => 'timesheet_periods',
        'event' => 'returned',
        'description' => 'Semana devuelta',
        'causer_type' => User::class,
        'causer_id' => $this->admin->id,
        'properties' => ['week' => '2026-09-21', 'user_id' => $this->employee->id, 'entries' => 5, 'comment' => '<p>Falta la <strong>descripción</strong></p>'],
    ]);

    $entries = collect(($this->page)()['entries']);
    $settings = $entries->firstWhere('entity.key', 'settings');
    $week = $entries->firstWhere('event', 'returned');

    expect($settings['subject'])->toBe(['label' => 'Ajustes generales', 'url' => '/admin/ajustes', 'deleted' => false])
        ->and($settings['changes'])->toBe([
            ['field' => 'timer_rounding_minutes', 'label' => 'Redondeo del temporizador', 'from' => '1 min', 'to' => '15 min'],
            ['field' => 'default_work_minutes', 'label' => 'Jornada por defecto', 'from' => '8:00 · 8:00 · 8:00 · 8:00 · 8:00 · 0:00 · 0:00', 'to' => '8:00 · 8:00 · 8:00 · 8:00 · 7:00 · 0:00 · 0:00'],
        ])
        ->and($week['event_label'])->toBe('Semana devuelta')
        ->and($week['entity']['label'])->toBe('Semana')
        ->and(collect($week['changes'])->pluck('to', 'label')->all())->toBe([
            'Semana' => '21/09/2026',
            'Persona' => 'Elena Empleada',
            'Entradas' => '5',
            'Comentario' => 'Falta la descripción',
        ]);
});

test('el número de consultas no crece con las entradas de la página (sin N+1)', function () {
    $this->actingAs($this->admin);
    $client = Client::factory()->create();
    $project = Project::factory()->create(['client_id' => $client->id]);
    $bank = HourBank::factory()->create(['project_id' => $project->id]);

    $seed = function (int $times) use ($project, $bank, $client): void {
        for ($i = 0; $i < $times; $i++) {
            $task = Task::factory()->create(['project_id' => $project->id, 'hour_bank_id' => $bank->id, 'assignee_user_id' => $this->employee->id]);
            TimeEntry::factory()->forTask($task)->create(['user_id' => $this->employee->id]);
            Absence::factory()->create(['user_id' => $this->employee->id]);
            $task->update(['title' => "Tarea {$i} revisada", 'assignee_user_id' => $this->admin->id]);
            $client->update(['notes' => "Nota {$i}"]);
        }
    };

    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->admin)->get('/admin/auditoria')->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $seed(1);
    $few = $count();

    $seed(8);
    $many = $count();

    // Una consulta por tipo de elemento o de referencia, nunca una por fila: con muchas más
    // entradas (de los mismos tipos) no hay más consultas.
    expect(Activity::query()->count())->toBeGreaterThan(40)
        ->and($many)->toBeLessThanOrEqual($few)
        ->and($few)->toBeLessThanOrEqual(40);
});
