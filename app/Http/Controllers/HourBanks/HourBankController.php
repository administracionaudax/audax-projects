<?php

namespace App\Http\Controllers\HourBanks;

use App\Domain\Billing\BillingAccess;
use App\Domain\Billing\BillingPanel;
use App\Domain\HourBanks\HourBankBreakdown;
use App\Domain\HourBanks\HourBankCommitment;
use App\Domain\HourBanks\HourBankDeletion;
use App\Domain\HourBanks\HourBankHistory;
use App\Domain\HourBanks\HourBankLedger;
use App\Domain\HourBanks\HourBankRenewal;
use App\Enums\HourBankStatus;
use App\Enums\OveragePolicy;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Projects\ProjectController;
use App\Http\Requests\HourBanks\StoreHourBankRequest;
use App\Http\Requests\HourBanks\UpdateHourBankRequest;
use App\Http\Resources\HourBanks\HourBankCardResource;
use App\Http\Resources\ProjectResource;
use App\Http\Resources\Projects\Paginated;
use App\Http\Resources\Projects\ResourceData;
use App\Http\Resources\TaskTypeResource;
use App\Http\Resources\TimeEntryResource;
use App\Http\Resources\UserSummaryResource;
use App\Models\Department;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Bolsas de un proyecto (SPEC §8): la pestaña «Bolsas» (tarjetas, filtro de cerradas y renovadas
 * e histórico de renovaciones), el detalle de una bolsa y su alta, edición y borrado. El consumo
 * y el exceso los calcula siempre HourBankLedger; aquí nunca se escriben.
 */
class HourBankController extends Controller
{
    use AuthorizesRequests;

    public const int ENTRIES_PER_PAGE = 20;

    public function index(
        Request $request,
        Project $project,
        HourBankCommitment $commitment,
        HourBankHistory $history,
        HourBankRenewal $renewal,
    ): Response {
        $this->authorize('view', $project);
        abort_unless($project->usesHourBanks(), 404);

        /** @var User $user */
        $user = $request->user();
        $showAll = $request->boolean('todas');
        $canManage = $user->can('update', $project);

        $banks = HourBank::query()
            ->where('project_id', $project->id)
            ->with(['department:id,name,color', 'closer:id,name'])
            ->withExists(['timeEntries as has_time', 'tasks as has_tasks', 'renewal as has_renewal'])
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->get();

        $figures = $commitment->forBanks($banks->modelKeys());
        $cards = [];

        foreach ($banks as $bank) {
            if (! $showAll && ! $bank->acceptsTime()) {
                continue;
            }

            $cards[] = [
                ...ResourceData::of(new HourBankCardResource($bank, $figures[$bank->id] ?? null), $request),
                'can' => $this->abilities($bank, $user, $canManage, $renewal),
            ];
        }

        $project->load(['client:id,name', 'owner' => fn ($owner) => $owner->select(ProjectController::USER_SUMMARY_COLUMNS)]);

        return Inertia::render('projects/hour-banks', [
            'project' => ResourceData::of(ProjectResource::make($project), $request),
            'canManage' => $canManage,
            'banks' => $cards,
            'hiddenCount' => $banks->count() - count($cards),
            'history' => $history->chains($banks),
            'filters' => ['todas' => $showAll],
            'departments' => $this->departmentOptions($banks->pluck('department_id')->filter()->all()),
            'overageDefault' => self::overageDefault(),
            'can' => ['create' => $user->can('create', [HourBank::class, $project])],
        ]);
    }

    public function store(StoreHourBankRequest $request, Project $project): RedirectResponse
    {
        if (! $project->usesHourBanks()) {
            throw ValidationException::withMessages(['hour_bank' => __('hour_banks.errors.not_hour_bank_project')]);
        }

        $bank = HourBank::query()->create([
            ...$request->validated(),
            'project_id' => $project->id,
            'status' => HourBankStatus::Active,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('hour_banks.flash.created', ['name' => $bank->name])]);

        return to_route('projects.hour-banks.index', $project);
    }

