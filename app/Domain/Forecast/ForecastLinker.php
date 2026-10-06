<?php

namespace App\Domain\Forecast;

use App\Domain\Projects\ProjectCreator;
use App\Enums\ForecastConfidence;
use App\Enums\ForecastStatus;
use App\Models\Allocation;
use App\Models\ForecastProject;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Del previsto al real (docs/PLAN-CARGAS.md §6.6, D-286):
 *
 * 1. **Vincular** con un proyecto real existente (no archivado y sin otro previsto: como mucho uno
 *    por proyecto). Se congela la línea base (ForecastBaseline) en el previsto, que pasa a
 *    «vinculado» (y «seguro»): sus asignaciones dejan de contar en la carga y quedan de solo
 *    lectura.
 * 2. **Copiar las asignaciones** (por defecto, sí): el real recibe asignaciones idénticas (mismas
 *    personas o huecos, modos, cantidades y fechas, con copied_from_allocation_id), que son su plan
 *    vivo; las personas que no eran miembros del proyecto entran como miembros (para imputar).
 * 3. **Crear el proyecto real desde el previsto**: el alta de siempre (ProjectCreator, D-022) con
 *    las personas asignadas como miembros, y después se vincula.
 * 4. **Desvincular** (solo admins): el previsto vuelve a «confirmado», se borra el vínculo y la foto,
 *    y no se tocan las asignaciones copiadas al real.
 *
 * Todo en una transacción y en la auditoría (los cambios del previsto, las asignaciones y los
 * miembros).
 */
final class ForecastLinker
{
    public function __construct(
        private readonly ForecastBaseline $baseline,
        private readonly AllocationWriter $allocations,
        private readonly ProjectCreator $projects,
    ) {}

    public function link(ForecastProject $forecast, Project $project, User $by, bool $copyAllocations = true): ForecastProject
    {
        if (! $forecast->status->counts()) {
            throw ValidationException::withMessages(['forecast' => __('forecast.errors.cannot_link')]);
        }

        if ($project->trashed() || ! $project->acceptsTime()) {
            throw ValidationException::withMessages(['project_id' => __('forecast.errors.project_archived')]);
        }

        return DB::transaction(function () use ($forecast, $project, $by, $copyAllocations): ForecastProject {
            // Con el bloqueo, dos vínculos a la vez con el mismo proyecto no pasan los dos.
            $taken = ForecastProject::withTrashed()->where('project_id', $project->id)->lockForUpdate()->exists();

            if ($taken) {
                throw ValidationException::withMessages(['project_id' => __('forecast.errors.project_taken')]);
            }

            $baseline = $this->baseline->take($forecast);

            if ($copyAllocations) {
                $memberIds = [];

                foreach ($forecast->allocations()->get() as $allocation) {
                    $this->allocations->copyTo($allocation, $project, $by);

                    if ($allocation->user_id !== null) {
                        $memberIds[$allocation->user_id] = true;
                    }
                }

                foreach (array_keys($memberIds) as $userId) {
                    if (! $project->hasMember($userId)) {
                        $project->addMember($userId);
                    }
                }
            }

            $forecast->update([
                'project_id' => $project->id,
                'status' => ForecastStatus::Linked,
                'confidence' => ForecastConfidence::Firm,
                'linked_at' => now(),
                'linked_by' => $by->id,
                'baseline' => $baseline,
            ]);

            return $forecast;
        });
    }

    /**
     * Crea el proyecto real con el alta de siempre y lo vincula. Las personas asignadas en el
     * previsto (de plantilla y activas) entran como miembros.
     *
     * @param  array<string, mixed>  $attributes  datos validados del alta (StoreProjectRequest)
     * @param  list<int>  $memberIds
     */
    public function createProject(ForecastProject $forecast, array $attributes, array $memberIds, User $by, bool $copyAllocations = true): Project
    {
        if (! $forecast->status->counts()) {
            throw ValidationException::withMessages(['forecast' => __('forecast.errors.cannot_link')]);
        }

        return DB::transaction(function () use ($forecast, $attributes, $memberIds, $by, $copyAllocations): Project {
            $assigned = ForecastPeople::assignables()
                ->whereIn('id', Allocation::query()->select('user_id')->where('forecast_project_id', $forecast->id)->whereNotNull('user_id'))
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            $project = $this->projects->create($attributes, array_values(array_unique([...$memberIds, ...$assigned])), $by);
            $this->link($forecast, $project, $by, $copyAllocations);

            return $project;
        });
    }

    public function unlink(ForecastProject $forecast): ForecastProject
    {
        if ($forecast->status !== ForecastStatus::Linked) {
            throw ValidationException::withMessages(['forecast' => __('forecast.errors.not_linked')]);
        }

        $forecast->update([
            'project_id' => null,
            'status' => ForecastStatus::Confirmed,
            'confidence' => ForecastConfidence::Firm,
            'linked_at' => null,
            'linked_by' => null,
            'baseline' => null,
        ]);

        return $forecast;
    }
}
