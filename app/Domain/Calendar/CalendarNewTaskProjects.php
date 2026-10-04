<?php

namespace App\Domain\Calendar;

use App\Domain\Gantt\GanttAccess;
use App\Http\Resources\Tasks\Plain;
use App\Http\Resources\Tasks\TaskBankOptionResource;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\User;

/**
 * Proyectos en los que quien mira puede crear una tarea desde el calendario del equipo (D-144):
 * los que no están archivados y en los que TaskPolicy::create lo deja (miembro o quien lo
 * gestiona; GanttAccess calcula esa regla para muchos proyectos a la vez), con sus bolsas abiertas
 * si son de bolsas (primero las del departamento de quien crea, SPEC §8.3). Para el diálogo
 * «Nueva tarea» del Gantt (NewTaskDialog), que se pide al abrirlo (prop opcional `creatable`).
 */
final class CalendarNewTaskProjects
{
    public function __construct(private readonly GanttAccess $access) {}

    /**
     * @return list<array{id: int, code: string, name: string, uses_hour_banks: bool, banks: list<array<array-key, mixed>>}>
     */
    public function for(User $viewer): array
    {
        $projects = Project::query()
            ->notArchived()
            ->visibleTo($viewer)
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'billing_type', 'status']);

        $editable = $this->access->editable($viewer, array_values(array_map(intval(...), $projects->modelKeys())));
        $projects = $projects->filter(fn (Project $project): bool => ($editable[$project->id] ?? false) && $project->acceptsTime());

        $bankProjects = $projects->filter(fn (Project $project): bool => $project->usesHourBanks())->modelKeys();
        $banks = $bankProjects === [] ? collect() : HourBank::query()
            ->open()
            ->whereIn('project_id', $bankProjects)
            ->with('department:id,name,color')
            ->orderBy('start_date')
            ->orderBy('id')
            ->get()
            ->sortBy(fn (HourBank $bank): int => $bank->department_id !== null && $bank->department_id === $viewer->department_id ? 0 : 1)
            ->groupBy('project_id');

        return array_values($projects->map(fn (Project $project): array => [
            'id' => $project->id,
            'code' => $project->code,
            'name' => $project->name,
            'uses_hour_banks' => $project->usesHourBanks(),
            'banks' => array_values(array_map(
                fn (HourBank $bank): array => Plain::of(TaskBankOptionResource::make($bank)),
                ($banks->get($project->id) ?? collect())->values()->all(),
            )),
        ])->all());
    }
}
