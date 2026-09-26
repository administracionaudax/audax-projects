<?php

namespace App\Domain\Time;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Para quién puede imputar horas una persona (SPEC §7, D-036): para sí misma y, además,
 * - un admin, para cualquier interno activo,
 * - un responsable, para las personas de sus departamentos,
 * - un gestor, para los miembros de sus proyectos (de ese proyecto, si se indica).
 * La última palabra la tienen TimeEntryPolicy::logTimeFor y TimeEntryRules al guardar.
 */
final class LoggablePeople
{
    /**
     * @return Collection<int, User> Con quien pregunta el primero.
     */
    public function for(User $actor, ?Project $project = null): Collection
    {
        $others = $this->othersQuery($actor, $project)?->orderBy('name')->get(['id', 'name', 'avatar_path', 'department_id', 'is_active'])
            ?? new Collection;

        return (new Collection([$actor]))->concat($others->all());
    }

    /**
     * ¿Puede $actor consultar o imputar horas de $target (en algún proyecto)?
     */
    public function canActFor(User $actor, User $target, ?Project $project = null): bool
    {
        if ($actor->id === $target->id) {
            return true;
        }

        return (bool) $this->othersQuery($actor, $project)?->whereKey($target->id)->exists();
    }

    /**
     * @return Builder<User>|null
     */
    private function othersQuery(User $actor, ?Project $project): ?Builder
    {
        $query = User::query()->active()->internal()->whereKeyNot($actor->id);

        if ($actor->isAdmin()) {
            return $query;
        }

        $departmentIds = $actor->managedDepartmentIds();
        $projectIds = $actor->managedProjectIds();

        if ($project !== null) {
            $projectIds = in_array($project->id, $projectIds, true) ? [$project->id] : [];
        }

        if ($departmentIds === [] && $projectIds === []) {
            return null;
        }

        return $query->where(function (Builder $scope) use ($departmentIds, $projectIds): void {
            if ($departmentIds !== []) {
                $scope->orWhereIn('department_id', $departmentIds);
            }

            if ($projectIds !== []) {
                $scope->orWhereHas('projects', fn (Builder $projects) => $projects->whereIn('projects.id', $projectIds));
            }
        });
    }
}
