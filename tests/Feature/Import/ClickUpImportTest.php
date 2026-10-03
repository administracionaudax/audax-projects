<?php

use App\Domain\HourBanks\Events\HourBankOverageRecorded;
use App\Domain\HourBanks\Events\HourBankThresholdReached;
use App\Domain\Import\ClickUp\ClickUpImporter;
use App\Domain\Import\ClickUp\ImportReport;
use App\Domain\Import\ClickUp\PeopleFile;
use App\Enums\BillingType;
use App\Enums\HourBankStatus;
use App\Enums\ProjectStatus;
use App\Enums\Role;
use App\Enums\TaskPriority;
use App\Enums\TaskStatusCategory;
use App\Enums\TimeEntryStatus;
use App\Enums\TimesheetStatus;
use App\Events\MembershipsChanged;
use App\Models\Client;
use App\Models\Department;
use App\Models\HourBank;
use App\Models\HourBankAlert;
use App\Models\ImportRef;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\TimesheetPeriod;
use App\Models\User;
use App\Notifications\Admin\UserInvitation;
use Carbon\CarbonImmutable;
use Database\Seeders\DefaultSettingsSeeder;
use Database\Seeders\DepartmentsSeeder;
use Database\Seeders\TaskStatusesSeeder;
use Database\Seeders\TaskTypesSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;

/*
| Importación de ClickUp (D-135 y D-136) con el export SINTÉTICO de tests/fixtures/clickup:
| «Manzanas Pérez» (BH1 y BH2 encadenadas, FE1, FE2 ??h, WE1 y una lista sin código), «Peras Gómez»
| (carpeta archivada con una bolsa), «Arándanos Vacía» (sin listas), Audax Interno (General y
| Marketing) y un espacio personal que queda fuera. «Hoy» es el miércoles 07/10/2026 en Madrid:
| la semana en curso empieza el lunes 05/10.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-07 12:00', 'Europe/Madrid'));
    $this->seed([DefaultSettingsSeeder::class, DepartmentsSeeder::class, TaskStatusesSeeder::class, TaskTypesSeeder::class]);
    $this->fixture = base_path('tests/fixtures/clickup');
});

function runClickUpImport(?string $directory = null, bool $dryRun = false): ImportReport
{
    $directory ??= base_path('tests/fixtures/clickup');

    return app(ClickUpImporter::class)->run($directory, PeopleFile::load($directory.'/personas.json'), $dryRun);
}

/**
 * Copia el export a una carpeta temporal y aplica $change a cada fichero decodificado.
 *
 * @param  callable(string, array<mixed>): array<mixed>  $change
 */
function editedClickUpExport(callable $change): string
{
    $directory = storage_path('framework/testing/clickup-'.uniqid());
    File::ensureDirectoryExists($directory);

    foreach (['tree.json', 'tasks.json', 'time_entries.json', 'personas.json'] as $file) {
        $data = json_decode((string) file_get_contents(base_path('tests/fixtures/clickup/'.$file)), true);
        file_put_contents($directory.'/'.$file, json_encode($change($file, $data), JSON_UNESCAPED_UNICODE));
    }

    return $directory;
}

function importedTask(string $clickupId): Task
{
    $id = ImportRef::query()->where(['source' => 'clickup', 'kind' => 'task', 'external_id' => $clickupId])->value('local_id');

    return Task::withTrashed()->with(['status', 'type', 'watchers'])->findOrFail($id);
}

function importedEntry(string $clickupId): TimeEntry
{
    $id = ImportRef::query()->where(['source' => 'clickup', 'kind' => 'time_entry', 'external_id' => $clickupId])->value('local_id');

    return TimeEntry::query()->findOrFail($id);
}

function importedUser(string $email): User
{
    return User::query()->with('roles')->where('email', $email)->firstOrFail();
}

afterEach(function () {
    foreach (File::directories(storage_path('framework/testing')) as $directory) {
        if (str_contains($directory, 'clickup-')) {
            File::deleteDirectory($directory);
        }
    }
});

