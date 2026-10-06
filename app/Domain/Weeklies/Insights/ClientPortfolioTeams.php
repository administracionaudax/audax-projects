<?php

namespace App\Domain\Weeklies\Insights;

use App\Domain\Weeklies\ProjectStatus\ProjectStatusBoard;
use App\Domain\Weeklies\WeeklyClientSubscriptions;
use App\Enums\ProjectStatus;
use App\Http\Resources\UserSummaryResource;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * El responsable y el equipo de cada cliente en la cartera (/clientes, 10.9b y D-232; las columnas
 * «Responsable» y «Equipo» de `ws:ClientView.tsx:433-488`): el responsable con ClientInsights::ownerOf()
 * y el equipo con las mismas reglas que la pestaña Equipo (quien gestiona o es miembro de sus
 * proyectos abiertos y quien se ha unido en la Weekly, activos y de plantilla), hasta MAX avatares.
 * Tres consultas para la página entera, sin importar cuántos clientes tenga.
 */
final class ClientPortfolioTeams
{
    /** Avatares por fila; el resto, «+N». */
    public const int MAX = 3;

    /**
     * @param  iterable<Client>  $clients  con owner_user_id
     * @return array<int, array{owner: array<string, mixed>|null, team: list<array<string, mixed>>, team_count: int}>
     */
    public function for(iterable $clients): array
    {
        $byId = [];
        foreach ($clients as $client) {
            $byId[$client->id] = $client;
        }

        if ($byId === []) {
            return [];
        }

        $ids = array_keys($byId);
        // Proyectos no archivados con sus miembros, en una consulta (una fila por miembro).
        $rows = DB::table('projects')
            ->leftJoin('project_members', 'project_members.project_id', '=', 'projects.id')
            ->whereIn('projects.client_id', $ids)
            ->whereNull('projects.deleted_at')
            ->where('projects.status', '!=', ProjectStatus::Archived->value)
            ->get(['projects.id', 'projects.client_id', 'projects.owner_user_id', 'projects.status', 'project_members.user_id as member_id']);

        /** @var array<int, Project> $projectsById */
        $projectsById = [];
        /** @var array<int, list<int>> $people cliente => personas */
        $people = [];
        $openStatuses = array_map(fn (ProjectStatus $status): string => $status->value, ProjectStatusBoard::OPEN_STATUSES);

        foreach ($rows as $row) {
            $clientId = (int) $row->client_id;

            if (! isset($projectsById[(int) $row->id])) {
                $projectsById[(int) $row->id] = (new Project)->forceFill([
                    'id' => (int) $row->id,
                    'client_id' => $clientId,
                    'owner_user_id' => $row->owner_user_id === null ? null : (int) $row->owner_user_id,
                    'status' => $row->status,
                ]);
            }

            if (in_array($row->status, $openStatuses, true)) {
                if ($row->owner_user_id !== null) {
                    $people[$clientId][] = (int) $row->owner_user_id;
                }

                if ($row->member_id !== null) {
                    $people[$clientId][] = (int) $row->member_id;
                }
            }
        }

        $projects = collect(array_values($projectsById));

        foreach (DB::table(WeeklyClientSubscriptions::TABLE)->whereIn('client_id', $ids)->get(['client_id', 'user_id']) as $row) {
            $people[(int) $row->client_id][] = (int) $row->user_id;
        }

        $explicit = array_filter(array_map(fn (Client $client): ?int => $client->owner_user_id, $byId));
        $userIds = array_values(array_unique([...array_merge(...array_values($people ?: [[]])), ...$projects->pluck('owner_user_id')->all(), ...array_values($explicit)]));
        $users = User::query()
            ->whereKey($userIds)
            ->where('is_active', true)
            ->whereHas('roles', fn ($roles) => $roles->whereIn('name', User::WEEKLY_ROLES))
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->keyBy('id');

        $result = [];
        foreach ($byId as $clientId => $client) {
            $own = $projects->where('client_id', $clientId)->each(fn (Project $project) => $project->setRelation('owner', $users->get($project->owner_user_id)));
            $client->setRelation('owner', $client->owner_user_id !== null ? $users->get($client->owner_user_id) : null);
            $owner = ClientInsights::ownerOf($client, $own);
            $team = $users->only(array_values(array_unique([...($people[$clientId] ?? []), ...($owner !== null ? [$owner->id] : [])])))
                ->sortBy(fn (User $user): array => [$user->id === $owner?->id ? 0 : 1, mb_strtolower($user->name)])
                ->values();

            $result[$clientId] = [
                'owner' => $owner === null ? null : (new UserSummaryResource($owner))->resolve(),
                'team' => $team->take(self::MAX)->map(fn (User $user): array => (new UserSummaryResource($user))->resolve())->all(),
                'team_count' => $team->count(),
            ];
        }

        return $result;
    }
}
