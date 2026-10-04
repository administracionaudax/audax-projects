<?php

namespace App\Http\Resources\Tasks;

use App\Domain\Tasks\AttachmentStorage;
use App\Domain\Tasks\TaskOptions;
use App\Http\Resources\ProjectResource;
use App\Http\Resources\TaskStatusResource;
use App\Http\Resources\UserSummaryResource;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Lo que el panel de una tarea necesita de su proyecto (estados, tipos, bolsas, personas y
 * permisos: resources/js/components/tasks/task-lookups.tsx), para la pestaña Tareas del proyecto y
 * para abrir el panel fuera de ella (el calendario del equipo, D-144). También los proyectos a los
 * que se puede mover una tarea (prop `moveTargets`).
 */
final class TaskPanelContext
{
    public function __construct(private readonly TaskOptions $options) {}

    /**
     * Las props de la pestaña Tareas que forman TaskLookups, para el proyecto de una tarea.
     *
     * @return array<string, mixed>
     */
    public function lookups(Project $project, User $viewer): array
    {
        $project->loadMissing(['client', 'owner']);
        $probe = (new Task(['project_id' => $project->id]))->setRelation('project', $project);

        return [
            'project' => Plain::of(ProjectResource::make($project)),
            'canManage' => $viewer->canManageProject($project),
            'can' => [
                'create' => Gate::forUser($viewer)->allows('create', [Task::class, $project]),
                'update' => Gate::forUser($viewer)->allows('update', $probe),
            ],
            'statuses' => Plain::of(TaskStatusResource::collection($this->options->statuses())),
            'types' => Plain::of(TaskTypeOptionResource::collection($this->options->types($this->usedTypeIds($project)))),
            'banks' => Plain::of(TaskBankOptionResource::collection($this->options->banks($project, $viewer))),
            'users' => $this->users($project, $viewer),
            'currentUser' => ['id' => $viewer->id, 'department_id' => $viewer->department_id],
            'maxAttachmentMb' => AttachmentStorage::maxMegabytes(),
        ];
    }

    /**
     * Internos activos (primero los miembros) con is_member.
     *
     * @return list<array<string, mixed>>
     */
    public function users(Project $project, User $viewer): array
    {
        ['users' => $users, 'memberIds' => $memberIds] = $this->options->assignableUsers($project, $viewer);

        return array_values($users->map(fn (User $user): array => [
            ...Plain::of(UserSummaryResource::make($user)),
            'is_member' => in_array($user->id, $memberIds, true),
        ])->all());
    }

    /**
     * Tipos que ya usan las tareas del proyecto (aunque estén desactivados o borrados).
     *
     * @return list<int>
     */
    public function usedTypeIds(Project $project): array
    {
        return array_values(Task::query()
            ->where('project_id', $project->id)
            ->whereNotNull('task_type_id')
            ->distinct()
            ->pluck('task_type_id')
            ->map(fn ($id): int => (int) $id)
            ->all());
    }

    /**
     * Proyectos no archivados donde puede crear tareas (TaskPolicy::create), con sus bolsas abiertas.
     *
     * @return list<array<string, mixed>>
     */
    public function moveTargets(Project $current, User $user): array
    {
        $query = Project::query()
            ->notArchived()
            ->whereKeyNot($current->id)
            ->with(['hourBanks' => fn ($banks) => $banks->open()->with('department')->orderBy('start_date')->orderBy('id')])
            ->orderBy('code');

        if (! $user->isAdmin() && ! $user->isDepartmentManager()) {
            $query->withMember($user);
        }

        return array_values($query->get()->map(fn (Project $project): array => [
            'id' => $project->id,
            'code' => $project->code,
            'name' => $project->name,
            'uses_hour_banks' => $project->usesHourBanks(),
            'banks' => Plain::of(TaskBankOptionResource::collection(
                $project->hourBanks->sortBy(fn (HourBank $bank): int => $bank->department_id !== null && $bank->department_id === $user->department_id ? 0 : 1)->values()
            )),
        ])->all());
    }
}
