<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Admin\Palette;
use App\Enums\HourBankStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DepartmentRequest;
use App\Http\Resources\Admin\DepartmentRowResource;
use App\Http\Resources\Admin\ResourceProps;
use App\Http\Resources\UserSummaryResource;
use App\Models\Department;
use App\Models\HourBank;
use App\Models\TaskType;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Departamentos (SPEC §4.1 y §14, D-024): nombre, color de la paleta y varios responsables.
 * Borrar es un borrado lógico y solo se permite sin personas activas ni bolsas abiertas; las
 * personas desactivadas que quedaran en él pasan a «sin departamento». Gate manage-settings.
 */
class DepartmentController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('manage-settings');

        $departments = Department::query()
            ->with(['managers' => fn ($query) => $query->orderBy('name')])
            ->withCount(['users' => fn (Builder $query) => $query->where('is_active', true)])
            ->addSelect(['open_hour_banks_count' => HourBank::query()
                ->selectRaw('count(*)')
                ->whereColumn('hour_banks.department_id', 'departments.id')
                ->whereIn('status', [HourBankStatus::Active->value, HourBankStatus::Exhausted->value]),
            ])
            ->orderBy('name')
            ->get();

        return Inertia::render('admin/departments/index', [
            'departments' => ResourceProps::list(DepartmentRowResource::collection($departments), $request),
            'managerOptions' => ResourceProps::list(UserSummaryResource::collection(
                DepartmentRequest::eligibleManagers()->orderBy('name')->get(['id', 'name', 'avatar_path', 'department_id', 'is_active']),
            ), $request),
            'palette' => Palette::COLORS,
        ]);
    }

    public function store(DepartmentRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request): void {
            $department = Department::query()->create([
                'name' => $request->string('name')->toString(),
                'color' => $request->string('color')->toString(),
            ]);

            $department->managers()->sync($request->managerIds());
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('admin.departments.created')]);

        return back();
    }

    public function update(DepartmentRequest $request, Department $department): RedirectResponse
    {
        DB::transaction(function () use ($request, $department): void {
            $department->fill([
                'name' => $request->string('name')->toString(),
                'color' => $request->string('color')->toString(),
            ])->save();

            $department->managers()->sync($request->managerIds());
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('admin.departments.updated')]);

        return back();
    }

    public function destroy(Department $department): RedirectResponse
    {
        Gate::authorize('manage-settings');

        DB::transaction(function () use ($department): void {
            /** @var Department $locked */
            $locked = Department::query()->whereKey($department->id)->lockForUpdate()->firstOrFail();

            if (User::query()->where('department_id', $locked->id)->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(['department' => __('admin.departments.errors.has_people')]);
            }

            if (HourBank::query()->where('department_id', $locked->id)->open()->exists()) {
                throw ValidationException::withMessages(['department' => __('admin.departments.errors.has_banks')]);
            }

            User::query()->where('department_id', $locked->id)->update(['department_id' => null]);
            TaskType::withTrashed()->where('department_id', $locked->id)->update(['department_id' => null]);
            $locked->managers()->detach();
            $locked->delete();
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('admin.departments.deleted', ['name' => $department->name])]);

        return back();
    }
}
