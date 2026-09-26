<?php

namespace App\Http\Controllers\Projects;

use App\Domain\Projects\ProjectMembership;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\UpdateProjectOwnerRequest;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Cambio de gestor principal (D-032): el nuevo pasa a gestor y el anterior sigue como gestor.
 */
class ProjectOwnerController extends Controller
{
    public function update(UpdateProjectOwnerRequest $request, Project $project, ProjectMembership $membership): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $owner = User::query()->findOrFail($request->integer('owner_user_id'));

        $membership->changeOwner($project, $owner, $actor);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('projects.flash.owner_changed', ['name' => $owner->name])]);

        return to_route('projects.settings', $project);
    }
}
