<?php

namespace App\Domain\Forecast;

use App\Enums\ProjectStatus;
use App\Models\Allocation;
use App\Models\ForecastProject;
use App\Models\Project;
use App\Models\User;
use App\Support\LocalTime;
use Illuminate\Database\Eloquent\Builder;

/**
 * «Mi carga» de una persona de plantilla (D-299 y D-305, con P6 y P8 b): solo sus asignaciones, de
 * proyectos reales y de previstos seguros y posibles, frente a su jornada. Lo usan la tarjeta de
 * Inicio (las 12 semanas que vienen y «Lo que viene») y la vista de /carga del empleado (26 semanas
 * y «Mis asignaciones»). Nunca la carga de los demás (P4).
 *
 * @phpstan-import-type Board from LoadCombiner
 *
 * @phpstan-type MyAllocation array{id: int, layer: string, mode: string, minutes: int|null, percent: int|null, start_date: string, end_date: string|null, note: string|null, container: array{kind: string, id: int, name: string, client_name: string|null}}
 */
final class MyForecast
{
    /** Semanas de /carga (6 meses). La tarjeta de Inicio enseña las primeras 12. */
    public const int WEEKS = 26;

    public function __construct(private readonly LoadCombiner $combiner) {}

    /**
     * @return array{board: Board, allocations: list<MyAllocation>}
     */
    public function for(User $user): array
    {
        $today = LocalTime::today();
        $period = new ForecastPeriod(
            $today->startOfWeek(),
            $today->startOfWeek()->addWeeks(self::WEEKS)->subDay(),
            ForecastPeriod::WEEK,
        );

        return [
            'board' => ForecastBoardView::redact($this->combiner->board($period, ['user_ids' => [$user->id]]), $user),
            'allocations' => $this->allocations($user, $today->toDateString()),
        ];
    }

    /**
     * Mis asignaciones que cuentan y no han acabado, por fecha de inicio.
     *
     * @return list<MyAllocation>
     */
    private function allocations(User $user, string $today): array
    {
        $allocations = Allocation::query()
            ->where('user_id', $user->id)
            ->where(fn (Builder $query) => $query->whereNull('end_date')->orWhere('end_date', '>=', $today))
            ->where(fn (Builder $query) => $query
                ->whereIn('project_id', Project::query()->select('id')->whereIn('status', [ProjectStatus::Planned->value, ProjectStatus::Active->value]))
                ->orWhereIn('forecast_project_id', ForecastProject::query()->select('id')->counting()))
            ->with(['project:id,code,name,client_id,status', 'project.client:id,name', 'forecastProject:id,name,client_id,prospect_name,confidence,status', 'forecastProject.client:id,name'])
            ->orderBy('start_date')
            ->orderBy('id')
            ->get();

        return array_values($allocations->map(function (Allocation $allocation): array {
            $forecast = $allocation->forecastProject;
            $project = $allocation->project;

            return [
                'id' => $allocation->id,
                'layer' => $this->combiner->layer($allocation)->value,
                'mode' => $allocation->mode->value,
                'minutes' => $allocation->minutes,
                'percent' => $allocation->percent,
                'start_date' => $allocation->start_date->toDateString(),
                'end_date' => $allocation->end_date?->toDateString(),
                'note' => $allocation->note,
                'container' => $forecast !== null
                    ? ['kind' => 'forecast', 'id' => $forecast->id, 'name' => $forecast->name, 'client_name' => $forecast->clientName()]
                    : ['kind' => 'project', 'id' => (int) $project?->id, 'name' => (string) $project?->name, 'client_name' => $project?->client?->name],
            ];
        })->all());
    }
}
