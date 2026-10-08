<?php

namespace App\Domain\Import\ClickUp;

use App\Domain\Chat\ConversationDirectory;
use App\Domain\HourBanks\HourBankLedger;
use App\Domain\Projects\ProjectCodeSuggester;
use App\Domain\Projects\ProjectColors;
use App\Domain\Projects\ProjectCreator;
use App\Domain\Time\TimeEntryImport;
use App\Domain\Time\TimeEntryWriter;
use App\Domain\Time\Week;
use App\Enums\BillingType;
use App\Enums\HourBankStatus;
use App\Enums\ProjectStatus;
use App\Enums\Role;
use App\Enums\TaskPriority;
use App\Enums\TaskStatusCategory;
use App\Enums\TimeEntryStatus;
use App\Enums\TimesheetStatus;
use App\Models\Client;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\TaskType;
use App\Models\TimeEntry;
use App\Models\TimesheetPeriod;
use App\Models\User;
use App\Support\JsonArrayStream;
use App\Support\LocalTime;
use App\Support\RichText;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Importación de ClickUp (D-135 y D-136) desde un export de la API v2 en una carpeta:
 * tree.json (espacios, carpetas y listas), tasks.json (tareas con subtareas y cerradas) y
 * time_entries.json (registros de horas).
 *
 * Fases:
 *   1. Lectura previa de tareas y registros (en streaming, memoria acotada): quién trabaja en cada
 *      lista, desde cuándo y las listas archivadas que solo aparecen en los registros de horas.
 *   2. Personas (PeopleImporter), tipos y estados.
 *   3. Estructura: clientes, proyectos, bolsas y sus renovaciones, miembros y gestor principal.
 *   4. Tareas, por bloques en transacción.
 *   5. Horas con TimeEntryWriter::import(), por bloques en transacción.
 *   6. Cierre: HourBankLedger::recalculate una vez por bolsa (sin avisos) y semanas aprobadas.
 *
 * Idempotente con import_refs (ImportRefs). Todo corre con los eventos de los modelos
 * desactivados: ni auditoría por fila, ni avisos de bolsa, ni mensajes del chat, ni tiempo real.
 * Nada envía notificaciones ni correos (las invitaciones son aparte, con --invitar).
 */
final class ClickUpImporter
{
    public const string SOURCE = 'clickup';

    /** log_name y evento de la entrada de auditoría de cada ejecución (AuditCatalog). */
    public const string AUDIT_LOG = 'import';

    public const string AUDIT_EVENT = 'clickup_import';

    public const string SPACE = 'Audax Studio';

    public const string INTERNAL_FOLDER = 'Audax Interno';

    public const string GENERAL_LIST = 'General';

    public const string UNASSIGNED_TASK = 'Horas sin tarea (ClickUp)';

    public const string HOUR_BANK_PROJECT = 'Bolsa de horas';

    public const int CHUNK = 500;

    /**
     * Estados de ClickUp por categoría (D-135). El resto, en curso.
     */
    public const array DONE_STATUSES = ['terminada', 'finalizada', 'archivada'];

    public const array TODO_STATUSES = ['backlog', 'por hacer'];

    /**
     * «Área» de ClickUp → tipo de tarea y su departamento (D-135).
     *
     * @var array<string, array{type: string, department: string|null, icon: string}>
     */
    public const array AREAS = [
        'UI' => ['type' => 'Diseño UI', 'department' => 'Diseño', 'icon' => 'palette'],
        'UX' => ['type' => 'UX', 'department' => 'Diseño', 'icon' => 'target'],
        'Maquetación' => ['type' => 'Maquetación', 'department' => 'Diseño', 'icon' => 'layout-template'],
        'Branding' => ['type' => 'Branding', 'department' => 'Diseño', 'icon' => 'pen-tool'],
        'Design System' => ['type' => 'Design System', 'department' => 'Diseño', 'icon' => 'book-open'],
        'Investigación' => ['type' => 'Investigación', 'department' => 'Diseño', 'icon' => 'lightbulb'],
        'Desarrollo' => ['type' => 'Desarrollo', 'department' => 'Desarrollo', 'icon' => 'code-xml'],
        'Contenidos' => ['type' => 'Contenidos', 'department' => 'Marketing', 'icon' => 'file-text'],
        'Estrategia' => ['type' => 'Estrategia', 'department' => 'Marketing', 'icon' => 'trending-up'],
        'SEO' => ['type' => 'SEO', 'department' => 'Marketing', 'icon' => 'search'],
        'Gestión' => ['type' => 'Gestión', 'department' => null, 'icon' => 'briefcase'],
        'Definición' => ['type' => 'Definición', 'department' => null, 'icon' => 'clipboard-list'],
        // Errata del propio ClickUp.
        'Definción' => ['type' => 'Definición', 'department' => null, 'icon' => 'clipboard-list'],
    ];

    private ImportReport $report;

    private ImportOutput $output;

    private ImportRefs $refs;

    private string $spaceId = '';

    /** @var array<string, array{id: string, name: string, archived: bool, internal: bool}> */
    private array $folders = [];

    /** @var array<string, array{id: string, folder: string, raw: string, archived: bool, start: string|null, due: string|null, recovered: bool}> */
    private array $lists = [];

    /** @var array<string, string|null> tarea => su padre (solo tareas del espacio) */
    private array $taskParent = [];

    /** @var array<string, string> tarea => lista */
    private array $taskList = [];

    /** @var array<string, array{name: string, status: string, type: string, list: string, at: string|null}> tareas que solo aparecen en los registros */
    private array $recoveredTasks = [];

    /** @var array<string, array<string, array{tasks: int, minutes: int}>> lista => correo de ClickUp => actividad */
    private array $activity = [];

    /** @var array<string, string> lista => primera fecha con actividad */
    private array $firstDate = [];

    private int $taskTotal = 0;

    private int $entryTotal = 0;

    /** @var array<string, PersonMatch> correo de ClickUp => persona */
    private array $people = [];

    /** @var list<int> cuentas desactivadas del fichero de personas (antiguos empleados) */
    private array $inactiveUserIds = [];

    /** @var list<int> colaboradores del fichero de personas */
    private array $collaboratorUserIds = [];

    private User $defaultManager;

    /** @var array<string, array{project: int, bank: int|null, internal: bool}> lista => destino de sus tareas */
    private array $targets = [];

    /** @var array<string, array{status: int, category: TaskStatusCategory}> */
    private array $statusCache = [];

    /** @var array<string, int> nombre del tipo => id */
    private array $typeCache = [];

    /** @var array<int, int> proyecto => última posición */
    private array $positions = [];

    /** @var array<string, Task> tareas «Horas sin tarea» ya resueltas en esta ejecución */
    private array $unassigned = [];

    /** @var array<int, true> proyectos con miembros nuevos (para el chat) */
    private array $membershipChanged = [];

    private int $colorIndex = 0;

    private string $currentWeekStart = '';

    public function __construct(
        private readonly TimeEntryWriter $writer,
        private readonly HourBankLedger $ledger,
        private readonly ProjectCodeSuggester $codes,
        private readonly ProjectCreator $projects,
        private readonly PeopleImporter $peopleImporter,
        private readonly ConversationDirectory $conversations,
    ) {}

