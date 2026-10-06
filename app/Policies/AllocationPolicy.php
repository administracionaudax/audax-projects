<?php

namespace App\Policies;

use App\Domain\Forecast\AllocationWriter;
use App\Domain\Forecast\ForecastAccess;
use App\Models\Allocation;
use App\Models\User;

/**
 * Una asignación (docs/PLAN-CARGAS.md §8, D-284) se cambia, se borra o se asigna a una persona
 * (si es un hueco) con la regla de su contenedor:
 * - de un previsto: manage-forecast, con el previsto abierto o confirmado (ForecastProjectPolicy),
 * - de un proyecto real: quien lo gestiona (admin, responsables y sus gestores, D-022), si no está
 *   archivado (ForecastAccess::allocatesProject).
 * Las de un previsto vinculado o perdido están congeladas: nadie las toca.
 */
class AllocationPolicy
{
    public function update(User $user, Allocation $allocation): bool
    {
        $allocation->loadMissing($allocation->forecast_project_id !== null ? 'forecastProject' : 'project');

        if ($allocation->forecast_project_id !== null) {
            $forecast = $allocation->forecastProject;

            return $forecast !== null && ! $forecast->trashed()
                && ForecastAccess::manages($user)
                && AllocationWriter::editable($forecast);
        }

        $project = $allocation->project;

        return $project !== null && ForecastAccess::allocatesProject($user, $project);
    }

    public function delete(User $user, Allocation $allocation): bool
    {
        return $this->update($user, $allocation);
    }

    /** «Asignar a…»: solo los huecos. */
    public function assign(User $user, Allocation $allocation): bool
    {
        return $allocation->isGap() && $this->update($user, $allocation);
    }
}
