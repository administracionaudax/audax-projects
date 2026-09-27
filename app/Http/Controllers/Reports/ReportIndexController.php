<?php

namespace App\Http\Controllers\Reports;

use App\Enums\ProjectStatus;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Department;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Índice de informes (/informes, SPEC §10, D-044): los dashboards a los que tiene acceso quien mira.
 * - Dirección: admins y responsables que dirigen algún departamento (DepartmentPolicy).
 * - Departamentos: todos (admin) o los que dirige.
 * - Clientes y proyectos: admins y responsables, todos; un gestor, los de los proyectos que
 *   gestiona; un empleado, ninguno.
 * - Personas: todas las internas (admin), su equipo y él mismo (responsable) o solo él mismo.
 * - Informe detallado: todos los internos (con sus horas visibles, TimeEntry::visibleTo).
 * Así un empleado ve su propio informe y el detallado. Los dashboards de cliente, proyecto y
 * detallado los sirven otras áreas (R2 y R3): aquí solo se enlazan.
 */
class ReportIndexController extends Controller
{
    public function __invoke(Request $request): Response
    {
        Gate::authorize('viewReports', User::class);

        /** @var User $user */
        $user = $request->user();
        $isAdmin = $user->isAdmin();
        $managedDepartments = $user->managedDepartmentIds();
        $seesAllProjects = $isAdmin || $user->isDepartmentManager();
        $managedProjects = $user->managedProjectIds();

        $projects = null;
        $clients = null;

        if ($seesAllProjects || $managedProjects !== []) {
            $projectModels = Project::query()
                ->when(! $seesAllProjects, fn (Builder $query) => $query->whereKey($managedProjects))
                ->with('client:id,name')
                ->orderByRaw('CASE WHEN status = ? THEN 1 ELSE 0 END', [ProjectStatus::Archived->value])
                ->orderBy('code')
                ->get(['id', 'code', 'name', 'color', 'client_id', 'status']);

            $projects = array_values($projectModels->map(fn (Project $project): array => [
                'id' => $project->id,
                'code' => $project->code,
                'name' => $project->name,
                'color' => $project->color,
                'client' => $project->client?->name,
                'archived' => $project->status === ProjectStatus::Archived,
            ])->all());

            $clients = array_values(Client::query()
                ->when(! $seesAllProjects, fn (Builder $query) => $query->whereKey($projectModels->pluck('client_id')->filter()->unique()->values()))
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->get(['id', 'name', 'is_active'])
                ->map(fn (Client $client): array => ['id' => $client->id, 'name' => $client->name, 'is_active' => $client->is_active])
                ->all());
        }

        $people = User::query()
            ->internal()
            ->when(! $isAdmin, fn (Builder $query) => $query->where(fn (Builder $scope) => $scope
                ->whereKey($user->id)
                ->when($managedDepartments !== [], fn (Builder $team) => $team->orWhereIn('department_id', $managedDepartments))))
            ->with('department:id,name')
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get(['id', 'name', 'department_id', 'is_active']);

        return Inertia::render('reports/index', [
            'me' => ['id' => $user->id, 'name' => $user->name],
            'direction' => Gate::forUser($user)->allows('viewDirectionReport', Department::class),
            'billing' => Gate::forUser($user)->allows('viewBilling', Client::class),
            'departments' => array_values(Department::query()
                ->when(! $isAdmin, fn (Builder $query) => $query->whereKey($managedDepartments))
                ->orderBy('name')
                ->get(['id', 'name', 'color'])
                ->map(fn (Department $department): array => ['id' => $department->id, 'name' => $department->name, 'color' => $department->color])
                ->all()),
            'clients' => $clients,
            'projects' => $projects,
            'people' => array_values($people->map(fn (User $person): array => [
                'id' => $person->id,
                'name' => $person->name,
                'department' => $person->department?->name,
                'is_active' => $person->is_active,
            ])->all()),
        ]);
    }
}