test('los nombres de lista se leen según el patrón, incluidos «??h» y las listas sin código', function () {
    runClickUpImport();

    $projects = Project::query()->whereNotNull('client_id')->get()->keyBy('code');

    expect($projects->keys()->sort()->values()->all())->toBe([
        'MANZANAS-AUDITORI', 'MANZANAS-BH', 'MANZANAS-FE1', 'MANZANAS-FE2', 'MANZANAS-WE0', 'MANZANAS-WE1', 'PERAS-BH',
    ]);

    // FE: por horas, con las horas del fee en la descripción.
    expect($projects['MANZANAS-FE1'])
        ->name->toBe('FE1 - 15h')
        ->billing_type->toBe(BillingType::TimeAndMaterials)
        ->budget_minutes->toBeNull()
        ->description->toBe('Fee mensual de 15 h.');

    // FE2 - ??h: horas sin definir.
    expect($projects['MANZANAS-FE2'])
        ->name->toBe('FE2 - Podcast')
        ->billing_type->toBe(BillingType::TimeAndMaterials)
        ->description->toBe('Fee mensual con las horas sin definir en ClickUp.');

    // Otros códigos: precio cerrado con el presupuesto del nombre, fechas de la lista.
    expect($projects['MANZANAS-WE1'])
        ->name->toBe('WE1 - 100h - Web corporativa')
        ->billing_type->toBe(BillingType::FixedPrice)
        ->budget_minutes->toBe(6000)
        ->start_date->toDateString()->toBe('2026-08-01')
        ->due_date->toDateString()->toBe('2026-12-31');

    // Sin código: por horas sin presupuesto.
    expect($projects['MANZANAS-AUDITORI'])
        ->name->toBe('Auditoría UX')
        ->billing_type->toBe(BillingType::TimeAndMaterials)
        ->budget_minutes->toBeNull();

    // Lista archivada que solo aparece en los registros de horas: proyecto archivado.
    expect($projects['MANZANAS-WE0'])->status->toBe(ProjectStatus::Archived)->budget_minutes->toBe(2400);

    // Clientes sin emoji ni espacios sobrantes; la carpeta archivada da un cliente inactivo.
    expect(Client::query()->orderBy('name')->pluck('is_active', 'name')->all())->toBe([
        'Arándanos Vacía' => true,
        'Manzanas Pérez' => true,
        'Peras Gómez' => false,
    ]);

    // Audax Interno: un proyecto interno por lista, sin cliente.
    expect(Project::query()->where('billing_type', BillingType::Internal->value)->whereNull('client_id')->pluck('name')->sort()->values()->all())
        ->toBe(['General', 'Marketing']);
});

test('las bolsas BH se agrupan por cliente y se encadenan por su número', function () {
    runClickUpImport();

    $project = Project::query()->where('code', 'MANZANAS-BH')->firstOrFail();
    expect($project)->billing_type->toBe(BillingType::HourBank)->name->toBe('Bolsa de horas')->status->toBe(ProjectStatus::Active);

    $banks = HourBank::query()->where('project_id', $project->id)->orderBy('id')->get();
    expect($banks)->toHaveCount(2);

    [$bh1, $bh2] = [$banks[0], $banks[1]];
    expect($bh1)
        ->name->toBe('BH1 - 10h')
        ->total_minutes->toBe(600)
        ->invoice_reference->toBe('F250001')
        ->status->toBe(HourBankStatus::Renewed)
        ->renewed_from_id->toBeNull();
    expect($bh2)
        ->name->toBe('BH2 - 20h - Mantenimiento')
        ->total_minutes->toBe(1200)
        ->invoice_reference->toBe('F250002')
        ->status->toBe(HourBankStatus::Active)
        ->renewed_from_id->toBe($bh1->id);

    // Carpeta archivada: proyecto archivado y bolsa cerrada, con su saldo registrado.
    $closed = HourBank::query()->whereHas('project', fn ($query) => $query->where('code', 'PERAS-BH'))->firstOrFail();
    expect($closed)->status->toBe(HourBankStatus::Closed)->closed_remaining_minutes->toBe(120)->closed_at->not->toBeNull();
    expect(Project::query()->where('code', 'PERAS-BH')->firstOrFail()->status)->toBe(ProjectStatus::Archived);

    // Las tareas de una lista BH van a su bolsa.
    expect(importedTask('T1')->hour_bank_id)->toBe($bh1->id)
        ->and(importedTask('T2')->hour_bank_id)->toBe($bh2->id);
});

