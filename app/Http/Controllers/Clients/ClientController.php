<?php

namespace App\Http\Controllers\Clients;

use App\Domain\Admin\TextSearch;
use App\Domain\HourBanks\HourBankCommitment;
use App\Domain\Portal\Access\ClientPortalAccess;
use App\Domain\Weeklies\AppModules;
use App\Domain\Weeklies\Insights\ClientInsights;
use App\Domain\Weeklies\Insights\ClientWeeklyTabs;
use App\Domain\Weeklies\ProjectStatus\ProjectKindCode;
use App\Domain\Weeklies\ProjectStatus\ProjectStatusBoard;
use App\Domain\Weeklies\Report\WeeklyProjectStatus;
use App\Enums\AppModule;
use App\Enums\HourBankStatus;
use App\Enums\ProjectStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Clients\ClientRequest;
use App\Http\Resources\Admin\ResourceProps;
use App\Http\Resources\ClientResource;
use App\Http\Resources\Clients\ClientHourBankResource;
use App\Http\Resources\Clients\ClientRowResource;
use App\Http\Resources\ProjectResource;
use App\Http\Resources\UserSummaryResource;
use App\Models\Client;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Support\LocalTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Clientes (SPEC §6, D-021, D-022, D-037): los ven todos los internos; los crean y editan admins
 * y responsables (ClientPolicy). No se borran: se desactivan. La tarifa solo con view-financials
 * (ClientResource). Las horas del mes y del año son totales agregados de las entradas de sus
 * proyectos, visibles para cualquier interno (no hay detalle por persona).
 */
class ClientController extends Controller
{
    use AuthorizesRequests;

    public const int PER_PAGE = 25;

