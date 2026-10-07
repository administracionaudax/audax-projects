<?php

namespace App\Domain\Projects;

use App\Http\Resources\Projects\ProjectListResource;
use App\Http\Resources\Projects\ResourceData;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Listado de proyectos jerarquizado por cliente (D-322), como las carpetas de ClickUp: cada
 * cliente con sus proyectos y, dentro de los de bolsas, sus bolsas abiertas (activas y agotadas).
 *
 * - Grupos: primero los proyectos internos sin cliente («Audax Studio (interno)», con el nombre de
 *   la empresa de los ajustes), después cada cliente por nombre y, al final, los que no son
 *   internos y no tienen cliente.
 * - Cada proyecto es la misma fila del listado plano (ProjectListResource) más `open_banks`: el
 *   consumo de cada bolsa abierta. Un colaborador externo no ve las bolsas (D-134): llega a null.
 * - Sin paginar: con ~160 proyectos cabe entero y la búsqueda ya filtra en el servidor.
 */
final class ProjectTree
{
    /** Tope de proyectos del árbol (por encima, la vista plana pagina). */
    public const int LIMIT = 1000;

    /**
     * @param  Collection<int, Project>  $projects  Con client, owner, los agregados open_banks_* y
     *                                              `hourBanks` cargados solo con las abiertas.
     * @return list<array{key: string, kind: 'internal'|'client'|'none', client: array{id: int, name: string}|null, projects: list<array<string, mixed>>}>
     */
    public function groups(Collection $projects, Request $request): array
    {
        $viewer = $request->user();
        $hidesBanks = $viewer instanceof User && $viewer->isCollaborator();
        $groups = [];

        foreach ($projects as $project) {
            [$key, $kind] = match (true) {
                $project->client_id !== null => ['client-'.$project->client_id, 'client'],
                $project->isInternal() => ['internal', 'internal'],
                default => ['none', 'none'],
            };

            $groups[$key] ??= [
                'key' => $key,
                'kind' => $kind,
                'client' => $kind === 'client' && $project->client !== null
                    ? ['id' => $project->client->id, 'name' => $project->client->name]
                    : null,
                'projects' => [],
            ];

            $row = ResourceData::of(ProjectListResource::make($project), $request);
            $row['open_banks'] = $project->usesHourBanks() && ! $hidesBanks
                ? array_values($project->hourBanks->map(fn (HourBank $bank): array => [
                    'id' => $bank->id,
                    'name' => $bank->name,
                    'status' => $bank->status->value,
                    'total_minutes' => (int) $bank->total_minutes,
                    'consumed_minutes' => (int) $bank->consumed_minutes,
                    'overage_minutes' => (int) $bank->overage_minutes,
                    'end_date' => $bank->end_date?->toDateString(),
                ])->all())
                : null;
            $groups[$key]['projects'][] = $row;
        }

        $rank = fn (array $group): string => match ($group['kind']) {
            'internal' => '0',
            'client' => '1'.Str::lower(Str::ascii($group['client']['name'] ?? '')),
            default => '2',
        };

        usort($groups, fn (array $a, array $b): int => strcmp($rank($a), $rank($b)));

        return $groups;
    }
}
