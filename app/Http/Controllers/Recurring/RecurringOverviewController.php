<?php

namespace App\Http\Controllers\Recurring;

use App\Domain\Recurring\RecurringRuleItems;
use App\Http\Controllers\Controller;
use App\Http\Resources\Projects\Paginated;
use App\Models\Project;
use App\Models\RecurringTaskRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Vista global de las tareas recurrentes en /admin/tareas-recurrentes (SPEC §14, D-059): todas las
 * reglas con su proyecto, frase, próxima fecha, responsable y avisos, con filtros por estado y
 * proyecto. Se gestionan desde los Ajustes de cada proyecto (enlace en cada fila). Solo un admin.
 */
class RecurringOverviewController extends Controller
{
    public const int PER_PAGE = 50;

    public const array STATUSES = ['activas', 'inactivas', 'todas'];

    public function __invoke(Request $request, RecurringRuleItems $items): Response
    {
        Gate::authorize('viewAny', RecurringTaskRule::class);

        $filters = $request->validate([
            'estado' => ['nullable', 'string', Rule::in(self::STATUSES)],
            'proyecto' => ['nullable', 'integer'],
        ]);

        $status = $filters['estado'] ?? 'activas';
        $projectId = isset($filters['proyecto']) ? (int) $filters['proyecto'] : null;

        $paginator = RecurringTaskRule::query()
            ->when($status === 'activas', fn (Builder $query) => $query->where('recurring_task_rules.is_active', true))
            ->when($status === 'inactivas', fn (Builder $query) => $query->where('recurring_task_rules.is_active', false))
            ->when($projectId !== null, fn (Builder $query) => $query->where('recurring_task_rules.project_id', $projectId))
            ->join('projects', 'projects.id', '=', 'recurring_task_rules.project_id')
            ->whereNull('projects.deleted_at')
            ->orderBy('projects.name')
            ->orderBy('recurring_task_rules.title')
            ->orderBy('recurring_task_rules.id')
            ->select('recurring_task_rules.*')
            ->paginate(self::PER_PAGE, pageName: 'pagina')
            ->withQueryString();

        /** @var list<RecurringTaskRule> $rules */
        $rules = $paginator->items();

        return Inertia::render('admin/recurring/index', [
            'rules' => Paginated::props($paginator, $items->build($rules)),
            'filters' => ['estado' => $status, 'proyecto' => $projectId],
            // Proyectos que tienen alguna regla, para el filtro.
            'projects' => Project::query()
                ->whereIn('id', RecurringTaskRule::query()->select('project_id'))
                ->orderBy('name')
                ->get(['id', 'name', 'code'])
                ->map(fn (Project $project): array => ['id' => $project->id, 'name' => $project->name, 'code' => $project->code])
                ->values()
                ->all(),
        ]);
    }
}
