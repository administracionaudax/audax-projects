<?php

namespace App\Domain\Portal\Access;

use App\Enums\Role;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;

/**
 * Sección «Portal del cliente» de los ajustes del proyecto (SPEC §11, D-064): quien gestiona el
 * proyecto decide si el cliente ve la vista del proyecto (tareas y estados), las horas totales por
 * tarea y el Gantt de solo lectura. Un proyecto sin cliente (interno) no se puede abrir.
 *
 * Contrato: resources/js/components/portal/access/types.ts (ProjectPortalSettings).
 */
final class ProjectPortalSettings
{
    /**
     * @return array{project_visible: bool, show_task_hours: bool, gantt_visible: bool, client: array{id: int, name: string, is_active: bool}|null, active_users: int}
     */
    public function for(Project $project): array
    {
        $client = $project->client_id === null ? null : Client::query()->find($project->client_id, ['id', 'name', 'is_active']);

        return [
            'project_visible' => $project->portal_project_visible,
            'show_task_hours' => $project->portal_show_task_hours,
            'gantt_visible' => $project->portal_gantt_visible,
            'client' => $client === null ? null : [
                'id' => $client->id,
                'name' => $client->name,
                'is_active' => $client->is_active,
            ],
            'active_users' => $client === null ? 0 : User::query()
                ->where('client_id', $client->id)
                ->where('is_active', true)
                ->role(Role::Client->value)
                ->count(),
        ];
    }
}
