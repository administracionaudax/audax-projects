<?php

namespace App\Http\Controllers\Tasks;

use App\Domain\Tasks\MyTaskSections;
use App\Http\Controllers\Controller;
use App\Http\Resources\Tasks\MyTaskItemResource;
use App\Http\Resources\Tasks\Plain;
use App\Http\Resources\TaskStatusResource;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Support\LocalTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Mis tareas (SPEC §6, D-037): tareas ABIERTAS asignadas a mí (también subtareas) de proyectos no
 * archivados, en las secciones Vencidas, Hoy, Esta semana, Próximas y Sin fecha, con «hoy» en
 * Europe/Madrid. Dentro de cada sección: por vencimiento, prioridad y título.
 */
class MyTasksController extends Controller
{
    public function __construct(private readonly MyTaskSections $sections) {}

    public function __invoke(Request $request): Response
    {
        Gate::authorize('viewAny', Task::class);

        /** @var User $user */
        $user = $request->user();
        $today = LocalTime::today();

        $tasks = Task::query()
            ->select(ProjectTasksController::LIST_COLUMNS)
            ->open()
            ->assignedTo($user)
            ->whereHas('project', fn (Builder $project) => $project->notArchived())
            ->with([
                'project:id,code,name,color',
                'hourBank:id,name',
                'parent:id,title',
            ])
            ->withSum('timeEntries', 'minutes')
            ->get();

        $grouped = array_fill_keys(MyTaskSections::ORDER, []);

        $sorted = $tasks->sortBy([
            fn (Task $a, Task $b): int => ($a->due_date?->toDateString() ?? '9999-12-31') <=> ($b->due_date?->toDateString() ?? '9999-12-31'),
            fn (Task $a, Task $b): int => ($a->start_date?->toDateString() ?? '9999-12-31') <=> ($b->start_date?->toDateString() ?? '9999-12-31'),
            fn (Task $a, Task $b): int => $a->priority->weight() <=> $b->priority->weight(),
            fn (Task $a, Task $b): int => strcasecmp($a->title, $b->title),
        ]);

        foreach ($sorted as $task) {
            $grouped[$this->sections->sectionOf($task, $today)][] = Plain::of(MyTaskItemResource::make($task));
        }

        return Inertia::render('my-tasks/index', [
            'today' => $today->toDateString(),
            'sections' => array_map(fn (string $key): array => [
                'key' => $key,
                'tasks' => $grouped[$key],
            ], MyTaskSections::ORDER),
            'statuses' => Plain::of(TaskStatusResource::collection(TaskStatus::query()->ordered()->get())),
        ]);
    }
}