test('estados, tipos, personas, fechas y descripción de las tareas', function () {
    runClickUpImport();

    $ana = importedUser('ana@empresa.test');
    $bea = importedUser('bea@empresa.test');
    $carla = importedUser('carla@externa.test');
    $dani = importedUser('dani@empresa.test');

    $t1 = importedTask('T1');
    expect($t1->status->category)->toBe(TaskStatusCategory::Done)
        ->and($t1->completed_at?->toIso8601String())->toBe('2026-09-01T16:00:00+00:00')
        ->and($t1->assignee_user_id)->toBe($ana->id)
        ->and($t1->priority)->toBe(TaskPriority::Urgent)
        ->and($t1->is_billable)->toBeTrue()
        ->and($t1->type?->name)->toBe('Diseño UI')
        ->and($t1->created_by)->toBe($ana->id);

    // Primer asignado responsable; el resto, seguidores. Estimación en minutos. «Factor facturable» = 1.
    $t2 = importedTask('T2');
    expect($t2->status->name)->toBe('En curso')
        ->and($t2->completed_at)->toBeNull()
        ->and($t2->assignee_user_id)->toBe($bea->id)
        ->and($t2->watchers->pluck('id')->all())->toBe([$ana->id])
        ->and($t2->estimated_minutes)->toBe(90)
        ->and($t2->is_billable)->toBeTrue()
        ->and($t2->start_date?->toDateString())->toBe('2026-09-10')
        ->and($t2->due_date?->toDateString())->toBe('2026-09-30')
        ->and($t2->description)->toContain('<p>Primera línea<br />segunda línea</p>')
        ->and($t2->description)->not->toContain('<script');

    // Maquetación pasa a Diseño (D-135) aunque el tipo por defecto fuera de Desarrollo.
    $design = Department::query()->where('name', 'Diseño')->value('id');
    expect($t2->type?->name)->toBe('Maquetación')->and($t2->type?->department_id)->toBe($design);

    // Backlog y por hacer → «Por hacer»; terminada/finalizada/archivada → hecha; el resto → en curso.
    expect(importedTask('T3')->status->category)->toBe(TaskStatusCategory::Todo)
        ->and(importedTask('T4')->status->category)->toBe(TaskStatusCategory::Todo)
        ->and(importedTask('T5')->status->category)->toBe(TaskStatusCategory::Done)
        ->and(importedTask('T6')->status->category)->toBe(TaskStatusCategory::InProgress)
        ->and(importedTask('T7')->status->name)->toBe('En revisión');

    // «Naturaleza: No productiva» no es facturable; la errata «Definción» da «Definición» sin departamento.
    $t5 = importedTask('T5');
    expect($t5->is_billable)->toBeFalse()
        ->and($t5->type?->name)->toBe('Definición')
        ->and($t5->type?->department_id)->toBeNull();

    // Proyecto interno: nunca facturable. Markdown → HTML saneado.
    $t6 = importedTask('T6');
    expect($t6->is_billable)->toBeFalse()
        ->and($t6->description)->toContain('<strong>Orden del día</strong>')
        ->and($t6->description)->toContain('<li>');

    // Asignados sin mapear: se saltan. Creador sin mapear: sin autor. Sin prioridad: normal.
    $t10 = importedTask('T10');
    expect($t10->assignee_user_id)->toBe($dani->id)
        ->and($t10->watchers->pluck('id')->all())->toBe([$carla->id]);
    expect(importedTask('T7'))->created_by->toBeNull()->priority->toBe(TaskPriority::Normal);

    // El espacio personal queda fuera.
    expect(ImportRef::query()->where('external_id', 'T8')->exists())->toBeFalse();
});

