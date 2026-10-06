<?php

namespace App\Http\Controllers\Forecast;

use App\Domain\Forecast\AllocationWriter;
use App\Http\Requests\Forecast\AllocationRequest;
use App\Models\Allocation;
use App\Models\ForecastProject;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Asignaciones (D-282) de un previsto (`/prevision/proyectos/{forecast}/asignaciones`) y de un
 * proyecto real (`/proyectos/{project}/asignaciones`, la pestaña Planificación): alta, edición,
 * borrado y «Asignar a…» (un hueco pasa a una persona). Las rutas anidadas comprueban que la
 * asignación es de ese contenedor (scopeBindings). Todo con AllocationWriter.
 */
class AllocationController extends ForecastController
{
    public function __construct(private readonly AllocationWriter $writer) {}

    /** POST /prevision/proyectos/{forecast}/asignaciones */
    public function storeForecast(AllocationRequest $request, ForecastProject $forecast): RedirectResponse
    {
        $this->authorize('update', $forecast);

        return $this->store($request, $forecast);
    }

    /** POST /proyectos/{project}/asignaciones */
    public function storeProject(AllocationRequest $request, Project $project): RedirectResponse
    {
        $this->authorize('manageAllocations', $project);

        return $this->store($request, $project);
    }

    /** PUT …/asignaciones/{allocation} */
    public function update(AllocationRequest $request, Allocation $allocation): RedirectResponse
    {
        $this->authorize('update', $allocation);

        $this->writer->update($allocation, $request->validated());
        $this->toast(__('forecast.flash.allocation_updated'));

        return back();
    }

    /** DELETE …/asignaciones/{allocation} */
    public function destroy(Allocation $allocation): RedirectResponse
    {
        $this->authorize('delete', $allocation);

        $this->writer->delete($allocation);
        $this->toast(__('forecast.flash.allocation_deleted'), 'info');

        return back();
    }

    /** POST …/asignaciones/{allocation}/asignar {user_id} */
    public function assign(Request $request, Allocation $allocation): RedirectResponse
    {
        $this->authorize('assign', $allocation);

        $data = $request->validate(['user_id' => ['required', 'integer']], [], ['user_id' => __('forecast.attributes.user_id')]);
        $person = User::query()->find((int) $data['user_id']);

        if ($person === null) {
            return back()->withErrors(['user_id' => __('forecast.errors.person_not_assignable')]);
        }

        $this->writer->assign($allocation, $person);
        $this->toast(__('forecast.flash.allocation_assigned', ['name' => $person->name]));

        return back();
    }

    private function store(AllocationRequest $request, Project|ForecastProject $container): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->writer->create($container, $request->validated(), $user);
        $this->toast(__('forecast.flash.allocation_created'));

        return back();
    }
}
