<?php

namespace App\Http\Controllers\Projects;

use App\Domain\Projects\ProjectMembership;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\UpdateProjectAlertsRequest;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Alertas de un gestor en un proyecto (D-023): umbrales y exceso de las bolsas. Cada gestor edita
 * las suyas; un admin, las de cualquiera (ProjectPolicy::updateAlerts).
 */
class ProjectAlertController extends Controller
{
    public function update(UpdateProjectAlertsRequest $request, Project $project, User $user, ProjectMembership $membership): RedirectResponse
    {
        $membership->updateAlerts($project, $user, $request->preferences());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('projects.flash.alerts_updated')]);

        return to_route('projects.settings', $project);
    }
}