test('las subtareas profundas cuelgan de su tarea raíz', function () {
    runClickUpImport();

    $root = importedTask('T2');

    expect(importedTask('T3')->parent_task_id)->toBe($root->id)
        ->and(importedTask('T4')->parent_task_id)->toBe($root->id)
        ->and($root->parent_task_id)->toBeNull();
});

test('las horas anteriores a la semana en curso quedan aprobadas y bloqueadas; las de esta semana, en borrador', function () {
    runClickUpImport();

    $locked = importedEntry('E1');
    expect($locked)
        ->status->toBe(TimeEntryStatus::Locked)
        ->approved_at->not->toBeNull()
        ->locked_at->not->toBeNull()
        ->minutes->toBe(480)
        ->date->toDateString()->toBe('2026-09-01')
        ->description->toBe('Diseño');

    $draft = importedEntry('E4');
    expect($draft)->status->toBe(TimeEntryStatus::Draft)->approved_at->toBeNull()->date->toDateString()->toBe('2026-10-06');

    // Su semana, aprobada.
    $ana = importedUser('ana@empresa.test');
    expect(TimesheetPeriod::forUserOn($ana, '2026-09-01'))->exists->toBeTrue()->status->toBe(TimesheetStatus::Approved);
    expect(TimesheetPeriod::query()->where('user_id', importedUser('dani@empresa.test')->id)->exists())->toBeFalse();
    expect(TimesheetPeriod::query()->count())->toBe(8);

    // Registro sin tarea: a «Horas sin tarea (ClickUp)» del proyecto interno General.
    $orphan = importedEntry('E5');
    expect($orphan->task()->value('title'))->toBe(ClickUpImporter::UNASSIGNED_TASK)
        ->and(Project::query()->whereKey($orphan->project_id)->value('name'))->toBe('General')
        ->and($orphan->is_billable)->toBeFalse();

    // Más de 24 h: se parte por días de Madrid.
    $parts = collect(['E11', 'E11:1', 'E11:2'])->map(fn (string $id): TimeEntry => importedEntry($id));
    expect($parts->map(fn (TimeEntry $entry): array => [$entry->date->toDateString(), $entry->minutes])->all())->toBe([
        ['2026-09-20', 120],
        ['2026-09-21', 1440],
        ['2026-09-22', 240],
    ]);

    // Descartados: sin mapear, 0 min, fuera del espacio y persona excluida.
    expect(TimeEntry::query()->count())->toBe(12);
});

test('el consumo de las bolsas se recalcula al final y el exceso queda en la entrada que no cabe (D-019)', function () {
    $report = runClickUpImport();

    $bh1 = HourBank::query()->where('name', 'BH1 - 10h')->firstOrFail();
    expect($bh1)->consumed_minutes->toBe(720)->overage_minutes->toBe(120);
    expect(importedEntry('E1')->overage_minutes)->toBe(0)
        ->and(importedEntry('E3')->overage_minutes)->toBe(120);

    $bh2 = HourBank::query()->where('name', 'BH2 - 20h - Mantenimiento')->firstOrFail();
    expect($bh2)->consumed_minutes->toBe(960)->overage_minutes->toBe(0)->status->toBe(HourBankStatus::Active);

    // El umbral del 75 % queda registrado sin avisar: la próxima imputación no lo repite.
    expect(HourBankAlert::query()->where('hour_bank_id', $bh2->id)->pluck('key')->all())->toBe(['threshold:75']);

    expect($report->totalMinutes())->toBe(4170)
        ->and($report->minutesByPerson())->toBe([
            'Bea Diseño' => 3090,
            'Ana Admin' => 720,
            'Dani Desarrollo' => 180,
            'Carla Colaboradora' => 180,
        ])
        ->and($report->discarded())->toHaveCount(4);
});