    public function index(Request $request, ProjectStatusBoard $board): Response
    {
        $this->authorize('viewAny', Client::class);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'estado' => ['nullable', 'string', Rule::in(['activos', 'inactivos', 'todos'])],
            // Cartera de la Weekly (Fase 10, F-123 y F-124): orden, tipo de proyecto, persona y «Mis proyectos».
            'orden' => ['nullable', 'string', Rule::in(self::SORTS)],
            'dir' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'tipo' => ['nullable', 'string', Rule::in(ProjectKindCode::TAGS)],
            'persona' => ['nullable', 'integer', 'min:1'],
            'mios' => ['nullable', 'boolean'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $weekly = self::weeklyEnabled($user);
        $status = $filters['estado'] ?? 'activos';
        $sort = $filters['orden'] ?? 'nombre';
        $direction = $filters['dir'] ?? ($sort === 'nombre' ? 'asc' : 'desc');

        if (! $weekly && $sort !== 'nombre') {
            $sort = 'nombre';
            $direction = 'asc';
        }

        $person = isset($filters['persona']) ? (int) $filters['persona'] : null;
        $mine = (bool) ($filters['mios'] ?? false);
        $kinds = isset($filters['tipo']) ? $board->prefixesByClient() : null;
        [$monthStart, $monthEnd] = self::monthRange();

        $clients = Client::query()
            ->withCount(['projects as active_projects_count' => fn (Builder $query) => $query->where('status', ProjectStatus::Active->value)])
            ->withSum(['timeEntries as month_minutes' => fn (Builder $query) => $query->whereBetween('time_entries.date', [$monthStart, $monthEnd])], 'minutes')
            ->when($weekly, fn (Builder $query) => $query->addSelect([
                'last_report_at' => self::lastReportQuery()->selectRaw('max(weekly_submissions.submitted_at)'),
                'satisfaction_previous' => DB::table('client_satisfaction_snapshots')
                    ->whereColumn('client_satisfaction_snapshots.client_id', 'clients.id')
                    ->orderByDesc('client_satisfaction_snapshots.id')
                    ->limit(1)
                    ->select('client_satisfaction_snapshots.previous_score'),
            ]))
            ->when($filters['q'] ?? null, fn (Builder $query, string $term) => TextSearch::apply($query, $term, ['clients.name', 'clients.tax_id', 'clients.contact_name', 'clients.contact_email']))
            ->when($status === 'activos', fn (Builder $query) => $query->where('is_active', true))
            ->when($status === 'inactivos', fn (Builder $query) => $query->where('is_active', false))
            ->when($kinds !== null, fn (Builder $query) => $query->whereKey(self::clientsWithTag($kinds ?? [], (string) $filters['tipo'])))
            ->when($person !== null, fn (Builder $query) => self::withPerson($query, (int) $person))
            ->when($mine, fn (Builder $query) => self::withPerson($query, $user->id))
            // Sin reportes cuenta como el más antiguo, igual en SQLite y en PostgreSQL (los nulos se
            // ordenan distinto): se ordena por el alias de una columna con COALESCE.
            ->when($sort === 'ultimo_reporte', fn (Builder $query) => $query
                ->addSelect(['last_report_sort' => self::lastReportQuery()->selectRaw('COALESCE(max(weekly_submissions.submitted_at), ?)', ['1970-01-01 00:00:00'])])
                ->orderBy('last_report_sort', $direction))
            ->when($sort === 'satisfaccion', fn (Builder $query) => $query->orderBy('satisfaction_score', $direction))
            ->orderBy('name', $sort === 'nombre' ? $direction : 'asc')
            ->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        // Insignias por tipo de proyecto (F-120): una consulta para los clientes de la página.
        $prefixes = $kinds ?? $board->prefixesByClient(array_values(array_map(intval(...), $clients->getCollection()->modelKeys())));
        $clients->getCollection()->each(fn (Client $client) => $client->setAttribute('kind_badges', ProjectKindCode::badges($prefixes[$client->id] ?? [])));

        return Inertia::render('clients/index', [
            'clients' => ClientRowResource::collection($clients),
            'filters' => [
                'q' => $filters['q'] ?? '',
                'estado' => $status,
                'orden' => $sort,
                'dir' => $direction,
                'tipo' => $filters['tipo'] ?? '',
                'persona' => $person === null ? '' : (string) $person,
                'mios' => $mine ? '1' : '',
            ],
            // Personas para el filtro (F-123): la plantilla activa que escribe la weekly. Diferida: no
            // pesa en la carga de la lista.
            'people' => Inertia::defer(fn (): array => User::query()
                ->where('is_active', true)
                ->role(User::WEEKLY_ROLES)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (User $person): array => ['id' => $person->id, 'name' => $person->name])
                ->values()
                ->all()),
            // Columnas de la Weekly (último reporte y satisfacción): con el módulo y quien la usa.
            'weekly' => $weekly,
        ]);
    }

    /** Pestañas de la ficha con la Weekly (F-129 a F-132). */
    public const array TABS = ['resumen', 'historial', 'equipo', 'satisfaccion'];

    /** Órdenes de la lista (F-124). */
    public const array SORTS = ['nombre', 'ultimo_reporte', 'satisfaccion'];

    /** ¿Ve quien mira lo de la Weekly en los clientes? (módulo encendido y use-weeklies). */
    public static function weeklyEnabled(?User $user): bool
    {
        return $user !== null && AppModules::enabled(AppModule::Weeklies) && $user->can('use-weeklies');
    }

    /**
     * Los envíos con un apunte del cliente (subconsulta correlacionada con clients.id, sin columnas:
     * cada uso elige la suya).
     */
    private static function lastReportQuery(): QueryBuilder
    {
        return DB::table('weekly_entries')
            ->join('weekly_submissions', 'weekly_submissions.id', '=', 'weekly_entries.weekly_submission_id')
            ->whereColumn('weekly_entries.client_id', 'clients.id')
            ->whereNotNull('weekly_submissions.submitted_at');
    }

