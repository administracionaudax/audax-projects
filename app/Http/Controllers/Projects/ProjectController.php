<?php

namespace App\Http\Controllers\Projects;

use App\Domain\HourBanks\FirstHourBank;
use App\Domain\HourBanks\HourBankCommitment;
use App\Domain\Projects\ProjectActivityFeed;
use App\Domain\Projects\ProjectColors;
use App\Domain\Projects\ProjectCreator;
use App\Domain\Projects\ProjectFilters;
use App\Domain\Projects\ProjectSummary;
use App\Domain\Recurring\ProjectRecurringSettings;
use App\Domain\Templates\ProjectFromTemplate;
use App\Domain\Templates\ProjectTemplatingSettings;
use App\Domain\Templates\TemplateItems;
use App\Enums\BillingType;
use App\Enums\HourBankStatus;
use App\Enums\ProjectStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\HourBanks\HourBankController;
use App\Http\Requests\Projects\StoreProjectRequest;
use App\Http\Requests\Projects\UpdateProjectRequest;
use App\Http\Resources\HourBanks\HourBankCardResource;
use App\Http\Resources\ProjectResource;
use App\Http\Resources\Projects\Paginated;
use App\Http\Resources\Projects\ProjectListResource;
use App\Http\Resources\Projects\ProjectMemberResource;
use App\Http\Resources\Projects\ResourceData;
use App\Http\Resources\UserSummaryResource;
use App\Models\Client;
use App\Models\Department;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\ProjectTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Proyectos (SPEC §6, D-021, D-022, D-032): listado con filtros, alta, resumen y ajustes.
 * Todos los internos los ven; los crean admins y responsables; los gestionan admins,
 * responsables y sus gestores (ProjectPolicy).
 */
class ProjectController extends Controller
{
    use AuthorizesRequests;

    public const int PER_PAGE = 25;

    /** Columnas del usuario para UserSummaryResource. */
    public const array USER_SUMMARY_COLUMNS = ['id', 'name', 'avatar_path', 'department_id', 'is_active'];

    public function index(Request $request, ProjectFilters $filters): Response
    {
        $this->authorize('viewAny', Project::class);

        /** @var User $user */
        $user = $request->user();
        $values = $filters->fromRequest($request);

        // Bolsas abiertas (activas y agotadas): el consumo agregado de la columna del listado.
        $openBanks = fn (Builder $banks) => $banks->whereIn('hour_banks.status', [
            HourBankStatus::Active->value,
            HourBankStatus::Exhausted->value,
        ]);

        $query = Project::query()
            ->with([
                'client:id,name',
                'owner' => fn ($owner) => $owner->select(self::USER_SUMMARY_COLUMNS),
            ])
            ->withCount(['hourBanks as open_banks_count' => $openBanks])
            ->withSum(['hourBanks as open_banks_total' => $openBanks], 'total_minutes')
            ->withSum(['hourBanks as open_banks_consumed' => $openBanks], 'consumed_minutes')
            ->withSum(['hourBanks as open_banks_overage' => $openBanks], 'overage_minutes');

        $filters->apply($query, $values, $user);

        $paginator = $query
            ->orderBy('projects.name')
            ->orderBy('projects.id')
            ->paginate(self::PER_PAGE, pageName: 'pagina')
            ->withQueryString();

        $items = [];
        foreach ($paginator->items() as $project) {
            $items[] = ResourceData::of(ProjectListResource::make($project), $request);
        }

        return Inertia::render('projects/index', [
            'projects' => Paginated::props($paginator, $items),
            'filters' => $values,
            'options' => [
                'clients' => Client::query()->orderBy('name')->get(['id', 'name'])
                    ->map(fn (Client $client): array => ['id' => $client->id, 'name' => $client->name])->all(),
                'owners' => User::query()
                    ->whereIn('id', Project::query()->select('owner_user_id'))
                    ->orderBy('name')
                    ->get(['id', 'name'])
                    ->map(fn (User $owner): array => ['id' => $owner->id, 'name' => $owner->name])->all(),
                'departments' => $this->departmentOptions(),
            ],
        ]);
    }

