<?php

namespace Database\Seeders;

use App\Domain\Absences\LeaveLedger;
use App\Domain\Absences\SpanishNationalHolidays;
use App\Domain\Absences\ValenciaHolidays;
use App\Domain\Billing\Holded\FakeHolded;
use App\Domain\Billing\Holded\HoldedSync;
use App\Domain\Billing\HoldedInvoiceLinker;
use App\Domain\Billing\InvoiceLinkSuggester;
use App\Domain\Chat\ConversationDirectory;
use App\Domain\Chat\MessageWriter;
use App\Domain\Forecast\AllocationWriter;
use App\Domain\Forecast\ForecastLinker;
use App\Domain\Forecast\ForecastProjectWriter;
use App\Domain\HourBanks\HourBankLedger;
use App\Domain\People\ClockCorrectionService;
use App\Domain\People\ClockWriter;
use App\Domain\People\MonthCloser;
use App\Domain\People\OvertimeService;
use App\Domain\People\PeopleAccess;
use App\Domain\People\PeopleDocuments;
use App\Domain\People\RegisterAnchors;
use App\Domain\People\Reports\RegisterDataset;
use App\Domain\People\TimeBalanceLedger;
use App\Domain\Privacy\PrivacyNotice;
use App\Domain\Time\Capacity;
use App\Domain\Weeklies\WeeklyCalendar;
use App\Enums\AbsenceStatus;
use App\Enums\AbsenceType;
use App\Enums\BalanceMovementKind;
use App\Enums\BillingType;
use App\Enums\CancellationStatus;
use App\Enums\ClockEventKind;
use App\Enums\ClockSource;
use App\Enums\DayPlanItemOrigin;
use App\Enums\DayPlanItemStatus;
use App\Enums\HourBankStatus;
use App\Enums\InvoiceLineKind;
use App\Enums\LeaveCalendarDayKind;
use App\Enums\LeaveMovementKind;
use App\Enums\OveragePolicy;
use App\Enums\OvertimeDestination;
use App\Enums\ProjectStatus;
use App\Enums\Role;
use App\Enums\TaskPriority;
use App\Enums\TaskStatusCategory;
use App\Enums\TimeEntryStatus;
use App\Enums\TimesheetStatus;
use App\Enums\WeeklyCycleStatus;
use App\Enums\WeeklyEntrySource;
use App\Enums\WeeklyReminderChannel;
use App\Enums\WorkMode;
use App\Events\Chat\ConversationRead;
use App\Events\Chat\MessagePosted;
use App\Events\Chat\MessageUpdated;
use App\Events\Weeklies\WeeklyChanged;
use App\Models\Absence;
use App\Models\AbsenceDocument;
use App\Models\Client;
use App\Models\Conversation;
use App\Models\DayPlan;
use App\Models\DayPlanComment;
use App\Models\DayPlanItem;
use App\Models\Department;
use App\Models\EmploymentProfile;
use App\Models\ForecastProject;
use App\Models\HoldedInvoice;
use App\Models\Holiday;
use App\Models\HourBank;
use App\Models\LeaveCalendarDay;
use App\Models\LeaveType;
use App\Models\Message;
use App\Models\Project;
use App\Models\Setting;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\TaskStatus;
use App\Models\TaskType;
use App\Models\TimeEntry;
use App\Models\TimeEntryLock;
use App\Models\User;
use App\Models\WeeklyCycle;
use App\Models\WeeklyEntry;
use App\Models\WeeklyReminderRule;
use App\Models\WeeklySubmission;
use App\Models\WorkSchedule;
use App\Support\LocalTime;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;

