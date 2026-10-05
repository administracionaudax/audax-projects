<?php

namespace App\Http\Controllers\Weeklies;

use App\Domain\Projects\ProjectMembership;
use App\Http\Controllers\Controller;
use App\Http\Requests\Weeklies\JoinProjectsRequest;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * «Unirme a proyectos» y «Dejar proyecto» desde la Weekly (F-034 y F-133, D-156): cualquier interno
 * de plantilla se puede apuntar como MIEMBRO (nunca gestor) a los proyectos abiertos de clientes
 * activos, y salir de los que no gestiona. Pasa por ProjectMembership: queda en la auditoría del
 * proyecto. Los colaboradores externos no (D-134).
 */
class WeeklyProjectMembershipController extends Controller
{
    public function join(JoinProjectsRequest $request, ProjectMembership $membership): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $projects = Project::query()
            ->whereKey($request->projectIds())
            ->notArchived()
            ->whereHas('client', fn ($client) => $client->where('is_active', true))
            ->get();

        if ($projects->count() !== count($request->projectIds())) {
            throw ValidationException::withMessages(['project_ids' => __('weeklies.validation.projects')]);
        }

        $joined = 0;

        DB::transaction(function () use ($projects, $user, $membership, &$joined): void {
            foreach ($projects as $project) {
                if (! $project->hasMember($user)) {
                    $membership->add($project, $user, false, $user);
                    $joined++;
                }
            }
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice('weeklies.flash.joined', $joined, ['count' => $joined])]);

        return back();
    }

    public function leave(Request $request, Project $project, ProjectMembership $membership): RedirectResponse
    {
        Gate::authorize('use-weeklies');

        /** @var User $user */
        $user = $request->user();
        $member = $project->members()->whereKey($user->id)->first();

        if ($member === null) {
            abort(404);
        }

        if ($member->membership?->is_manager || $project->owner_user_id === $user->id) {
            throw ValidationException::withMessages(['project' => __('weeklies.validation.manager_cannot_leave')]);
        }

        $membership->remove($project, $user, $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('weeklies.flash.left', ['project' => $project->code])]);

        return back();
    }
}
