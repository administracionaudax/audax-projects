<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Admin\UserGuard;
use App\Domain\Admin\WorkScheduleVersions;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\WorkScheduleRequest;
use App\Models\User;
use App\Models\WorkSchedule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Jornada versionada de un usuario (SPEC §4.1, D-036). Las reglas (sin solapes, solo se toca la
 * última versión si aún no ha empezado) están en WorkScheduleVersions.
 */
class WorkScheduleController extends Controller
{
    public function __construct(
        private readonly WorkScheduleVersions $versions,
        private readonly UserGuard $guard,
    ) {}

    public function store(WorkScheduleRequest $request, User $user): RedirectResponse
    {
        $this->authorizeFor($request, $user);

        $this->versions->create($user, $request->string('valid_from')->toString(), $request->week());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('admin.schedules.created')]);

        return back();
    }

    public function update(WorkScheduleRequest $request, User $user, WorkSchedule $workSchedule): RedirectResponse
    {
        $this->authorizeFor($request, $user);

        $this->versions->update($workSchedule, $request->string('valid_from')->toString(), $request->week());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('admin.schedules.updated')]);

        return back();
    }

    public function destroy(Request $request, User $user, WorkSchedule $workSchedule): RedirectResponse
    {
        $this->authorizeFor($request, $user);

        $this->versions->delete($workSchedule);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('admin.schedules.deleted')]);

        return back();
    }

    private function authorizeFor(Request $request, User $user): void
    {
        Gate::authorize('manage-users');

        /** @var User $actor */
        $actor = $request->user();
        $this->guard->assertCanManage($actor, $user);
    }
}
