<?php

namespace App\Http\Controllers\Time;

use App\Domain\Reports\Delivery\ReportKind;
use App\Domain\Reports\Delivery\ReportRequest;
use App\Enums\TimeEntryStatus;
use App\Http\Resources\ProjectResource;
use App\Http\Resources\Time\Plain;
use App\Http\Resources\TimeEntryResource;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pestaña Horas del proyecto /proyectos/{project}/horas (SPEC §6, D-021): entradas con filtros
 * (persona, fechas, bolsa, estado y facturable), totales y paginación. Un empleado ve solo las
 * suyas; gestores, responsables (las de su equipo) y admins, las que TimeEntry::visibleTo les deja.
 * Se exporta e imprime desde su menú «Exportar ▾» (HoursExportController::project, Fase 9).
 */
class ProjectTimeController extends TimeController
{
    public const int PER_PAGE = 50;

    public function __invoke(Request $request, Project $project): Response
    {
        $this->authorize('view', $project);

        /** @var User $viewer */
        $viewer = $request->user();
        $viewAll = Gate::forUser($viewer)->allows('viewAllTime', $project);
        $filters = $this->filters($request);

        $base = TimeEntry::query()
            ->where('time_entries.project_id', $project->id)
            ->when(
                $viewAll,
                fn (Builder $query) => $query->visibleTo($viewer),
                fn (Builder $query) => $query->where('time_entries.user_id', $viewer->id),
            );

        $filtered = (clone $base)
            ->when($filters['persona'] !== null, fn (Builder $query) => $query->where('time_entries.user_id', $filters['persona']))
            ->when($filters['desde'] !== null, fn (Builder $query) => $query->where('time_entries.date', '>=', $filters['desde']))
            ->when($filters['hasta'] !== null, fn (Builder $query) => $query->where('time_entries.date', '<=', $filters['hasta']))
            ->when($filters['bolsa'] !== null, fn (Builder $query) => $query->where('time_entries.hour_bank_id', $filters['bolsa']))
            ->when($filters['estado'] !== null, fn (Builder $query) => $query->where('time_entries.status', $filters['estado']))
            ->when($filters['facturable'] !== null, fn (Builder $query) => $query->where('time_entries.is_billable', $filters['facturable'] === 'si'));

        $totals = (array) (clone $filtered)
            ->toBase()
            ->selectRaw('COUNT(*) as entries, COALESCE(SUM(minutes), 0) as minutes, COALESCE(SUM(overage_minutes), 0) as overage, COALESCE(SUM(CASE WHEN hour_bank_id IS NOT NULL THEN minutes - overage_minutes ELSE 0 END), 0) as in_bank, COALESCE(SUM(CASE WHEN is_billable THEN minutes ELSE 0 END), 0) as billable')
            ->first();

        $paginator = $filtered
            ->with(['user', 'task:id,title,deleted_at', 'project:id,code,name,color,deleted_at'])
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $project->load(['client', 'owner']);
        $canEdit = $this->editableChecker($viewer);

        return Inertia::render('projects/time', [
            'project' => Plain::of(new ProjectResource($project)),
            'canManage' => Gate::forUser($viewer)->allows('update', $project),
            'scope' => $viewAll ? ($viewer->isAdmin() || $viewer->isManagerOf($project) ? 'all' : 'team') : 'mine',
            'entries' => [
                'data' => collect($paginator->items())->map(fn (TimeEntry $entry): array => [
                    ...Plain::of(new TimeEntryResource($entry)),
                    'can_edit' => $canEdit($entry),
                ])->all(),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'from' => $paginator->firstItem(),
                    'to' => $paginator->lastItem(),
                ],
                'links' => [
                    'prev' => $paginator->previousPageUrl(),
                    'next' => $paginator->nextPageUrl(),
                ],
            ],
            'totals' => [
                'entries' => (int) ($totals['entries'] ?? 0),
                'minutes' => (int) ($totals['minutes'] ?? 0),
                'overage_minutes' => (int) ($totals['overage'] ?? 0),
                // Dentro de bolsa (D-078): solo las entradas con bolsa, sin su exceso.
                'in_bank_minutes' => (int) ($totals['in_bank'] ?? 0),
                'billable_minutes' => (int) ($totals['billable'] ?? 0),
            ],
            'filters' => $filters,
            // Menú «Exportar ▾» de la pestaña (Fase 9, D-139): sus mismos filtros.
            'report_request' => (new ReportRequest(ReportKind::ProjectHours, ['project' => $project->id],
                array_map(fn (int|string $value): string => (string) $value, array_filter($filters, fn (int|string|null $value): bool => $value !== null))))->toArray(),
            'options' => [
                'people' => User::query()
                    ->whereIn('id', (clone $base)->select('time_entries.user_id')->distinct())
                    ->orderBy('name')
                    ->get(['id', 'name'])
                    ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name])
                    ->all(),
                'banks' => $project->hourBanks()
                    ->orderBy('start_date')
                    ->get(['id', 'name'])
                    ->map(fn (HourBank $bank): array => ['id' => $bank->id, 'name' => $bank->name])
                    ->all(),
            ],
        ]);
    }

    /**
     * Filtros de la URL (en español). Los valores que no se entienden se ignoran.
     *
     * @return array{persona: int|null, desde: string|null, hasta: string|null, bolsa: int|null, estado: string|null, facturable: 'si'|'no'|null}
     */
    private function filters(Request $request): array
    {
        $date = function (string $key) use ($request): ?string {
            $value = $request->string($key)->toString();

            return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && CarbonImmutable::canBeCreatedFromFormat($value, 'Y-m-d') ? $value : null;
        };

        $status = $request->string('estado')->toString();
        $billable = $request->string('facturable')->toString();

        return [
            'persona' => $request->filled('persona') && ctype_digit($request->string('persona')->toString()) ? $request->integer('persona') : null,
            'desde' => $date('desde'),
            'hasta' => $date('hasta'),
            'bolsa' => $request->filled('bolsa') && ctype_digit($request->string('bolsa')->toString()) ? $request->integer('bolsa') : null,
            'estado' => in_array($status, TimeEntryStatus::values(), true) ? $status : null,
            'facturable' => in_array($billable, ['si', 'no'], true) ? $billable : null,
        ];
    }

    /**
     * Mismo criterio que TimeEntryPolicy::update, sin consultas por fila: las bloqueadas solo un
     * admin; el resto, su dueño, un admin, su responsable o un gestor del proyecto. Además, la
     * entrada tiene que estar en borrador (su semana abierta) salvo las bloqueadas para un admin.
     *
     * @return \Closure(TimeEntry): bool
     */
    private function editableChecker(User $viewer): \Closure
    {
        $admin = $viewer->isAdmin();
        $departmentIds = $viewer->managedDepartmentIds();
        $projectIds = $viewer->managedProjectIds();

        return function (TimeEntry $entry) use ($viewer, $admin, $departmentIds, $projectIds): bool {
            if ($entry->status === TimeEntryStatus::Locked) {
                return $admin;
            }

            if ($entry->status !== TimeEntryStatus::Draft) {
                return false;
            }

            return $entry->user_id === $viewer->id
                || $admin
                || in_array($entry->user->department_id, $departmentIds, true)
                || in_array($entry->project_id, $projectIds, true);
        };
    }
}