    /**
     * @throws RuntimeException si falta algún fichero o el export no tiene el espacio
     */
    public function run(string $directory, PeopleFile $peopleFile, bool $dryRun = false, ?ImportOutput $output = null): ImportReport
    {
        $started = microtime(true);
        $this->report = new ImportReport;
        $this->report->dryRun = $dryRun;
        $this->output = $output ?? new SilentOutput;
        $this->refs = new ImportRefs(self::SOURCE);
        $this->currentWeekStart = Week::current()->startString();
        $this->reset();

        $paths = $this->paths($directory);

        if ($dryRun) {
            DB::beginTransaction();
        }

        // Ni una fila de auditoría por registro importado (serían decenas de miles): los eventos de
        // los modelos van apagados y, por si algo escribe en activity_log directamente, el registro
        // también. Al final, UNA entrada resumen (summary()).
        $activity = activity();
        $activity->disableLogging();

        try {
            Model::withoutEvents(function () use ($paths, $peopleFile): void {
                $this->refs->load();
                $this->readTree($paths['tree']);
                $this->scanTasks($paths['tasks']);
                $this->scanEntries($paths['entries']);

                DB::transaction(function () use ($peopleFile): void {
                    $this->output->stage('Personas, tipos y estados');
                    $this->people = $this->peopleImporter->import($peopleFile, $this->report);
                    foreach ($this->people as $match) {
                        if ($match->user !== null && ! $match->user->is_active) {
                            $this->inactiveUserIds[] = $match->user->id;
                        }
                        if ($match->user !== null && $match->spec->role === Role::Collaborator) {
                            $this->collaboratorUserIds[] = $match->user->id;
                        }
                    }
                    $this->defaultManager = $this->defaultManager($peopleFile);
                    $this->loadStatuses();

                    $this->output->stage('Clientes, proyectos y bolsas');
                    $this->importStructure();
                });

                $this->importTasks($paths['tasks']);
                $this->importRecoveredTasks();
                $this->importEntries($paths['entries']);

                DB::transaction(fn () => $this->finish());
            });

            $activity->enableLogging();

            if ($dryRun) {
                DB::rollBack();
            } else {
                $this->afterImport();
            }
        } catch (Throwable $e) {
            if ($dryRun) {
                DB::rollBack();
            }

            throw $e;
        } finally {
            $activity->enableLogging();
        }

        $this->report->seconds = microtime(true) - $started;
        $this->report->peakMemoryBytes = memory_get_peak_usage(true);

        if (! $dryRun) {
            $this->summary();
        }

        return $this->report;
    }

    /**
     * Entrada de auditoría de la ejecución (D-136): sin autor (el sistema), con los recuentos.
     */
    private function summary(): void
    {
        activity(self::AUDIT_LOG)
            ->event(self::AUDIT_EVENT)
            ->withProperties([
                'source' => self::SOURCE,
                'counts' => $this->report->counts(),
                'minutes' => $this->report->totalMinutes(),
                'discarded' => $this->report->discarded(),
                'warnings' => count($this->report->warnings()),
                'seconds' => round($this->report->seconds, 1),
            ])
            ->log('Importación de ClickUp');
    }

    /**
     * Estado de una ejecución: el mismo importador puede ejecutarse varias veces.
     */
    private function reset(): void
    {
        $this->spaceId = '';
        $this->folders = [];
        $this->lists = [];
        $this->taskParent = [];
        $this->taskList = [];
        $this->recoveredTasks = [];
        $this->activity = [];
        $this->firstDate = [];
        $this->taskTotal = 0;
        $this->entryTotal = 0;
        $this->people = [];
        $this->inactiveUserIds = [];
        $this->collaboratorUserIds = [];
        $this->targets = [];
        $this->statusCache = [];
        $this->typeCache = [];
        $this->positions = [];
        $this->unassigned = [];
        $this->membershipChanged = [];
        $this->approvedWeeks = [];
        $this->toLock = [];
    }

    /**
     * @return array{tree: string, tasks: string, entries: string}
     */
    private function paths(string $directory): array
    {
        $directory = rtrim($directory, '/');
        $paths = [
            'tree' => $directory.'/tree.json',
            'tasks' => $directory.'/tasks.json',
            'entries' => $directory.'/time_entries.json',
        ];

        foreach ($paths as $path) {
            if (! is_file($path)) {
                throw new RuntimeException("Falta el fichero {$path}.");
            }
        }

        return $paths;
    }

    // ---------------------------------------------------------------------------------------
    // 1. Lectura previa
    // ---------------------------------------------------------------------------------------

    private function readTree(string $path): void
    {
        $this->output->stage('Estructura de ClickUp');

        $tree = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        foreach (is_array($tree) ? $tree : [] as $node) {
            $space = self::arr(self::arr($node)['space'] ?? null);
            $spaceName = self::str($space['name'] ?? null);

            if ($spaceName !== self::SPACE || (bool) ($space['archived'] ?? false)) {
                continue;
            }

            $this->spaceId = self::str($space['id'] ?? null);

            foreach (self::arr(self::arr($node)['folders'] ?? null) as $folder) {
                $folder = self::arr($folder);
                $this->registerFolder(
                    self::str($folder['id'] ?? null),
                    self::str($folder['name'] ?? null),
                    (bool) ($folder['_archived'] ?? $folder['archived'] ?? false),
                );

                foreach (self::arr($folder['lists'] ?? null) as $list) {
                    $this->registerList(self::arr($list), self::str($folder['id'] ?? null), false);
                }
            }

            // Listas sueltas del espacio (sin carpeta): proyectos internos.
            foreach (self::arr(self::arr($node)['lists'] ?? null) as $list) {
                $this->registerFolder('space:'.$this->spaceId, self::INTERNAL_FOLDER, false);
                $this->registerList(self::arr($list), 'space:'.$this->spaceId, false);
            }
        }

        if ($this->spaceId === '') {
            throw new RuntimeException('El export no tiene el espacio «'.self::SPACE.'».');
        }
    }

    private function registerFolder(string $id, string $rawName, bool $archived): void
    {
        $name = ListName::stripEmoji($rawName);

        $this->folders[$id] ??= [
            'id' => $id,
            'name' => $name !== '' ? $name : $rawName,
            'archived' => $archived,
            'internal' => mb_strtolower($name) === mb_strtolower(self::INTERNAL_FOLDER),
        ];
    }

    /**
     * @param  array<mixed>  $list
     */
    private function registerList(array $list, string $folderId, bool $recovered): void
    {
        $id = self::str($list['id'] ?? null);

        if ($id === '' || isset($this->lists[$id])) {
            return;
        }

        $this->lists[$id] = [
            'id' => $id,
            'folder' => $folderId,
            'raw' => self::str($list['name'] ?? null),
            'archived' => $recovered || (bool) ($list['_archived'] ?? $list['archived'] ?? false) || ($this->folders[$folderId]['archived'] ?? false),
            'start' => self::localDate($list['start_date'] ?? null),
            'due' => self::localDate($list['due_date'] ?? null),
            'recovered' => $recovered,
        ];
    }

    private function scanTasks(string $path): void
    {
        $this->output->stage('Lectura previa de las tareas');
        $outside = [];

        foreach (JsonArrayStream::objects($path) as $task) {
            $spaceId = self::str(self::arr($task['space'] ?? null)['id'] ?? null);

            if ($spaceId !== $this->spaceId) {
                $outside[$spaceId] = ($outside[$spaceId] ?? 0) + 1;

                continue;
            }

            $id = self::str($task['id'] ?? null);
            $listId = self::str(self::arr($task['list'] ?? null)['id'] ?? null);
            $parent = self::str($task['parent'] ?? null);

            $this->taskParent[$id] = $parent !== '' ? $parent : null;
            $this->taskList[$id] = $listId;
            $this->taskTotal++;

            foreach (self::arr($task['assignees'] ?? null) as $assignee) {
                $email = self::email(self::arr($assignee)['email'] ?? null);
                if ($email !== '') {
                    $this->addActivity($listId, $email, tasks: 1);
                }
            }

            $created = self::localDate($task['date_created'] ?? null);
            if ($created !== null && (! isset($this->firstDate[$listId]) || $created < $this->firstDate[$listId])) {
                $this->firstDate[$listId] = $created;
            }
        }

        foreach ($outside as $count) {
            $this->report->count('tasks', ImportReport::SKIPPED, $count);
        }
        if ($outside !== []) {
            $this->report->warn('Tareas fuera del espacio «'.self::SPACE.'» (espacios personales y «Recursos»): no se importan.', array_sum($outside));
        }
    }

