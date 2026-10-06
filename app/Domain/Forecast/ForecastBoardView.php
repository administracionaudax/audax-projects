<?php

namespace App\Domain\Forecast;

use App\Domain\Time\Capacity;
use App\Domain\Time\CapacityPlan;
use App\Enums\ProjectStatus;
use App\Http\Resources\Forecast\AllocationResource;
use App\Http\Resources\Tasks\Plain;
use App\Models\Allocation;
use App\Models\ForecastProject;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Lo que la pantalla de la previsión necesita además del tablero (D-301 a D-303):
 *
 * - **redact()**: el tipo de una ausencia solo lo ve quien puede ver las ausencias de esa persona
 *   (ella misma, su responsable y los admins, D-088); al resto le llega vacío (la celda dice
 *   «Ausencia» sin más).
 * - **gaps()**: los huecos sin persona del periodo (asignaciones de un departamento sin persona de
 *   proyectos reales que cuentan y de previstos abiertos o confirmados), con su contenedor y si
 *   quien mira puede «Asignar a…».
 * - **availability()**: la carga de cada persona asignable en unas fechas, para «Asignar a…» (como
 *   PersonLoadPicker): capacidad y asignado de todas las capas.
 *
 * @phpstan-import-type Board from LoadCombiner
 */
final class ForecastBoardView
{
    public function __construct(
        private readonly LoadCombiner $combiner,
        private readonly AllocationPlanner $planner,
    ) {}

    /**
     * @param  Board  $board
     * @return Board
     */
    public static function redact(array $board, User $viewer): array
    {
        $all = $viewer->isAdmin();
        $managed = $all ? [] : array_flip($viewer->managedDepartmentIds());

        foreach ($board['people'] as $index => $person) {
            $sees = $all || $person['id'] === $viewer->id
                || ($person['department_id'] !== null && isset($managed[$person['department_id']]));

            if ($sees) {
                continue;
            }

            $board['people'][$index]['absences'] = array_map(
                fn (?array $absence): ?array => $absence === null ? null : [...$absence, 'type' => null],
                $person['absences'],
            );
        }

        return $board;
    }

    /**
     * Huecos sin persona que tocan el periodo, por departamento y fecha.
     *
     * @return list<array<string, mixed>>
     */
    public function gaps(ForecastPeriod $period, User $viewer, ?int $departmentId = null): array
    {
        $from = $period->from->toDateString();
        $to = $period->to->toDateString();

        $allocations = Allocation::query()
            ->whereNull('user_id')
            ->whereNotNull('department_id')
            ->when($departmentId !== null, fn (Builder $query) => $query->where('department_id', $departmentId))
            ->where('start_date', '<=', $to)
            ->where(fn (Builder $query) => $query->whereNull('end_date')->orWhere('end_date', '>=', $from))
            ->where(fn (Builder $query) => $query
                ->whereIn('project_id', Project::query()->select('id')->whereIn('status', [ProjectStatus::Planned->value, ProjectStatus::Active->value]))
                ->orWhereIn('forecast_project_id', ForecastProject::query()->select('id')->counting()))
            ->with(['user:id,name,department_id', 'department:id,name,color', 'project:id,code,name,client_id,status,owner_user_id', 'project.client:id,name', 'forecastProject:id,name,client_id,prospect_name,confidence,status', 'forecastProject.client:id,name'])
            ->orderBy('start_date')
            ->get();

        if ($allocations->isEmpty()) {
            return [];
        }

        $horizon = CapacityPlan::day($to);
        $plan = $this->planner->plan($allocations->all(), $horizon);

        return array_values($allocations
            ->sortBy(fn (Allocation $allocation): string => ($allocation->department->name ?? '').' '.$allocation->start_date->toDateString())
            ->map(function (Allocation $allocation) use ($viewer, $plan): array {
                $forecast = $allocation->forecastProject;
                $project = $allocation->project;

                return [
                    'allocation' => Plain::of(new AllocationResource($allocation, [
                        'planned_minutes' => $plan->minutes($allocation->id),
                    ], [
                        'update' => $viewer->can('update', $allocation),
                        'assign' => $viewer->can('assign', $allocation),
                    ])),
                    'container' => $forecast !== null ? [
                        'kind' => 'forecast',
                        'id' => $forecast->id,
                        'name' => $forecast->name,
                        'client_name' => $forecast->clientName(),
                        'layer' => $forecast->confidence->layer()->value,
                    ] : [
                        'kind' => 'project',
                        'id' => $project?->id,
                        'name' => $project?->name,
                        'client_name' => $project?->client?->name,
                        'layer' => 'real',
                    ],
                ];
            })->all());
    }

    /**
     * Capacidad y asignado (todas las capas) de cada persona asignable entre dos fechas.
     *
     * @return list<array{id: int, name: string, department_id: int|null, collaborator: bool, has_schedule: bool, capacity: int, load: int}>
     */
    public function availability(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $period = new ForecastPeriod($from, $to, ForecastPeriod::WEEK);
        $board = $this->combiner->board($period);
        $people = [];

        foreach ($board['people'] as $person) {
            $capacity = 0;
            $load = 0;
            foreach ($person['cells'] as $cell) {
                $capacity += $cell['capacity'];
                $load += $cell['real'] + $cell['firm'] + $cell['tentative'];
            }

            $people[$person['id']] = [
                'id' => $person['id'],
                'name' => $person['name'],
                'department_id' => $person['department_id'],
                'collaborator' => $person['collaborator'],
                'has_schedule' => $person['has_schedule'],
                'capacity' => $capacity,
                'load' => $load,
            ];
        }

        // Los colaboradores sin carga en esas fechas no salen en el tablero, pero se pueden asignar:
        // con jornada, su capacidad desde hoy; sin ella, ninguna.
        $missing = ForecastPeople::collaborators()->whereKeyNot(array_keys($people))->orderBy('name')->get(['id', 'name', 'department_id']);
        $scheduled = array_values(WorkSchedule::query()->whereIn('user_id', $missing->modelKeys())->distinct()->pluck('user_id')->map(fn ($id): int => (int) $id)->all());
        $start = max($from, LocalTime::today());
        $plans = $to < $start ? [] : app(Capacity::class)->plansForRanges(array_map(
            fn (int $id): array => ['user_id' => $id, 'from' => $start, 'to' => $to],
            $scheduled,
        ));
        $capacities = [];
        foreach ($scheduled as $index => $id) {
            $capacities[$id] = isset($plans[$index]) ? $plans[$index]->total() : 0;
        }

        foreach ($missing as $collaborator) {
            $people[$collaborator->id] = [
                'id' => $collaborator->id,
                'name' => $collaborator->name,
                'department_id' => $collaborator->department_id,
                'collaborator' => true,
                'has_schedule' => isset($capacities[$collaborator->id]),
                'capacity' => $capacities[$collaborator->id] ?? 0,
                'load' => 0,
            ];
        }

        $people = array_values($people);
        usort($people, fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $people;
    }
}
