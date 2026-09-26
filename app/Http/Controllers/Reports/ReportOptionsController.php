<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Reports\Concerns\BuildsReportScope;
use App\Models\Client;
use App\Models\Department;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\TaskType;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Opciones de la barra de filtros de los informes (GET /informes/opciones), acotadas a lo que
 * quien mira puede usar (D-044): nunca ofrece personas, departamentos o proyectos que no podría ver.
 */
class ReportOptionsController extends Controller
{
    use BuildsReportScope;

    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $scope = $this->baseReportScope($request);
        $isAdmin = $user->isAdmin();
        $managed = $user->managedDepartmentIds();

        $projects = Project::query()
            ->when(! $isAdmin && ! $user->isDepartmentManager(), fn (Builder $q) => $q->withMember($user))
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'client_id', 'color', 'status']);

        return response()->json([
            'people' => $scope->people()->map(fn (User $person) => ['id' => $person->id, 'name' => $person->name])->values(),
            'departments' => Department::query()
                ->when(! $isAdmin, fn (Builder $q) => $q->whereKey($managed))
                ->orderBy('name')->get(['id', 'name', 'color']),
            'clients' => Client::query()
                ->whereIn('id', $projects->pluck('client_id')->filter()->unique()->values())
                ->orderBy('name')->get(['id', 'name', 'is_active']),
            'projects' => $projects->map(fn (Project $p) => ['id' => $p->id, 'name' => $p->code.' · '.$p->name, 'client_id' => $p->client_id, 'archived' => $p->status->value === 'archived'])->values(),
            'hour_banks' => HourBank::query()
                ->whereIn('project_id', $projects->modelKeys())
                ->with(['project' => fn ($q) => $q->select(['id', 'code'])])
                ->orderByDesc('start_date')->get(['id', 'name', 'project_id', 'status'])
                ->map(fn (HourBank $b) => ['id' => $b->id, 'name' => $b->project->code.' · '.$b->name, 'project_id' => $b->project_id, 'status' => $b->status->value])->values(),
            'task_types' => TaskType::query()->ordered()->get(['id', 'name', 'color']),
        ]);
    }
}
