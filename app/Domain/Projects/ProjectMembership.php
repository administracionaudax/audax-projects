<?php

namespace App\Domain\Projects;

use App\Domain\Access\CollaboratorOffboarding;
use App\Enums\ProjectAlert;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Miembros, gestores y alertas de un proyecto (SPEC §4.2, D-005, D-023, D-032):
 * - el gestor principal (owner) es siempre miembro gestor: no se le quita ni se le desmarca,
 * - al cambiar de gestor principal, el nuevo pasa a gestor y el anterior sigue como gestor,
 * - cada gestor tiene sus alertas (por defecto, todas activadas),
 * - un colaborador externo nunca es gestor (D-134); al salir, suelta las tareas del proyecto
 *   (CollaboratorOffboarding).
 * Los cambios de miembros quedan en la auditoría del proyecto (el pivote no la tiene propia).
 */
final class ProjectMembership
{
    public const string EVENT_MEMBER_ADDED = 'member_added';

    public const string EVENT_MEMBER_REMOVED = 'member_removed';

    public const string EVENT_MANAGER_ADDED = 'manager_added';

    public const string EVENT_MANAGER_REMOVED = 'manager_removed';

    public function __construct(private readonly CollaboratorOffboarding $offboarding) {}

    public function add(Project $project, User $member, bool $isManager, User $actor): void
    {
        if ($isManager) {
            $this->assertCanManage($member, 'is_manager');
        }

        if ($project->hasMember($member)) {
            throw ValidationException::withMessages([
                'user_id' => __('projects.errors.already_member', ['name' => $member->name]),
            ]);
        }

        DB::transaction(function () use ($project, $member, $isManager, $actor): void {
            $project->addMember($member, $isManager);
            $this->log($project, $actor, self::EVENT_MEMBER_ADDED, $member);

            if ($isManager) {
                $this->log($project, $actor, self::EVENT_MANAGER_ADDED, $member);
            }
        });
    }

    public function setManager(Project $project, User $member, bool $isManager, User $actor): void
    {
        $membership = $this->membership($project, $member);

        if ($isManager) {
            $this->assertCanManage($member, 'is_manager');
        }

        if (! $isManager && $member->id === $project->owner_user_id) {
            throw ValidationException::withMessages([
                'is_manager' => __('projects.errors.owner_always_manager'),
            ]);
        }

        if ($membership->is_manager === $isManager) {
            return;
        }

        DB::transaction(function () use ($project, $member, $isManager, $actor): void {
            $project->members()->updateExistingPivot($member->id, ['is_manager' => $isManager]);
            $this->log($project, $actor, $isManager ? self::EVENT_MANAGER_ADDED : self::EVENT_MANAGER_REMOVED, $member);
        });
    }

    public function remove(Project $project, User $member, User $actor): void
    {
        $this->membership($project, $member);

        if ($member->id === $project->owner_user_id) {
            throw ValidationException::withMessages([
                'user_id' => __('projects.errors.owner_cannot_be_removed'),
            ]);
        }

        DB::transaction(function () use ($project, $member, $actor): void {
            $project->members()->detach($member->id);
            $this->log($project, $actor, self::EVENT_MEMBER_REMOVED, $member);

            // Un colaborador externo deja de seguir sus tareas, de ser su responsable y de medir
            // tiempo en ellas (D-134).
            $this->offboarding->leftProject($member, $project->id);
        });
    }

    /**
     * Nuevo gestor principal: pasa a ser miembro gestor (conserva sus alertas si ya era miembro);
     * el anterior sigue como gestor. El cambio de owner_user_id queda en la auditoría.
     */
    public function changeOwner(Project $project, User $newOwner, User $actor): void
    {
        $this->assertCanManage($newOwner, 'owner_user_id');

        if ($newOwner->id === $project->owner_user_id) {
            throw ValidationException::withMessages([
                'owner_user_id' => __('projects.errors.already_owner', ['name' => $newOwner->name]),
            ]);
        }

        DB::transaction(function () use ($project, $newOwner, $actor): void {
            $previousOwnerId = $project->owner_user_id;
            $wasMember = $project->hasMember($newOwner);
            $wasManager = $wasMember && $project->isManagedBy($newOwner);

            $project->owner_user_id = $newOwner->id;
            $project->save();

            // addMember conserva las alertas de un miembro que ya existía.
            $project->addMember($newOwner, isManager: true);
            $project->addMember($previousOwnerId, isManager: true);

            if (! $wasMember) {
                $this->log($project, $actor, self::EVENT_MEMBER_ADDED, $newOwner);
            }

            if (! $wasManager) {
                $this->log($project, $actor, self::EVENT_MANAGER_ADDED, $newOwner);
            }
        });
    }

    /**
     * Un colaborador externo nunca es gestor de un proyecto (D-134).
     *
     * @throws ValidationException
     */
    private function assertCanManage(User $member, string $field): void
    {
        if ($member->isCollaborator()) {
            throw ValidationException::withMessages([$field => __('projects.errors.collaborator_cannot_manage')]);
        }
    }

    /**
     * Alertas de un gestor (D-023): se guardan solo las claves conocidas y el resto conserva su
     * valor (por defecto, activadas).
     *
     * @param  array<string, bool>  $preferences
     */
    public function updateAlerts(Project $project, User $manager, array $preferences): void
    {
        $membership = $this->membership($project, $manager);

        $merged = array_merge(ProjectAlert::defaults(), $membership->alert_preferences ?? []);

        foreach (ProjectAlert::cases() as $alert) {
            if (array_key_exists($alert->value, $preferences)) {
                $merged[$alert->value] = (bool) $preferences[$alert->value];
            }
        }

        $project->members()->updateExistingPivot($manager->id, [
            'alert_preferences' => array_intersect_key($merged, ProjectAlert::defaults()),
        ]);
    }

    private function membership(Project $project, User $member): ProjectMember
    {
        /** @var User|null $row */
        $row = $project->members()->whereKey($member->id)->first(['users.id']);

        if ($row === null || $row->membership === null) {
            throw ValidationException::withMessages([
                'user_id' => __('projects.errors.not_member', ['name' => $member->name]),
            ]);
        }

        return $row->membership;
    }

    private function log(Project $project, User $actor, string $event, User $member): void
    {
        activity($project->getTable())
            ->performedOn($project)
            ->causedBy($actor)
            ->event($event)
            ->withProperties(['user_id' => $member->id, 'user_name' => $member->name])
            ->log($event);
    }
}