test('miembros y gestor principal: el admin o responsable con más horas; si no, el gestor por defecto', function () {
    runClickUpImport();

    $ana = importedUser('ana@empresa.test');
    $bea = importedUser('bea@empresa.test');
    $carla = importedUser('carla@externa.test');
    $dani = importedUser('dani@empresa.test');
    $eva = importedUser('eva@empresa.test');

    $members = fn (string $code): array => Project::query()->where('code', $code)->firstOrFail()
        ->members()->orderBy('users.id')->get()->mapWithKeys(fn (User $user): array => [$user->id => $user->membership?->is_manager])->all();

    // Bolsas: Ana (admin) tiene más horas que nadie que pueda gestionar; Bea es empleada.
    expect(Project::query()->where('code', 'MANZANAS-BH')->value('owner_user_id'))->toBe($ana->id)
        ->and($members('MANZANAS-BH'))->toBe([$ana->id => true, $bea->id => false]);

    // WE1: Dani (responsable) gestiona; la colaboradora es miembro pero nunca gestora.
    expect(Project::query()->where('code', 'MANZANAS-WE1')->value('owner_user_id'))->toBe($dani->id)
        ->and($members('MANZANAS-WE1'))->toBe([$carla->id => false, $dani->id => true]);

    // FE1: solo horas de una empleada → el gestor por defecto.
    expect(Project::query()->where('code', 'MANZANAS-FE1')->value('owner_user_id'))->toBe($eva->id)
        ->and($members('MANZANAS-FE1'))->toBe([$bea->id => false, $eva->id => true]);

    // General (interno): la colaboradora tiene tarea y horas, pero no entra en un proyecto interno.
    expect($members('INTERNO-GENERAL'))->toBe([$eva->id => true]);
    expect(Project::query()->withMember($carla)->pluck('code')->all())->toBe(['MANZANAS-WE1']);
});

test('personas: crea las cuentas, actualiza las existentes y respeta a las colaboradoras', function () {
    $existing = User::factory()->withRole(Role::Employee)->create(['email' => 'dani@empresa.test', 'name' => 'Daniel Existente', 'department_id' => null]);

    $report = runClickUpImport();

    // Nuevas: activas, con su rol, su departamento y su jornada por defecto; sin invitación.
    $bea = importedUser('bea@empresa.test');
    expect($bea->is_active)->toBeTrue()
        ->and($bea->roles->pluck('name')->all())->toBe([Role::Employee->value])
        ->and($bea->department?->name)->toBe('Diseño')
        ->and($bea->workSchedules()->count())->toBe(1);
    expect(DB::table('invitation_tokens')->count())->toBe(0);

    // La colaboradora, con su rol y su departamento, nunca responsable.
    $carla = importedUser('carla@externa.test');
    expect($carla->isCollaborator())->toBeTrue()->and($carla->department?->name)->toBe('Diseño')
        ->and($carla->managedDepartments()->exists())->toBeFalse();

    // Existente: mismo usuario, con el rol y el departamento del fichero (y responsable del suyo).
    $dani = importedUser('dani@empresa.test');
    expect($dani->id)->toBe($existing->id)
        ->and($dani->name)->toBe('Daniel Existente')
        ->and($dani->roles->pluck('name')->all())->toBe([Role::DepartmentManager->value])
        ->and($dani->department?->name)->toBe('Desarrollo')
        ->and(Department::query()->where('name', 'Desarrollo')->firstOrFail()->managers()->pluck('users.id')->all())->toBe([$dani->id]);

    // import: false → ni cuenta.
    expect(User::query()->where('email', 'fran@empresa.test')->exists())->toBeFalse();
    expect($report->get('people', ImportReport::CREATED))->toBe(4)
        ->and($report->get('people', ImportReport::UPDATED))->toBe(1)
        ->and($report->get('people', ImportReport::SKIPPED))->toBe(1);
});