    public function show(
        Request $request,
        Project $project,
        HourBank $hourBank,
        HourBankCommitment $commitment,
        HourBankBreakdown $breakdown,
        HourBankRenewal $renewal,
    ): Response {
        $this->authorize('view', $hourBank);

        /** @var User $user */
        $user = $request->user();
        $canManage = $user->can('update', $project);

        $hourBank->load([
            'department:id,name,color',
            'closer:id,name',
            'renewedFrom' => fn ($previous) => $previous->withTrashed()->select(['id', 'name', 'status']),
            'renewal:id,name,status,renewed_from_id',
        ]);
        $hourBank->loadExists(['timeEntries as has_time', 'tasks as has_tasks', 'renewal as has_renewal']);

        $figures = $commitment->forBanks([$hourBank->id]);
        $canBreakdown = $user->can('viewBreakdown', $hourBank);

        $entries = $breakdown->entries($hourBank, $user)
            ->paginate(self::ENTRIES_PER_PAGE, pageName: 'pagina')
            ->withQueryString();

        $entryItems = [];
        foreach ($entries->items() as $entry) {
            $entryItems[] = ResourceData::of(TimeEntryResource::make($entry), $request);
        }

        $project->load(['client:id,name', 'owner' => fn ($owner) => $owner->select(ProjectController::USER_SUMMARY_COLUMNS)]);

        return Inertia::render('projects/hour-bank', [
            // Vendido frente a real de la bolsa y sus facturas de Holded (Fase 12, D-392): diferido y
            // solo con el módulo Facturación y view-sold-vs-actual (las facturas, con view-billing).
            'billing' => BillingAccess::viewsProject($user, $project)
                ? Inertia::defer(fn (): array => app(BillingPanel::class)->forBank($hourBank, $user), 'billing')
                : null,
            'project' => ResourceData::of(ProjectResource::make($project), $request),
            'canManage' => $canManage,
            'bank' => [
                ...ResourceData::of(new HourBankCardResource($hourBank, $figures[$hourBank->id] ?? null), $request),
                'can' => $this->abilities($hourBank, $user, $canManage, $renewal),
            ],
            'weekly' => $breakdown->weekly($hourBank),
            'byPerson' => $canBreakdown ? array_map(fn (array $row): array => [
                'user' => ResourceData::of(UserSummaryResource::make($row['user']), $request),
                'minutes' => $row['minutes'],
                'overage_minutes' => $row['overage_minutes'],
            ], $breakdown->byPerson($hourBank, $user)) : null,
            'byType' => array_map(fn (array $row): array => [
                'type' => $row['type'] !== null ? ResourceData::of(TaskTypeResource::make($row['type']), $request) : null,
                'minutes' => $row['minutes'],
                'overage_minutes' => $row['overage_minutes'],
            ], $breakdown->byType($hourBank)),
            'entries' => Paginated::props($entries, $entryItems),
            'tasks' => array_map(fn (array $row): array => [
                'id' => $row['task']->id,
                'title' => $row['task']->title,
                'parent_task_id' => $row['task']->parent_task_id,
                'depth' => $row['depth'],
                'is_completed' => $row['task']->isCompleted(),
                'is_milestone' => $row['task']->is_milestone,
                'status' => [
                    'name' => $row['task']->status->name,
                    'color' => $row['task']->status->color,
                    'category' => $row['task']->status->category->value,
                ],
                'assignee' => $row['task']->assignee !== null ? ResourceData::of(UserSummaryResource::make($row['task']->assignee), $request) : null,
                'estimated_minutes' => $row['estimated_minutes'],
                'logged_minutes' => $row['logged_minutes'],
                'committed_minutes' => $row['committed_minutes'],
            ], $commitment->tasksFor($hourBank)),
            'departments' => $this->departmentOptions(array_filter([$hourBank->department_id])),
            'overageDefault' => self::overageDefault(),
        ]);
    }

    public function update(UpdateHourBankRequest $request, Project $project, HourBank $hourBank): RedirectResponse
    {
        // Si cambia el total, HourBank::booted recalcula el consumo y el exceso con HourBankLedger.
        $hourBank->fill($request->validated());
        $hourBank->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('hour_banks.flash.updated')]);

        return back();
    }

    /**
     * Borrar (solo admin y sin horas, HourBankPolicy::delete). HourBankDeletion no deja borrar una
     * bolsa con tareas o ya renovada, y si es la renovación de otra, la anterior vuelve a abrirse.
     */
    public function destroy(Project $project, HourBank $hourBank, HourBankDeletion $deletion): RedirectResponse
    {
        $this->authorize('delete', $hourBank);

        $name = $hourBank->name;
        $previous = $deletion->delete($hourBank);

        $message = $previous === null
            ? __('hour_banks.flash.deleted', ['name' => $name])
            : __('hour_banks.flash.deleted_renewal', [
                'name' => $name,
                'previous' => $previous->name,
                'status' => mb_strtolower($previous->status->label()),
            ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return to_route('projects.hour-banks.index', $project);
    }

    /**
     * Qué botones ofrecer (la autorización real la hacen HourBankPolicy y las clases de dominio en
     * cada acción). Renovar, solo si está agotada o próxima a agotarse (D-035).
     *
     * @return array{update: bool, renew: bool, close: bool, reopen: bool, delete: bool}
     */
    private function abilities(HourBank $bank, User $user, bool $canManage, HourBankRenewal $renewal): array
    {
        $attributes = $bank->getAttributes();

        return [
            'update' => $canManage && $bank->status !== HourBankStatus::Renewed,
            'renew' => $canManage && $renewal->isDue($bank),
            'close' => $canManage && $bank->acceptsTime(),
            'reopen' => $user->isAdmin() && $bank->status === HourBankStatus::Closed,
            'delete' => $user->isAdmin()
                && ! ($attributes['has_time'] ?? true)
                && ! ($attributes['has_tasks'] ?? true)
                && ! ($attributes['has_renewal'] ?? true),
        ];
    }

    /**
     * Política efectiva de una bolsa con `inherit` (el ajuste allow_hour_bank_overage), para
     * explicarla en el formulario (también en el de la primera bolsa de un proyecto).
     */
    public static function overageDefault(): string
    {
        return app(HourBankLedger::class)
            ->effectivePolicy(new HourBank(['overage_policy' => OveragePolicy::Inherit]))
            ->value;
    }

    /**
     * Departamentos para el selector: los que existen y, marcados como eliminados, los de las
     * bolsas que se muestran aunque ya se hayan borrado (para poder conservarlos al editar o
     * renovar, como el cliente de un proyecto).
     *
     * @param  array<int, int|null>  $keep
     * @return list<array{id: int, name: string, deleted: bool}>
     */
    private function departmentOptions(array $keep = []): array
    {
        $keep = array_values(array_unique(array_map('intval', array_filter($keep))));

        return array_values(Department::query()
            ->withTrashed()
            ->where(fn (Builder $where) => $where->whereNull('deleted_at')
                ->when($keep !== [], fn (Builder $kept) => $kept->orWhereIn('id', $keep)))
            ->orderBy('name')
            ->get(['id', 'name', 'deleted_at'])
            ->map(fn (Department $department): array => [
                'id' => $department->id,
                'name' => $department->name,
                'deleted' => $department->trashed(),
            ])
            ->all());
    }
}
