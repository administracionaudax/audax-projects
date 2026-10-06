<?php

namespace App\Domain\Forecast;

/**
 * Resultado de AllocationPlanner: los minutos de cada asignación por día, las que van vencidas
 * (restante con el fin ya pasado: todo a hoy) y las que no tienen ningún día laborable en su rango
 * (todo al primer día), con el calendario de capacidad que se usó para repartir.
 */
final class AllocationPlan
{
    /** @var array<int, array<int, int>> asignación → día → minutos */
    public array $days = [];

    /** @var list<int> */
    public array $overdue = [];

    /** @var list<int> */
    public array $unscheduled = [];

    public function __construct(public readonly CapacityCalendar $capacity) {}

    /** Minutos de una asignación entre dos días (ambos incluidos; null: sin límite). */
    public function minutes(int $allocationId, ?int $from = null, ?int $to = null): int
    {
        $total = 0;

        foreach ($this->days[$allocationId] ?? [] as $day => $minutes) {
            if (($from === null || $day >= $from) && ($to === null || $day <= $to)) {
                $total += $minutes;
            }
        }

        return $total;
    }
}
