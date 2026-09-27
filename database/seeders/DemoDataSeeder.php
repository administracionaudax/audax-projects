<?php

namespace Database\Seeders;

use App\Domain\Absences\SpanishNationalHolidays;
use App\Domain\HourBanks\HourBankLedger;
use App\Domain\Time\Capacity;
use App\Enums\AbsenceStatus;
use App\Enums\AbsenceType;
use App\Enums\BillingType;
use App\Enums\HourBankStatus;
use App\Enums\OveragePolicy;
use App\Enums\ProjectStatus;
use App\Enums\Role;
use App\Enums\TaskPriority;
use App\Enums\TaskStatusCategory;
use App\Enums\TimeEntryStatus;
use App\Enums\TimesheetStatus;
use App\Models\Absence;
use App\Models\Client;
use App\Models\Department;
use App\Models\Holiday;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\TaskStatus;
use App\Models\TaskType;
use App\Models\TimeEntry;
use App\Models\TimeEntryLock;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;

/**
 * Datos de ejemplo realistas (SPEC §15): 3 departamentos, 10 personas internas, 8 clientes,
 * 15 proyectos de todos los tipos, bolsas en todos los estados (activa, casi agotada, agotada con
 * exceso, con política block, cerrada y renovada), festivos nacionales, ausencias pasadas y
 * futuras, y 12 meses de horas con su flujo de aprobación (aprobadas, enviadas, devueltas,
 * bloqueadas al facturar y borradores de esta semana). Nadie imputa en un día sin capacidad.
 *
 * SOLO local, testing y CI (nunca en el servidor, D-018). Determinista (semilla fija) y relativo a
 * hoy, para que los dashboards tengan siempre datos recientes. Se ejecuta una sola vez: si ya hay
 * proyectos, no hace nada.
 */
class DemoDataSeeder extends Seeder
{
    private Randomizer $random;

    private CarbonImmutable $today;

    private CarbonImmutable $start;

    /** @var array<string, User> */
    private array $people = [];

    /** @var array<string, Department> */
    private array $departments = [];

    /** @var array<string, TaskType> */
    private array $types = [];

    /** @var array<string, int> */
    private array $statuses = [];

    /**
     * Proyectos de ejemplo. `banks`: [nombre, departamento|null, estado objetivo, política, desde meses, hasta meses].
     * Estados objetivo: healthy, near, exhausted, block, closed, renewed, renewal.
     *
     * @var list<array<string, mixed>>
     */
    private const array PROJECTS = [
        ['code' => 'ARR-WEB', 'name' => 'Web corporativa', 'client' => 'Bodegas Arrieta', 'billing' => 'hour_bank', 'status' => 'active', 'owner' => 'raul', 'members' => ['elena', 'lucia', 'pablo', 'sergio', 'marta'], 'from' => 10, 'to' => 0, 'rate' => null,
            'banks' => [['Bolsa Diseño', 'Diseño', 'exhausted', 'allow', 10, 0], ['Bolsa Desarrollo', 'Desarrollo', 'near', 'inherit', 9, 0]]],
        ['code' => 'ARR-MKT', 'name' => 'Campañas 2026', 'client' => 'Bodegas Arrieta', 'billing' => 'hour_bank', 'status' => 'active', 'owner' => 'nuria', 'members' => ['irene', 'daniel'], 'from' => 12, 'to' => 0, 'rate' => null,
            'banks' => [['Marketing – 1.er semestre', 'Marketing', 'renewed', 'inherit', 12, 6], ['Marketing – 2.º semestre', 'Marketing', 'renewal', 'inherit', 6, 0]]],
        ['code' => 'SON-APP', 'name' => 'App de citas', 'client' => 'Clínica Dental Sonrisas', 'billing' => 'fixed_price', 'status' => 'active', 'owner' => 'marta', 'members' => ['pablo', 'sergio', 'elena'], 'from' => 7, 'to' => 0, 'rate' => null, 'price' => '18500.00', 'banks' => []],
        ['code' => 'SON-SEO', 'name' => 'SEO local', 'client' => 'Clínica Dental Sonrisas', 'billing' => 'hour_bank', 'status' => 'active', 'owner' => 'nuria', 'members' => ['irene', 'daniel'], 'from' => 5, 'to' => 0, 'rate' => '58.00',
            'banks' => [['Bolsa SEO', 'Marketing', 'block', 'block', 5, 0]]],
        ['code' => 'MIR-WEB', 'name' => 'Rediseño web', 'client' => 'Hoteles Mirador', 'billing' => 'hour_bank', 'status' => 'active', 'owner' => 'raul', 'members' => ['elena', 'lucia', 'sergio'], 'from' => 4, 'to' => 0, 'rate' => null,
            'banks' => [['Bolsa general', null, 'healthy', 'inherit', 4, 0]]],
        ['code' => 'MIR-SOP', 'name' => 'Soporte y mantenimiento', 'client' => 'Hoteles Mirador', 'billing' => 'hour_bank', 'status' => 'active', 'owner' => 'marta', 'members' => ['pablo', 'sergio'], 'from' => 12, 'to' => 0, 'rate' => '52.00',
            'banks' => [['Soporte – año anterior', 'Desarrollo', 'closed', 'allow', 12, 5], ['Soporte – año en curso', 'Desarrollo', 'healthy', 'allow', 5, 0]]],
        ['code' => 'LAM-INT', 'name' => 'Intranet de obra', 'client' => 'Construcciones Lamas', 'billing' => 'time_and_materials', 'status' => 'active', 'owner' => 'marta', 'members' => ['pablo', 'sergio', 'lucia'], 'from' => 8, 'to' => 0, 'rate' => '60.00', 'banks' => []],
        ['code' => 'FAR-SHOP', 'name' => 'Tienda online', 'client' => 'Librería El Faro', 'billing' => 'fixed_price', 'status' => 'active', 'owner' => 'marta', 'members' => ['sergio', 'elena', 'lucia'], 'from' => 6, 'to' => 0, 'rate' => null, 'price' => '9800.00', 'banks' => []],
        ['code' => 'FAR-RRSS', 'name' => 'Redes sociales', 'client' => 'Librería El Faro', 'billing' => 'hour_bank', 'status' => 'active', 'owner' => 'nuria', 'members' => ['irene', 'daniel'], 'from' => 9, 'to' => 0, 'rate' => null,
            'banks' => [['Bolsa contenidos', 'Marketing', 'exhausted', 'allow', 9, 0]]],
        ['code' => 'FER-MARCA', 'name' => 'Identidad de marca', 'client' => 'Grupo Ferrán Logística', 'billing' => 'fixed_price', 'status' => 'completed', 'owner' => 'raul', 'members' => ['elena', 'lucia'], 'from' => 11, 'to' => 7, 'rate' => null, 'price' => '6400.00', 'banks' => []],
        ['code' => 'FER-PORTAL', 'name' => 'Portal de clientes', 'client' => 'Grupo Ferrán Logística', 'billing' => 'hour_bank', 'status' => 'active', 'owner' => 'marta', 'members' => ['pablo', 'sergio', 'lucia'], 'from' => 7, 'to' => 0, 'rate' => '62.00',
            'banks' => [['Bolsa desarrollo', 'Desarrollo', 'healthy', 'inherit', 7, 0]]],
        ['code' => 'MON-TEMP', 'name' => 'Lanzamiento cerveza de temporada', 'client' => 'Cervezas Montaña', 'billing' => 'hour_bank', 'status' => 'on_hold', 'owner' => 'nuria', 'members' => ['irene', 'daniel', 'elena'], 'from' => 4, 'to' => 1, 'rate' => null,
            'banks' => [['Bolsa campaña', null, 'healthy', 'inherit', 4, 1]]],
        ['code' => 'AUL-WEB', 'name' => 'Web de la fundación', 'client' => 'Fundación Aula Viva', 'billing' => 'time_and_materials', 'status' => 'archived', 'owner' => 'raul', 'members' => ['elena', 'sergio'], 'from' => 12, 'to' => 9, 'rate' => '50.00', 'banks' => []],
        ['code' => 'MON-MICRO', 'name' => 'Microsite de verano', 'client' => 'Cervezas Montaña', 'billing' => 'time_and_materials', 'status' => 'planned', 'owner' => 'nuria', 'members' => ['daniel', 'lucia'], 'from' => -1, 'to' => -2, 'rate' => null, 'banks' => []],
    ];