test('antiguos empleados: cuenta desactivada, conservan sus tareas hechas y las abiertas quedan sin ellos', function () {
    $directory = editedClickUpExport(function (string $file, array $data): array {
        if ($file === 'personas.json') {
            $data['people'][] = ['clickup_email' => 'gina@clickup.test', 'email' => 'gina@empresa.test', 'name' => 'Gina Antigua', 'role' => 'employee', 'department' => 'Diseño', 'active' => false];
        }

        if ($file === 'tasks.json') {
            foreach ($data as &$task) {
                if (in_array($task['id'], ['T1', 'T3'], true)) {
                    $task['assignees'] = [['id' => 99, 'username' => 'Gina Antigua', 'email' => 'gina@clickup.test']];
                }
            }
        }

        return $data;
    });

    runClickUpImport($directory);

    $gina = importedUser('gina@empresa.test');
    expect($gina->is_active)->toBeFalse()
        ->and($gina->projects()->exists())->toBeFalse()
        ->and(importedTask('T1')->assignee_user_id)->toBe($gina->id)
        ->and(importedTask('T3')->assignee_user_id)->toBeNull()
        ->and(importedTask('T3')->watchers->pluck('id')->all())->not->toContain($gina->id);
    expect(DB::table('invitation_tokens')->count())->toBe(0);
});

test('un colaborador no queda como responsable de tareas de los proyectos internos', function () {
    runClickUpImport();

    // T6 (Audax Interno · General) estaba asignada a Carla, colaboradora: queda sin responsable.
    $carla = importedUser('carla@externa.test');
    expect(importedTask('T6')->assignee_user_id)->toBeNull()
        ->and(importedTask('T6')->watchers->pluck('id')->all())->not->toContain($carla->id);
});

test('el acceso a listas del fichero de personas hace miembro aunque no tenga tareas ni horas', function () {
    $directory = editedClickUpExport(function (string $file, array $data): array {
        if ($file === 'personas.json') {
            $data['people'][] = ['clickup_email' => 'hugo@clickup.test', 'email' => 'hugo@externa.test', 'name' => 'Hugo Invitado', 'role' => 'collaborator', 'department' => null, 'lists' => ['L5']];
        }

        return $data;
    });

    runClickUpImport($directory);

    $hugo = importedUser('hugo@externa.test');
    $projects = $hugo->projects()->get();
    expect($projects)->toHaveCount(1)
        ->and($projects->first()?->id)->toBe(importedTask('T10')->project_id)
        ->and($hugo->isManagerOf($projects->first()))->toBeFalse();

    // Idempotente: una segunda ejecución no cambia la pertenencia.
    runClickUpImport($directory);
    expect($hugo->projects()->count())->toBe(1);
});

test('es idempotente: dos ejecuciones dan los mismos datos y una tarea cambiada se actualiza', function () {
    runClickUpImport();
    $before = [Client::count(), Project::count(), HourBank::count(), Task::withTrashed()->count(), TimeEntry::count(), User::count(), TimesheetPeriod::count()];

    $second = runClickUpImport();

    expect([Client::count(), Project::count(), HourBank::count(), Task::withTrashed()->count(), TimeEntry::count(), User::count(), TimesheetPeriod::count()])->toBe($before);
    foreach (array_keys(ImportReport::TYPES) as $type) {
        expect($second->get($type, ImportReport::CREATED))->toBe(0, "{$type} creados")
            ->and($second->get($type, ImportReport::UPDATED))->toBe(0, "{$type} actualizados");
    }
    expect($second->get('tasks', ImportReport::UNCHANGED))->toBe(11)
        ->and($second->get('time_entries', ImportReport::UNCHANGED))->toBe(12)
        ->and($second->totalMinutes())->toBe(4170);

    // En el export nuevo: T2 cambia de título, E4 (borrador) de duración y E1 (bloqueada) también.
    $directory = editedClickUpExport(function (string $file, array $data): array {
        foreach ($data as &$item) {
            if (! is_array($item)) {
                continue;
            }
            if ($file === 'tasks.json' && $item['id'] === 'T2') {
                $item['name'] = 'Maquetación de fichas (v2)';
            }
            if ($file === 'time_entries.json' && in_array($item['id'], ['E1', 'E4'], true)) {
                $item['duration'] = (string) ((int) $item['duration'] + 3600000);
            }
        }

        return $data;
    });

    $third = runClickUpImport($directory);

    expect(importedTask('T2')->title)->toBe('Maquetación de fichas (v2)')
        ->and($third->get('tasks', ImportReport::UPDATED))->toBe(1)
        ->and(importedEntry('E4')->minutes)->toBe(240)
        ->and(importedEntry('E1')->minutes)->toBe(480)
        ->and($third->get('time_entries', ImportReport::UPDATED))->toBe(1)
        ->and(Task::withTrashed()->count())->toBe($before[3]);
});

