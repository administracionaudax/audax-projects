<?php

namespace App\Http\Controllers\Time;

use App\Domain\Time\LoggablePeople;
use App\Domain\Time\TimesheetService;
use App\Enums\BillingType;
use App\Http\Resources\Time\LoggableTaskResource;
use App\Models\Task;
use App\Models\User;
use App\Support\LocalTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * GET /horas/tareas?q=&user_id= → {"tasks": LoggableTask[]}: buscador de tareas donde la persona
 * puede imputar (miembro del proyecto o proyecto interno, D-033), sin hitos ni proyectos
 * archivados. Sin texto, propone sus tareas abiertas, las que ha imputado hace poco y las del
 * proyecto interno. Si se imputa por otra persona, solo en los proyectos donde quien busca puede
 * hacerlo (LoggablePeople). Un colaborador externo (D-134) solo en las tareas de sus proyectos y
 * nunca en el proyecto interno.
 */
class LoggableTaskController extends TimeController
{
    public const int LIMIT = 30;

    public function __construct(
        private readonly TimesheetService $sheets,
        private readonly LoggablePeople $people,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'user_id' => ['nullable', 'integer'],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        $target = $actor;

        if ($request->filled('user_id') && $request->integer('user_id') !== $actor->id) {
            /** @var User $target */
            $target = User::query()->internal()->findOrFail($request->integer('user_id'));
            abort_unless($this->people->canActFor($actor, $target), 403);
        }

        $restricted = $target->isCollaborator();
        $query = $this->sheets->loggableTasks(Task::query())
            ->where(function (Builder $where) use ($target, $restricted): void {
                $where->whereIn('project_id', DB::table('project_members')->select('project_id')->where('user_id', $target->id));

                if (! $restricted) {
                    $where->orWhereHas('project', fn (Builder $project) => $project->where('billing_type', BillingType::Internal->value));
                }
            })
            ->when($restricted, fn (Builder $tasks) => $tasks->whereHas('project', fn (Builder $project) => $project->where('billing_type', '!=', BillingType::Internal->value)));

        // Por otra persona: un gestor (que no es admin ni su responsable), solo en sus proyectos.
        if ($target->id !== $actor->id && ! $actor->isAdmin() && ! $actor->supervises($target)) {
            $query->whereIn('project_id', $actor->managedProjectIds());
        }

        $search = trim($request->string('q')->toString());

        $tasks = $search === ''
            ? $this->suggestions($query, $target, $restricted)
            : $this->search($query, $search);

        return response()->json(['tasks' => LoggableTaskResource::collection($tasks)->resolve($request)]);
    }

    /**
     * @param  Builder<Task>  $query
     * @return Collection<int, Task>
     */
    private function search(Builder $query, string $search): Collection
    {
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], Str::lower($search)).'%';

        return $query
            ->where(function (Builder $where) use ($like): void {
                $where->whereRaw("LOWER(title) LIKE ? ESCAPE '\\'", [$like])
                    ->orWhereHas('project', fn (Builder $project) => $project
                        ->whereRaw("LOWER(code) LIKE ? ESCAPE '\\'", [$like])
                        ->orWhereRaw("LOWER(name) LIKE ? ESCAPE '\\'", [$like]));
            })
            ->orderByRaw('CASE WHEN completed_at IS NULL THEN 0 ELSE 1 END')
            ->orderBy('title')
            ->limit(self::LIMIT)
            ->get();
    }

    /**
     * Sus tareas abiertas, las imputadas en los últimos 14 días y las del proyecto interno.
     *
     * @param  Builder<Task>  $query
     * @return Collection<int, Task>
     */
    private function suggestions(Builder $query, User $target, bool $restricted = false): Collection
    {
        $since = LocalTime::today()->subDays(14)->toDateString();

        return $query
            ->where(function (Builder $where) use ($target, $since, $restricted): void {
                $where->where(fn (Builder $mine) => $mine->whereNull('completed_at')->where('assignee_user_id', $target->id))
                    ->orWhereIn('id', DB::table('time_entries')->select('task_id')->where('user_id', $target->id)->where('date', '>=', $since));

                if (! $restricted) {
                    $where->orWhereHas('project', fn (Builder $project) => $project->where('billing_type', BillingType::Internal->value));
                }
            })
            ->orderByRaw('CASE WHEN completed_at IS NULL THEN 0 ELSE 1 END')
            ->orderBy('project_id')
            ->orderBy('title')
            ->limit(self::LIMIT)
            ->get();
    }
}