    private function scanEntries(string $path): void
    {
        $this->output->stage('Lectura previa de las horas');

        foreach (JsonArrayStream::objects($path) as $entry) {
            $this->entryTotal++;
            $location = self::arr($entry['task_location'] ?? null);
            $task = self::arr($entry['task'] ?? null);
            $spaceId = self::str($location['space_id'] ?? null);

            if ($task !== [] && $spaceId !== $this->spaceId) {
                continue;
            }

            $listId = self::str($location['list_id'] ?? null);
            $taskId = self::str($task['id'] ?? null);

            // Listas archivadas que el export de la estructura no trae: se recuperan de los registros.
            if ($listId !== '' && ! isset($this->lists[$listId])) {
                $folderId = self::str($location['folder_id'] ?? null);
                if ($folderId !== '' && ! isset($this->folders[$folderId])) {
                    $this->registerFolder($folderId, self::str($location['folder_name'] ?? null), true);
                }
                $this->registerList(['id' => $listId, 'name' => $location['list_name'] ?? $listId], $folderId !== '' ? $folderId : 'space:'.$this->spaceId, true);
                if ($folderId === '') {
                    $this->registerFolder('space:'.$this->spaceId, self::INTERNAL_FOLDER, false);
                }
            }

            if ($taskId !== '' && ! array_key_exists($taskId, $this->taskParent) && ! isset($this->recoveredTasks[$taskId]) && $listId !== '') {
                $status = self::arr($task['status'] ?? null);
                $this->recoveredTasks[$taskId] = [
                    'name' => self::str($task['name'] ?? null),
                    'status' => self::str($status['status'] ?? null),
                    'type' => self::str($status['type'] ?? null),
                    'list' => $listId,
                    'at' => self::str($entry['end'] ?? null) !== '' ? self::str($entry['end'] ?? null) : null,
                ];
            }

            if ($listId === '') {
                continue;
            }

            $email = self::email(self::arr($entry['user'] ?? null)['email'] ?? null);
            $minutes = (int) round(((int) self::str($entry['duration'] ?? null)) / 60000);
            if ($email !== '' && $minutes > 0) {
                $this->addActivity($listId, $email, minutes: $minutes);
            }

            $date = self::localDate($entry['start'] ?? null);
            if ($date !== null && (! isset($this->firstDate[$listId]) || $date < $this->firstDate[$listId])) {
                $this->firstDate[$listId] = $date;
            }
        }

        $recoveredLists = count(array_filter($this->lists, fn (array $list): bool => $list['recovered']));
        if ($recoveredLists > 0) {
            $this->report->warn('Listas archivadas que solo aparecen en los registros de horas: se importan como archivadas, con las tareas de esos registros.', $recoveredLists);
        }
    }

    private function addActivity(string $listId, string $email, int $tasks = 0, int $minutes = 0): void
    {
        $current = $this->activity[$listId][$email] ?? ['tasks' => 0, 'minutes' => 0];

        $this->activity[$listId][$email] = [
            'tasks' => $current['tasks'] + $tasks,
            'minutes' => $current['minutes'] + $minutes,
        ];
    }

    // ---------------------------------------------------------------------------------------
    // 2. Personas, estados y tipos
    // ---------------------------------------------------------------------------------------

    private function defaultManager(PeopleFile $file): User
    {
        if ($file->defaultManagerEmail !== null) {
            $user = User::query()->whereRaw('lower(email) = ?', [$file->defaultManagerEmail])->first();

            if ($user !== null && $user->is_active && ! $user->isCollaborator() && ! $user->isClient()) {
                return $user;
            }

            $this->report->warn('El gestor por defecto del fichero de personas no es una cuenta interna activa: se usa el primer admin.');
        }

        $admin = User::role(Role::Admin->value)->where('is_active', true)->orderBy('id')->first();

        if ($admin === null) {
            throw new RuntimeException('No hay ninguna cuenta de administración activa para ser gestor principal por defecto.');
        }

        return $admin;
    }

    private function person(string $clickupEmail): ?PersonMatch
    {
        return $this->people[$clickupEmail] ?? null;
    }

    /**
     * Cuenta de la app de una persona de ClickUp, o null (con el motivo en el informe si $reason).
     */
    private function userFor(string $clickupEmail, ?string $context = null): ?User
    {
        $match = $this->person($clickupEmail);

        if ($match === null) {
            if ($context !== null) {
                $this->report->warn("Persona sin mapear en el fichero de personas ({$clickupEmail}): {$context}.");
            }

            return null;
        }

        return $match->user;
    }

    private function loadStatuses(): void
    {
        TaskStatus::ensureDefaults();
    }

    /**
     * Estado local de un estado de ClickUp (D-135): por categoría y, dentro de ella, el del mismo
     * nombre si existe o el primero.
     *
     * @return array{status: int, category: TaskStatusCategory}
     */
    private function status(string $name, string $type): array
    {
        $normalized = mb_strtolower(trim($name));
        $category = match (true) {
            in_array($normalized, self::DONE_STATUSES, true) => TaskStatusCategory::Done,
            in_array($normalized, self::TODO_STATUSES, true) => TaskStatusCategory::Todo,
            $type === 'done' || $type === 'closed' => TaskStatusCategory::Done,
            default => TaskStatusCategory::InProgress,
        };

        $cacheKey = $category->value.'|'.$normalized;

        if (! isset($this->statusCache[$cacheKey])) {
            $statuses = TaskStatus::query()->where('category', $category->value)->ordered()->get();
            $match = $statuses->first(fn (TaskStatus $status): bool => Str::lower(Str::ascii($status->name)) === Str::lower(Str::ascii($normalized)))
                ?? ($category === TaskStatusCategory::Todo ? $statuses->firstWhere('is_default', true) : null)
                ?? $statuses->first();

            if ($match === null) {
                $match = TaskStatus::defaultStatus();
                $this->report->warn("No hay ningún estado de categoría «{$category->value}»: se usa el estado por defecto.");
            }

            $this->statusCache[$cacheKey] = ['status' => $match->id, 'category' => $match->category];
        }

        return $this->statusCache[$cacheKey];
    }

    /**
     * Tipo de tarea de un «Área» (D-135), creado si no existe y con el departamento de D-135.
     */
    private function taskType(string $area): ?int
    {
        $area = trim($area);
        if ($area === '') {
            return null;
        }

        $definition = self::AREAS[$area] ?? null;
        if ($definition === null) {
            $this->report->warn("Área desconocida «{$area}»: se crea un tipo de tarea sin departamento.");
            $definition = ['type' => $area, 'department' => null, 'icon' => 'briefcase'];
        }

        $name = $definition['type'];
        if (isset($this->typeCache[$name])) {
            return $this->typeCache[$name];
        }

        $department = $definition['department'] !== null ? $this->peopleImporter->department($definition['department'], $this->report) : null;
        $type = TaskType::query()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->orderBy('id')->first();

        if ($type === null) {
            $type = TaskType::query()->create([
                'name' => $name,
                'color' => $department->color ?? '#56667A',
                'icon' => $definition['icon'],
                'department_id' => $department?->id,
                'is_billable_default' => true,
                'is_active' => true,
                'position' => (int) TaskType::withTrashed()->max('position') + 1,
            ]);
            $this->report->count('task_types', ImportReport::CREATED);
        } elseif ($type->department_id !== $department?->id) {
            $type->department_id = $department?->id;
            $type->save();
            $this->report->count('task_types', ImportReport::UPDATED);
        } else {
            $this->report->count('task_types', ImportReport::UNCHANGED);
        }

        return $this->typeCache[$name] = $type->id;
    }