/**
 * Datos de ejemplo realistas (SPEC §15): 3 departamentos, 10 personas internas, 8 clientes (dos
 * con persona en el portal: Bodegas Arrieta, con bolsas, y Construcciones Lamas, sin ellas),
 * 15 proyectos de todos los tipos, bolsas en todos los estados (activa, casi agotada, agotada con
 * exceso, con política block, cerrada y renovada), festivos nacionales, ausencias pasadas y
 * futuras, 12 meses de horas con su flujo de aprobación (aprobadas, enviadas, devueltas,
 * bloqueadas al facturar y borradores de esta semana), y chat (Fase 6): conversaciones de
 * proyecto, dos directas y un grupo, con menciones, @todos, una reacción, un hilo y un mensaje
 * fijado (sin audios ni adjuntos). Nadie imputa en un día sin capacidad.
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

    /** Persona de ejemplo que aún no ha leído el texto de privacidad (aviso en la app). */
    public const string PRIVACY_PENDING = 'daniel';

    /** Colaboradora externa de ejemplo (Fase 8, D-134) y los proyectos de los que es miembro. */
    public const string COLLABORATOR_EMAIL = 'sara.colaboradora@example.com';

    public const array COLLABORATOR_PROJECTS = ['MIR-WEB', 'FAR-SHOP'];

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

            // Portal (Fase 5, D-064): el cliente de cliente@example.com ve ARR-WEB (tareas, horas por
            // tarea y Gantt); ARR-MKT sigue cerrado al portal.
            Project::query()->where('code', 'ARR-WEB')->update([
                'portal_project_visible' => true,
                'portal_show_task_hours' => true,
                'portal_gantt_visible' => true,
            ]);

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
            $this->overloadedDay($projects);
            $this->collaborator();
            // Sin avisos de tiempo real (D-229): nadie está mirando.
            WeeklyChanged::muted(fn () => $this->weeklies());
            $this->dayPlans();
            $this->forecast();
            $this->clockRegister();
            $this->registerR2();
            $this->leaveR3();
            // Facturación (Fase 12, F1): al final, para no cambiar el resto de los datos de ejemplo.
            $this->billing();
        });

        $this->chat();
    }

    /**
     * Facturación (Fase 12, F1; D-394), SOLO local: datos que hacen hablar a «Vendido frente a real»
     * sin cambiar el resto de los datos de ejemplo (va al final y no usa la semilla común):
     * - los datos fiscales de Audax como emisor (ficticios),
     * - un fee mensual ya convertido (FER-FE1, 20 h y 1.400 € al mes) con las reuniones internas de
     *   Pablo de los últimos 8 meses pasadas a él, y otro importado de ClickUp sin convertir (MIR-FE1,
     *   «Fee mensual de 10 h.», para app:convert-monthly-fees),
     * - el código F de cada bolsa vendida y de los precios cerrados (como los dejó ClickUp, D-135),
     * - y una sincronización con el Holded falso (FakeHolded::fromDatabase): contactos, facturas
     *   coherentes con las bolsas y los fees, una rectificativa, cobros y vencidas, una factura sin
     *   enlazar y un contacto sin casar. Sin PDF: se piden la primera vez que alguien los abre.
     */
    private function billing(): void
    {
        Setting::set('billing_issuer', [
            'legal_name' => 'Audax Studio, S.L. (ficticia)', 'tax_id' => 'B00000000', 'address' => 'Calle de Ejemplo, 1, 2.º',
            'postal_code' => '46001', 'city' => 'València', 'province' => 'Valencia', 'country_code' => 'ES',
            'registry' => 'Registro Mercantil de Valencia (ejemplo)', 'iban' => 'ES0000000000000000000000', 'email' => 'facturacion@example.com', 'phone' => null,
        ]);

        $ferran = Client::query()->where('name', 'Grupo Ferrán Logística')->first();
        $mirador = Client::query()->where('name', 'Hoteles Mirador')->first();
        $internal = Project::query()->where('code', Project::INTERNAL_CODE)->first();
        if ($ferran === null || $mirador === null || $internal === null) {
            return;
        }

        $fee = Project::withoutEvents(fn () => Project::query()->create([
            'client_id' => $ferran->id, 'name' => 'Mantenimiento mensual', 'code' => 'FER-FE1', 'color' => '#3C41AE',
            'billing_type' => BillingType::MonthlyFee, 'status' => ProjectStatus::Active,
            'start_date' => $this->monthsAgo(8)->startOfMonth()->toDateString(),
            'monthly_minutes' => 20 * 60, 'monthly_fee_amount' => '1400.00', 'hourly_rate' => '62.00',
            'owner_user_id' => $this->people['marta']->id,
        ]));
        $fee->addMember($this->people['marta'], isManager: true);
        $fee->addMember($this->people['pablo']);
        $task = Task::withoutEvents(fn () => Task::query()->forceCreate([
            'project_id' => $fee->id, 'title' => 'Mantenimiento y soporte del mes', 'task_type_id' => $this->types['Soporte']->id,
            'status_id' => $this->statuses['doing'], 'is_billable' => true, 'position' => 0, 'created_by' => $this->people['marta']->id,
        ]));
        // Las reuniones internas de Pablo de esos meses pasan a ser horas del fee (sin cambiar el total del día).
        DB::table('time_entries')
            ->where('project_id', $internal->id)
            ->where('user_id', $this->people['pablo']->id)
            ->where('date', '>=', $fee->start_date?->toDateString())
            ->update(['project_id' => $fee->id, 'task_id' => $task->id, 'is_billable' => true, 'description' => 'Mantenimiento y soporte']);

        $legacy = Project::withoutEvents(fn () => Project::query()->create([
            'client_id' => $mirador->id, 'name' => 'Redes sociales', 'code' => 'MIR-FE1', 'color' => '#0892C4',
            'description' => 'Fee mensual de 10 h.', 'billing_type' => BillingType::TimeAndMaterials, 'status' => ProjectStatus::Active,
            'start_date' => $this->monthsAgo(3)->startOfMonth()->toDateString(), 'owner_user_id' => $this->people['nuria']->id,
        ]));
        $legacy->addMember($this->people['nuria'], isManager: true);

        // Código F de ClickUp (D-135): el número de la factura de Holded de cada bolsa vendida y del
        // primer pago de cada precio cerrado.
        $numbers = [];
        $next = function (CarbonImmutable $date) use (&$numbers): string {
            $year = $date->format('y');
            $numbers[$year] = ($numbers[$year] ?? 100) + 1;

            return 'F'.$year.sprintf('%04d', $numbers[$year]);
        };
        foreach (HourBank::query()->whereNotNull('price_amount')->orderBy('start_date')->orderBy('id')->get() as $bank) {
            HourBank::withoutEvents(fn () => $bank->forceFill(['invoice_reference' => $next($bank->start_date)])->save());
        }
        // Su presupuesto de horas, como lo dejó ClickUp («WE1 - 120h - …», D-135): uno holgado, uno
        // justo y uno que se pasa, frente a sus horas reales.
        $ratios = [1.25, 0.95, 0.8];
        foreach (Project::query()->where('billing_type', BillingType::FixedPrice->value)->whereNotNull('fixed_price_amount')->orderBy('id')->get() as $index => $project) {
            $start = $project->start_date ?? $this->today;
            $real = (int) TimeEntry::query()->where('project_id', $project->id)->sum('minutes');
            Project::withoutEvents(fn () => $project->forceFill([
                'description' => trim(($project->description ?? '').' Factura: '.$next($start).'.'),
                'budget_minutes' => $project->budget_minutes ?? max(600, (int) round($real * $ratios[$index % 3] / 600) * 600),
            ])->save());
        }

        app(HoldedSync::class)->run(FakeHolded::fromDatabase($this->today), 'seeder', null, pdfs: false);

        // Las facturas de los fees no llevan proyecto en Holded: alguien ha aceptado la sugerencia de
        // casi todas (enlace a mano, D-388); las dos últimas de cada fee siguen sin enlazar.
        $suggester = app(InvoiceLinkSuggester::class);
        $linker = app(HoldedInvoiceLinker::class);
        $unlinked = HoldedInvoice::query()->whereDoesntHave('links')->with('lines')->orderByDesc('issued_on')->orderByDesc('id')->get();
        $skipped = [];
        foreach ($unlinked as $invoice) {
            $suggestion = $suggester->for($invoice)[0] ?? null;
            if ($suggestion === null || InvoiceLinkSuggester::dominantKind($invoice) !== InvoiceLineKind::Fee) {
                continue;
            }
            $projectId = $suggestion['project']['id'];
            if (! $invoice->is_draft && ($skipped[$projectId] ?? 0) < 2) {
                $skipped[$projectId] = ($skipped[$projectId] ?? 0) + 1;

                continue;
            }
            $linker->link($invoice, Project::query()->findOrFail($projectId), null, $this->people['marta']);
        }
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
                Holiday::query()->firstOrCreate(['date' => $holiday['date']], ['name' => $holiday['name'], 'scope' => 'company', 'level' => $holiday['level']]);
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

    /**
     * La Weekly de ejemplo (Fase 10), para sus E2E (weeklies.spec.ts): las tres semanas anteriores
     * cerradas, con los envíos de la plantilla (Elena siempre a tiempo, Pablo con retraso y Daniel
     * sin enviar la última), y la semana en curso abierta, como estará en producción (D-187). Sin el
     * generador aleatorio, para no cambiar el resto de datos.
     */
    private function weeklies(): void
    {
        if (WeeklyCycle::query()->exists()) {
            return;
        }

        $calendar = new WeeklyCalendar;
        $current = $calendar->current($this->today->setTime(12, 0));
        $people = User::query()
            ->active()
            ->internal()
            ->whereNotIn('email', [self::COLLABORATOR_EMAIL])
            ->orderBy('id')
            ->get()
            ->filter(fn (User $user): bool => $user->writesWeeklies())
            ->values();
        $texts = [
            'Revisión de avances con el cliente y ajustes de la propuesta.',
            'Entregadas las piezas de la semana; pendiente de su validación.',
            'Reunión de seguimiento: acordamos los próximos pasos y las fechas.',
            'Corrección de incidencias y preparación de la siguiente entrega.',
        ];

        for ($weeksAgo = 3; $weeksAgo >= 1; $weeksAgo--) {
            $period = $calendar->periodFor($current->start->subWeeks($weeksAgo));
            // La foto al cerrar (F-092) se fija a mano: la plantilla de ejemplo se da de alta hoy,
            // así que WeeklyEligibility::freeze() no la vería en semanas pasadas.
            $cycle = WeeklyCycle::query()->create([
                ...$period->toAttributes(),
                'status' => WeeklyCycleStatus::Active,
                'expected_user_ids' => $people->modelKeys(),
            ]);
            $deadline = CarbonImmutable::parse($period->deadline->toDateString().' 17:00:00', WeeklyCalendar::TIMEZONE);

            foreach ($people as $index => $person) {
                if ($weeksAgo === 1 && $person->email === 'daniel.ortega@example.com') {
                    continue;
                }

                $late = $person->email === 'pablo.ruiz@example.com' && $weeksAgo === 2;
                $this->weeklySubmission($person, $cycle, $late ? $deadline->addDays(3) : $deadline->subHours($index % 6), $texts, $weeksAgo + $index);
            }

            $cycle->forceFill([
                'status' => WeeklyCycleStatus::Closed,
                'closed_at' => $deadline->addDays(3)->utc(),
            ])->save();
        }

        // La semana en curso, abierta como en producción (la abre el planificador, D-155), con la
        // weekly ya enviada de Elena y un borrador de Pablo; el resto, pendiente.
        $cycle = WeeklyCycle::query()->create([...$current->toAttributes(), 'status' => WeeklyCycleStatus::Active]);
        $deadline = CarbonImmutable::parse($current->deadline->toDateString().' 17:00:00', WeeklyCalendar::TIMEZONE);

        foreach ($people as $index => $person) {
            match ($person->email) {
                'empleado@example.com' => $this->weeklySubmission($person, $cycle, CarbonImmutable::now()->min($deadline->subDays(2)), $texts, $index),
                'pablo.ruiz@example.com' => $this->weeklySubmission($person, $cycle, null, $texts, $index),
                default => null,
            };
        }

        // Los recordatorios de WeeklySync (10.5): el email del viernes a las 16:00 y, nuevo, uno en
        // la app el jueves a las 10:00. El de los viernes con las horas sale además a las 13:00.
        WeeklyReminderRule::query()->create(['channel' => WeeklyReminderChannel::App, 'day_of_week' => 4, 'time' => '10:00', 'position' => 0]);
        WeeklyReminderRule::query()->create(['channel' => WeeklyReminderChannel::Email, 'day_of_week' => 5, 'time' => '16:00', 'position' => 1]);
    }

    /**
     * Una weekly de ejemplo: un apunte por cada cliente de sus proyectos (como mucho dos) o, sin
     * clientes, uno de «General / Interno». Sin fecha de envío, queda como borrador.
     *
     * @param  list<string>  $texts
     */
    private function weeklySubmission(User $person, WeeklyCycle $cycle, ?CarbonImmutable $submittedAt, array $texts, int $seed): void
    {
        $submission = WeeklySubmission::query()->create([
            'weekly_cycle_id' => $cycle->id,
            'user_id' => $person->id,
            'submitted_at' => $submittedAt?->utc(),
            'draft_saved_at' => ($submittedAt ?? CarbonImmutable::now())->utc(),
        ]);
        $clientIds = $person->projects()
            ->whereNotNull('client_id')
            ->orderBy('projects.id')
            ->pluck('client_id')
            ->unique()
            ->take(2)
            ->values()
            ->all();

        foreach ($clientIds === [] ? [null] : $clientIds as $position => $clientId) {
            WeeklyEntry::query()->create([
                'weekly_submission_id' => $submission->id,
                'client_id' => $clientId,
                'body' => $texts[($seed + $position) % count($texts)],
                'source' => WeeklyEntrySource::Text,
                'position' => $position,
            ]);
        }
    }

    /**
     * Colaboradora externa de ejemplo (Fase 8, D-134), para su E2E (collaborator.spec.ts).
     */
    private function collaborator(): void
    {
        // Colaboradora externa (D-134): en Diseño, miembro solo de MIR-WEB y FAR-SHOP, con una tarea
        // en cada uno. Se añade al final y sin el generador aleatorio, para no cambiar el resto de
        // datos de ejemplo (de los que dependen otros E2E). Sin horas pasadas: imputa en el E2E.
        $sara = User::query()->firstOrCreate(['email' => self::COLLABORATOR_EMAIL], [
            'name' => 'Sara Colaboradora',
            'password' => 'password',
            'email_verified_at' => now(),
        ]);
        $sara->forceFill(['department_id' => $this->departments['Diseño']->id])->save();
        $sara->syncRoles([Role::Collaborator->value]);
        // Ya ha leído el texto de privacidad, como el resto de la plantilla de ejemplo (D-133).
        app(PrivacyNotice::class)->acknowledge($sara);

        if (! $sara->workSchedules()->exists()) {
            WorkSchedule::query()->create(['user_id' => $sara->id, 'valid_from' => $this->start->toDateString(), 'mon_minutes' => 480, 'tue_minutes' => 480, 'wed_minutes' => 480, 'thu_minutes' => 480, 'fri_minutes' => 480]);
        }

        $tasks = [
            'MIR-WEB' => ['Retoque de las fotos de habitaciones', 'Diseño UI'],
            'FAR-SHOP' => ['Banners de la tienda', 'Diseño UI'],
        ];

        foreach (self::COLLABORATOR_PROJECTS as $index => $code) {
            $project = Project::query()->where('code', $code)->firstOrFail();
            $project->addMember($sara);
            $bank = $project->usesHourBanks()
                ? HourBank::query()->where('project_id', $project->id)->open()->orderByDesc('start_date')->first()
                : null;
            [$title, $type] = $tasks[$code];

            /** @var Task $task */
            $task = Task::withoutEvents(fn () => Task::query()->forceCreate([
                'project_id' => $project->id,
                'hour_bank_id' => $bank?->id,
                'title' => $title,
                'task_type_id' => $this->types[$type]->id,
                'status_id' => $this->statuses['todo'],
                'priority' => TaskPriority::Normal->value,
                'assignee_user_id' => $sara->id,
                'start_date' => $this->today->toDateString(),
                'due_date' => $this->today->addDays(3 + $index)->toDateString(),
                'estimated_minutes' => 6 * 60,
                'is_billable' => true,
                'position' => 998,
                'created_by' => $project->owner_user_id,
            ]));
            $task->watchers()->syncWithoutDetaching(array_unique([$sara->id, $project->owner_user_id]));
        }

        $this->people['sara'] = $sara;
    }

    /**
     * Un día sobrecargado seguro, para el E2E de la vista Carga (reasignar desde la celda de una
     * persona sobrecargada): Lucía tiene en «MIR-WEB · Rediseño web» una tarea de 16 h que empieza
     * y se entrega el primer día laborable de la semana que viene (sin festivo), además de la carga
     * que le toque al azar. Se crea después de las horas, así que no tiene nada imputado.
     *
     * @param  list<array{project: Project, banks: list<array{bank: HourBank, state: string, from: CarbonImmutable, to: CarbonImmutable}>, tasks: list<Task>, from: CarbonImmutable, to: CarbonImmutable, members: list<User>}>  $projects
     */
    private function overloadedDay(array $projects): void
    {
        $data = null;
        foreach ($projects as $candidate) {
            if ($candidate['project']->code === 'MIR-WEB') {
                $data = $candidate;
            }
        }

        if ($data === null || $data['banks'] === []) {
            return;
        }

        $holidays = Holiday::query()->pluck('date')->map(fn (CarbonInterface $date): string => $date->toDateString())->all();
        $day = $this->today->startOfWeek()->addWeek();
        while ($day->isWeekend() || in_array($day->toDateString(), $holidays, true)) {
            $day = $day->addDay();
        }

        $project = $data['project'];
        $lucia = $this->people['lucia'];

        /** @var Task $task */
        $task = Task::withoutEvents(fn () => Task::query()->forceCreate([
            'project_id' => $project->id,
            'hour_bank_id' => end($data['banks'])['bank']->id,
            'title' => 'Maquetas para la feria de turismo',
            'task_type_id' => $this->types['Diseño UI']->id,
            'status_id' => $this->statuses['todo'],
            'priority' => TaskPriority::High->value,
            'assignee_user_id' => $lucia->id,
            'start_date' => $day->toDateString(),
            'due_date' => $day->toDateString(),
            'estimated_minutes' => 16 * 60,
            'is_billable' => true,
            'position' => 999,
            'created_by' => $project->owner_user_id,
        ]));
        $task->watchers()->syncWithoutDetaching(array_unique([$lucia->id, $project->owner_user_id]));
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

            // Texto de privacidad (D-075): la plantilla de ejemplo ya lo ha leído, salvo Daniel, que
            // sigue con el aviso pendiente para verlo en la app (y en el E2E de privacidad).
            if ($key !== self::PRIVACY_PENDING) {
                app(PrivacyNotice::class)->acknowledge($user);
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

        // Otra persona del portal, de un cliente sin bolsas: su Inicio es el estado vacío grande con
        // el degradado de marca (SPEC §3.1; lo comprueba el E2E brand-gradient).
        $lamas = $clients['Construcciones Lamas'] ?? null;
        if ($lamas !== null) {
            $portalUser = User::query()->firstOrCreate(['email' => 'cliente.lamas@example.com'], [
                'name' => 'Rosa Lamas',
                'password' => 'password',
                'email_verified_at' => now(),
            ]);
            $portalUser->forceFill(['client_id' => $lamas->id])->save();
            $portalUser->syncRoles([Role::Client->value]);
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

    /**
     * Chat (Fase 6) con MessageWriter, la única vía de escritura (D-069): el de tres proyectos, dos
     * directas y un grupo, en los últimos días. Sin avisos ni tiempo real (los eventos del chat se
     * silencian mientras tanto) y sin enlaces, audios ni adjuntos. Elena (empleado@example.com) se
     * queda con mensajes sin leer y menciones recientes para la tarjeta de Inicio. Ningún texto dice
     * «prueba» (lo busca el E2E de los audios) ni nombra a Ana (el E2E del chat la busca en la lista).
     */
    private function chat(): void
    {
        Event::fakeFor(function (): void {
            DB::transaction(function (): void {
                $directory = app(ConversationDirectory::class);
                $writer = app(MessageWriter::class);
                $p = $this->people;
                $mention = fn (string $key): string => '<@'.$p[$key]->id.'>';
                $project = fn (string $code): Conversation => $directory->forProject(Project::query()->where('code', $code)->firstOrFail());

                /** @var list<array{0: Message, 1: CarbonImmutable}> $timeline */
                $timeline = [];
                $post = function (string $who, Conversation $conversation, string $body, int $daysAgo, string $time, ?Message $parent = null) use ($writer, $p, &$timeline): Message {
                    $message = $writer->post($p[$who], $conversation, $body, $parent?->id);
                    $timeline[] = [$message, LocalTime::today()->subDays($daysAgo)->setTimeFromTimeString($time)];

                    return $message;
                };

                $web = $project('ARR-WEB');
                $post('raul', $web, 'Buenos días. Esta semana cerramos la **maqueta de la home** y empezamos con las fichas de producto.', 6, '09:12');
                $brief = $post('elena', $web, "He dejado las tres propuestas de cabecera en la tarea. {$mention('raul')}, ¿las revisas cuando puedas?", 6, '10:40');
                $post('raul', $web, 'Me quedo con la segunda: más aire y la tipografía respira mejor. 👍', 6, '12:05', $brief);
                $writer->toggleReaction($p['elena'], $timeline[2][0], '🙌');
                $plan = $post('marta', $web, '@todos el lunes a las 10:00 revisamos el plan de lanzamiento. Traed las dudas apuntadas.', 4, '17:30');
                $writer->setPinned($p['marta'], $plan, true);
                $post('pablo', $web, "{$mention('sergio')} el formulario de contacto ya valida en el servidor; falta el aviso por correo.", 3, '11:15');
                $post('sergio', $web, 'Hecho. Lo subo a la rama de desarrollo esta tarde.', 3, '13:02');
                $post('lucia', $web, "{$mention('elena')} ¿me pasas los iconos en `svg` para la ficha de producto?", 1, '09:48');
                $post('raul', $web, "{$mention('elena')} el cliente pide un tono más cálido en las fotos de la bodega. ¿Lo vemos mañana?", 1, '18:30');

                $app = $project('SON-APP');
                $post('marta', $app, 'La pasarela de pago ya funciona en el entorno de integración del banco.', 5, '16:20');
                $post('pablo', $app, "{$mention('elena')} ¿puedes revisar los textos de la pantalla de confirmación de cita?", 2, '10:05');
                $post('elena', $app, 'Revisados: he cambiado «Agendar» por «Reservar cita», que se entiende mejor.', 2, '12:30');

                $mirador = $project('MIR-WEB');
                $post('raul', $mirador, 'Hoy nos mandan las fotos nuevas de las habitaciones.', 1, '15:10');

                $toElena = $directory->direct($p['raul'], $p['elena']);
                $post('raul', $toElena, '¿Tienes un rato a las 12 para ver la propuesta de color?', 2, '09:30');
                $post('elena', $toElena, 'Sí, te llamo a las 12.', 2, '09:41');
                $post('raul', $toElena, 'Perfecto. Te dejo el enlace a la carpeta en la tarea.', 1, '19:05');

                $toSergio = $directory->direct($p['marta'], $p['sergio']);
                $post('marta', $toSergio, '¿Cómo vas con la migración de la intranet?', 1, '17:45');
                $post('sergio', $toSergio, 'Terminando los tests de carga. Mañana te cuento.', 1, '18:02');

                $group = $directory->group($p['raul'], 'Diseño y desarrollo', [$p['elena']->id, $p['lucia']->id, $p['marta']->id, $p['pablo']->id]);
                $post('marta', $group, 'Propongo una revisión conjunta de componentes cada dos semanas. ¿Qué os parece?', 5, '11:00');
                $post('lucia', $group, 'Me parece bien. Así no se nos duplican botones con estilos distintos.', 5, '11:20');
                $post('raul', $group, "@todos primera revisión el jueves a las 16:00. {$mention('pablo')}, ¿preparas el inventario?", 1, '13:40');

                // Fechas de los últimos días (MessageWriter publica «ahora»), en orden.
                foreach ($timeline as [$message, $at]) {
                    $instant = $at->utc();
                    DB::table('messages')->where('id', $message->id)->update(['created_at' => $instant, 'updated_at' => $instant]);
                    DB::table('conversations')->where('id', $message->conversation_id)->update(['last_message_at' => $instant]);
                }
                DB::table('messages')->where('id', $plan->id)->update(['pinned_at' => $timeline[4][1]->addMinutes(2)->utc()]);
            });
        }, [MessagePosted::class, MessageUpdated::class, ConversationRead::class]);
    }

    /**
     * Plan del día (D-250, docs/PLAN-CARGAS.md §10): las cuatro últimas semanas de cada persona de
     * plantilla, solo en sus días con jornada, con líneas hechas, no hechas y pasadas al día
     * siguiente («↻ ×N»), algunas con proyecto o tarea y horas previstas, y un comentario de su
     * responsable. Hoy: casi todos ya lo han escrito; Elena (empleado@example.com) no, y tiene dos
     * pendientes de su último día con jornada (el aviso «Pasar a hoy» de los E2E); Lucía tampoco (sale
     * «Sin plan» en «Equipo hoy»). Sin auditoría ni avisos.
     */
    private function dayPlans(): void
    {
        Model::withoutEvents(fn () => $this->writeDayPlans());
    }

    private function writeDayPlans(): void
    {
        $texts = ['Revisar textos de la landing', 'Creatividades de la campaña', 'Reunión de producción', 'Ajustes de diseño', 'Maquetar la home', 'Llamada con el cliente', 'Preparar presupuesto', 'Revisar incidencias', 'Planificación de la semana', 'Informe mensual', 'Moodboard', 'Optimizar imágenes', 'Publicaciones en redes', 'Responder correos'];
        $capacity = app(Capacity::class);
        $from = $this->today->subDays(27);
        $people = array_filter($this->people, fn (User $user): bool => $user->writesWeeklies());

        foreach ($people as $key => $user) {
            $projects = Project::query()->whereHas('members', fn ($query) => $query->whereKey($user->id))->notArchived()->get(['id', 'client_id']);
            $tasks = Task::query()->where('assignee_user_id', $user->id)->whereNull('completed_at')->where('is_milestone', false)->limit(12)->get(['id', 'project_id', 'title']);
            $days = array_map('strval', array_keys(array_filter($capacity->forRange($user, $from, $this->today), fn (int $minutes): bool => $minutes > 0)));
            $past = array_values(array_filter($days, fn (string $date): bool => $date < $this->today->toDateString()));
            $lastPast = $past === [] ? null : $past[count($past) - 1];

            foreach ($days as $index => $date) {
                $isToday = $date === $this->today->toDateString();

                if ($isToday && in_array($key, ['elena', 'lucia'], true)) {
                    continue;
                }

                $plan = DayPlan::query()->firstOrCreate(['user_id' => $user->id, 'date' => $date]);
                $plan->forceFill(['published_at' => CarbonImmutable::parse($date.' 08:'.str_pad((string) $this->random->getInt(0, 59), 2, '0', STR_PAD_LEFT), LocalTime::timezone())->utc()])->save();
                $count = $this->random->getInt(3, 5);

                for ($position = 0; $position < $count; $position++) {
                    $task = $tasks->isNotEmpty() && $this->random->getInt(0, 3) === 0 ? $this->pick($tasks->all()) : null;
                    $project = $task === null && $projects->isNotEmpty() && $this->random->getInt(0, 1) === 0 ? $this->pick($projects->all()) : null;
                    $roll = $this->random->getInt(1, 10);
                    $status = match (true) {
                        // Las dos primeras de Elena en su último día con jornada, pendientes (E2E).
                        $key === 'elena' && $date === $lastPast && $position < 2 => DayPlanItemStatus::Pending,
                        $isToday => $roll <= 3 ? DayPlanItemStatus::Done : DayPlanItemStatus::Pending,
                        $roll <= 7 => DayPlanItemStatus::Done,
                        $roll === 8 => DayPlanItemStatus::NotDone,
                        default => DayPlanItemStatus::Pending,
                    };

                    $item = new DayPlanItem([
                        'day_plan_id' => $plan->id,
                        'user_id' => $user->id,
                        'date' => $date,
                        'position' => $position,
                        'text' => $task->title ?? $this->pick($texts),
                        'client_id' => $task !== null ? Project::query()->whereKey($task->project_id)->value('client_id') : $project?->client_id,
                        'project_id' => $task->project_id ?? $project?->id,
                        'task_id' => $task?->id,
                        'planned_minutes' => $this->random->getInt(0, 2) === 0 ? null : $this->pick([30, 45, 60, 90, 120, 180]),
                        'status' => $status,
                        'status_changed_at' => $status === DayPlanItemStatus::Pending ? null : CarbonImmutable::parse($date.' 17:30', LocalTime::timezone())->utc(),
                        'not_done_reason' => $status === DayPlanItemStatus::NotDone ? $this->pick(['Sin respuesta del cliente', 'Surgió una urgencia', null]) : null,
                        'created_by' => $user->id,
                    ]);
                    $item->saveQuietly();

                    // Las pendientes de días pasados se pasaron al siguiente día con jornada, salvo las
                    // del último (quedan para «Pasar a hoy»).
                    $next = $days[$index + 1] ?? null;

                    if ($status === DayPlanItemStatus::Pending && ! $isToday && $next !== null && $date !== $lastPast) {
                        $item->forceFill(['status' => DayPlanItemStatus::Carried, 'status_changed_at' => CarbonImmutable::parse($next.' 08:15', LocalTime::timezone())->utc()])->saveQuietly();
                        $nextPlan = DayPlan::query()->firstOrCreate(['user_id' => $user->id, 'date' => $next]);
                        (new DayPlanItem([
                            'day_plan_id' => $nextPlan->id,
                            'user_id' => $user->id,
                            'date' => $next,
                            'position' => 20 + $position,
                            'text' => $item->text,
                            'client_id' => $item->client_id,
                            'project_id' => $item->project_id,
                            'task_id' => $item->task_id,
                            'planned_minutes' => $item->planned_minutes,
                            'status' => DayPlanItemStatus::Pending,
                            'carried_from_id' => $item->id,
                            'carry_count' => $item->carry_count + 1,
                            'origin' => DayPlanItemOrigin::Carried,
                            'created_by' => $user->id,
                        ]))->saveQuietly();
                    }
                }
            }
        }

        // Un comentario de su responsable en una línea de Lucía.
        $line = DayPlanItem::query()->where('user_id', $this->people['lucia']->id)->orderByDesc('date')->first();

        if ($line !== null) {
            DayPlanComment::query()->create(['day_plan_item_id' => $line->id, 'user_id' => $this->people['raul']->id, 'body' => '¿Te ayudo con esto?']);
        }
    }

    /**
     * Previsión (D-280 a D-289): asignaciones en proyectos reales (personas, un fee mensual de
     * Marketing y un hueco de Desarrollo en el proyecto planificado) y cuatro previstos: uno posible
     * de un cliente que aún no existe, uno seguro de un cliente actual, uno vinculado con su proyecto
     * real (con su línea base congelada y las asignaciones copiadas) y uno perdido.
     */
    private function forecast(): void
    {
        $writer = app(AllocationWriter::class);
        $forecasts = app(ForecastProjectWriter::class);
        $p = $this->people;
        $raul = $p['raul'];
        $monday = $this->today->startOfWeek();
        $date = fn (CarbonImmutable $day): string => $day->toDateString();
        $projects = Project::query()->whereIn('code', ['ARR-WEB', 'FER-PORTAL', 'SON-SEO', 'MON-MICRO', 'SON-APP'])->get()->keyBy('code');
        $allocate = function (Project|ForecastProject $container, array $data) use ($writer, $raul): void {
            $writer->create($container, $data, $raul);
        };

        // Proyectos reales: el plan de trabajo sin tareas (pestaña Planificación).
        $allocate($projects['ARR-WEB'], ['user_id' => $p['elena']->id, 'mode' => 'per_day', 'minutes' => 180, 'start_date' => $date($monday->subWeeks(2)), 'end_date' => $date($monday->addWeeks(6)->subDays(3))]);
        $allocate($projects['ARR-WEB'], ['user_id' => $p['lucia']->id, 'mode' => 'total', 'minutes' => 60 * 60, 'start_date' => $date($monday), 'end_date' => $date($monday->addWeeks(4)->subDays(3)), 'note' => 'Diseño de las fichas de vino']);
        $allocate($projects['FER-PORTAL'], ['user_id' => $p['pablo']->id, 'mode' => 'percent', 'percent' => 50, 'start_date' => $date($monday), 'end_date' => $date($monday->addMonths(3))]);
        $allocate($projects['SON-SEO'], ['department_id' => $this->departments['Marketing']->id, 'mode' => 'monthly', 'minutes' => 20 * 60, 'start_date' => $date($this->today->startOfMonth()), 'end_date' => null, 'note' => 'Fee de SEO local']);
        $allocate($projects['MON-MICRO'], ['department_id' => $this->departments['Desarrollo']->id, 'mode' => 'total', 'minutes' => 80 * 60, 'start_date' => $date($this->today->addMonthNoOverflow()->startOfMonth()), 'end_date' => $date($this->today->addMonthNoOverflow()->endOfMonth())]);

        // Posible, de un cliente que aún no existe.
        $start = $this->today->addMonthNoOverflow()->startOfMonth()->startOfWeek();
        $hotel = $forecasts->create(['name' => 'Web y branding', 'prospect_name' => 'Hotel Mar Azul', 'start_date' => $date($start), 'end_date' => $date($start->addWeeks(7)->subDays(3)), 'estimated_minutes' => 250 * 60, 'description' => 'Propuesta enviada; decisión a final de mes.'], $raul);
        $allocate($hotel, ['department_id' => $this->departments['Diseño']->id, 'mode' => 'total', 'minutes' => 80 * 60, 'start_date' => $date($start), 'end_date' => $date($start->addWeeks(4)->subDays(3))]);
        $allocate($hotel, ['user_id' => $p['lucia']->id, 'mode' => 'percent', 'percent' => 50, 'start_date' => $date($start->addWeek()), 'end_date' => $date($start->addWeeks(7)->subDays(3))]);
        $allocate($hotel, ['department_id' => $this->departments['Desarrollo']->id, 'mode' => 'total', 'minutes' => 120 * 60, 'start_date' => $date($start->addWeeks(4)), 'end_date' => $date($start->addWeeks(7)->subDays(3))]);

        // Seguro, de un cliente actual.
        $spring = $this->today->addMonthsNoOverflow(3)->startOfMonth();
        $arrieta = $forecasts->create(['name' => 'Campaña de primavera', 'client_id' => Client::query()->where('name', 'Bodegas Arrieta')->value('id'), 'confidence' => 'firm', 'start_date' => $date($spring), 'end_date' => $date($spring->addMonths(3)->subDay())], $p['nuria']);
        $allocate($arrieta, ['user_id' => $p['irene']->id, 'mode' => 'monthly', 'minutes' => 30 * 60, 'start_date' => $date($spring), 'end_date' => $date($spring->addMonths(3)->subDay())]);
        $allocate($arrieta, ['user_id' => $p['daniel']->id, 'mode' => 'per_day', 'minutes' => 120, 'start_date' => $date($spring), 'end_date' => $date($spring->addMonth()->subDay())]);

        // Vinculado: la app de Sonrisas salió de un previsto (línea base y asignaciones copiadas).
        $app = $forecasts->create(['name' => 'App de citas', 'client_id' => $projects['SON-APP']->client_id, 'confidence' => 'firm', 'start_date' => $date($this->today->subMonthsNoOverflow(4)->startOfMonth()), 'end_date' => $date($this->today->addMonthNoOverflow()->endOfMonth()), 'estimated_minutes' => 420 * 60], $p['marta']);
        $allocate($app, ['user_id' => $p['pablo']->id, 'mode' => 'total', 'minutes' => 260 * 60, 'start_date' => $date($this->today->subMonthsNoOverflow(4)->startOfMonth()), 'end_date' => $date($this->today->addMonthNoOverflow()->endOfMonth())]);
        $allocate($app, ['user_id' => $p['sergio']->id, 'mode' => 'total', 'minutes' => 160 * 60, 'start_date' => $date($this->today->subMonthsNoOverflow(3)->startOfMonth()), 'end_date' => $date($this->today->endOfMonth())]);
        app(ForecastLinker::class)->link($app, $projects['SON-APP'], $p['marta']);

        // Perdido, con su motivo.
        $lamas = $forecasts->create(['name' => 'App de obra', 'client_id' => Client::query()->where('name', 'Construcciones Lamas')->value('id'), 'start_date' => $date($spring), 'end_date' => $date($spring->addMonths(2))], $p['marta']);
        $allocate($lamas, ['department_id' => $this->departments['Desarrollo']->id, 'mode' => 'total', 'minutes' => 200 * 60, 'start_date' => $date($spring), 'end_date' => $date($spring->addMonths(2))]);
        $forecasts->lose($lamas, 'Precio');

        $this->forecastTeam($monday, $allocate);
    }

    /**
     * El resto del equipo con su plan de los próximos tres meses (pantallas de la previsión, D-300):
     * cada persona de plantilla con asignaciones en proyectos reales (alguien se pasa, alguien va
     * holgado), la colaboradora externa con las suyas y dos previstos más, uno seguro y uno posible
     * con un hueco de Marketing. Sin el generador aleatorio, para no cambiar el resto de datos.
     *
     * @param  callable(Project|ForecastProject, array<string, mixed>): void  $allocate
     */
    private function forecastTeam(CarbonImmutable $monday, callable $allocate): void
    {
        $p = $this->people;
        $date = fn (CarbonImmutable $day): string => $day->toDateString();
        $projects = Project::query()->whereIn('code', ['MIR-WEB', 'MIR-SOP', 'LAM-INT', 'FAR-SHOP', 'FAR-RRSS', 'ARR-MKT'])->get()->keyBy('code');
        $forecasts = app(ForecastProjectWriter::class);
        $weeks = fn (int $count): string => $date($monday->addWeeks($count)->subDays(3));

        $allocate($projects['MIR-WEB'], ['user_id' => $p['raul']->id, 'mode' => 'percent', 'percent' => 50, 'start_date' => $date($monday), 'end_date' => $weeks(10)]);
        $allocate($projects['MIR-WEB'], ['user_id' => $p['elena']->id, 'mode' => 'per_day', 'minutes' => 150, 'start_date' => $date($monday->addWeeks(4)), 'end_date' => $weeks(12)]);
        $allocate($projects['FAR-SHOP'], ['user_id' => $p['lucia']->id, 'mode' => 'per_day', 'minutes' => 240, 'start_date' => $date($monday->addWeeks(4)), 'end_date' => $weeks(10)]);
        $allocate($projects['LAM-INT'], ['user_id' => $p['marta']->id, 'mode' => 'percent', 'percent' => 40, 'start_date' => $date($monday), 'end_date' => $weeks(12)]);
        $allocate($projects['FAR-SHOP'], ['user_id' => $p['sergio']->id, 'mode' => 'per_day', 'minutes' => 300, 'start_date' => $date($monday), 'end_date' => $weeks(6)]);
        $allocate($projects['MIR-SOP'], ['user_id' => $p['sergio']->id, 'mode' => 'monthly', 'minutes' => 24 * 60, 'start_date' => $date($this->today->startOfMonth()), 'end_date' => null, 'note' => 'Soporte del mes']);
        $allocate($projects['ARR-MKT'], ['user_id' => $p['nuria']->id, 'mode' => 'percent', 'percent' => 50, 'start_date' => $date($monday), 'end_date' => $weeks(12)]);
        $allocate($projects['FAR-RRSS'], ['user_id' => $p['irene']->id, 'mode' => 'per_day', 'minutes' => 180, 'start_date' => $date($monday), 'end_date' => $weeks(12)]);
        $allocate($projects['ARR-MKT'], ['user_id' => $p['daniel']->id, 'mode' => 'per_day', 'minutes' => 240, 'start_date' => $date($monday), 'end_date' => $weeks(12)]);

        // La colaboradora externa (D-300): retoques de fotos en MIR-WEB, con su jornada.
        $sara = User::query()->where('email', self::COLLABORATOR_EMAIL)->first();
        if ($sara !== null) {
            $allocate($projects['MIR-WEB'], ['user_id' => $sara->id, 'mode' => 'total', 'minutes' => 60 * 60, 'start_date' => $date($monday->addWeeks(2)), 'end_date' => $weeks(6)]);
        }

        // Seguro: la app de reservas de Hoteles Mirador, que carga a Pablo y a Sergio.
        $start = $monday->addWeeks(5);
        $booking = $forecasts->create(['name' => 'App de reservas', 'client_id' => $projects['MIR-WEB']->client_id, 'confidence' => 'firm', 'start_date' => $date($start), 'end_date' => $date($start->addWeeks(10)->subDays(3)), 'estimated_minutes' => 420 * 60, 'description' => 'Firmado a falta del pedido.'], $p['marta']);
        $allocate($booking, ['user_id' => $p['pablo']->id, 'mode' => 'per_day', 'minutes' => 240, 'start_date' => $date($start), 'end_date' => $date($start->addWeeks(10)->subDays(3))]);
        $allocate($booking, ['user_id' => $p['sergio']->id, 'mode' => 'percent', 'percent' => 40, 'start_date' => $date($start->addWeeks(2)), 'end_date' => $date($start->addWeeks(10)->subDays(3))]);
        $allocate($booking, ['department_id' => $this->departments['Diseño']->id, 'mode' => 'monthly', 'minutes' => 40 * 60, 'start_date' => $date($start), 'end_date' => $date($start->addWeeks(10)->subDays(3))]);

        // Posible: la campaña de verano de Cervezas Montaña, con un hueco de Marketing.
        $start = $monday->addWeeks(7);
        $summer = $forecasts->create(['name' => 'Campaña de verano', 'client_id' => Client::query()->where('name', 'Cervezas Montaña')->value('id'), 'start_date' => $date($start), 'end_date' => $date($start->addWeeks(6)->subDays(3)), 'estimated_minutes' => 150 * 60], $p['nuria']);
        $allocate($summer, ['department_id' => $this->departments['Marketing']->id, 'mode' => 'total', 'minutes' => 90 * 60, 'start_date' => $date($start), 'end_date' => $date($start->addWeeks(6)->subDays(3))]);
        $allocate($summer, ['user_id' => $p['daniel']->id, 'mode' => 'percent', 'percent' => 30, 'start_date' => $date($start), 'end_date' => $date($start->addWeeks(6)->subDays(3))]);
    }

    /**
     * Registro de jornada de ejemplo (Fase 11, R1; D-344): las cuatro últimas semanas de fichajes de
     * toda la plantilla, con la hora «del servidor» de cada momento (se mueve el reloj y se ficha con
     * ClockWriter, como en producción), su margen de entrada (8:00 a 10:00) y la comida prevista. Hay
     * de todo para enseñar las pantallas:
     * - Elena (empleado@example.com) aún no ha fichado hoy (los E2E fichan con ella) y tiene una
     *   corrección aceptada por Raúl la semana pasada,
     * - Daniel olvidó la salida hace dos semanas y su corrección espera a Nuria,
     * - Lucía tiene una corrección en discrepancia (Raúl no está de acuerdo),
     * - Marta propone a Sergio un ajuste que espera la conformidad de Sergio,
     * - Pablo alargó un día (exceso y más de 9 h) y Sergio entregó de noche (menos de 12 h de
     *   descanso),
     * - Irene, a media jornada, sin comida; los viernes, parte de la plantilla trabaja a distancia,
     * - hoy, quien ya ha entrado está trabajando o en la comida, según la hora.
     * Sin avisos: el módulo `people` viene apagado.
     */
    private function clockRegister(): void
    {
        $random = new Randomizer(new Mt19937(3411));
        $writer = app(ClockWriter::class);
        $capacity = app(Capacity::class);
        $realNow = CarbonImmutable::now();
        $zone = LocalTime::timezone();
        // Desde el día 1 del mes anterior (o 4 semanas, si es más): el mes anterior entero, para su
        // cierre de ejemplo (R2, D-359).
        $from = $this->today->subWeeks(4)->startOfWeek()->min($this->today->startOfMonth()->subMonthNoOverflow());
        $today = $this->today->toDateString();
        $lastWeek = $this->today->startOfWeek()->subWeek();
        // El n-ésimo día laborable (de lunes a viernes) antes de hoy.
        $workdayBefore = function (int $n): string {
            $date = $this->today;
            while ($n > 0) {
                $date = $date->subDay();
                $n -= $date->isWeekday() ? 1 : 0;
            }

            return $date->toDateString();
        };
        // Las pendientes, de hace pocos días (a los 7 sin respuesta quedarían en discrepancia).
        $special = [
            'daniel_no_out' => $workdayBefore(2),
            'lucia_disputed' => $this->today->startOfWeek()->subWeeks(3)->addDays(2)->toDateString(),
            'elena_late' => $lastWeek->toDateString(),
            'sergio_night' => $lastWeek->addDay()->toDateString(),
            'pablo_long' => $lastWeek->addDays(2)->toDateString(),
            'sergio_adjust' => $workdayBefore(3),
            // R2: un día largo de Lucía este mes, para clasificar su hora extra (E2E de people-register).
            'lucia_long' => $workdayBefore(1),
        ];

        foreach ($this->people as $key => $user) {
            WorkSchedule::query()->where('user_id', $user->id)->whereNull('valid_to')->update([
                'start_time_from' => '08:00',
                'start_time_to' => '10:00',
                'expected_pause_minutes' => $key === 'irene' ? 0 : 60,
            ]);
        }

        $punch = function (User $user, string $date, string $time, ClockEventKind $kind, ?WorkMode $mode = null) use ($writer, $realNow, $zone): bool {
            $at = CarbonImmutable::parse("{$date} {$time}", $zone);

            if ($at->greaterThan($realNow)) {
                return false;
            }

            Carbon::setTestNow($at);
            CarbonImmutable::setTestNow($at);
            $writer->punch($user, $kind, $mode, ClockSource::Web, null, 'Mozilla/5.0 (datos de ejemplo)');

            return true;
        };

        try {
            foreach ($this->people as $key => $user) {
                // Solo ficha la plantilla (D-331): la colaboradora externa, no.
                if (! PeopleAccess::subject($user)) {
                    continue;
                }

                $remoteDays = match ($key) {
                    'elena', 'lucia' => [5],
                    'pablo', 'sergio' => [1, 4],
                    'irene' => [2, 4],
                    default => [],
                };

                foreach ($capacity->details($user, $from, $this->today) as $date => $detail) {
                    if ($detail['minutes'] <= 0 || ($key === 'elena' && $date === $today)) {
                        continue;
                    }

                    $mode = in_array(CarbonImmutable::parse($date)->dayOfWeekIso, $remoteDays, true) ? WorkMode::Remote : WorkMode::OnSite;
                    $in = 8 * 60 + $random->getInt(0, 95);
                    $lunch = $detail['minutes'] >= 420 ? [13 * 60 + 30 + $random->getInt(0, 45), 45 + $random->getInt(0, 30)] : null;
                    $out = $in + $detail['minutes'] + ($lunch[1] ?? 0) + $random->getInt(-10, 35);

                    match (true) {
                        $key === 'elena' && $date === $special['elena_late'] => $in = 9 * 60 + 52,
                        $key === 'lucia' && $date === $special['lucia_disputed'] => $in = 9 * 60 + 40,
                        $key === 'pablo' && $date === $special['pablo_long'] => $out += 125,
                        $key === 'lucia' && $date === $special['lucia_long'] => $out += 80,
                        $key === 'sergio' && $date === $special['sergio_night'] => [$in, $lunch, $out] = [12 * 60, [15 * 60, 30], 23 * 60 + 30],
                        $key === 'sergio' && $date === CarbonImmutable::parse($special['sergio_night'])->addDay()->toDateString() => $in = 8 * 60 + 35,
                        default => null,
                    };

                    $clock = fn (int $minutes): string => sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);

                    if (! $punch($user, $date, $clock($in), ClockEventKind::ClockIn, $mode)) {
                        continue;
                    }

                    if ($lunch !== null) {
                        if (! $punch($user, $date, $clock($lunch[0]), ClockEventKind::PauseStart)) {
                            continue;
                        }

                        if (! $punch($user, $date, $clock($lunch[0] + $lunch[1]), ClockEventKind::PauseEnd, $mode)) {
                            continue;
                        }
                    }

                    if ($key === 'daniel' && $date === $special['daniel_no_out']) {
                        continue;
                    }

                    $punch($user, $date, $clock(min($out, 23 * 60 + 59)), ClockEventKind::ClockOut);
                }
            }

            $this->clockCorrections($special, $zone);
        } finally {
            Carbon::setTestNow();
            CarbonImmutable::setTestNow();
        }
    }

    /**
     * Vacaciones y permisos, R3 (D-373; solo local). Va al final y sin el generador aleatorio, para no
     * cambiar las horas, los fichajes ni los cierres de ejemplo: nada nuevo cae dentro de los 12 meses
     * de horas ni de los fichajes (lo pasado, antes; lo nuevo, en el futuro).
     * - Festivos de València del año que viene (con su nivel y su fuente); los de este año quedan en
     *   /admin/festivos como «faltan», para no mover la capacidad de esta semana.
     * - Los saldos de la app empiezan el 1 de enero (`people_leave_starts_on`): la asignación de este
     *   año y del siguiente (22 días de vacaciones y las horas de 4 días de fuerza mayor) y, como saldo
     *   inicial de Woffu, el arrastre de Lucía (caduca el 31/03) y las vacaciones que Daniel aplazó
     *   por una IT (art. 38.3 ET).
     * - Futuras: vacaciones aprobadas de Raúl, Marta y Ana (dentro de 8 semanas o más), dos pendientes
     *   (Sergio, con antelación; Pablo, con menos de 2 meses), un deber inexcusable de Pablo por horas
     *   con franja y su citación, un traslado de Daniel sin justificante y un permiso de salud de
     *   Irene con su justificante (su responsable no lo ve).
     * - Sergio pide cancelar unas vacaciones ya disfrutadas («trabajé el viernes»).
     * - Días especiales: el 24 y el 31 de diciembre, media jornada; del 28 al 30, bloqueados.
     * - Un ajuste con motivo (un día más de Lucía).
     * Elena (los E2E) tiene sus 22 días menos las vacaciones de la semana que viene.
     */
    private function leaveR3(): void
    {
        $ledger = app(LeaveLedger::class);
        $year = $this->today->year;
        $types = LeaveType::query()->get()->keyBy('key');
        $before = $this->start->subWeeks(10)->startOfWeek();

        // Festivos de València del año que viene (si están comprobados).
        foreach ((new ValenciaHolidays)->forYear($year + 1) as $holiday) {
            Holiday::query()->firstOrCreate(['date' => $holiday['date']], [
                'name' => $holiday['name'],
                'scope' => 'company',
                'level' => $holiday['level'],
                'source' => $holiday['source'],
            ]);
        }

        // Los saldos de la app empiezan el 1 de enero: lo de antes vino de Woffu como saldo inicial.
        Setting::set('people_leave_starts_on', "{$year}-01-01");
        foreach ([$year, $year + 1] as $item) {
            $ledger->syncYear($item);
        }

        $absence = function (string $who, string $key, CarbonImmutable $from, CarbonImmutable $to, string $status = 'approved', ?string $approver = null, array $extra = []) use ($types): Absence {
            $type = $types[$key];
            $reviewer = $approver !== null ? $this->people[$approver] : null;
            $absence = new Absence([
                'user_id' => $this->people[$who]->id,
                'type' => $type->category,
                'leave_type_id' => $type->id,
                'start_date' => $from->toDateString(),
                'end_date' => $to->toDateString(),
                'status' => $status,
                'approved_by' => $reviewer?->id,
                'reviewed_at' => $status === 'approved' ? ($from < $this->today ? $from->subWeeks(3)->setTime(10, 0) : $this->today->subDay()) : null,
                ...$extra,
            ]);
            $absence->created_at = $from < $this->today ? $from->subWeeks(4)->setTime(9, 0) : $this->today->subDays(2)->setTime(9, 0);
            $absence->save();

            return $absence;
        };

        // Una IT larga de Daniel antes de las horas de ejemplo: sus vacaciones de ese año se aplazan
        // (art. 38.3 ET) y llegan de Woffu como saldo inicial con su caducidad.
        $ana = $this->people['ana'];
        $absence('daniel', 'sick', $before, $before->addDays(20), approver: 'nuria');
        $ledger->adjust($ana, $this->people['daniel'], $types['vacation'], LeaveMovementKind::OpeningBalance, 500, $year, 'Saldo inicial desde Woffu a 01/01/'.$year.': vacaciones de '.$before->year.' aplazadas por la IT (art. 38.3 ET).', "{$year}-01-01", CarbonImmutable::create($year, 6, 30)->max($this->today->addMonths(2)->endOfMonth())->toDateString());
        // Arrastre de Lucía del año pasado, traído de Woffu (ya caducado si ha pasado el 31/03).
        $ledger->adjust($ana, $this->people['lucia'], $types['vacation'], LeaveMovementKind::OpeningBalance, 200, $year - 1, 'Saldo inicial desde Woffu a 01/01/'.$year.': arrastre de '.($year - 1).'.', "{$year}-01-01", "{$year}-03-31");

        // Una cancelación pedida: Sergio trabajó el viernes de sus vacaciones (hace 20 semanas).
        $sergio = Absence::query()->where('user_id', $this->people['sergio']->id)->where('type', 'vacation')->where('start_date', $this->today->startOfWeek()->subWeeks(20)->toDateString())->first();
        $sergio?->forceFill([
            'cancellation_status' => CancellationStatus::Requested,
            'cancellation_reason' => 'Me llamaron por la entrega del viernes y trabajé ese día: devolvedme las vacaciones.',
            'cancellation_requested_at' => $this->today->subDays(2)->setTime(11, 0),
        ])->save();

        // Futuras.
        $in = fn (int $weeks, int $day = 0): CarbonImmutable => $this->today->startOfWeek()->addWeeks($weeks)->addDays($day);
        $absence('raul', 'vacation', $in(8), $in(8, 4), approver: 'ana');
        $absence('marta', 'vacation', $in(9), $in(10, 4), approver: 'ana');
        $absence('ana', 'vacation', $in(11), $in(11, 2));
        $absence('sergio', 'vacation', $in(12), $in(12, 4), 'requested');
        $absence('pablo', 'vacation', $in(5), $in(5, 2), 'requested');
        $citation = $absence('pablo', 'public_duty', $in(3, 2), $in(3, 2), approver: 'marta', extra: ['partial_minutes' => 150, 'start_time' => '10:00', 'end_time' => '12:30', 'notes' => 'Citación como testigo en el juzgado.']);
        $this->leaveDocument($citation, 'citacion-juzgado.pdf', $this->people['pablo']);
        $absence('daniel', 'moving', $in(2, 3), $in(2, 3), approver: 'nuria');
        // Una intervención programada del padre de Irene: permiso de salud con su justificante
        // (solo lo ven ella y RR. HH.; su responsable sabe que está entregado).
        $family = $absence('irene', 'family_illness', $in(4, 1), $in(4, 2), approver: 'nuria');
        $this->leaveDocument($family, 'justificante-hospital.pdf', $this->people['irene']);

        // Días especiales de este año: media jornada el 24 y el 31 de diciembre; del 28 al 30, bloqueados.
        LeaveCalendarDay::query()->create(['kind' => LeaveCalendarDayKind::HalfDay, 'name' => 'Nochebuena (media jornada)', 'start_date' => "{$year}-12-24", 'end_date' => "{$year}-12-24", 'created_by' => $ana->id]);
        LeaveCalendarDay::query()->create(['kind' => LeaveCalendarDayKind::HalfDay, 'name' => 'Nochevieja (media jornada)', 'start_date' => "{$year}-12-31", 'end_date' => "{$year}-12-31", 'created_by' => $ana->id]);
        LeaveCalendarDay::query()->create(['kind' => LeaveCalendarDayKind::Blocked, 'name' => 'Cierre del ejercicio', 'start_date' => "{$year}-12-28", 'end_date' => "{$year}-12-30", 'created_by' => $ana->id]);

        // Un ajuste con motivo: el día de la empresa de Lucía.
        $ledger->adjust($ana, $this->people['lucia'], $types['vacation'], LeaveMovementKind::Adjustment, 100, $year, 'Día extra por el aniversario de la agencia.');
    }

    /** Un justificante de ejemplo (un PDF mínimo) en el disco privado. */
    private function leaveDocument(Absence $absence, string $name, User $uploader): void
    {
        $contents = "%PDF-1.4\n% Justificante de ejemplo (datos ficticios)\n%%EOF\n";
        $path = sprintf('people/justificantes/%d/%s.pdf', $absence->user_id, Str::uuid()->toString());
        Storage::disk('local')->put($path, $contents);

        $document = new AbsenceDocument;
        $document->forceFill([
            'absence_id' => $absence->id,
            'user_id' => $absence->user_id,
            'uploaded_by' => $uploader->id,
            'path' => $path,
            'original_name' => $name,
            'mime' => 'application/pdf',
            'size' => strlen($contents),
            'sha256' => hash('sha256', $contents),
            'created_at' => $this->today->subDay(),
        ])->save();
    }

    /**
     * Registro de jornada, R2 (D-359; solo local): con el reloj en el momento de cada cosa,
     * - Irene, a tiempo parcial (sus horas por encima de la jornada son complementarias),
     * - el exceso del mes anterior, clasificado por el responsable de cada persona (RR. HH., Ana, el de
     *   los responsables): lo que pasa de una hora es hora extra (a compensar, salvo Pablo, que alterna
     *   con pagar); lo demás, flexibilidad. Este mes queda casi todo por clasificar (el día largo de
     *   Lucía, para el E2E),
     * - el saldo de horas: un descanso disfrutado de Pablo y el saldo inicial de Sergio «desde Woffu»,
     * - los cierres del mes anterior del día 1: confirmados, salvo Elena y Daniel (pendientes; el E2E
     *   confirma el de Elena) y Lucía (en desacuerdo),
     * - los dos documentos de RR. HH. (borradores), leídos por todos menos Elena y Daniel,
     * - el ancla de hoy.
     * Los PDF de los cierres se guardan con el motor html (sin Gotenberg en local). Sin avisos: el
     * módulo `people` viene apagado.
     */
    private function registerR2(): void
    {
        config(['services.reports_pdf.driver' => 'html']);
        $p = $this->people;
        $zone = LocalTime::timezone();
        $realNow = CarbonImmutable::now();
        $at = function (CarbonImmutable $instant) use ($realNow): void {
            $instant = $instant->min($realNow);
            Carbon::setTestNow($instant);
            CarbonImmutable::setTestNow($instant);
        };
        $previous = $this->today->startOfMonth()->subMonthNoOverflow();

        try {
            $profile = EmploymentProfile::query()->firstOrNew(['user_id' => $p['irene']->id]);
            $profile->fill(['part_time' => true])->save();
            $p['irene']->unsetRelation('employmentProfile');

            $overtime = app(OvertimeService::class);
            $dataset = app(RegisterDataset::class);
            $decider = function (User $user) use ($p): ?User {
                if ($user->isDepartmentManager() || $user->isAdmin()) {
                    return $user->id === $p['ana']->id ? null : $p['ana'];
                }

                return collect($p)->first(fn (User $candidate): bool => $candidate->isDepartmentManager() && $candidate->department_id === $user->department_id) ?? $p['ana'];
            };

            // El exceso del mes anterior, clasificado el día 1 a primera hora.
            $at(CarbonImmutable::parse($this->today->startOfMonth()->toDateString().' 05:30', $zone));
            $toggle = true;

            foreach ($p as $key => $user) {
                $boss = $decider($user);

                if ($boss === null || ! PeopleAccess::subject($user)) {
                    continue;
                }

                $lines = $dataset->build([$user->fresh() ?? $user], $previous->toDateString(), $previous->endOfMonth()->toDateString())[$user->id]['lines'];

                foreach ($lines as $date => $line) {
                    if ($line['excess_minutes'] <= 0) {
                        continue;
                    }

                    $extra = $line['excess_minutes'] >= 60 ? $line['excess_minutes'] : 0;
                    $destination = $extra === 0 ? null : ($key === 'irene' ? OvertimeDestination::Pay : ($key === 'pablo' && ($toggle = ! $toggle) ? OvertimeDestination::Pay : OvertimeDestination::Compensate));
                    $overtime->decide($boss, $user->fresh() ?? $user, $date, $extra, $destination, $extra > 0 ? 'Entrega de cliente.' : null);
                }
            }

            // El saldo de horas: Pablo disfruta una hora; Sergio trae su saldo de Woffu.
            $at(CarbonImmutable::parse($this->today->startOfMonth()->toDateString().' 08:00', $zone));
            $ledger = app(TimeBalanceLedger::class);
            if ($ledger->balance($p['pablo']->id) >= 60) {
                $ledger->record($p['marta'], $p['pablo'], BalanceMovementKind::RestTaken, 60, $this->today->startOfMonth()->toDateString(), 'Salió una hora antes el viernes por la entrega del día 30.');
            }
            $ledger->record($p['ana'], $p['sergio'], BalanceMovementKind::OpeningBalance, 300, $this->today->startOfMonth()->toDateString(), 'Saldo inicial desde Woffu a '.$this->today->startOfMonth()->format('d/m/Y').'.');

            // Los cierres del mes anterior (día 1, 06:00) y las respuestas (día 2).
            $closer = app(MonthCloser::class);
            $at(CarbonImmutable::parse($this->today->startOfMonth()->toDateString().' 06:00', $zone));
            $closes = [];
            foreach ($closer->dueSubjects($previous) as $user) {
                $closes[$user->id] = $closer->generate($user, $previous, null, false);
            }

            $at(CarbonImmutable::parse($this->today->startOfMonth()->addDay()->toDateString().' 09:15', $zone));
            foreach ($p as $key => $user) {
                $close = $closes[$user->id] ?? null;

                if ($close === null || in_array($key, ['elena', 'daniel'], true)) {
                    continue;
                }

                if ($key === 'lucia') {
                    $closer->disagree($user, $close, 'El día que entré a las 8:30 desde casa sigue sin contar. Lo hablé con Raúl y no estamos de acuerdo.');

                    continue;
                }

                $closer->confirm($user, $close);
            }

            // Documentos de RR. HH. (borradores), leídos por todos menos Elena y Daniel.
            $documents = app(PeopleDocuments::class);
            foreach ($documents->all() as $document) {
                foreach ($p as $key => $user) {
                    if (PeopleAccess::staff($user) && ! in_array($key, ['elena', 'daniel'], true)) {
                        $documents->markRead($user, $document);
                    }
                }
            }
        } finally {
            Carbon::setTestNow();
            CarbonImmutable::setTestNow();
        }

        // El ancla de hoy (la comprobación nocturna).
        app(RegisterAnchors::class)->nightly(notify: false);
    }

    /**
     * Correcciones de ejemplo del registro (D-335): una aceptada, una pendiente de su responsable,
     * una en discrepancia y una que espera la conformidad de la persona.
     *
     * @param  array<string, string>  $special
     */
    private function clockCorrections(array $special, string $zone): void
    {
        $service = app(ClockCorrectionService::class);
        $p = $this->people;
        $at = function (string $date, string $time) use ($zone): void {
            $instant = CarbonImmutable::parse($date, $zone)->addDay()->setTimeFromTimeString($time);
            Carbon::setTestNow($instant);
            CarbonImmutable::setTestNow($instant);
        };
        $rows = fn (User $user, string $date, ?callable $change = null): array => $this->correctionRows($service, $user, $date, $zone, $change);

        $has = fn (string $who, string $date): bool => $service->dayEvents($p[$who]->id, $date) !== [];

        // Elena: llegó a las 9:05 y fichó a las 9:52; Raúl lo acepta.
        if ($has('elena', $special['elena_late'])) {
            $at($special['elena_late'], '09:10');
            $elena = $service->propose($p['elena'], $p['elena'], $special['elena_late'], $rows($p['elena'], $special['elena_late'], function (array $rows): array {
                $rows[0]['time'] = '09:05';

                return $rows;
            }), 'Llegué a las 9:05, pero el móvil se había quedado sin batería y fiché al encender el portátil.');
            $at($special['elena_late'], '11:30');
            $service->accept($p['raul'], $elena, 'Correcto, estabas en la reunión de las 9:15.');
        }

        // Lucía: dice que entró a las 8:30; Raúl no está de acuerdo (en discrepancia).
        if ($has('lucia', $special['lucia_disputed'])) {
            $at($special['lucia_disputed'], '10:00');
            $lucia = $service->propose($p['lucia'], $p['lucia'], $special['lucia_disputed'], $rows($p['lucia'], $special['lucia_disputed'], function (array $rows): array {
                $rows[0]['time'] = '08:30';

                return $rows;
            }), 'Empecé a las 8:30 desde casa revisando el correo del cliente.');
            $at($special['lucia_disputed'], '17:00');
            $service->reject($p['raul'], $lucia, 'Ese día no había nada urgente y no consta actividad antes de las 9:30. Lo hablamos.');
        }

        // Daniel: olvidó la salida; la propone y espera a Nuria.
        if ($has('daniel', $special['daniel_no_out'])) {
            $at($special['daniel_no_out'], '09:20');
            $service->propose($p['daniel'], $p['daniel'], $special['daniel_no_out'], $rows($p['daniel'], $special['daniel_no_out'], fn (array $rows): array => [
                ...$rows,
                ['kind' => 'clock_out', 'time' => '18:05'],
            ]), 'Olvidé fichar la salida; salí a las 18:05 después de la llamada con Bodegas Arrieta.');
        }

        // Marta propone a Sergio un ajuste de su vuelta de la comida: espera a Sergio.
        if ($has('sergio', $special['sergio_adjust'])) {
            $at($special['sergio_adjust'], '12:00');
            $service->propose($p['marta'], $p['sergio'], $special['sergio_adjust'], $rows($p['sergio'], $special['sergio_adjust'], function (array $rows): array {
                foreach ($rows as $index => $row) {
                    if ($row['kind'] === 'pause_end') {
                        $rows[$index]['time'] = substr((string) CarbonImmutable::parse('2000-01-01 '.$row['time'])->addMinutes(20)->format('H:i'), 0, 5);
                    }
                }

                return $rows;
            }), 'La comida con el cliente se alargó hasta más tarde de lo que fichaste; lo ajusto para que cuadre.');
        }
    }

    /**
     * Las filas del formulario de corrección de un día (sus fichajes efectivos) con un cambio.
     *
     * @param  (callable(list<array{id?: int|null, kind: string, time: string, next_day?: bool, work_mode?: string|null}>): list<array{id?: int|null, kind: string, time: string, next_day?: bool, work_mode?: string|null}>)|null  $change
     * @return list<array{id?: int|null, kind: string, time: string, next_day?: bool, work_mode?: string|null}>
     */
    private function correctionRows(ClockCorrectionService $service, User $user, string $date, string $zone, ?callable $change): array
    {
        $rows = array_map(fn ($event): array => [
            'id' => $event->id,
            'kind' => $event->kind->value,
            'time' => $event->occurred_at->setTimezone($zone)->format('H:i'),
            'work_mode' => $event->work_mode?->value,
        ], $service->dayEvents($user->id, $date));

        return $change === null ? $rows : $change($rows);
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
