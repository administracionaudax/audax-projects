<?php

namespace App\Http\Controllers\PortalAccess;

use App\Http\Controllers\Controller;
use App\Http\Requests\PortalAccess\ProjectPortalSettingsRequest;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Portal del cliente en los ajustes del proyecto (SPEC §11, D-064): vista del proyecto, horas por
 * tarea y Gantt de solo lectura. Quien gestiona el proyecto (ProjectPolicy::update). El cambio
 * queda en la auditoría del proyecto (LogsDomainActivity).
 */
class ProjectPortalSettingsController extends Controller
{
    public function update(ProjectPortalSettingsRequest $request, Project $project): RedirectResponse
    {
        $project->fill($request->settings())->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('portal.access.project_saved')]);

        return back();
    }
}
