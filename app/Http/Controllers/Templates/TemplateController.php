<?php

namespace App\Http\Controllers\Templates;

use App\Domain\Admin\TextSearch;
use App\Domain\Templates\ProjectTemplateService;
use App\Domain\Templates\TemplateItems;
use App\Enums\TaskPriority;
use App\Http\Controllers\Controller;
use App\Http\Requests\Templates\TemplateRequest;
use App\Http\Requests\Templates\TemplateStructure;
use App\Http\Resources\Projects\Paginated;
use App\Models\ProjectTemplate;
use App\Models\TaskType;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Plantillas de proyecto en /admin/plantillas (SPEC §14, D-058): listado con filtros (activas,
 * inactivas, papelera y búsqueda), editor de estructura para crear, editar y duplicar (la copia se
 * abre en el editor sin guardar), activar y desactivar, papelera y recuperar. Solo un admin
 * (ProjectTemplatePolicy).
 */
class TemplateController extends Controller
{
    public const int PER_PAGE = 50;

    /** Filtro de estado: activas, inactivas o todas. */
    public const array STATUSES = ['activas', 'inactivas', 'todas'];

    public function index(Request $request, TemplateItems $items): Response
    {
        Gate::authorize('create', ProjectTemplate::class);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'estado' => ['nullable', 'string', Rule::in(self::STATUSES)],
            'papelera' => ['nullable', 'boolean'],
        ]);

        $status = $filters['estado'] ?? 'todas';
        $trash = (bool) ($filters['papelera'] ?? false);

        $paginator = ProjectTemplate::query()
            ->when($trash, fn (Builder $query) => $query->onlyTrashed())
            ->when(! $trash && $status === 'activas', fn (Builder $query) => $query->where('is_active', true))
            ->when(! $trash && $status === 'inactivas', fn (Builder $query) => $query->where('is_active', false))
            ->when($filters['q'] ?? null, fn (Builder $query, string $term) => TextSearch::apply($query, $term, ['project_templates.name', 'project_templates.description']))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(self::PER_PAGE, pageName: 'pagina')
            ->withQueryString();

        /** @var list<ProjectTemplate> $templates */
        $templates = $paginator->items();

        return Inertia::render('admin/templates/index', [
            'templates' => Paginated::props($paginator, $items->rows(collect($templates))),
            'filters' => [
                'q' => $filters['q'] ?? '',
                'estado' => $status,
                'papelera' => $trash,
            ],
            'trashedCount' => ProjectTemplate::onlyTrashed()->count(),
        ]);
    }

    /**
     * Editor vacío o, con ?desde={id}, con la copia de otra plantilla («Duplicar»).
     */
    public function create(Request $request): Response
    {
        Gate::authorize('create', ProjectTemplate::class);

        $source = $request->filled('desde') && is_numeric($request->query('desde'))
            ? ProjectTemplate::query()->withTrashed()->find((int) $request->query('desde'))
            : null;

        $copyName = __('templates.copy_of', ['name' => $source->name ?? '']);
        $structure = $source !== null ? ProjectTemplateService::normalize($source->structure) : null;

        return Inertia::render('admin/templates/edit', [
            'template' => $source === null || $structure === null ? null : [
                'id' => null,
                'name' => mb_substr(is_string($copyName) ? $copyName : $source->name, 0, 255),
                'description' => $source->description,
                'is_active' => true,
                'structure' => $structure,
            ],
            ...$this->editorOptions($structure),
        ]);
    }

    public function store(TemplateRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $template = ProjectTemplate::query()->create([...$request->templateData(), 'created_by' => $user->id]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('templates.flash.created', ['name' => $template->name])]);

        return to_route('templates.edit', $template);
    }

    public function edit(ProjectTemplate $template): Response
    {
        Gate::authorize('update', $template);

        $structure = ProjectTemplateService::normalize($template->structure);

        return Inertia::render('admin/templates/edit', [
            'template' => [
                'id' => $template->id,
                'name' => $template->name,
                'description' => $template->description,
                'is_active' => $template->is_active,
                'structure' => $structure,
            ],
            ...$this->editorOptions($structure),
        ]);
    }

    public function update(TemplateRequest $request, ProjectTemplate $template): RedirectResponse
    {
        $template->fill($request->templateData())->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('templates.flash.updated', ['name' => $template->name])]);

        return to_route('templates.edit', $template);
    }

    /**
     * Activar o desactivar: una plantilla desactivada no se ofrece al crear proyectos ni en Ajustes.
     */
    public function status(Request $request, ProjectTemplate $template): RedirectResponse
    {
        Gate::authorize('update', $template);

        $active = (bool) $request->validate(['is_active' => ['required', 'boolean']])['is_active'];
        $template->forceFill(['is_active' => $active])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __($active ? 'templates.flash.activated' : 'templates.flash.deactivated', ['name' => $template->name])]);

        return back();
    }

    /**
     * A la papelera (SoftDeletes). Los proyectos creados con ella no cambian.
     */
    public function destroy(ProjectTemplate $template): RedirectResponse
    {
        Gate::authorize('delete', $template);

        $template->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('templates.flash.deleted', ['name' => $template->name])]);

        return back();
    }

    public function restore(int $template): RedirectResponse
    {
        $model = ProjectTemplate::onlyTrashed()->findOrFail($template);
        Gate::authorize('restore', $model);

        $model->restore();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('templates.flash.restored', ['name' => $model->name])]);

        return back();
    }

    /**
     * Opciones del editor: tipos activos (y los desactivados que use la plantilla, marcados, para
     * que se vea cuál era y se pueda cambiar), prioridades y límites.
     *
     * @param  array{tasks: list<array{task_type_id: int|null}>}|null  $structure
     * @return array<string, mixed>
     */
    private function editorOptions(?array $structure = null): array
    {
        $used = $structure === null ? [] : array_values(array_unique(array_filter(array_column($structure['tasks'], 'task_type_id'))));

        return [
            'types' => TaskType::query()
                ->withTrashed()
                ->where(fn (Builder $query) => $query->where(fn (Builder $active) => $active->where('is_active', true)->whereNull('deleted_at'))
                    ->orWhereIn('id', $used))
                ->ordered()
                ->orderBy('id')
                ->get(['id', 'name', 'is_active', 'deleted_at'])
                ->map(fn (TaskType $type): array => ['id' => $type->id, 'name' => $type->name, 'is_active' => $type->is_active && ! $type->trashed()])
                ->values()
                ->all(),
            'priorities' => TaskPriority::values(),
            'limits' => [
                'max_tasks' => ProjectTemplateService::MAX_TASKS,
                'max_days' => TemplateStructure::MAX_DAYS,
            ],
        ];
    }
}
