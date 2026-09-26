<?php

namespace App\Http\Controllers\Projects;

use App\Domain\Projects\ProjectMembership;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\StoreProjectMemberRequest;
use App\Http\Requests\Projects\UpdateProjectMemberRequest;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Miembros y gestores de un proyecto (D-005, D-022, D-032): los gestionan admins, responsables y
 * los gestores del proyecto. Al gestor principal no se le quita ni se le desmarca.
 */
class ProjectMemberController extends Controller
{
    use AuthorizesRequests;

    public function store(StoreProjectMemberRequest $request, Project $project, ProjectMembership $membership): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $member = User::query()->findOrFail($request->integer('user_id'));

        $membership->add($project, $member, $request->boolean('is_manager'), $actor);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('projects.flash.member_added', ['name' => $member->name])]);

        return to_route('projects.settings', $project);
    }

    public function update(UpdateProjectMemberRequest $request, Project $project, User $user, ProjectMembership $membership): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $membership->setManager($project, $user, $request->boolean('is_manager'), $actor);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('projects.flash.member_updated')]);

        return to_route('projects.settings', $project);
    }

    public function destroy(Request $request, Project $project, User $user, ProjectMembership $membership): RedirectResponse
    {
        $this->authorize('manageMembers', $project);

        /** @var User $actor */
        $actor = $request->user();

        $membership->remove($project, $user, $actor);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('projects.flash.member_removed', ['name' => $user->name])]);

        return to_route('projects.settings', $project);
    }
}
