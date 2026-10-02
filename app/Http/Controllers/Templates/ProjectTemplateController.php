<?php

namespace App\Http\Controllers\Templates;

use App\Domain\Templates\ProjectFromTemplate;
use App\Domain\Templates\ProjectTemplateService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Templates\ApplyTemplateRequest;
use App\Http\Requests\Templates\CaptureTemplateRequest;
use App\Models\Project;
use App\Models\ProjectTemplate;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Plantillas desde los Ajustes de un proyecto existente (D-058), para quien lo gestiona:
 * - «Aplicar plantilla»: añade sus tareas, subtareas, hitos y dependencias desde la fecha elegida
 *   (y a la bolsa elegida en proyectos de bolsas), sin tocar las tareas que ya hay,
 * - «Guardar como plantilla»: guarda la estructura del proyecto (sin personas, horas ni estados).
 */
class ProjectTemplateController extends Controller
{
    public function __construct(private readonly ProjectTemplateService $templates) {}

    public function apply(ApplyTemplateRequest $request, Project $project): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var ProjectTemplate $template */
        $template = $request->template();

        Gate::authorize('applyToProject', [$template, $project]);

        try {
            $tasks = $this->templates->apply($template, $project, $request->start(), $user, $request->bank());
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(['template_id' => ProjectFromTemplate::firstMessage($exception)]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice('templates.flash.applied', count($tasks), [
            'count' => count($tasks),
            'name' => $template->name,
        ])]);

        return to_route('projects.settings', $project);
    }

    public function capture(CaptureTemplateRequest $request, Project $project): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $description = $request->validated('description');

        try {
            $template = $this->templates->capture(
                $project,
                trim((string) $request->validated('name')),
                is_string($description) && trim($description) !== '' ? trim($description) : null,
                $user,
            );
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(['capture' => ProjectFromTemplate::firstMessage($exception)]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('templates.flash.captured', [
            'name' => $template->name,
            'count' => ProjectTemplateService::stats($template->structure)['tasks'],
        ])]);

        return to_route('projects.settings', $project);
    }
}
