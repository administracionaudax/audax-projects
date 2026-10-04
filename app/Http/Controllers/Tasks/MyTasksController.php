<?php

namespace App\Http\Controllers\Tasks;

use App\Domain\Tasks\MyTaskFilters;
use App\Domain\Tasks\MyTaskList;
use App\Domain\Tasks\TaskOptions;
use App\Enums\TaskPriority;
use App\Http\Controllers\Controller;
use App\Http\Resources\Tasks\Plain;
use App\Http\Resources\Tasks\TaskTypeOptionResource;
use App\Http\Resources\TaskStatusResource;
use App\Models\Task;
use App\Models\User;
use App\Support\LocalTime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Mis tareas (SPEC §6, D-037 y D-143): las tareas asignadas a mí y aquellas en las que he imputado
 * en los últimos 30 días, por defecto ordenadas por «Imputadas recientemente», con filtros y orden
 * en la URL (App\Domain\Tasks\MyTaskFilters) y paginación por cursor de 50 en 50
 * (App\Domain\Tasks\MyTaskList). «Vencimiento» conserva las secciones Vencidas, Hoy, Esta semana,
 * Próximas y Sin fecha (cada tarea trae la suya), con «hoy» en Europe/Madrid. Un colaborador
 * externo, solo las de sus proyectos (D-134).
 *
 * «Cargar más» pide solo `tasks`, `cursor` y `next_cursor` con ?cursor=.
 */
class MyTasksController extends Controller
{
    public function __construct(
        private readonly MyTaskList $list,
        private readonly TaskOptions $options,
    ) {}

    public function __invoke(Request $request): Response
    {
        Gate::authorize('viewAny', Task::class);

        /** @var User $user */
        $user = $request->user();
        $filters = MyTaskFilters::fromRequest($request);
        $statuses = $this->options->statuses();
        $cursor = is_string($request->query('cursor')) ? $request->query('cursor') : null;

        // Una sola consulta de la página aunque se pidan sus tres props.
        $page = null;
        $load = function () use (&$page, $user, $filters, $statuses, $cursor): array {
            return $page ??= $this->list->page($user, $filters, $statuses, $cursor);
        };

        return Inertia::render('my-tasks/index', [
            'today' => LocalTime::todayString(),
            'filters' => $filters->toArray(),
            'tasks' => fn (): array => $load()['tasks'],
            'cursor' => fn (): ?string => $load()['cursor'],
            'next_cursor' => fn (): ?string => $load()['next_cursor'],
            'statuses' => fn (): array => Plain::of(TaskStatusResource::collection($statuses)),
            'options' => fn (): array => [
                ...$this->list->options($user),
                'types' => Plain::of(TaskTypeOptionResource::collection($this->options->types())),
                'priorities' => TaskPriority::values(),
            ],
        ]);
    }
}