test('con --dry-run no queda nada, pero sale el informe', function () {
    $tables = ['clients', 'projects', 'hour_banks', 'tasks', 'time_entries', 'import_refs', 'timesheet_periods', 'task_types', 'users', 'activity_log'];
    $before = collect($tables)->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all();

    $report = runClickUpImport(dryRun: true);

    expect($report->dryRun)->toBeTrue()
        ->and($report->get('tasks', ImportReport::CREATED))->toBe(11)
        ->and($report->get('time_entries', ImportReport::CREATED))->toBe(12);
    expect(collect($tables)->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all())->toBe($before);
});

test('no envía notificaciones, correos ni avisos de bolsa, y deja una sola entrada de auditoría', function () {
    Notification::fake();
    Mail::fake();
    Event::fake([HourBankThresholdReached::class, HourBankOverageRecorded::class, MembershipsChanged::class]);
    $activityBefore = Activity::query()->count();

    $report = runClickUpImport();

    Notification::assertNothingSent();
    Mail::assertNothingOutgoing();
    Event::assertNotDispatched(HourBankThresholdReached::class);
    Event::assertNotDispatched(HourBankOverageRecorded::class);
    // La memoria de pertenencia y la caché de informes se vacían una vez, al final.
    Event::assertDispatchedTimes(MembershipsChanged::class, 1);

    // Ni una fila por registro importado: solo el resumen de la ejecución, del sistema.
    expect(Activity::query()->count())->toBe($activityBefore + 1);
    $summary = Activity::query()->latest('id')->firstOrFail();
    expect($summary)
        ->log_name->toBe(ClickUpImporter::AUDIT_LOG)
        ->event->toBe(ClickUpImporter::AUDIT_EVENT)
        ->description->toBe('Importación de ClickUp')
        ->causer_id->toBeNull();
    expect($summary->properties['counts']['time_entries']['created'])->toBe(12)
        ->and($summary->properties['minutes'])->toBe($report->totalMinutes());

    // Y el registro vuelve a funcionar después.
    expect(activity()->log('después')?->exists)->toBeTrue();
});

test('el comando muestra el informe y solo invita con --invitar', function () {
    Notification::fake();

    $this->artisan('app:import-clickup', ['ruta' => $this->fixture, '--dry-run' => true, '--invitar' => true])
        ->expectsOutputToContain('Simulación')
        ->expectsOutputToContain('Con --dry-run no se envía ninguna invitación.')
        ->assertSuccessful();
    Notification::assertNothingSent();

    $this->artisan('app:import-clickup', ['ruta' => $this->fixture])
        ->expectsOutputToContain('Horas importadas')
        ->assertSuccessful();
    Notification::assertNothingSent();

    $this->artisan('app:import-clickup', ['ruta' => $this->fixture, '--invitar' => true])
        ->expectsOutputToContain('Invitaciones enviadas: 5.')
        ->assertSuccessful();
    Notification::assertSentTo(importedUser('carla@externa.test'), UserInvitation::class);
    Notification::assertCount(5);
});

test('un fichero de personas con errores no importa nada', function () {
    $directory = editedClickUpExport(fn (string $file, array $data): array => $file === 'personas.json'
        ? ['people' => [['clickup_email' => 'no-es-correo', 'email' => 'x@empresa.test', 'name' => 'X', 'role' => 'jefe']]]
        : $data);

    $this->artisan('app:import-clickup', ['ruta' => $directory])
        ->expectsOutputToContain('El fichero de personas no es válido')
        ->assertFailed();

    expect(Project::query()->count())->toBe(0);
});
