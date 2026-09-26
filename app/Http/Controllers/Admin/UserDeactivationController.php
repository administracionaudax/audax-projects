<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Admin\DeactivationResult;
use App\Domain\Admin\UserDeactivator;
use App\Domain\Admin\UserGuard;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DeactivateUserRequest;
use App\Http\Resources\Admin\ResourceProps;
use App\Http\Resources\UserSummaryResource;
use App\Models\ActiveTimer;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\Duration;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Asistente de baja (SPEC §14): muestra las tareas abiertas asignadas, el temporizador activo y lo
 * que dirige (departamentos y proyectos como gestor principal, que se pueden traspasar, D-032) y,
 * al confirmar, desactiva con UserDeactivator. Reactivar vuelve a dar acceso (nunca se borra a
 * nadie).
 */
class UserDeactivationController extends Controller
{
    public function __construct(private readonly UserGuard $guard) {}

    public function create(Request $request, User $user): Response|RedirectResponse
    {
        Gate::authorize('manage-users');
        abort_if($user->isClient(), 404);

        /** @var User $actor */
        $actor = $request->user();
        $this->guard->assertCanManage($actor, $user);

        if (! $user->is_active) {
            return to_route('admin.users.edit', $user);
        }

        $blocked = null;
        try {
            $this->guard->assertCanDeactivate($actor, $user);
        } catch (ValidationException $exception) {
            $blocked = collect($exception->errors())->flatten()->first();
        }

        $tasks = Task::query()
            ->open()
            ->assignedTo($user)
            ->with(['project' => fn ($query) => $query->withTrashed()->select(['id', 'code', 'name', 'color'])])
            // Primero las que vencen antes; las que no tienen fecha, al final (en SQLite y PostgreSQL).
            ->orderByRaw('CASE WHEN due_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('due_date')
            ->orderBy('id')
            ->get(['id', 'title', 'project_id', 'due_date', 'assignee_user_id']);

        $timer = ActiveTimer::query()
            ->with(['task' => fn ($query) => $query->withTrashed()->select(['id', 'title', 'project_id'])])
            ->find($user->id);

        $candidates = User::query()
            ->active()
            ->internal()
            ->whereKeyNot($user->id)
            ->orderBy('name')
            ->get(['id', 'name', 'avatar_path', 'department_id', 'is_active']);

        $ownedProjects = Project::query()
            ->where('owner_user_id', $user->id)
            ->notArchived()
            ->orderBy('name')
            ->get(['id', 'code', 'name']);

        return Inertia::render('admin/users/deactivate', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
            'blocked' => $blocked,
            'tasks' => $tasks->map(fn (Task $task): array => [
                'id' => $task->id,
                'title' => $task->title,
                'due_date' => $task->due_date?->toDateString(),
                'project' => [
                    'id' => $task->project->id,
                    'code' => $task->project->code,
                    'name' => $task->project->name,
                    'color' => $task->project->color,
                ],
            ])->values()->all(),
            'timer' => $timer === null ? null : [
                'task_title' => $timer->task->title,
                'started_at' => $timer->started_at->toIso8601ZuluString(),
                'elapsed_minutes' => intdiv($timer->elapsedSeconds(), 60),
            ],
            'candidates' => ResourceProps::list(UserSummaryResource::collection($candidates), $request),
            'managedDepartments' => $user->managedDepartments()->orderBy('name')->pluck('name')->all(),
            'ownedProjects' => $ownedProjects->map(fn (Project $project): array => [
                'id' => $project->id,
                'code' => $project->code,
                'name' => $project->name,
            ])->values()->all(),
        ]);
    }

    public function store(DeactivateUserRequest $request, User $user, UserDeactivator $deactivator): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $result = $deactivator->deactivate($actor, $user, $request->assignments(), $request->defaultAssignee(), $request->owners());

        Inertia::flash('toast', [
            'type' => $result->timer === DeactivationResult::TIMER_DISCARDED ? 'warning' : 'success',
            'message' => $this->summary($user, $result),
        ]);

        return to_route('admin.users.edit', $user);
    }

    public function reactivate(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('manage-users');

        /** @var User $actor */
        $actor = $request->user();
        $this->guard->assertCanManage($actor, $user);

        if (! $user->is_active) {
            $user->is_active = true;

            // Si su departamento se borró mientras estaba de baja, queda sin departamento.
            if ($user->department_id !== null && $user->department()->doesntExist()) {
                $user->department_id = null;
            }

            $user->save();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('admin.users.reactivated', ['name' => $user->name])]);

        return back();
    }

    private function summary(User $user, DeactivationResult $result): string
    {
        $parts = [__('admin.users.deactivated', ['name' => $user->name])];

        if ($result->reassigned > 0) {
            $parts[] = trans_choice('admin.users.summary.reassigned', $result->reassigned, ['count' => $result->reassigned]);
        }

        if ($result->unassigned > 0) {
            $parts[] = trans_choice('admin.users.summary.unassigned', $result->unassigned, ['count' => $result->unassigned]);
        }

        $parts[] = match ($result->timer) {
            DeactivationResult::TIMER_STOPPED => __('admin.users.summary.timer_stopped', ['minutes' => Duration::format($result->timerMinutes)]),
            DeactivationResult::TIMER_DISCARDED => __('admin.users.summary.timer_discarded', ['reason' => implode(' ', $result->timerErrors)]),
            DeactivationResult::TIMER_TOO_SHORT => __('admin.users.summary.timer_too_short'),
            default => '',
        };

        if ($result->projectsTransferred > 0) {
            $parts[] = trans_choice('admin.users.summary.projects', $result->projectsTransferred, ['count' => $result->projectsTransferred]);
        }

        if ($result->departmentsLeft > 0) {
            $parts[] = trans_choice('admin.users.summary.departments', $result->departmentsLeft, ['count' => $result->departmentsLeft]);
        }

        return implode(' ', array_filter($parts, fn (string $part): bool => $part !== ''));
    }
}
