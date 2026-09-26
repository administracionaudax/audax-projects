<?php

namespace App\Http\Controllers\Clients;

use App\Domain\Admin\TextSearch;
use App\Enums\HourBankStatus;
use App\Enums\ProjectStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Clients\ClientRequest;
use App\Http\Resources\Admin\ResourceProps;
use App\Http\Resources\ClientResource;
use App\Http\Resources\Clients\ClientHourBankResource;
use App\Http\Resources\Clients\ClientRowResource;
use App\Http\Resources\ProjectResource;
use App\Models\Client;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Support\LocalTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
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

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Client::class);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'estado' => ['nullable', 'string', Rule::in(['activos', 'inactivos', 'todos'])],
        ]);

        $status = $filters['estado'] ?? 'activos';
        [$monthStart, $monthEnd] = self::monthRange();

        $clients = Client::query()
            ->withCount(['projects as active_projects_count' => fn (Builder $query) => $query->where('status', ProjectStatus::Active->value)])
            ->withSum(['timeEntries as month_minutes' => fn (Builder $query) => $query->whereBetween('time_entries.date', [$monthStart, $monthEnd])], 'minutes')
            ->when($filters['q'] ?? null, fn (Builder $query, string $term) => TextSearch::apply($query, $term, ['clients.name', 'clients.tax_id', 'clients.contact_name', 'clients.contact_email']))
            ->when($status === 'activos', fn (Builder $query) => $query->where('is_active', true))
            ->when($status === 'inactivos', fn (Builder $query) => $query->where('is_active', false))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('clients/index', [
            'clients' => ClientRowResource::collection($clients),
            'filters' => [
                'q' => $filters['q'] ?? '',
                'estado' => $status,
            ],
        ]);
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

    public function show(Request $request, Client $client): Response
    {
        $this->authorize('view', $client);

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

        $committed = self::committedMinutes($open->map(fn (HourBank $bank): int => $bank->id)->values()->all());
        $open->each(fn (HourBank $bank) => $bank->setAttribute('committed_minutes', $committed[$bank->id] ?? 0));

        [$monthStart, $monthEnd] = self::monthRange();
        $yearStart = LocalTime::today()->startOfYear()->toDateString();
        $yearEnd = LocalTime::today()->endOfYear()->toDateString();

        return Inertia::render('clients/show', [
            'client' => ResourceProps::item(ClientResource::make($client), $request),
            'projects' => ResourceProps::list(ProjectResource::collection($projects), $request),
            'hourBanks' => ResourceProps::list(ClientHourBankResource::collection($open), $request),
            'hourBankHistory' => ResourceProps::list(ClientHourBankResource::collection($history), $request),
            'hours' => [
                'month_minutes' => self::loggedMinutes($projects, $monthStart, $monthEnd),
                'year_minutes' => self::loggedMinutes($projects, $yearStart, $yearEnd),
                'month_start' => $monthStart,
                'year' => (int) LocalTime::today()->year,
            ],
            'can' => [
                'update' => $request->user()?->can('update', $client) ?? false,
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

    /**
     * Horas comprometidas por bolsa (SPEC §8, UI): suma de max(estimada − imputada, 0) de sus tareas
     * abiertas. Si una tarea tiene subtareas con estimación, cuentan las subtareas (su estimación
     * es la suma de las de las subtareas, SPEC §6) y no la tarea padre, para no contar dos veces.
     * Una sola consulta agregada.
     *
     * @param  array<int, int>  $bankIds
     * @return array<int, int>
     */
    public static function committedMinutes(array $bankIds): array
    {
        if ($bankIds === []) {
            return [];
        }

        $logged = DB::table('time_entries')
            ->select('task_id')
            ->selectRaw('SUM(minutes) AS logged')
            ->groupBy('task_id');

        return DB::table('tasks')
            ->leftJoinSub($logged, 'logged', 'logged.task_id', '=', 'tasks.id')
            ->whereIn('tasks.hour_bank_id', $bankIds)
            ->whereNull('tasks.completed_at')
            ->whereNull('tasks.deleted_at')
            ->whereNotNull('tasks.estimated_minutes')
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))
                ->from('tasks as subtasks')
                ->whereColumn('subtasks.parent_task_id', 'tasks.id')
                ->whereNotNull('subtasks.estimated_minutes')
                ->whereNull('subtasks.deleted_at'))
            ->groupBy('tasks.hour_bank_id')
            ->selectRaw('tasks.hour_bank_id AS bank_id')
            ->selectRaw('SUM(CASE WHEN tasks.estimated_minutes > COALESCE(logged.logged, 0) THEN tasks.estimated_minutes - COALESCE(logged.logged, 0) ELSE 0 END) AS committed')
            ->pluck('committed', 'bank_id')
            ->mapWithKeys(fn ($committed, $bankId): array => [(int) $bankId => (int) $committed])
            ->all();
    }
}