    /**
     * Clientes en los que la persona gestiona o es miembro de un proyecto abierto (F-123: filtro por
     * persona y «Mis proyectos»).
     *
     * @param  Builder<Client>  $query
     */
    private static function withPerson(Builder $query, int $userId): void
    {
        $query->whereHas('projects', fn (Builder $projects) => $projects
            ->whereIn('status', array_map(fn (ProjectStatus $status): string => $status->value, ProjectStatusBoard::OPEN_STATUSES))
            ->where(fn (Builder $scope) => $scope->where('owner_user_id', $userId)
                ->orWhereHas('members', fn (Builder $members) => $members->whereKey($userId))));
    }

    /**
     * @param  array<int, list<string>>  $prefixes
     * @return list<int>
     */
    private static function clientsWithTag(array $prefixes, string $tag): array
    {
        $ids = [];

        foreach ($prefixes as $clientId => $codes) {
            foreach ($codes as $code) {
                if (ProjectKindCode::tag($code) === $tag) {
                    $ids[] = $clientId;
                    break;
                }
            }
        }

        return $ids;
    }

    /**
     * Opciones para selectores (p. ej. el cliente de «Nuevo proyecto»): solo clientes activos, más
     * el indicado en ?incluir= (el cliente actual de un proyecto aunque se haya desactivado).
     */
    public function options(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Client::class);

        $include = $request->integer('incluir');