    /**
     * Objetivo de consumo / total de cada estado.
     */
    private const array TARGET_RATIO = [
        'healthy' => 0.55,
        'near' => 0.86,
        'exhausted' => 1.09,
        'block' => 0.96,
        'closed' => 0.8,
        'renewed' => 1.03,
        'renewal' => 0.5,
    ];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('DemoDataSeeder crea datos ficticios: solo se ejecuta en local o testing.');
        }

        if (Project::query()->exists()) {
            return;
        }

        $this->random = new Randomizer(new Mt19937(2026));
        $this->today = LocalTime::today();
        $this->start = $this->today->subMonthsNoOverflow(12)->startOfWeek();

        DB::transaction(function (): void {
            TaskStatus::ensureDefaults();
            $this->statuses = [
                'todo' => TaskStatus::defaultStatus()->id,
                'doing' => TaskStatus::query()->where('category', TaskStatusCategory::InProgress->value)->orderBy('position')->firstOrFail()->id,
                'review' => TaskStatus::query()->where('name', 'En revisión')->value('id') ?? TaskStatus::query()->where('category', TaskStatusCategory::InProgress->value)->orderByDesc('position')->firstOrFail()->id,
                'done' => TaskStatus::query()->where('category', TaskStatusCategory::Done->value)->orderBy('position')->firstOrFail()->id,
            ];

            $this->departments = Department::query()->get()->keyBy('name')->all();
            $this->taskTypes();
            $this->people();
            $clients = $this->clients();

            $projects = [];
            foreach (self::PROJECTS as $definition) {
                $projects[] = $this->project($definition, $clients);
            }
            $internal = $this->internalProject();
            $projects[] = $internal;

            // Antes que las horas: los festivos y las ausencias aprobadas dejan esos días sin
            // capacidad (Capacity), así que nadie imputa en ellos (SPEC §9 y §15).
            $this->holidaysAndAbsences();
            $this->timeEntries($projects, $internal);
            $this->sizeBanks($projects);
            $this->approvalWorkflow();
            if (isset($clients['Hoteles Mirador'])) {
                $this->lockInvoicedHours($clients['Hoteles Mirador']);
            }
            $this->comments($projects);
        });
    }

    /**
     * Fase 3 (SPEC §15): festivos nacionales del año pasado, este y el que viene, y ausencias de
     * ejemplo para que Carga, Inicio y los informes enseñen capacidad reducida desde el primer día:
     * - pasadas y aprobadas (en los 12 meses de horas): una semana de vacaciones de Lucía y otra de
     *   Sergio, un día de formación de Irene, una baja de dos días de Daniel y medio día de permiso
     *   de Pablo,
     * - en las próximas semanas: las vacaciones de Elena (la semana que viene), la formación de
     *   Pablo, medio día de Irene y una solicitud pendiente de Lucía (los E2E cuentan con ellas).
     */
    private function holidaysAndAbsences(): void
    {
        $national = new SpanishNationalHolidays;
        foreach ([$this->today->year - 1, $this->today->year, $this->today->year + 1] as $year) {
            foreach ($national->forYear($year) as $holiday) {
                Holiday::query()->firstOrCreate(['date' => $holiday['date']], ['name' => $holiday['name'], 'scope' => 'company']);
            }
        }

        $thisWeek = $this->today->startOfWeek();
        $monday = $thisWeek->addWeek();
        $absences = [
            // Pasadas, aprobadas por su responsable una semana antes de empezar.
            ['sergio', AbsenceType::Vacation, $thisWeek->subWeeks(20), $thisWeek->subWeeks(20)->addDays(4), null, AbsenceStatus::Approved, 'marta'],
            ['lucia', AbsenceType::Vacation, $thisWeek->subWeeks(10), $thisWeek->subWeeks(10)->addDays(4), null, AbsenceStatus::Approved, 'raul'],
            ['irene', AbsenceType::Training, $thisWeek->subWeeks(6)->addDays(2), $thisWeek->subWeeks(6)->addDays(2), null, AbsenceStatus::Approved, 'nuria'],
            ['daniel', AbsenceType::Sick, $thisWeek->subWeeks(4)->addDays(1), $thisWeek->subWeeks(4)->addDays(2), null, AbsenceStatus::Approved, 'nuria'],
            ['pablo', AbsenceType::Leave, $thisWeek->subWeeks(3)->addDays(3), $thisWeek->subWeeks(3)->addDays(3), 240, AbsenceStatus::Approved, 'marta'],
            // Semana que viene: vacaciones de Elena (Diseño), ya aprobadas por Raúl.
            ['elena', AbsenceType::Vacation, $monday->addDays(1), $monday->addDays(3), null, AbsenceStatus::Approved, 'raul'],
            // Dentro de dos semanas: formación de Pablo (Desarrollo), aprobada por Marta.
            ['pablo', AbsenceType::Training, $monday->addWeek(), $monday->addWeek(), null, AbsenceStatus::Approved, 'marta'],
            // Medio día de Irene (Marketing), aprobado.
            ['irene', AbsenceType::Leave, $monday->addDays(4), $monday->addDays(4), 240, AbsenceStatus::Approved, 'nuria'],
            // Solicitud pendiente de Lucía (Diseño): Raúl la ve en «Ausencias del equipo».
            ['lucia', AbsenceType::Vacation, $monday->addWeeks(3), $monday->addWeeks(3)->addDays(4), null, AbsenceStatus::Requested, null],
        ];

        foreach ($absences as [$who, $type, $from, $to, $partial, $status, $approver]) {
            $reviewer = is_string($approver) ? $this->people[$approver] : null;
            // Las pasadas se pidieron y aprobaron antes de empezar; las futuras, ayer.
            $reviewed = $from < $this->today ? $from->subWeek()->setTime(10, 0) : $this->today->subDay();

            $absence = new Absence([
                'user_id' => $this->people[$who]->id,
                'type' => $type,
                'start_date' => $from->toDateString(),
                'end_date' => $to->toDateString(),
                'partial_minutes' => $partial,
                'status' => $status,
                'approved_by' => $reviewer?->id,
                'reviewed_at' => $reviewer === null ? null : $reviewed,
            ]);
            if ($from < $this->today) {
                $absence->created_at = $reviewed->subDay();
                $absence->updated_at = $reviewed;
            }
            $absence->save();
        }
    }

    private function taskTypes(): void
    {
        foreach (TaskType::DEFAULTS as $position => $type) {
            $this->types[$type['name']] = TaskType::query()->firstOrCreate(['name' => $type['name']], [
                'color' => $type['color'],
                'icon' => $type['icon'],
                'department_id' => $type['department'] !== null ? $this->departments[$type['department']]->id : null,
                'is_billable_default' => $type['is_billable_default'],
                'position' => $position,
            ]);
        }
    }

    private function people(): void
    {
        $definitions = [
            'ana' => ['Ana Administración', 'admin@example.com', Role::Admin, null, '38.00', '75.00'],
            'raul' => ['Raúl Responsable', 'responsable@example.com', Role::DepartmentManager, 'Diseño', '32.00', '62.00'],
            'elena' => ['Elena Empleada', 'empleado@example.com', Role::Employee, 'Diseño', '24.00', '52.00'],
            'lucia' => ['Lucía Martín', 'lucia.martin@example.com', Role::Employee, 'Diseño', '23.00', '50.00'],
            'marta' => ['Marta Iglesias', 'marta.iglesias@example.com', Role::DepartmentManager, 'Desarrollo', '34.00', '65.00'],
            'pablo' => ['Pablo Ruiz', 'pablo.ruiz@example.com', Role::Employee, 'Desarrollo', '27.00', '58.00'],
            'sergio' => ['Sergio Gómez', 'sergio.gomez@example.com', Role::Employee, 'Desarrollo', '26.00', '56.00'],
            'nuria' => ['Nuria Campos', 'nuria.campos@example.com', Role::DepartmentManager, 'Marketing', '31.00', '60.00'],
            'irene' => ['Irene Castro', 'irene.castro@example.com', Role::Employee, 'Marketing', '22.00', '48.00'],
            'daniel' => ['Daniel Ortega', 'daniel.ortega@example.com', Role::Employee, 'Marketing', '23.00', '48.00'],
        ];

        foreach ($definitions as $key => [$name, $email, $role, $department, $cost, $rate]) {
            $user = User::query()->firstOrCreate(['email' => $email], [
                'name' => $name,
                'password' => 'password',
                'email_verified_at' => now(),
            ]);
            $user->forceFill([
                'department_id' => $department !== null ? $this->departments[$department]->id : null,
                'hourly_cost' => $cost,
                'default_hourly_rate' => $rate,
            ])->save();
            $user->syncRoles([$role->value]);

            if ($role === Role::DepartmentManager) {
                $this->departments[$department]->managers()->syncWithoutDetaching([$user->id]);
            }

            $this->people[$key] = $user;
        }

        // Horarios: jornada completa por defecto; Irene a media jornada; Pablo, intensiva en verano.
        foreach ($this->people as $key => $user) {
            if ($user->workSchedules()->exists()) {
                continue;
            }

            if ($key === 'irene') {
                WorkSchedule::query()->create(['user_id' => $user->id, 'valid_from' => $this->start->toDateString(), 'mon_minutes' => 360, 'tue_minutes' => 360, 'wed_minutes' => 360, 'thu_minutes' => 360, 'fri_minutes' => 360]);

                continue;
            }

            if ($key === 'pablo') {
                $summer = CarbonImmutable::create($this->today->year - ($this->today->month < 7 ? 1 : 0), 7, 1);
                WorkSchedule::query()->create(['user_id' => $user->id, 'valid_from' => $this->start->toDateString(), 'valid_to' => $summer->subDay()->toDateString(), 'mon_minutes' => 480, 'tue_minutes' => 480, 'wed_minutes' => 480, 'thu_minutes' => 480, 'fri_minutes' => 480]);
                WorkSchedule::query()->create(['user_id' => $user->id, 'valid_from' => $summer->toDateString(), 'valid_to' => $summer->addMonths(2)->subDay()->toDateString(), 'mon_minutes' => 420, 'tue_minutes' => 420, 'wed_minutes' => 420, 'thu_minutes' => 420, 'fri_minutes' => 420]);
                WorkSchedule::query()->create(['user_id' => $user->id, 'valid_from' => $summer->addMonths(2)->toDateString(), 'mon_minutes' => 480, 'tue_minutes' => 480, 'wed_minutes' => 480, 'thu_minutes' => 480, 'fri_minutes' => 480]);

                continue;
            }

            WorkSchedule::query()->create(['user_id' => $user->id, 'valid_from' => $this->start->toDateString(), 'mon_minutes' => 480, 'tue_minutes' => 480, 'wed_minutes' => 480, 'thu_minutes' => 480, 'fri_minutes' => 480]);
        }
    }

    /**
     * @return array<string, Client>
     */
    private function clients(): array
    {
        $definitions = [
            ['Bodegas Arrieta', 'B26123456', 'Íñigo Arrieta', 'inigo@bodegasarrieta.example', '941 000 111', '62.00', true],
            ['Clínica Dental Sonrisas', 'B28111222', 'Carmen Ferrer', 'carmen@sonrisas.example', '910 000 222', '58.00', true],
            ['Hoteles Mirador', 'A35999888', 'Jorge Santana', 'jorge@hotelesmirador.example', '928 000 333', '55.00', true],
            ['Construcciones Lamas', 'B15444555', 'Rosa Lamas', 'rosa@lamas.example', '981 000 444', null, true],
            ['Librería El Faro', 'B39777666', 'Tomás Herrero', 'tomas@elfaro.example', '942 000 555', '50.00', true],
            ['Grupo Ferrán Logística', 'A08555444', 'Montse Ferrán', 'montse@ferran.example', '933 000 666', '65.00', true],
            ['Cervezas Montaña', 'B22333111', 'Álvaro Pueyo', 'alvaro@cervezasmontana.example', '974 000 777', '52.00', true],
            ['Fundación Aula Viva', 'G46222333', 'Laura Pastor', 'laura@aulaviva.example', '963 000 888', '45.00', false],
        ];

        $clients = [];
        foreach ($definitions as [$name, $taxId, $contact, $email, $phone, $rate, $active]) {
            $clients[$name] = Client::withoutEvents(fn () => Client::query()->create([
                'name' => $name,
                'tax_id' => $taxId,
                'contact_name' => $contact,
                'contact_email' => $email,
                'phone' => $phone,
                'default_hourly_rate' => $rate,
                'is_active' => $active,
            ]));
        }

        // El usuario del portal (Fase 5) pertenece al primer cliente.
        $first = $clients['Bodegas Arrieta'] ?? null;
        if ($first !== null) {
            User::query()->where('email', 'cliente@example.com')->update(['client_id' => $first->id]);
        }

        return $clients;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, Client>  $clients
     * @return array{project: Project, banks: list<array{bank: HourBank, state: string, from: CarbonImmutable, to: CarbonImmutable}>, tasks: list<Task>, from: CarbonImmutable, to: CarbonImmutable, members: list<User>}
     */
    private function project(array $definition, array $clients): array
    {
        $from = $this->monthsAgo((int) $definition['from']);
        $to = $this->monthsAgo((int) $definition['to']);
        $owner = $this->people[(string) $definition['owner']];

        /** @var Project $project */
        $project = Project::withoutEvents(fn () => Project::query()->create([
            'client_id' => $clients[(string) $definition['client']]->id,
            'name' => $definition['name'],
            'code' => $definition['code'],
            'description' => null,
            'color' => $this->random->getInt(0, 1) === 1 ? '#0171FF' : '#179FA5',
            'billing_type' => BillingType::from((string) $definition['billing']),
            'status' => ProjectStatus::from((string) $definition['status']),
            'start_date' => $from->toDateString(),
            'due_date' => (int) $definition['to'] > 0 ? $to->toDateString() : null,
            'fixed_price_amount' => $definition['price'] ?? null,
            'hourly_rate' => $definition['rate'],
            'owner_user_id' => $owner->id,
        ]));
        $project->addMember($owner, isManager: true);

        /** @var list<User> $members */
        $members = [$owner];
        foreach ((array) $definition['members'] as $key) {
            $project->addMember($this->people[(string) $key]);
            $members[] = $this->people[(string) $key];
        }

        $banks = [];
        $previous = null;
        foreach ((array) $definition['banks'] as [$name, $department, $state, $policy, $bankFrom, $bankTo]) {
            $bankStart = $this->monthsAgo((int) $bankFrom);
            $bankEnd = $this->monthsAgo((int) $bankTo);
            $bank = HourBank::withoutEvents(fn () => HourBank::query()->create([
                'project_id' => $project->id,
                'name' => $name,
                'department_id' => $department !== null ? $this->departments[$department]->id : null,
                'total_minutes' => 60 * 60,
                'start_date' => $bankStart->toDateString(),
                'end_date' => in_array($state, ['closed', 'renewed'], true) ? $bankEnd->toDateString() : null,
                'overage_policy' => OveragePolicy::from((string) $policy),
                'renewed_from_id' => $state === 'renewal' ? $previous?->id : null,
                'invoice_reference' => $state === 'closed' ? 'FAC-'.$bankEnd->format('Y').'-017' : null,
            ]));
            $banks[] = ['bank' => $bank, 'state' => (string) $state, 'from' => $bankStart, 'to' => $bankEnd];
            $previous = $bank;
        }

        $tasks = $this->tasks($project, $banks, $members, $from, $to);

        return ['project' => $project, 'banks' => $banks, 'tasks' => $tasks, 'from' => $from, 'to' => $to, 'members' => $members];
    }

    /**
     * @param  list<array{bank: HourBank, state: string, from: CarbonImmutable, to: CarbonImmutable}>  $banks
     * @param  list<User>  $members
     * @return list<Task>
     */
    private function tasks(Project $project, array $banks, array $members, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $catalogue = [
            'Diseño' => [['Diseño UI', 'Diseño de la home'], ['Diseño UI', 'Diseño de fichas de producto'], ['Diseño UI', 'Guía de estilo'], ['Diseño UI', 'Prototipo móvil'], ['Reunión', 'Presentación de propuestas'], ['Gestión', 'Revisión con el cliente']],
            'Desarrollo' => [['Maquetación', 'Maquetación de la home'], ['Desarrollo', 'Integración del CMS'], ['Desarrollo', 'Pasarela de pago'], ['Bug', 'Corregir formulario de contacto'], ['Soporte', 'Actualizaciones de seguridad'], ['Desarrollo', 'Área privada'], ['Maquetación', 'Plantillas de email']],
            'Marketing' => [['SEO', 'Auditoría SEO'], ['SEO', 'Optimización de fichas locales'], ['Contenidos', 'Calendario editorial'], ['Contenidos', 'Artículos del blog'], ['Campaña', 'Campaña de lanzamiento'], ['Campaña', 'Informe mensual de campañas']],
        ];

        $groups = $banks === [] ? [['bank' => null, 'from' => $from, 'to' => $to]] : array_map(fn (array $bank): array => ['bank' => $bank['bank'], 'from' => $bank['from'], 'to' => $bank['to']], $banks);
        $tasks = [];
        $position = 0;

        foreach ($groups as $group) {
            /** @var HourBank|null $bank */
            $bank = $group['bank'];
            $eligible = array_values(array_filter($members, fn (User $user): bool => $bank === null || $bank->department_id === null || $user->department_id === $bank->department_id));
            $departments = array_values(array_unique(array_filter(array_map(fn (User $user): ?string => $this->departmentName($user), $eligible))));
            $pool = [];
            foreach ($departments as $department) {
                array_push($pool, ...$catalogue[$department]);
            }
            if ($pool === []) {
                $pool = $catalogue['Diseño'];
            }

            foreach ($pool as [$type, $title]) {
                $assignee = $this->pick($eligible === [] ? $members : $eligible);
                $taskFrom = $group['from'];
                $taskTo = $group['to'];
                $ended = $taskTo < $this->today->subWeeks(2);
                $progress = $this->random->getInt(0, 9);
                $status = $ended ? 'done' : ($progress < 3 ? 'todo' : ($progress < 7 ? 'doing' : ($progress < 8 ? 'review' : 'done')));

                /** @var Task $task */
                $task = Task::withoutEvents(fn () => Task::query()->forceCreate([
                    'project_id' => $project->id,
                    'hour_bank_id' => $bank?->id,
                    'title' => $title,
                    'task_type_id' => $this->types[$type]->id,
                    'status_id' => $this->statuses[$status],
                    'priority' => $this->pick([TaskPriority::Normal, TaskPriority::Normal, TaskPriority::High, TaskPriority::Low, TaskPriority::Urgent])->value,
                    'assignee_user_id' => $assignee->id,
                    'start_date' => $taskFrom->toDateString(),
                    'due_date' => $status === 'done' ? $taskTo->toDateString() : $this->today->addDays($this->random->getInt(-6, 20))->toDateString(),
                    'estimated_minutes' => $this->random->getInt(4, 30) * 60,
                    'is_billable' => ! $project->isInternal(),
                    'position' => $position++,
                    'completed_at' => $status === 'done' ? $taskTo->setTime(17, 0) : null,
                    'created_by' => $project->owner_user_id,
                    'created_at' => $taskFrom->setTime(9, 0),
                    'updated_at' => $taskFrom->setTime(9, 0),
                ]));
                $task->watchers()->syncWithoutDetaching(array_unique([$assignee->id, $project->owner_user_id]));
                $tasks[] = $task;
            }
        }

        // Un hito por proyecto con fechas (sin horas).
        if ($to > $this->today) {
            Task::withoutEvents(fn () => Task::query()->forceCreate([
                'project_id' => $project->id,
                'hour_bank_id' => $banks === [] ? null : end($banks)['bank']->id,
                'title' => 'Entrega al cliente',
                'status_id' => $this->statuses['todo'],
                'priority' => TaskPriority::High->value,
                'due_date' => $this->today->addWeeks(3)->toDateString(),
                'is_billable' => false,
                'is_milestone' => true,
                'position' => $position,
                'created_by' => $project->owner_user_id,
            ]));
        }

        return $tasks;
    }

    /**
     * @return array{project: Project, banks: list<array{bank: HourBank, state: string, from: CarbonImmutable, to: CarbonImmutable}>, tasks: list<Task>, from: CarbonImmutable, to: CarbonImmutable, members: list<User>}
     */
    private function internalProject(): array
    {
        /** @var Project $project */
        $project = Project::withoutEvents(fn () => Project::query()->create([
            'client_id' => null,
            'name' => 'Interno – Agencia',
            'code' => Project::INTERNAL_CODE,
            'color' => '#56667A',
            'billing_type' => BillingType::Internal,
            'status' => ProjectStatus::Active,
            'start_date' => $this->start->toDateString(),
            'owner_user_id' => $this->people['ana']->id,
        ]));
        $project->addMember($this->people['ana'], isManager: true);

        $types = ['Reuniones' => 'Reunión', 'Formación' => 'Gestión', 'Gestión' => 'Gestión', 'Comercial' => 'Gestión'];
        $tasks = [];
        foreach (Project::INTERNAL_TASKS as $position => $title) {
            $tasks[] = Task::withoutEvents(fn () => Task::query()->forceCreate([
                'project_id' => $project->id,
                'title' => $title,
                'task_type_id' => $this->types[$types[$title]]->id,
                'status_id' => $this->statuses['doing'],
                'is_billable' => false,
                'position' => $position,
                'created_by' => $this->people['ana']->id,
            ]));
        }

        return ['project' => $project, 'banks' => [], 'tasks' => $tasks, 'from' => $this->start, 'to' => $this->today, 'members' => array_values($this->people)];
    }

    /**
     * 12 meses de horas: cada día con capacidad (Capacity, que ya descuenta los festivos y las
     * ausencias aprobadas: nadie imputa en un festivo ni en un día de vacaciones, y con medio día
     * de permiso se imputa la mitad), cada persona imputa casi su jornada repartida entre 2 y 4
     * tareas de proyectos donde puede imputar (miembro y departamento de la bolsa), con un 10 % a
     * reuniones internas y dos semanas sin imputar en verano.
     *
     * @param  list<array{project: Project, banks: list<array{bank: HourBank, state: string, from: CarbonImmutable, to: CarbonImmutable}>, tasks: list<Task>, from: CarbonImmutable, to: CarbonImmutable, members: list<User>}>  $projects
     * @param  array{project: Project, banks: list<array{bank: HourBank, state: string, from: CarbonImmutable, to: CarbonImmutable}>, tasks: list<Task>, from: CarbonImmutable, to: CarbonImmutable, members: list<User>}  $internal
     */
    private function timeEntries(array $projects, array $internal): void
    {
        $bankWindows = [];
        $bankDepartments = [];
        foreach ($projects as $data) {
            foreach ($data['banks'] as $bank) {
                $bankWindows[$bank['bank']->id] = [$bank['from'], $bank['to']];
                $bankDepartments[$bank['bank']->id] = $bank['bank']->department_id;
            }
        }
        $memberIds = [];
        foreach ($projects as $index => $data) {
            $memberIds[$index] = array_map(fn (User $member): int => $member->id, $data['members']);
        }

        $descriptions = ['Avances y revisión', 'Ajustes tras el feedback', 'Trabajo en la tarea', 'Revisión interna', 'Preparación de la entrega', null, null];
        $rows = [];
        $capacity = app(Capacity::class);

        foreach ($this->people as $key => $user) {
            $vacationStart = CarbonImmutable::create($this->today->year - ($this->today->month < 8 ? 1 : 0), 8, 3 + $this->random->getInt(0, 14))->startOfWeek();
            $days = $capacity->forRange($user, $this->start, $this->today);

            foreach (CarbonPeriod::create($this->start, $this->today) as $day) {
                $date = CarbonImmutable::parse($day->toDateString());
                $minutes = $days[$date->toDateString()] ?? 0;

                if ($minutes === 0 || ($date >= $vacationStart && $date < $vacationStart->addWeeks(2)) || $this->random->getInt(1, 100) <= 3) {
                    continue;
                }

                // El admin y los responsables imputan menos a proyectos (gestión).
                $target = (int) round($minutes * ($key === 'ana' ? 0.35 : $this->random->getInt(80, 100) / 100) / 15) * 15;
                if ($date->isSameDay($this->today)) {
                    $target = (int) round($target / 2 / 15) * 15;
                }

                $candidates = [];
                foreach ($projects as $index => $data) {
                    if ($data['project']->isInternal() || $date < $data['from'] || $date > $data['to'] || ! in_array($user->id, $memberIds[$index], true)) {
                        continue;
                    }
                    foreach ($data['tasks'] as $task) {
                        if ($task->hour_bank_id !== null) {
                            [$bankFrom, $bankTo] = $bankWindows[$task->hour_bank_id];
                            $department = $bankDepartments[$task->hour_bank_id];
                            if ($date < $bankFrom || $date > $bankTo || ($department !== null && $department !== $user->department_id)) {
                                continue;
                            }
                        }
                        $candidates[] = $task;
                    }
                }

                $internalShare = $candidates === [] ? $target : (int) round($target * 0.1 / 15) * 15;
                $remaining = $target - $internalShare;

                if ($internalShare > 0) {
                    $rows[] = $this->entryRow($user, $this->pick($internal['tasks']), $date, $internalShare, 'Reunión de equipo');
                }

                $parts = $candidates === [] ? 0 : $this->random->getInt(2, 4);
                for ($i = 0; $i < $parts && $remaining > 0; $i++) {
                    $chunk = $i === $parts - 1 ? $remaining : max((int) round($remaining / ($parts - $i) * $this->random->getInt(70, 130) / 100 / 15) * 15, 15);
                    $chunk = min($chunk, $remaining);
                    $rows[] = $this->entryRow($user, $this->pick($candidates), $date, $chunk, $this->pick($descriptions));
                    $remaining -= $chunk;
                }
            }
        }

        TimeEntry::withoutEvents(function () use ($rows): void {
            foreach (array_chunk($rows, 500) as $chunk) {
                TimeEntry::query()->insert($chunk);
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function entryRow(User $user, Task $task, CarbonImmutable $date, int $minutes, ?string $description): array
    {
        $created = $date->setTime(18, $this->random->getInt(0, 59));

        return [
            'user_id' => $user->id,
            'task_id' => $task->id,
            'project_id' => $task->project_id,
            'hour_bank_id' => $task->hour_bank_id,
            'date' => $date->toDateString(),
            'minutes' => $minutes,
            'overage_minutes' => 0,
            'description' => $description,
            'is_billable' => $task->is_billable,
            'status' => TimeEntryStatus::Draft->value,
            'created_by' => $user->id,
            'created_at' => $created,
            'updated_at' => $created,
        ];
    }

    /**
     * Ajusta el total de cada bolsa a su estado objetivo y recalcula con HourBankLedger (sin avisos).
     *
     * @param  list<array{project: Project, banks: list<array{bank: HourBank, state: string, from: CarbonImmutable, to: CarbonImmutable}>, tasks: list<Task>, from: CarbonImmutable, to: CarbonImmutable, members: list<User>}>  $projects
     */
    private function sizeBanks(array $projects): void
    {
        $ledger = app(HourBankLedger::class);

        foreach ($projects as $data) {
            foreach ($data['banks'] as ['bank' => $bank, 'state' => $state]) {
                $consumed = (int) TimeEntry::query()->where('hour_bank_id', $bank->id)->sum('minutes');
                // La bolsa «block» se queda con 0:45 libres exactos: los E2E comprueban que rechaza lo que no cabe.
                $total = $state === 'block'
                    ? $consumed + 45
                    : max((int) round($consumed / self::TARGET_RATIO[$state] / 300) * 300, 600);

                HourBank::withoutEvents(fn () => $bank->forceFill([
                    'total_minutes' => $total,
                    'hourly_rate' => $data['project']->hourly_rate,
                    'price_amount' => number_format($total / 60 * 55, 2, '.', ''),
                ])->save());

                $ledger->recalculate($bank, notify: false);

                if ($state === 'closed') {
                    HourBank::withoutEvents(fn () => $bank->forceFill([
                        'status' => HourBankStatus::Closed,
                        'closed_at' => $bank->end_date?->setTime(18, 0),
                        'closed_by' => $data['project']->owner_user_id,
                        'closed_remaining_minutes' => $bank->remaining_minutes,
                    ])->save());
                } elseif ($state === 'renewed') {
                    HourBank::withoutEvents(fn () => $bank->forceFill(['status' => HourBankStatus::Renewed])->save());
                }
            }
        }
    }

    /**
     * Semanas: esta, en borrador; la pasada, enviada (una devuelta con comentario); las anteriores,
     * aprobadas con sus instantáneas de tarifa (bolsa > proyecto > cliente > persona) y coste.
     */
    private function approvalWorkflow(): void
    {
        $currentWeek = $this->today->startOfWeek();
        $lastWeek = $currentWeek->subWeek();
        $approvers = [];
        foreach ($this->people as $user) {
            $manager = $user->department_id === null ? null : collect($this->people)->first(fn (User $candidate): bool => $candidate->isDepartmentManager() && $candidate->department_id === $user->department_id && $candidate->id !== $user->id);
            $approvers[$user->id] = $manager->id ?? $this->people['ana']->id;
        }

        $rates = DB::table('time_entries')
            ->join('projects', 'projects.id', '=', 'time_entries.project_id')
            ->leftJoin('clients', 'clients.id', '=', 'projects.client_id')
            ->leftJoin('hour_banks', 'hour_banks.id', '=', 'time_entries.hour_bank_id')
            ->join('users', 'users.id', '=', 'time_entries.user_id')
            ->where('time_entries.date', '<', $lastWeek->toDateString())
            ->select(['time_entries.id', 'time_entries.user_id', 'time_entries.date', 'hour_banks.hourly_rate as bank_rate', 'projects.hourly_rate as project_rate', 'clients.default_hourly_rate as client_rate', 'users.default_hourly_rate as user_rate', 'users.hourly_cost as cost'])
            ->orderBy('time_entries.id')
            ->get();

        foreach ($rates->chunk(500) as $chunk) {
            foreach ($chunk as $row) {
                $weekEnd = CarbonImmutable::parse((string) $row->date)->endOfWeek()->addDays(2)->setTime(10, 0);
                DB::table('time_entries')->where('id', $row->id)->update([
                    'status' => TimeEntryStatus::Approved->value,
                    'hourly_rate_snapshot' => $row->bank_rate ?? $row->project_rate ?? $row->client_rate ?? $row->user_rate,
                    'hourly_cost_snapshot' => $row->cost,
                    'approved_by' => $approvers[(int) $row->user_id],
                    'approved_at' => $weekEnd,
                ]);
            }
        }

        DB::table('time_entries')
            ->whereBetween('date', [$lastWeek->toDateString(), $lastWeek->endOfWeek()->toDateString()])
            ->where('user_id', '!=', $this->people['elena']->id)
            ->update(['status' => TimeEntryStatus::Submitted->value]);

        $periods = [];
        foreach ($this->people as $user) {
            for ($week = $this->start; $week < $lastWeek; $week = $week->addWeek()) {
                $periods[] = [
                    'user_id' => $user->id,
                    'week_start' => $week->toDateString(),
                    'status' => TimesheetStatus::Approved->value,
                    'submitted_at' => $week->endOfWeek()->addDay()->setTime(9, 0),
                    'reviewed_by' => $approvers[$user->id],
                    'reviewed_at' => $week->endOfWeek()->addDays(2)->setTime(10, 0),
                    'created_at' => $week->endOfWeek()->addDay()->setTime(9, 0),
                    'updated_at' => $week->endOfWeek()->addDays(2)->setTime(10, 0),
                ];
            }

            $returned = $user->id === $this->people['elena']->id;
            $periods[] = [
                'user_id' => $user->id,
                'week_start' => $lastWeek->toDateString(),
                'status' => $returned ? TimesheetStatus::Returned->value : TimesheetStatus::Submitted->value,
                'submitted_at' => $lastWeek->endOfWeek()->addDay()->setTime(9, 0),
                'reviewed_by' => $returned ? $approvers[$user->id] : null,
                'reviewed_at' => $returned ? $lastWeek->endOfWeek()->addDays(2)->setTime(10, 0) : null,
                'review_comment' => $returned ? 'Revisa el jueves: falta la descripción en las horas de maquetación.' : null,
                'created_at' => $lastWeek->endOfWeek()->addDay()->setTime(9, 0),
                'updated_at' => $lastWeek->endOfWeek()->addDay()->setTime(9, 0),
            ];
        }

        foreach (array_chunk($periods, 500) as $chunk) {
            DB::table('timesheet_periods')->insert(array_map(fn (array $row): array => $row + ['review_comment' => null], $chunk));
        }
    }

    /**
     * Horas facturadas de Hoteles Mirador hasta hace 4 meses: bloqueadas por un admin (D-034).
     */
    private function lockInvoicedHours(Client $client): void
    {
        $until = $this->monthsAgo(4)->endOfMonth();
        $lock = TimeEntryLock::query()->create([
            'client_id' => $client->id,
            'date_from' => $this->start->toDateString(),
            'date_to' => $until->toDateString(),
            'locked_by' => $this->people['ana']->id,
            'reference' => 'FAC-'.$until->format('Y').'-031',
        ]);

        $count = DB::table('time_entries')
            ->whereIn('project_id', Project::query()->where('client_id', $client->id)->select('id'))
            ->where('date', '<=', $until->toDateString())
            ->where('status', TimeEntryStatus::Approved->value)
            ->update([
                'status' => TimeEntryStatus::Locked->value,
                'locked_at' => $until->addDays(3)->setTime(12, 0),
                'time_entry_lock_id' => $lock->id,
            ]);

        $lock->update(['entries_count' => $count]);

        // Las semanas con todas sus horas bloqueadas quedan bloqueadas (D-034).
        $weeks = DB::table('time_entries')
            ->where('time_entry_lock_id', $lock->id)
            ->select(['user_id', 'date'])
            ->get()
            ->map(fn (object $row): string => $row->user_id.'|'.CarbonImmutable::parse((string) $row->date)->startOfWeek()->toDateString())
            ->unique();

        foreach ($weeks as $week) {
            [$userId, $weekStart] = explode('|', $week);
            $open = DB::table('time_entries')
                ->where('user_id', (int) $userId)
                ->whereBetween('date', [$weekStart, CarbonImmutable::parse($weekStart)->addDays(6)->toDateString()])
                ->where('status', '!=', TimeEntryStatus::Locked->value)
                ->exists();

            if (! $open) {
                DB::table('timesheet_periods')
                    ->where('user_id', (int) $userId)
                    ->where('week_start', $weekStart)
                    ->update(['status' => TimesheetStatus::Locked->value]);
            }
        }
    }

    /**
     * Algunos comentarios (con menciones) en tareas abiertas recientes.
     *
     * @param  list<array{project: Project, banks: list<array{bank: HourBank, state: string, from: CarbonImmutable, to: CarbonImmutable}>, tasks: list<Task>, from: CarbonImmutable, to: CarbonImmutable, members: list<User>}>  $projects
     */
    private function comments(array $projects): void
    {
        foreach ($projects as $data) {
            if ($data['project']->isInternal()) {
                continue;
            }

            foreach (array_slice($data['tasks'], 0, 3) as $task) {
                $author = $this->pick($data['members']);
                $mentioned = $this->pick($data['members']);
                TaskComment::query()->create([
                    'task_id' => $task->id,
                    'user_id' => $author->id,
                    'body' => '<p>He subido una primera versión. <span data-type="mention" data-id="'.$mentioned->id.'" data-label="'.e($mentioned->name).'">@'.e($mentioned->name).'</span>, ¿le echas un vistazo?</p>',
                    'mentioned_user_ids' => [$mentioned->id],
                    'created_at' => $this->today->subDays($this->random->getInt(1, 20))->setTime(11, 0),
                ]);
            }
        }
    }

    private function monthsAgo(int $months): CarbonImmutable
    {
        return $months >= 0 ? $this->today->subMonthsNoOverflow($months) : $this->today->addMonthsNoOverflow(-$months);
    }

    private function departmentName(User $user): ?string
    {
        foreach ($this->departments as $name => $department) {
            if ($department->id === $user->department_id) {
                return $name;
            }
        }

        return null;
    }

    /**
     * @template T
     *
     * @param  array<int, T>  $items
     * @return T
     */
    private function pick(array $items): mixed
    {
        $items = array_values($items);

        return $items[$this->random->getInt(0, count($items) - 1)];
    }
}