    // ---------------------------------------------------------------------------------------
    // 3. Estructura
    // ---------------------------------------------------------------------------------------

    private function importStructure(): void
    {
        $this->colorIndex = Project::withTrashed()->count();

        /** @var array<string, list<string>> $clientFolders nombre normalizado => carpetas */
        $clientFolders = [];
        foreach ($this->folders as $folder) {
            if (! $folder['internal']) {
                $clientFolders[self::key($folder['name'])][] = $folder['id'];
            }
        }

        foreach ($clientFolders as $folderIds) {
            $client = $this->client($folderIds);
            $lists = array_values(array_filter($this->lists, fn (array $list): bool => in_array($list['folder'], $folderIds, true)));

            if ($lists === []) {
                $this->report->warn("Cliente sin listas en ClickUp: {$client->name}.");
            }

            $banks = [];
            foreach ($lists as $list) {
                $parsed = ListName::parse($list['raw']);

                if ($parsed->isHourBank()) {
                    $banks[] = [$list, $parsed];
                } else {
                    $this->listProject($list, $parsed, $client);
                }
            }

            if ($banks !== []) {
                $this->hourBanks($client, $banks);
            }
        }

        foreach ($this->folders as $folder) {
            if (! $folder['internal']) {
                continue;
            }

            foreach ($this->lists as $list) {
                if ($list['folder'] === $folder['id']) {
                    $this->listProject($list, ListName::parse($list['raw']), null);
                }
            }
        }
    }

    /**
     * Cliente de una o varias carpetas con el mismo nombre (una activa y otra archivada, p. ej.).
     *
     * @param  list<string>  $folderIds
     */
    private function client(array $folderIds): Client
    {
        $folders = array_map(fn (string $id): array => $this->folders[$id], $folderIds);
        $name = $folders[0]['name'];
        $active = in_array(false, array_column($folders, 'archived'), true);

        $clientId = null;
        foreach ($folderIds as $folderId) {
            $clientId ??= $this->refs->find('folder', $folderId);
        }

        $client = $clientId !== null ? Client::withTrashed()->find($clientId) : null;
        $client ??= Client::query()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->orderBy('id')->first();

        if ($client === null) {
            $client = Client::query()->create(['name' => $name, 'is_active' => $active]);
            $this->report->count('clients', ImportReport::CREATED);
        } else {
            $client->fill(['name' => $name, 'is_active' => $active]);
            $this->report->count('clients', $client->isDirty() ? ImportReport::UPDATED : ImportReport::UNCHANGED);
            $client->save();
        }

        foreach ($folderIds as $folderId) {
            $this->refs->put('folder', $folderId, 'client', $client->id);
        }

        return $client;
    }

    /**
     * Proyecto de una lista que no es de bolsas (D-135): FE y sin código, por horas; el resto de
     * códigos, precio cerrado con presupuesto; las de Audax Interno, internos.
     *
     * @param  array{id: string, folder: string, raw: string, archived: bool, start: string|null, due: string|null, recovered: bool}  $list
     */
    private function listProject(array $list, ListName $parsed, ?Client $client): void
    {
        $internal = $client === null;

        [$billing, $budget, $description] = match (true) {
            $internal => [BillingType::Internal, null, null],
            $parsed->isMonthlyFee() => [BillingType::TimeAndMaterials, null, $parsed->hours !== null
                ? "Fee mensual de {$parsed->hours} h."
                : 'Fee mensual con las horas sin definir en ClickUp.'],
            $parsed->code !== null => [BillingType::FixedPrice, $parsed->hours !== null ? $parsed->hours * 60 : null, null],
            default => [BillingType::TimeAndMaterials, null, null],
        };

        if ($parsed->invoiceReference !== null) {
            $description = trim(($description ?? '').' Factura: '.$parsed->invoiceReference.'.');
        }

        if (! $internal && $parsed->code === null) {
            $this->report->warn('Listas sin el patrón «TIPO+N - Hh - …»: proyecto por horas sin presupuesto.');
        }

        $attributes = [
            'client_id' => $client?->id,
            'name' => Str::limit($parsed->name, 255, ''),
            'description' => $description,
            'billing_type' => $billing,
            'status' => $list['archived'] ? ProjectStatus::Archived : ProjectStatus::Active,
            'start_date' => $list['start'],
            'due_date' => $list['due'],
            'budget_minutes' => $budget,
        ];

        $project = $this->project('list', $list['id'], $attributes, [$list['id']], $client->name ?? 'Interno', $internal);

        $this->targets[$list['id']] = ['project' => $project->id, 'bank' => null, 'internal' => $internal];
    }

    /**
     * Bolsas de un cliente (D-135): un proyecto «Bolsa de horas» con una bolsa por lista BH,
     * encadenadas por su número. La última sigue activa (cerrada si está archivada) y las
     * anteriores pasan a renovadas.
     *
     * @param  list<array{0: array{id: string, folder: string, raw: string, archived: bool, start: string|null, due: string|null, recovered: bool}, 1: ListName}>  $banks
     */
    private function hourBanks(Client $client, array $banks): void
    {
        usort($banks, fn (array $a, array $b): int => [$a[1]->number, $a[0]['id']] <=> [$b[1]->number, $b[0]['id']]);

        $listIds = array_map(fn (array $bank): string => $bank[0]['id'], $banks);
        $allArchived = ! in_array(false, array_map(fn (array $bank): bool => $bank[0]['archived'], $banks), true);
        $starts = array_filter(array_map(fn (array $bank): ?string => $this->bankStart($bank[0]), $banks));

        $project = $this->project('hour_bank_project', (string) $client->id, [
            'client_id' => $client->id,
            'name' => self::HOUR_BANK_PROJECT,
            'description' => null,
            'billing_type' => BillingType::HourBank,
            'status' => $allArchived ? ProjectStatus::Archived : ProjectStatus::Active,
            'start_date' => $starts !== [] ? min($starts) : null,
            'due_date' => null,
            'budget_minutes' => null,
        ], $listIds, $client->name, false, 'BH');

        $previous = null;
        $last = count($banks) - 1;

        foreach ($banks as $index => [$list, $parsed]) {
            $status = match (true) {
                $index < $last => HourBankStatus::Renewed,
                $list['archived'] => HourBankStatus::Closed,
                default => HourBankStatus::Active,
            };

            if ($parsed->hours === null) {
                $this->report->warn('Bolsas sin horas en el nombre: se crean con 0 h (todo lo imputado irá como exceso).');
            }

            $bank = $this->bank($project, $list, $parsed, $status, $previous);
            $this->targets[$list['id']] = ['project' => $project->id, 'bank' => $bank->id, 'internal' => false];
            $previous = $bank;
        }
    }

    /**
     * @param  array{id: string, folder: string, raw: string, archived: bool, start: string|null, due: string|null, recovered: bool}  $list
     */
    private function bankStart(array $list): ?string
    {
        return $list['start'] ?? $this->firstDate[$list['id']] ?? null;
    }

