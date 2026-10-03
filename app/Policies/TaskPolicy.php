<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;

/**
 * Tareas (D-031): las crean, editan, mueven y borran los miembros del proyecto y quienes lo
 * gestionan; comenta cualquier interno. Una tarea con horas no se borra (D-037).
 * Un colaborador externo (D-134) solo ve y comenta las tareas de sus proyectos (canSeeProject).
 */
class TaskPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isInternal();
    }

    public function view(User $user, Task $task): bool
    {
        return $user->isInternal() && $user->canSeeProject($task->project_id);
    }

    public function create(User $user, Project $project): bool
    {
        return $project->acceptsTime()
            && ($user->isMemberOf($project) || $user->canManageProject($project));
    }

    public function update(User $user, Task $task): bool
    {
        $project = $task->project;

        return $user->isMemberOf($project) || $user->canManageProject($project);
    }

    public function delete(User $user, Task $task): bool
    {
        return $this->update($user, $task) && ! $task->timeEntries()->exists();
    }

    public function comment(User $user, Task $task): bool
    {
        return $user->isInternal() && $user->canSeeProject($task->project_id);
    }
}
