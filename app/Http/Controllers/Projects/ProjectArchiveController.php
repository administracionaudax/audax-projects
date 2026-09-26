<?php

namespace App\Http\Controllers\Projects;

use App\Domain\Projects\ProjectArchiver;
use App\Http\Controllers\Controller;
use App\Models\Project;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Archivar y recuperar un proyecto (D-037: nunca se borra). Un proyecto archivado no admite horas
 * (SPEC §7) y sale del listado por defecto.
 */
class ProjectArchiveController extends Controller
{
    use AuthorizesRequests;

    public function store(Project $project, ProjectArchiver $archiver): RedirectResponse
    {
        $this->authorize('archive', $project);

        $archiver->archive($project);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('projects.flash.archived')]);

        return to_route('projects.settings', $project);
    }

    public function destroy(Project $project, ProjectArchiver $archiver): RedirectResponse
    {
        $this->authorize('archive', $project);

        $archiver->unarchive($project);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('projects.flash.unarchived')]);

        return to_route('projects.settings', $project);
    }
}
