<?php

namespace App\Domain\DayPlan;

use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * El catálogo del selector de cliente y proyecto de una línea (`@cliente`, `#proyecto`,
 * docs/PLAN-CARGAS.md §4.2): los clientes activos y los proyectos no archivados que la persona puede
 * ver, primero los suyos (miembro). Va en la página como prop diferida: se filtra en el navegador,
 * sin una petición por tecla (la agencia tiene decenas de clientes y proyectos, no miles).
 */
final class DayPlanTargets
{
    /**
     * @return array{
     *     clients: list<array{id: int, name: string}>,
     *     projects: list<array{id: int, code: string, name: string, color: string, client_id: int|null, client_name: string|null, is_mine: bool, is_internal: bool}>
     * }
     */
    public function for(User $user): array
    {
        $mine = array_flip(DB::table('project_members')->where('user_id', $user->id)->pluck('project_id')->map(fn ($id): int => (int) $id)->all());

        $projects = array_values(Project::query()
            ->notArchived()
            ->visibleTo($user)
            ->with('client:id,name')
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'color', 'client_id', 'billing_type'])
            ->map(fn (Project $project): array => [
                'id' => $project->id,
                'code' => $project->code,
                'name' => $project->name,
                'color' => $project->color,
                'client_id' => $project->client_id,
                'client_name' => $project->client?->name,
                'is_mine' => isset($mine[$project->id]),
                'is_internal' => $project->isInternal(),
            ])
            ->sortBy(fn (array $project): string => ($project['is_mine'] ? '0' : '1').$project['code'])
            ->all());

        $clients = array_values(Client::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Client $client): array => ['id' => $client->id, 'name' => $client->name])
            ->all());

        return ['clients' => $clients, 'projects' => $projects];
    }
}