        $clients = Client::query()
            ->where(fn (Builder $query) => $query->where('is_active', true)
                ->when($include > 0, fn (Builder $query) => $query->orWhere('id', $include)))
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json($clients->map(fn (Client $client): array => [
            'id' => $client->id,
            'name' => $client->name,
        ])->values()->all());
    }

    public function store(ClientRequest $request): RedirectResponse
    {
        $client = Client::query()->create([...$request->clientData(), 'is_active' => true]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('clients.created', ['name' => $client->name])]);

        return to_route('clients.show', $client);
    }

    public function show(Request $request, Client $client, HourBankCommitment $commitment, WeeklyProjectStatus $projectStatus): Response
    {
        $this->authorize('view', $client);

        /** @var User $user */
        $user = $request->user();
        $weekly = self::weeklyEnabled($user);
        $tab = $request->validate(['pestana' => ['nullable', 'string', Rule::in(self::TABS)]])['pestana'] ?? 'resumen';
        $tab = $weekly ? $tab : 'resumen';

        $projects = $client->projects()
            ->with('owner')
            ->orderByRaw('CASE status WHEN ? THEN 0 WHEN ? THEN 1 WHEN ? THEN 2 WHEN ? THEN 3 ELSE 4 END', [
                ProjectStatus::Active->value,
                ProjectStatus::Planned->value,
                ProjectStatus::OnHold->value,
                ProjectStatus::Completed->value,
            ])
            ->orderBy('name')
            ->get();
        $projects->each(fn (Project $project) => $project->setRelation('client', $client));

        $banks = HourBank::query()
            ->whereIn('project_id', $projects->modelKeys())
            ->with(['department', 'project'])
            ->orderByDesc('start_date')
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        $open = $banks->filter(fn (HourBank $bank): bool => in_array($bank->status, [HourBankStatus::Active, HourBankStatus::Exhausted], true))->values();
        $history = $banks->filter(fn (HourBank $bank): bool => in_array($bank->status, [HourBankStatus::Renewed, HourBankStatus::Closed], true))->values();

        // Las mismas horas comprometidas que el detalle de la bolsa, su tarjeta y la vista global.
        $committed = $commitment->forBanks($open->modelKeys());
        $open->each(fn (HourBank $bank) => $bank->setAttribute('committed_minutes', $committed[$bank->id]['committed_minutes'] ?? 0));

        [$monthStart, $monthEnd] = self::monthRange();
        $yearStart = LocalTime::today()->startOfYear()->toDateString();
        $yearEnd = LocalTime::today()->endOfYear()->toDateString();

        // Responsable (F-128) e insignias por tipo de proyecto (F-120), con los proyectos ya cargados.
        $owner = ClientInsights::mainOwner($projects);
        $openProjects = $projects->filter(fn (Project $project): bool => in_array($project->status, ProjectStatusBoard::OPEN_STATUSES, true));

        return Inertia::render('clients/show', [
            'client' => ResourceProps::item(ClientResource::make($client), $request),
            'tab' => $tab,
            'owner' => $owner === null ? null : [...(new UserSummaryResource($owner))->resolve(), 'job_title' => $owner->job_title],
            'kindBadges' => ProjectKindCode::badges($openProjects->map(fn (Project $project): string => ProjectKindCode::for($project->code, $projectStatus->kind($project)))->all()),
            // La Weekly del cliente (Fase 10, F-129 a F-133): solo la pestaña abierta, diferida; null
            // sin el módulo o para quien no usa la Weekly.
            'weekly' => $weekly ? Inertia::defer(fn (): array => app(ClientWeeklyTabs::class)->for($client, $tab, $user), 'weekly') : null,
            'projects' => ResourceProps::list(ProjectResource::collection($projects), $request),
            'hourBanks' => ResourceProps::list(ClientHourBankResource::collection($open), $request),
            'hourBankHistory' => ResourceProps::list(ClientHourBankResource::collection($history), $request),
            'hours' => [
                'month_minutes' => self::loggedMinutes($projects, $monthStart, $monthEnd),
                'year_minutes' => self::loggedMinutes($projects, $yearStart, $yearEnd),
                'month_start' => $monthStart,
                'year' => (int) LocalTime::today()->year,
            ],
            // Acceso al portal (Fase 5, D-063 y D-064): diferida, no pesa en la carga; null para
            // quien no lo gestiona. Sus acciones recargan solo esta prop.
            'portal' => Inertia::defer(fn (): ?array => app(ClientPortalAccess::class)->for($client, $request->user()), 'portal', true),
            'can' => [
                'update' => $request->user()?->can('update', $client) ?? false,
                // Informe del cliente (Fase 2, R2; D-044): enlace «Ver informe».
                'viewReport' => $request->user()?->can('viewReport', $client) ?? false,
                // Horas para facturar de este cliente (Fase 2, R2; D-045): admins y view-financials.
                'viewBilling' => $request->user()?->can('viewBilling', Client::class) ?? false,
                // La Weekly del cliente: sus pestañas, el resumen con IA y unirse o dejar proyectos.
                'useWeeklies' => $weekly,
            ],
        ]);
    }

    public function update(ClientRequest $request, Client $client): RedirectResponse
    {
        $client->fill($request->clientData())->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('clients.updated')]);

        return back();
    }

    public function deactivate(Client $client): RedirectResponse
    {
        $this->authorize('update', $client);

        $client->is_active = false;
        $client->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('clients.deactivated', ['name' => $client->name])]);

        return back();
    }

    public function reactivate(Client $client): RedirectResponse
    {
        $this->authorize('update', $client);

        $client->is_active = true;
        $client->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('clients.reactivated', ['name' => $client->name])]);

        return back();
    }

    /**
     * Primer y último día del mes en curso (en Madrid).
     *
     * @return array{0: string, 1: string}
     */
    private static function monthRange(): array
    {
        $today = LocalTime::today();

        return [$today->startOfMonth()->toDateString(), $today->endOfMonth()->toDateString()];
    }

    /**
     * @param  Collection<int, Project>  $projects
     */
    private static function loggedMinutes(Collection $projects, string $from, string $to): int
    {
        if ($projects->isEmpty()) {
            return 0;
        }

        return (int) TimeEntry::query()
            ->whereIn('project_id', $projects->modelKeys())
            ->whereBetween('date', [$from, $to])
            ->sum('minutes');
    }
}
