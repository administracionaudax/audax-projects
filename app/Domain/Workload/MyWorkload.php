<?php

namespace App\Domain\Workload;

use App\Domain\Time\Capacity;
use App\Models\User;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;

/**
 * «Mi carga» en Inicio (SPEC §5.1 y §9): la carga planificada de quien mira esta semana (de hoy al
 * domingo) y la que viene, frente a su capacidad, con el mismo reparto que la vista Carga; y
 * cuántas de sus tareas están vencidas o sin planificar. Solo lo suyo (D-021).
 *
 * @phpstan-import-type Reason from CapacityExplainer
 * @phpstan-import-type Reduced from CapacityExplainer
 */
final class MyWorkload
{
    public function __construct(
        private readonly WorkloadPlanner $planner,
        private readonly Capacity $capacity,
    ) {}

    /**
     * @return array{weeks: list<array{key: string, from: string, to: string, planned: int, capacity: int, reason: Reason|null, reduced: Reduced|null}>, overdue: int, unplanned: int}
     */
    public function for(User $user, ?CarbonImmutable $today = null): array
    {
        $today = CarbonImmutable::parse(($today ?? LocalTime::today())->toDateString());
        $weeks = [
            WorkloadHorizon::CurrentWeek->value => WorkloadHorizon::CurrentWeek->bounds($today),
            WorkloadHorizon::NextWeek->value => WorkloadHorizon::NextWeek->bounds($today),
        ];
        $from = $weeks[WorkloadHorizon::CurrentWeek->value][0];
        $to = $weeks[WorkloadHorizon::NextWeek->value][1];

        $plan = $this->planner->plan([$user->id], $from, $to, $today);
        $details = $this->capacity->detailsForRanges([['user_id' => $user->id, 'from' => $from, 'to' => $to]])[0];

        $rows = [];
        foreach ($weeks as $key => [$weekFrom, $weekTo]) {
            $start = $weekFrom->toDateString();
            $end = $weekTo->toDateString();

            $rows[] = [
                'key' => $key,
                'from' => $start,
                'to' => $end,
                'planned' => $plan->loadBetween($user->id, $start, $end),
                'capacity' => $plan->capacityBetween($user->id, $start, $end),
                ...CapacityExplainer::explain(array_filter($details, fn (string $date): bool => $date >= $start && $date <= $end, ARRAY_FILTER_USE_KEY)),
            ];
        }

        return [
            'weeks' => $rows,
            'overdue' => count($plan->overdue),
            'unplanned' => count(array_filter($plan->unplanned, fn (array $item): bool => $item['user_id'] === $user->id)),
        ];
    }
}
