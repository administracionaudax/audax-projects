<?php

namespace App\Domain\Projects;

use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Alta de un proyecto (SPEC §6, D-022, D-032). El gestor principal (por defecto, quien lo crea)
 * es siempre miembro gestor; los miembros iniciales entran como miembros sin gestión. Sin código,
 * se genera uno único a partir del cliente y del nombre.
 */
final class ProjectCreator
{
    public function __construct(private readonly ProjectCodeSuggester $codes) {}

    /**
     * @param  array<string, mixed>  $attributes  datos validados (StoreProjectRequest)
     * @param  list<int>  $memberIds
     */
    public function create(array $attributes, array $memberIds, User $creator): Project
    {
        return DB::transaction(function () use ($attributes, $memberIds, $creator): Project {
            $attributes['owner_user_id'] = (int) ($attributes['owner_user_id'] ?? $creator->id);

            if (($attributes['code'] ?? '') === '' || $attributes['code'] === null) {
                $clientName = isset($attributes['client_id'])
                    ? Client::query()->whereKey($attributes['client_id'])->value('name')
                    : null;

                $attributes['code'] = $this->codes->unique(
                    is_string($clientName) ? $clientName : null,
                    (string) $attributes['name'],
                );
            }

            $project = Project::query()->create($attributes);
            $project->addMember($project->owner_user_id, isManager: true);

            foreach (array_unique($memberIds) as $memberId) {
                if ($memberId !== $project->owner_user_id) {
                    $project->addMember($memberId);
                }
            }

            return $project;
        });
    }
}