    /**
     * @param  array{id: string, folder: string, raw: string, archived: bool, start: string|null, due: string|null, recovered: bool}  $list
     */
    private function bank(Project $project, array $list, ListName $parsed, HourBankStatus $status, ?HourBank $previous): HourBank
    {
        $name = $parsed->name;
        $attributes = [
            'project_id' => $project->id,
            'name' => Str::limit($name, 255, ''),
            'total_minutes' => ($parsed->hours ?? 0) * 60,
            'start_date' => $this->bankStart($list) ?? LocalTime::todayString(),
            'end_date' => $list['due'],
            'renewed_from_id' => $previous?->id,
            'invoice_reference' => $parsed->invoiceReference,
        ];

        $bankId = $this->refs->find('bank_list', $list['id']);
        $bank = $bankId !== null ? HourBank::withTrashed()->find($bankId) : null;

        if ($bank === null) {
            $bank = new HourBank($attributes);
            $bank->status = $status;
            if ($status === HourBankStatus::Closed) {
                $bank->closed_at = CarbonImmutable::now();
                $bank->closed_by = $this->defaultManager->id;
            }
            $bank->save();
            $this->report->count('hour_banks', ImportReport::CREATED);
        } else {
            $bank->fill($attributes);
            // «Agotada» la calcula el libro de horas: para el importador equivale a activa.
            $current = $bank->status === HourBankStatus::Exhausted ? HourBankStatus::Active : $bank->status;
            if ($current !== $status) {
                $bank->status = $status;
            }
            if ($status === HourBankStatus::Closed && $bank->closed_at === null) {
                $bank->closed_at = CarbonImmutable::now();
                $bank->closed_by = $this->defaultManager->id;
            }
            $this->report->count('hour_banks', $bank->isDirty() ? ImportReport::UPDATED : ImportReport::UNCHANGED);
            $bank->save();
        }

        $this->refs->put('bank_list', $list['id'], 'hour_bank', $bank->id);

        return $bank;
    }

    /**
     * Crea o actualiza un proyecto con sus miembros y su gestor principal (D-135): miembros, quien
     * tiene tareas asignadas u horas en sus listas; gestor principal, el admin o responsable con
     * más horas o, si no hay, el gestor por defecto. Un colaborador nunca es gestor ni entra en un
     * proyecto interno.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $listIds
     */
    private function project(string $kind, string $externalId, array $attributes, array $listIds, string $clientName, bool $internal, ?string $codeHint = null): Project
    {
        [$memberIds, $ownerId] = $this->membersOf($listIds, $internal);

        $projectId = $this->refs->find($kind, $externalId);
        $project = $projectId !== null ? Project::withTrashed()->find($projectId) : null;

        if ($project === null) {
            $attributes['code'] = $this->codes->unique($clientName, $codeHint ?? (string) $attributes['name']);
            $attributes['color'] = ProjectColors::next($this->colorIndex++);
            $attributes['owner_user_id'] = $ownerId;

            $project = $this->projects->create($attributes, $memberIds, $this->defaultManager);
            $this->report->count('projects', ImportReport::CREATED);
        } else {
            unset($attributes['client_id']);
            // Un fee ya convertido a su tipo propio (Fase 12, D-382) no vuelve a «Por horas».
            if ($project->billing_type === BillingType::MonthlyFee && ($attributes['billing_type'] ?? null) === BillingType::TimeAndMaterials) {
                unset($attributes['billing_type']);
            }
            $project->fill($attributes);
            $changed = $project->isDirty();
            $project->save();

            $attached = $project->members()->syncWithoutDetaching(array_fill_keys($memberIds, []))['attached'];

            if ($project->owner_user_id !== $ownerId) {
                $project->owner_user_id = $ownerId;
                $project->save();
                $changed = true;
            }
            $project->addMember($ownerId, isManager: true);

            if ($attached !== []) {
                $this->membershipChanged[$project->id] = true;
                $changed = true;
            }

            $this->report->count('projects', $changed ? ImportReport::UPDATED : ImportReport::UNCHANGED);
        }

        $this->refs->put($kind, $externalId, 'project', $project->id);

        return $project;
    }

    /**
     * @param  list<string>  $listIds
     * @return array{0: list<int>, 1: int}
     */
    private function membersOf(array $listIds, bool $internal): array
    {
        /** @var array<int, array{minutes: int, tasks: int, user: User}> $stats */
        $stats = [];

        // Acceso explícito a la lista en ClickUp (campo «lists» del fichero de personas): miembro
        // aunque no tenga tareas ni horas, p. ej. los colaboradores invitados (D-136).
        foreach ($this->people as $email => $match) {
            if (array_intersect($listIds, $match->spec->lists) !== []) {
                foreach ($listIds as $listId) {
                    $this->activity[$listId][$email] ??= ['tasks' => 0, 'minutes' => 0];
                }
            }
        }

        foreach ($listIds as $listId) {
            foreach ($this->activity[$listId] ?? [] as $email => $activity) {
                $user = $this->person($email)?->user;
                if ($user === null || ! $user->is_active) {
                    continue;
                }

                $stats[$user->id] ??= ['minutes' => 0, 'tasks' => 0, 'user' => $user];
                $stats[$user->id]['minutes'] += $activity['minutes'];
                $stats[$user->id]['tasks'] += $activity['tasks'];
            }
        }

        $members = [];
        $best = null;

        foreach ($stats as $userId => $stat) {
            $collaborator = $stat['user']->hasRole(Role::Collaborator->value);

            if ($collaborator && $internal) {
                continue;
            }

            $members[] = $userId;

            $canOwn = ! $collaborator && $stat['user']->hasAnyRole([Role::Admin->value, Role::DepartmentManager->value]);
            if (! $canOwn || $stat['minutes'] <= 0) {
                continue;
            }

            if ($best === null
                || $stat['minutes'] > $stats[$best]['minutes']
                || ($stat['minutes'] === $stats[$best]['minutes'] && $userId < $best)) {
                $best = $userId;
            }
        }

        sort($members);

        return [$members, $best ?? $this->defaultManager->id];
    }

    // ---------------------------------------------------------------------------------------
    // 4. Tareas
    // ---------------------------------------------------------------------------------------

    private function importTasks(string $path): void
    {
        $this->output->stage('Tareas');
        $this->output->progressStart($this->taskTotal);

        /** @var array<int, string> $pending tarea local => raíz de ClickUp aún sin importar */
        $pending = [];
        $chunk = [];

        foreach (JsonArrayStream::objects($path) as $task) {
            if (self::str(self::arr($task['space'] ?? null)['id'] ?? null) !== $this->spaceId) {
                continue;
            }

            $chunk[] = $task;

            if (count($chunk) >= self::CHUNK) {
                DB::transaction(function () use ($chunk, &$pending): void {
                    $this->importTaskChunk($chunk, $pending);
                });
                $this->output->progressAdvance(count($chunk));
                $chunk = [];
            }
        }

        if ($chunk !== []) {
            DB::transaction(function () use ($chunk, &$pending): void {
                $this->importTaskChunk($chunk, $pending);
            });
            $this->output->progressAdvance(count($chunk));
        }

        // Subtareas que llegaron antes que su raíz.
        DB::transaction(function () use ($pending): void {
            foreach ($pending as $taskId => $rootId) {
                $parentId = $this->refs->find('task', $rootId);
                if ($parentId !== null) {
                    Task::query()->whereKey($taskId)->update(['parent_task_id' => $parentId]);
                }
            }
        });

        $this->output->progressFinish();
    }

