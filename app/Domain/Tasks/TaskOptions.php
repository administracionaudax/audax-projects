<?php

namespace App\Domain\Tasks;

use App\Enums\Role;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\TaskStatus;
use App\Models\TaskType;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Opciones de los selectores de tareas (estados, tipos, bolsas y personas), con el orden que
 * pide el SPEC:
 * - bolsas (SPEC §8.3): primero las abiertas del departamento del usuario, luego las demás
 *   abiertas (sin departamento o de otros) y al final las cerradas o renovadas (solo para leer),
 * - responsables: internos activos, primero los miembros del proyecto. Un colaborador externo
 *   (D-134) solo aparece en los proyectos de los que es miembro y, si es quien mira, solo ve a
 *   los miembros del proyecto.
 */
final class TaskOptions
{
    /**
     * @return Collection<int, TaskStatus>
     */
    public function statuses(): Collection
    {
        return TaskStatus::query()->ordered()->get();
    }

    /**
     * Tipos activos más los que ya usan tareas de la lista (aunque estén desactivados o borrados).
     *
     * @param  list<int>  $usedIds
     * @return Collection<int, TaskType>
     */
    public function types(array $usedIds = []): Collection
    {
        return TaskType::query()
            ->withTrashed()
            ->where(fn ($query) => $query->where(fn ($active) => $active->where('is_active', true)->whereNull('deleted_at'))
                ->orWhereIn('id', $usedIds))
            ->ordered()
            ->get();
    }

    /**
     * @return Collection<int, HourBank>
     */
    public function banks(Project $project, User $user): Collection
    {
        $banks = HourBank::query()
            ->where('project_id', $project->id)
            ->with('department')
            ->orderBy('start_date')
            ->orderBy('id')
            ->get();

        return $banks->sortBy(fn (HourBank $bank): string => sprintf(
            '%d-%d-%s-%010d',
            $bank->acceptsTime() ? 0 : 1,
            $bank->department_id !== null && $bank->department_id === $user->department_id ? 0 : 1,
            $bank->start_date->toDateString(),
            $bank->id,
        ))->values();
    }

    /**
     * Internos activos, primero los miembros del proyecto (con is_member en `membership`).
     *
     * @return array{users: Collection<int, User>, memberIds: list<int>}
     */
    public function assignableUsers(Project $project, ?User $viewer = null): array
    {
        $memberIds = array_values($project->members()->pluck('users.id')->map(fn ($id): int => (int) $id)->all());

        $users = User::query()
            ->active()
            ->internal()
            ->when(
                $viewer?->isCollaborator() ?? false,
                fn ($query) => $query->whereKey($memberIds),
                fn ($query) => $query->where(fn ($scope) => $scope->whereKey($memberIds)->orWhereDoesntHave('roles', fn ($roles) => $roles->where('name', Role::Collaborator->value))),
            )
            ->orderBy('name')
            ->get(['id', 'name', 'avatar_path', 'department_id', 'is_active']);

        $sorted = $users->sortBy(fn (User $user): string => (in_array($user->id, $memberIds, true) ? '0' : '1').mb_strtolower($user->name))->values();

        return ['users' => $sorted, 'memberIds' => $memberIds];
    }
}