    public function create(Request $request, TemplateItems $templates): Response
    {
        $this->authorize('create', Project::class);

        /** @var User $user */
        $user = $request->user();

        return Inertia::render('projects/create', [
            'clients' => $this->clientOptions(null),
            'people' => $this->peopleOptions(),
            'defaults' => [
                'color' => ProjectColors::next(Project::query()->withTrashed()->count()),
                'owner_user_id' => $user->id,
                'status' => ProjectStatus::Active->value,
                'billing_type' => BillingType::HourBank->value,
            ],
            // «Desde plantilla» (D-058): plantillas activas y, para la primera bolsa, departamentos.
            'templates' => $user->can('viewAny', ProjectTemplate::class) ? $templates->options() : [],
            'departments' => $this->departmentOptions(),
            'overageDefault' => HourBankController::overageDefault(),
        ]);
    }

    public function store(StoreProjectRequest $request, ProjectCreator $creator, ProjectFromTemplate $fromTemplate): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $attributes = collect($request->validated())->except(['member_ids', ...StoreProjectRequest::templateFields()])->all();
        $template = $request->template();

        if ($template === null) {
            $project = $creator->create($attributes, $request->memberIds(), $user);
            $message = __('projects.flash.created');
        } else {
            // Desde plantilla (D-058): proyecto, primera bolsa y tareas, todo o nada.
            $result = $fromTemplate->create($attributes, $request->memberIds(), $user, $template, $request->templateStart(), $request->templateBankData());
            $project = $result['project'];
            $message = trans_choice('templates.flash.project_created', $result['tasks'], ['count' => $result['tasks'], 'name' => $template->name]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return to_route('projects.show', $project);
    }

    public function show(
        Request $request,
        Project $project,
        ProjectSummary $summary,
        ProjectActivityFeed $activity,
        HourBankCommitment $commitment,
    ): Response {
        $this->authorize('view', $project);

        /** @var User $user */
        $user = $request->user();

        $project->load([
            'client:id,name',
            'owner' => fn ($owner) => $owner->select(self::USER_SUMMARY_COLUMNS),
        ]);

        $managers = $project->managers()
            ->orderBy('name')
            ->get(array_map(fn (string $column): string => "users.{$column}", self::USER_SUMMARY_COLUMNS));

        $banks = [];
        if ($project->usesHourBanks()) {
            $open = HourBank::query()
                ->where('project_id', $project->id)
                ->open()
                ->with('department:id,name,color')
                ->orderBy('start_date')
                ->orderBy('id')
                ->get();
            $figures = $commitment->forBanks($open->modelKeys());

            foreach ($open as $bank) {
                $banks[] = ResourceData::of(new HourBankCardResource($bank, $figures[$bank->id] ?? null), $request);
            }
        }

        return Inertia::render('projects/show', [
            'project' => ResourceData::of(ProjectResource::make($project), $request),
            'canManage' => $user->can('update', $project),
            'summary' => $summary->for($project),
            'managers' => ResourceData::of(UserSummaryResource::collection($managers), $request),
            'membersCount' => $project->members()->count(),
            'hourBanks' => $banks,
            'activity' => $activity->latest($project, $user),
        ]);
    }

    public function edit(Request $request, Project $project): Response
    {
        $this->authorize('update', $project);

        /** @var User $user */
        $user = $request->user();

        $project->load([
            'client:id,name',
            'owner' => fn ($owner) => $owner->select(self::USER_SUMMARY_COLUMNS),
        ]);

        $members = $project->members()
            ->get(array_map(fn (string $column): string => "users.{$column}", self::USER_SUMMARY_COLUMNS))
            ->sortBy(fn (User $member): string => sprintf(
                '%d%d%s',
                $member->id === $project->owner_user_id ? 0 : 1,
                $member->membership?->is_manager ? 0 : 1,
                mb_strtolower($member->name),
            ))
            ->values();

        $editableAlerts = [];
        foreach ($members as $member) {
            if ($member->membership?->is_manager && ($user->isAdmin() || $user->id === $member->id)) {
                $editableAlerts[] = $member->id;
            }
        }

        return Inertia::render('projects/settings', [
            'project' => ResourceData::of(ProjectResource::make($project), $request),
            'canManage' => true,
            'members' => $members->map(fn (User $member): array => ResourceData::of(new ProjectMemberResource($member, $project), $request))->all(),
            'clients' => $this->clientOptions($project->client_id),
            'people' => $this->peopleOptions(),
            'hasHourBanks' => $project->hourBanks()->exists(),
            // Si pasa a «bolsa de horas» con tareas, se crea su primera bolsa en el mismo paso.
            'tasksWithoutBank' => $project->usesHourBanks() ? 0 : $project->tasks()->whereNull('hour_bank_id')->count(),
            'departments' => $this->departmentOptions(),
            'overageDefault' => HourBankController::overageDefault(),
            // Secciones «Plantilla» y «Tareas recurrentes» (D-058, D-059): diferidas, no pesan en la carga.
            'templating' => Inertia::defer(fn (): array => app(ProjectTemplatingSettings::class)->for($project), 'planning', true),
            'recurring' => Inertia::defer(fn (): array => app(ProjectRecurringSettings::class)->for($project), 'planning', true),
            'can' => [
                'manageMembers' => $user->can('manageMembers', $project),
                'archive' => $user->can('archive', $project),
                'editAlertsOf' => $editableAlerts,
            ],
        ]);
    }

    /**
     * Guarda los datos. Si el proyecto pasa a «bolsa de horas» y ya tiene tareas, crea en la misma
     * transacción su primera bolsa y le asigna esas tareas (FirstHourBank; las horas no se mueven).
     */
    public function update(UpdateProjectRequest $request, Project $project, FirstHourBank $firstBank): RedirectResponse
    {
        $data = $request->validated();
        $bankData = $data['hour_bank'] ?? null;
        unset($data['hour_bank']);

        $first = DB::transaction(function () use ($project, $data, $bankData, $firstBank): ?array {
            $project->fill($data);
            $project->save();

            return is_array($bankData) ? $firstBank->create($project, $bankData) : null;
        });

        $message = $first === null
            ? __('projects.flash.updated')
            : trans_choice('projects.flash.updated_with_bank', $first['moved_tasks'], [
                'count' => $first['moved_tasks'],
                'name' => $first['bank']->name,
            ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return to_route('projects.settings', $project);
    }

    /**
     * Clientes para el selector: los activos y, al editar, el actual aunque esté desactivado.
     *
     * @return list<array{id: int, name: string, is_active: bool}>
     */
    private function clientOptions(?int $currentClientId): array
    {
        return array_values(Client::query()
            ->where(fn (Builder $where) => $where->where('is_active', true)
                ->when($currentClientId !== null, fn (Builder $current) => $current->orWhere('id', $currentClientId)))
            ->orderBy('name')
            ->get(['id', 'name', 'is_active'])
            ->map(fn (Client $client): array => ['id' => $client->id, 'name' => $client->name, 'is_active' => $client->is_active])
            ->all());
    }

    /**
     * Personas internas activas (gestor principal y miembros), con su departamento.
     *
     * @return list<array{id: int, name: string, department: string|null}>
     */
    private function peopleOptions(): array
    {
        return array_values(User::query()
            ->active()
            ->internal()
            ->with('department:id,name')
            ->orderBy('name')
            ->get(['id', 'name', 'department_id'])
            ->map(fn (User $person): array => [
                'id' => $person->id,
                'name' => $person->name,
                'department' => $person->department?->name,
            ])
            ->all());
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function departmentOptions(): array
    {
        return array_values(Department::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Department $department): array => ['id' => $department->id, 'name' => $department->name])
            ->all());
    }
}
