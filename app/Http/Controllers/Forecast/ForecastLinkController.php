<?php

namespace App\Http\Controllers\Forecast;

use App\Domain\Forecast\ForecastLinker;
use App\Http\Requests\Forecast\CreateProjectFromForecastRequest;
use App\Http\Requests\Projects\StoreProjectRequest;
use App\Models\ForecastProject;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Del previsto al real (docs/PLAN-CARGAS.md §6.6, D-286): vincular con un proyecto existente (que
 * quien vincula gestiona), crear el real desde el previsto (el alta de siempre, D-022) y
 * desvincular (solo admins). Con ForecastLinker.
 */
class ForecastLinkController extends ForecastController
{
    public function __construct(private readonly ForecastLinker $linker) {}

    /** POST /prevision/proyectos/{forecast}/vincular {project_id, copy_allocations?} */
    public function link(Request $request, ForecastProject $forecast): RedirectResponse
    {
        $this->authorize('link', $forecast);

        $data = $request->validate([
            'project_id' => ['required', 'integer', Rule::exists('projects', 'id')->withoutTrashed()],
            'copy_allocations' => ['sometimes', 'boolean'],
        ], [], ['project_id' => __('forecast.attributes.project_id')]);

        /** @var User $user */
        $user = $request->user();
        $project = Project::query()->findOrFail((int) $data['project_id']);

        if (! $user->can('update', $project)) {
            throw ValidationException::withMessages(['project_id' => __('forecast.errors.project_forbidden')]);
        }

        $this->linker->link($forecast, $project, $user, (bool) ($data['copy_allocations'] ?? true));
        $this->toast(__('forecast.flash.linked', ['project' => $project->code]));

        return back();
    }

    /** POST /prevision/proyectos/{forecast}/crear-proyecto (los campos del alta de proyecto) */
    public function createProject(CreateProjectFromForecastRequest $request, ForecastProject $forecast): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $attributes = collect($request->validated())->except(['member_ids', 'copy_allocations', ...StoreProjectRequest::templateFields()])->all();

        $project = $this->linker->createProject($forecast, $attributes, $request->memberIds(), $user, $request->boolean('copy_allocations', true));
        $this->toast(__('forecast.flash.project_created', ['project' => $project->code]));

        return to_route('projects.show', $project);
    }

    /** DELETE /prevision/proyectos/{forecast}/vinculo */
    public function unlink(ForecastProject $forecast): RedirectResponse
    {
        $this->authorize('unlink', $forecast);

        $this->linker->unlink($forecast);
        $this->toast(__('forecast.flash.unlinked'), 'info');

        return back();
    }
}