    /**
     * @param  list<array<string, mixed>>  $chunk
     * @param  array<int, string>  $pending
     */
    private function importTaskChunk(array $chunk, array &$pending): void
    {
        $existingIds = [];
        foreach ($chunk as $task) {
            $localId = $this->refs->find('task', self::str($task['id'] ?? null));
            if ($localId !== null) {
                $existingIds[] = $localId;
            }
        }

        $existing = $existingIds === [] ? collect() : Task::withTrashed()->whereKey($existingIds)->get()->keyBy('id');

        foreach ($chunk as $task) {
            $id = self::str($task['id'] ?? null);
            $listId = $this->taskList[$id] ?? '';
            $target = $this->targets[$listId] ?? null;

            if ($target === null) {
                $this->report->count('tasks', ImportReport::SKIPPED);
                $this->report->warn('Tareas de una lista que no está en la estructura: no se importan.');

                continue;
            }

            $root = $this->rootOf($id);
            $parentId = null;
            $waitsForRoot = false;

            if ($root !== $id) {
                if (($this->taskList[$root] ?? null) !== $listId) {
                    $this->report->warn('Subtareas en otra lista que su tarea raíz: quedan como tareas sueltas.');
                } else {
                    $parentId = $this->refs->find('task', $root);
                    $waitsForRoot = $parentId === null;
                }

                if (($this->taskParent[$id] ?? null) !== $root) {
                    $this->report->warn('Subtareas de más de un nivel: cuelgan de su tarea raíz.');
                }
            }

            $status = self::arr($task['status'] ?? null);
            $assignees = $this->assignees(self::arr($task['assignees'] ?? null));
            if ($target['internal']) {
                // Los colaboradores no son miembros de los proyectos internos (D-134, D-135): no
                // pueden ser responsables ni seguidores de sus tareas.
                $assignees = array_values(array_diff($assignees, $this->collaboratorUserIds));
            }
            $activeAssignees = array_values(array_filter($assignees, fn (int $userId): bool => ! in_array($userId, $this->inactiveUserIds, true)));
            $creator = $this->userFor(self::email(self::arr($task['creator'] ?? null)['email'] ?? null));
            $statusMatch = $this->status(self::str($status['status'] ?? null), self::str($status['type'] ?? null));
            $done = $statusMatch['category'] === TaskStatusCategory::Done;

            $attributes = [
                'project_id' => $target['project'],
                'hour_bank_id' => $target['bank'],
                'parent_task_id' => $parentId,
                'title' => self::title(self::str($task['name'] ?? null)),
                'description' => self::description($task),
                'task_type_id' => $this->taskType(self::str(self::field($task, 'Área'))),
                'status_id' => $statusMatch['status'],
                'priority' => self::priority(self::str(self::arr($task['priority'] ?? null)['priority'] ?? null)),
                // Antiguos empleados (cuentas desactivadas): siguen como responsables de lo que
                // hicieron (tareas hechas); las abiertas quedan sin ellos para repartirlas.
                'assignee_user_id' => ($done ? $assignees : $activeAssignees)[0] ?? null,
                'start_date' => self::localDate($task['start_date'] ?? null),
                'due_date' => self::localDate($task['due_date'] ?? null),
                'estimated_minutes' => self::minutes($task['time_estimate'] ?? null),
                'is_billable' => ! $target['internal'] && self::billable($task),
                'created_by' => $creator?->id,
            ];

            if ($attributes['start_date'] !== null && $attributes['due_date'] !== null && $attributes['start_date'] > $attributes['due_date']) {
                $attributes['start_date'] = null;
            }

            $completedAt = $done
                ? (self::instant($task['date_done'] ?? null) ?? self::instant($task['date_closed'] ?? null) ?? self::instant($task['date_updated'] ?? null))
                : null;

            $localId = $this->refs->find('task', $id);
            /** @var Task|null $model */
            $model = $localId !== null ? $existing->get($localId) : null;

            if ($model !== null && $waitsForRoot) {
                // Se conserva el padre que ya tenía hasta que se resuelva al final.
                unset($attributes['parent_task_id']);
            }

            $model = $this->saveTask($model, $attributes, $completedAt, self::instant($task['date_created'] ?? null), array_values(array_diff(array_slice($done ? $assignees : $activeAssignees, 1), $this->inactiveUserIds)));
            $this->refs->put('task', $id, 'task', $model->id);

            if ($waitsForRoot) {
                $pending[$model->id] = $root;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<int>  $watcherIds
     */
    private function saveTask(?Task $task, array $attributes, ?CarbonImmutable $completedAt, ?CarbonImmutable $createdAt, array $watcherIds): Task
    {
        $created = $task === null;

        if ($task === null) {
            $task = new Task;
            $projectId = (int) $attributes['project_id'];
            $this->positions[$projectId] ??= (int) Task::withTrashed()->where('project_id', $projectId)->max('position');
            $task->position = ++$this->positions[$projectId];
            if ($createdAt !== null) {
                $task->created_at = $createdAt;
            }
        }

        $task->fill($attributes);
        $task->forceFill(['completed_at' => $completedAt]);

        // completed_at: se compara por segundo (la base no guarda los milisegundos).
        if (! $created && $task->isDirty('completed_at')
            && $task->getOriginal('completed_at')?->getTimestamp() === $completedAt?->getTimestamp()) {
            $task->completed_at = $task->getOriginal('completed_at');
        }

        $dirty = $created || $task->isDirty();
        if ($dirty) {
            $task->save();
        }

        $attached = $watcherIds === [] ? [] : $task->watchers()->syncWithoutDetaching($watcherIds)['attached'];

        $this->report->count('tasks', match (true) {
            $created => ImportReport::CREATED,
            $dirty || $attached !== [] => ImportReport::UPDATED,
            default => ImportReport::UNCHANGED,
        });

        return $task;
    }

    /**
     * Tareas que solo aparecen en los registros de horas (las de listas archivadas): título y estado.
     */
    private function importRecoveredTasks(): void
    {
        if ($this->recoveredTasks === []) {
            return;
        }

        $this->output->stage('Tareas de las listas archivadas');

        foreach (array_chunk($this->recoveredTasks, self::CHUNK, true) as $chunk) {
            DB::transaction(function () use ($chunk): void {
                foreach ($chunk as $id => $task) {
                    $target = $this->targets[$task['list']] ?? null;
                    if ($target === null) {
                        $this->report->count('tasks', ImportReport::SKIPPED);

                        continue;
                    }

                    $status = $this->status($task['status'], $task['type']);
                    $localId = $this->refs->find('task', (string) $id);
                    $model = $localId !== null ? Task::withTrashed()->find($localId) : null;

                    $model = $this->saveTask($model, [
                        'project_id' => $target['project'],
                        'hour_bank_id' => $target['bank'],
                        'title' => self::title($task['name']),
                        'status_id' => $status['status'],
                        'is_billable' => ! $target['internal'],
                    ], $status['category'] === TaskStatusCategory::Done ? self::instant($task['at']) ?? $model->completed_at ?? CarbonImmutable::now() : null, null, []);

                    $this->refs->put('task', (string) $id, 'task', $model->id);
                }
            });
        }
    }

    /**
     * Tarea «Horas sin tarea (ClickUp)» de una lista o, sin lista, del proyecto interno General.
     */
    private function unassignedTask(?string $listId): ?Task
    {
        $target = $listId !== null ? ($this->targets[$listId] ?? null) : null;
        $external = $listId ?? 'none';

        if ($target === null) {
            $external = 'none';
            $target = $this->generalTarget();
            if ($target === null) {
                return null;
            }
        }

        if (isset($this->unassigned[$external])) {
            return $this->unassigned[$external];
        }

        $taskId = $this->refs->find('unassigned_task', $external);
        $task = $taskId !== null ? Task::withTrashed()->with('project')->find($taskId) : null;

        if ($task !== null) {
            $this->report->count('tasks', ImportReport::UNCHANGED);
        } else {
            $task = $this->saveTask(null, [
                'project_id' => $target['project'],
                'hour_bank_id' => $target['bank'],
                'title' => self::UNASSIGNED_TASK,
                'status_id' => $this->status('terminada', 'done')['status'],
                'is_billable' => ! $target['internal'],
                'created_by' => $this->defaultManager->id,
            ], CarbonImmutable::now(), null, []);
            $this->refs->put('unassigned_task', $external, 'task', $task->id);
            $task->load('project');
        }

        return $this->unassigned[$external] = $task;
    }

    /**
     * @return array{project: int, bank: int|null, internal: bool}|null
     */
    private function generalTarget(): ?array
    {
        foreach ($this->lists as $list) {
            if (($this->folders[$list['folder']]['internal'] ?? false)
                && self::key(ListName::stripEmoji($list['raw'])) === self::key(self::GENERAL_LIST)
                && isset($this->targets[$list['id']])) {
                return $this->targets[$list['id']];
            }
        }

        $internal = Project::query()->where('code', Project::INTERNAL_CODE)->value('id');

        return $internal !== null ? ['project' => (int) $internal, 'bank' => null, 'internal' => true] : null;
    }

    /**
     * Raíz de una tarea (D-135: subtareas de un solo nivel). Con un ciclo, la tarea queda suelta.
     */
    private function rootOf(string $id): string
    {
        $current = $id;
        $seen = [$id => true];

        while (true) {
            $parent = $this->taskParent[$current] ?? null;

            if ($parent === null || ! array_key_exists($parent, $this->taskParent)) {
                return $current;
            }

            if (isset($seen[$parent])) {
                $this->report->warn('Tareas con un ciclo de padres en ClickUp: quedan como tareas sueltas.');

                return $id;
            }

            $seen[$parent] = true;
            $current = $parent;
        }
    }

    /**
     * Ids de los asignados con cuenta en la app, en el orden de ClickUp y sin repetir.
     *
     * @param  array<mixed>  $assignees
     * @return list<int>
     */
    private function assignees(array $assignees): array
    {
        $ids = [];
        foreach ($assignees as $assignee) {
            $email = self::email(self::arr($assignee)['email'] ?? null);
            $user = $this->userFor($email, 'sus tareas quedan sin esa persona');
            if ($user !== null && ! in_array($user->id, $ids, true)) {
                $ids[] = $user->id;
            }
        }

        return $ids;
    }

    // ---------------------------------------------------------------------------------------
    // 5. Horas
    // ---------------------------------------------------------------------------------------

    /** @var array<int, array<string, true>> persona => semanas con horas aprobadas */
    private array $approvedWeeks = [];

    /** @var list<int> entradas aprobadas en esta ejecución que se bloquean al final */
    private array $toLock = [];

    private function importEntries(string $path): void
    {
        $this->output->stage('Horas');
        $this->output->progressStart($this->entryTotal);
        $chunk = [];

        foreach (JsonArrayStream::objects($path) as $entry) {
            $chunk[] = $entry;

            if (count($chunk) >= self::CHUNK) {
                DB::transaction(fn () => $this->importEntryChunk($chunk));
                $this->output->progressAdvance(count($chunk));
                $chunk = [];
            }
        }

        if ($chunk !== []) {
            DB::transaction(fn () => $this->importEntryChunk($chunk));
            $this->output->progressAdvance(count($chunk));
        }

        $this->output->progressFinish();
    }

    /**
     * @param  list<array<string, mixed>>  $chunk
     */
    private function importEntryChunk(array $chunk): void
    {
        /** @var list<array{external: string, user: User, task: string|null, list: string|null, start: CarbonImmutable, minutes: int, ended: CarbonImmutable, description: string|null, billable: bool|null, at: CarbonImmutable|null}> $rows */
        $rows = [];

        foreach ($chunk as $entry) {
            foreach ($this->entryRows($entry) as $row) {
                $rows[] = $row;
            }
        }

        $taskIds = [];
        $entryIds = [];
        foreach ($rows as $row) {
            if ($row['task'] !== null && ($local = $this->refs->find('task', $row['task'])) !== null) {
                $taskIds[] = $local;
            }
            if (($local = $this->refs->find('time_entry', $row['external'])) !== null) {
                $entryIds[] = $local;
            }
        }

        $tasks = $taskIds === [] ? collect() : Task::withTrashed()->with(['project' => fn ($query) => $query->withTrashed()])->whereKey(array_unique($taskIds))->get()->keyBy('id');
        $entries = $entryIds === [] ? collect() : TimeEntry::query()->whereKey($entryIds)->get()->keyBy('id');

        foreach ($rows as $row) {
            $taskLocal = $row['task'] !== null ? $this->refs->find('task', $row['task']) : null;
            /** @var Task|null $task */
            $task = $taskLocal !== null ? $tasks->get($taskLocal) : null;

            if ($task === null && $row['task'] !== null) {
                $this->report->discard('tarea fuera de la importación');

                continue;
            }

            $task ??= $this->unassignedTask($row['list']);
            if ($task === null) {
                $this->report->discard('sin tarea ni proyecto interno General');

                continue;
            }

            $date = $row['start']->setTimezone(LocalTime::timezone());
            $dateString = $date->toDateString();
            // Anteriores a la semana en curso: aprobadas ahora y bloqueadas en finish(), DESPUÉS de
            // que el libro de horas reparta el exceso (las bloqueadas conservan el suyo, D-019).
            $approved = $dateString < $this->currentWeekStart;
            $now = CarbonImmutable::now();

            $data = new TimeEntryImport(
                userId: $row['user']->id,
                task: $task,
                date: CarbonImmutable::parse($dateString),
                minutes: $row['minutes'],
                description: $row['description'],
                startedAt: $row['start'],
                endedAt: $row['ended'],
                isBillable: $row['billable'],
                status: $approved ? TimeEntryStatus::Approved : TimeEntryStatus::Draft,
                approvedAt: $approved ? $now : null,
                createdAt: $row['at'],
            );

            $localId = $this->refs->find('time_entry', $row['external']);
            /** @var TimeEntry|null $existing */
            $existing = $localId !== null ? $entries->get($localId) : null;

            try {
                $saved = $this->writer->import($data, $existing);
            } catch (InvalidArgumentException) {
                $this->report->discard('duración fuera de rango');

                continue;
            }

            $this->refs->put('time_entry', $row['external'], 'time_entry', $saved->id);
            $this->report->count('time_entries', match (true) {
                $existing === null => ImportReport::CREATED,
                $saved->wasChanged() => ImportReport::UPDATED,
                default => ImportReport::UNCHANGED,
            });
            $this->report->addMinutes($row['user']->name, $saved->minutes);

            if ($saved->status === TimeEntryStatus::Approved) {
                $this->toLock[] = $saved->id;
            }

            if ($saved->status === TimeEntryStatus::Approved || $saved->status === TimeEntryStatus::Locked) {
                $this->approvedWeeks[$saved->user_id][Week::containing($saved->date->toDateString())->startString()] = true;
            }
        }
    }

    /**
     * Filas de un registro de ClickUp: normalmente una; varias si dura más de 24 h (se parte en
     * los cambios de día de Madrid, como el temporizador). Sin filas si se descarta.
     *
     * @param  array<string, mixed>  $entry
     * @return list<array{external: string, user: User, task: string|null, list: string|null, start: CarbonImmutable, minutes: int, ended: CarbonImmutable, description: string|null, billable: bool|null, at: CarbonImmutable|null}>
     */
    private function entryRows(array $entry): array
    {
        $location = self::arr($entry['task_location'] ?? null);
        $task = self::arr($entry['task'] ?? null);
        $taskId = self::str($task['id'] ?? null);

        if ($task !== [] && self::str($location['space_id'] ?? null) !== $this->spaceId) {
            $this->report->discard('fuera del espacio «'.self::SPACE.'»');

            return [];
        }

        $email = self::email(self::arr($entry['user'] ?? null)['email'] ?? null);
        $match = $this->person($email);

        if ($match === null) {
            $this->report->discard('persona sin mapear');
            $this->report->warn("Persona sin mapear en el fichero de personas ({$email}): sus horas no se importan.");

            return [];
        }

        if ($match->user === null) {
            $this->report->discard('persona excluida («import»: false)');

            return [];
        }

        $start = self::instant($entry['start'] ?? null);
        $duration = (int) self::str($entry['duration'] ?? null);
        $minutes = (int) round($duration / 60000);

        if ($start === null || $minutes <= 0) {
            $this->report->discard('0 minutos al redondear');

            return [];
        }

        $id = self::str($entry['id'] ?? null);
        $end = $start->addMilliseconds($duration);
        $billable = ($entry['billable'] ?? null) === false ? false : null;
        $description = Str::limit(trim(self::str($entry['description'] ?? null)), 2000, '');
        $base = [
            'user' => $match->user,
            'task' => $taskId !== '' ? $taskId : null,
            'list' => self::str($location['list_id'] ?? null) !== '' ? self::str($location['list_id'] ?? null) : null,
            'description' => $description !== '' ? $description : null,
            'billable' => $billable,
            'at' => self::instant($entry['at'] ?? null),
        ];

        if ($minutes <= TimeEntry::MAX_MINUTES_PER_DAY) {
            return [['external' => $id, 'start' => $start, 'minutes' => $minutes, 'ended' => $end, ...$base]];
        }

        $this->report->warn('Registros de más de 24 h: se parten por días (hora de Madrid).');

        $rows = [];
        $cursor = $start;
        $part = 0;
        while ($cursor < $end) {
            $midnight = $cursor->setTimezone(LocalTime::timezone())->startOfDay()->addDay()->utc();
            $partEnd = $midnight < $end ? $midnight : $end;
            $partMinutes = min((int) round($cursor->diffInMilliseconds($partEnd) / 60000), TimeEntry::MAX_MINUTES_PER_DAY);

            if ($partMinutes > 0) {
                $rows[] = ['external' => $part === 0 ? $id : $id.':'.$part, 'start' => $cursor, 'minutes' => $partMinutes, 'ended' => $partEnd, ...$base];
            }

            $cursor = $partEnd;
            $part++;
        }

        return $rows;
    }

    // ---------------------------------------------------------------------------------------
    // 6. Cierre
    // ---------------------------------------------------------------------------------------

    private function finish(): void
    {
        $this->output->stage('Consumo de las bolsas y semanas aprobadas');

        foreach ($this->refs->all('bank_list') as $localId) {
            $bank = HourBank::withTrashed()->find($localId);

            if ($bank !== null) {
                $this->ledger->recalculate($bank, notify: false);
                $this->ledger->recordAlertsSilently($bank);
            }
        }

        $now = CarbonImmutable::now();

        // Ya facturadas (D-136): se bloquean con el exceso ya repartido. Actualización masiva, sin
        // eventos ni recálculo (el bloqueo no cambia el consumo).
        foreach (array_chunk($this->toLock, self::CHUNK) as $ids) {
            TimeEntry::query()->whereKey($ids)->update([
                'status' => TimeEntryStatus::Locked->value,
                'locked_at' => $now,
            ]);
        }

        foreach ($this->approvedWeeks as $userId => $weeks) {
            foreach (array_keys($weeks) as $weekStart) {
                $period = TimesheetPeriod::forUserOn($userId, $weekStart);

                if ($period->exists && in_array($period->status, [TimesheetStatus::Approved, TimesheetStatus::Locked], true)) {
                    $this->report->count('timesheet_periods', ImportReport::UNCHANGED);

                    continue;
                }

                $created = ! $period->exists;
                $period->fill([
                    'status' => TimesheetStatus::Approved,
                    'submitted_at' => $period->submitted_at ?? $now,
                    'reviewed_at' => $now,
                ])->save();

                $this->report->count('timesheet_periods', $created ? ImportReport::CREATED : ImportReport::UPDATED);
            }
        }
    }

    /**
     * Fuera de la importación (con los eventos ya activos): el chat de los proyectos con miembros
     * nuevos y la memoria de pertenencia y la caché de informes (MembershipsChanged).
     */
    private function afterImport(): void
    {
        foreach (array_keys($this->membershipChanged) as $projectId) {
            $project = Project::withTrashed()->find($projectId);
            if ($project !== null) {
                $this->conversations->syncProject($project);
            }
        }

        User::forgetMemberships();
    }

    // ---------------------------------------------------------------------------------------
    // Utilidades
    // ---------------------------------------------------------------------------------------

    /**
     * @return array<mixed>
     */
    private static function arr(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private static function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private static function email(mixed $value): string
    {
        return Str::lower(trim(self::str($value)));
    }

    private static function key(string $name): string
    {
        return Str::lower(Str::ascii(trim($name)));
    }

    private static function instant(mixed $milliseconds): ?CarbonImmutable
    {
        $value = self::str($milliseconds);

        if ($value === '' || ! ctype_digit($value)) {
            return null;
        }

        return CarbonImmutable::createFromTimestampMsUTC((int) $value);
    }

    private static function localDate(mixed $milliseconds): ?string
    {
        return self::instant($milliseconds)?->setTimezone(LocalTime::timezone())->toDateString();
    }

    private static function minutes(mixed $milliseconds): ?int
    {
        $value = self::str($milliseconds);

        if ($value === '' || ! is_numeric($value)) {
            return null;
        }

        $minutes = (int) round(((float) $value) / 60000);

        return $minutes > 0 ? $minutes : null;
    }

    private static function title(string $name): string
    {
        $title = trim((string) preg_replace('/\s+/u', ' ', $name));

        return Str::limit($title !== '' ? $title : 'Sin título', 255, '');
    }

    private static function priority(string $priority): TaskPriority
    {
        return TaskPriority::tryFrom(mb_strtolower($priority)) ?? TaskPriority::Normal;
    }

    /**
     * Descripción en HTML saneado (RichText): el Markdown si lo hay, si no el texto plano.
     *
     * @param  array<string, mixed>  $task
     */
    private static function description(array $task): ?string
    {
        $markdown = trim(self::str($task['markdown_description'] ?? null));

        if ($markdown !== '') {
            return RichText::sanitize(Str::markdown($markdown, ['html_input' => 'strip', 'allow_unsafe_links' => false]));
        }

        $text = trim(str_replace("\r\n", "\n", self::str($task['text_content'] ?? null)));

        if ($text === '') {
            return null;
        }

        $paragraphs = preg_split('/\n{2,}/', $text) ?: [];
        $html = implode('', array_map(
            fn (string $paragraph): string => '<p>'.str_replace("\n", '<br>', e(trim($paragraph))).'</p>',
            $paragraphs,
        ));

        return RichText::sanitize(Str::limit($html, RichText::MAX_LENGTH, ''));
    }

    /**
     * Valor de un campo personalizado; en los desplegables, el nombre de la opción.
     *
     * @param  array<string, mixed>  $task
     */
    private static function field(array $task, string $name): mixed
    {
        foreach (self::arr($task['custom_fields'] ?? null) as $field) {
            $field = self::arr($field);

            if (($field['name'] ?? null) !== $name || ! array_key_exists('value', $field) || $field['value'] === null) {
                continue;
            }

            if (($field['type'] ?? null) === 'drop_down') {
                foreach (self::arr(self::arr($field['type_config'] ?? null)['options'] ?? null) as $option) {
                    $option = self::arr($option);
                    if (($option['orderindex'] ?? null) === $field['value'] || ($option['id'] ?? null) === $field['value']) {
                        return $option['name'] ?? null;
                    }
                }

                return null;
            }

            return $field['value'];
        }

        return null;
    }

    /**
     * Facturable (D-135): «Facturable» marcado o «Factor facturable» = 1, salvo «Naturaleza: No productiva».
     *
     * @param  array<string, mixed>  $task
     */
    private static function billable(array $task): bool
    {
        if (self::str(self::field($task, 'Naturaleza')) === 'No productiva') {
            return false;
        }

        $checkbox = self::field($task, 'Facturable');
        $factor = self::field($task, 'Factor facturable');

        return $checkbox === true || $checkbox === 'true' || (is_numeric($factor) && (float) $factor === 1.0);
    }
}
