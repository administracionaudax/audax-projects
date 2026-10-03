<?php

namespace App\Http\Controllers\Gantt;

use App\Domain\Gantt\GanttData;
use App\Domain\Gantt\GanttPreferences;
use App\Domain\Tasks\TaskOptions;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Projects\ProjectController;
use App\Http\Resources\ProjectResource;
use App\Http\Resources\Projects\ResourceData;
use App\Http\Resources\Tasks\TaskBankOptionResource;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\LocalTime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pestaña Gantt del proyecto (/proyectos/{project}/gantt, SPEC §6.1, D-060).
 * - La ve cualquier interno (D-021); los clientes no llegan (middleware internal → /portal).
 * - Mueve, redimensiona y enlaza quien puede editar las tareas (TaskPolicy::update); el resto,
 *   en solo lectura. Las fechas se cambian con las rutas schedule.reschedule.* (D-057) y las
 *   dependencias con schedule.dependencies.* (D-056); las tareas se crean con tasks.store.
 * - Sin datos económicos: el proyecto va sin tarifa ni precio.
 * - tasks, dependencies y range se recargan por separado (recarga parcial de Inertia).
 */
class ProjectGanttController extends Controller
{
    public function __construct(
        private readonly GanttData $gantt,
        private readonly TaskOptions $options,
    ) {}

    public function __invoke(Request $request, Project $project): Response
    {
        Gate::authorize('view', $project);
        Gate::authorize('viewAny', Task::class);

        /** @var User $user */
        $user = $request->user();

        $project->loadMissing([
            'client:id,name',
            'owner' => fn ($owner) => $owner->select(ProjectController::USER_SUMMARY_COLUMNS),
        ]);

        $probe = (new Task(['project_id' => $project->id]))->setRelation('project', $project);
        $canUpdate = Gate::allows('update', $probe);
        $canCreate = Gate::allows('create', [Task::class, $project]);
        $today = LocalTime::today();

        // tasks y range salen de la misma consulta: se calcula una vez aunque se pidan las dos.
        $tasks = null;
        $loadTasks = function () use (&$tasks, $project, $canUpdate, $user): array {
            return $tasks ??= $this->gantt->tasks([$project->id], [$project->id => $canUpdate], withLogged: ! $user->isCollaborator());
        };

        $projectData = ResourceData::of(ProjectResource::make($project), $request);
        unset($projectData['fixed_price_amount'], $projectData['hourly_rate']);

        return Inertia::render('projects/gantt', [
            'project' => $projectData,
            'canManage' => $user->canManageProject($project),
            'can' => ['create' => $canCreate, 'update' => $canUpdate],
            'preferences' => GanttPreferences::fromRequest($request),
            'today' => $today->toDateString(),
            'tasks' => fn (): array => $loadTasks(),
            'dependencies' => fn (): array => $this->gantt->dependencies([$project->id]),
            'range' => fn (): array => $this->gantt->range($loadTasks(), [$project->start_date, $project->due_date], $today),
            'statuses' => fn (): array => $this->gantt->statuses(),
            'banks' => fn (): array => $canCreate && $project->usesHourBanks() ? $this->openBanks($request, $project, $user) : [],
            'currentUser' => ['id' => $user->id, 'department_id' => $user->department_id],
        ]);
    }

    /**
     * Bolsas abiertas del proyecto para crear tareas (primero las del departamento del usuario).
     *
     * @return list<array<array-key, mixed>>
     */
    private function openBanks(Request $request, Project $project, User $user): array
    {
        $banks = [];
        foreach ($this->options->banks($project, $user) as $bank) {
            /** @var HourBank $bank */
            if ($bank->acceptsTime()) {
                $banks[] = ResourceData::of(TaskBankOptionResource::make($bank), $request);
            }
        }

        return $banks;
    }
}
