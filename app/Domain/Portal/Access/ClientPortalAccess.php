<?php

namespace App\Domain\Portal\Access;

use App\Enums\PortalEntryVisibility;
use App\Enums\PortalPersonDisplay;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * Sección «Acceso al portal» de la ficha de cliente (D-063, D-064). Solo la reciben quienes pueden
 * gestionar el portal del cliente (ClientPolicy::managePortal) o cambiar sus ajustes
 * (ClientPolicy::update); al resto de internos se les manda null y ven solo la descripción.
 *
 * Contrato: resources/js/components/portal/access/types.ts (ClientPortalAccess).
 */
final class ClientPortalAccess
{
    public function __construct(private readonly PortalUsers $users) {}

    /**
     * @return array<string, mixed>|null
     */
    public function for(Client $client, ?User $viewer): ?array
    {
        if ($viewer === null) {
            return null;
        }

        $gate = Gate::forUser($viewer);
        $manageUsers = $gate->allows('managePortal', $client);
        $updateSettings = $gate->allows('update', $client);

        if (! $manageUsers && ! $updateSettings) {
            return null;
        }

        return [
            'can' => [
                'manageUsers' => $manageUsers,
                'updateSettings' => $updateSettings,
            ],
            'client_active' => $client->is_active,
            'users' => $this->users->list($client),
            'settings' => [
                'person_display' => $client->portal_person_display->value,
                'entry_visibility' => $client->portal_entry_visibility->value,
                'notify_thresholds' => $client->portal_notify_thresholds,
            ],
            'options' => [
                'person_display' => PortalPersonDisplay::values(),
                'entry_visibility' => PortalEntryVisibility::values(),
            ],
            'projects' => $this->openProjects($client),
        ];
    }

    /**
     * Proyectos del cliente abiertos al portal (vista del proyecto o Gantt), para el resumen.
     *
     * @return list<array{id: int, code: string, name: string, project_visible: bool, show_task_hours: bool, gantt_visible: bool}>
     */
    private function openProjects(Client $client): array
    {
        $projects = Project::query()
            ->where('client_id', $client->id)
            ->where(fn (Builder $open) => $open->where('portal_project_visible', true)->orWhere('portal_gantt_visible', true))
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'code', 'name', 'portal_project_visible', 'portal_show_task_hours', 'portal_gantt_visible']);

        $rows = [];
        foreach ($projects as $project) {
            $rows[] = [
                'id' => $project->id,
                'code' => $project->code,
                'name' => $project->name,
                'project_visible' => $project->portal_project_visible,
                'show_task_hours' => $project->portal_project_visible && $project->portal_show_task_hours,
                'gantt_visible' => $project->portal_gantt_visible,
            ];
        }

        return $rows;
    }
}
