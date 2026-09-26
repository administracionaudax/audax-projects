<?php

namespace App\Domain\Workload;

/**
 * Resultado de WorkloadPlanner para un horizonte [from, to] (SPEC §9).
 */
final class WorkloadPlan
{
    /**
     * @param  array<int, array<string, int>>  $load  persona → fecha → minutos planificados
     * @param  array<int, array<string, array<int, int>>>  $contributions  persona → fecha → tarea → minutos
     * @param  array<int, array<string, int>>  $capacity  persona → fecha → minutos de capacidad
     * @param  list<array{task_id: int, user_id: int, reason: string}>  $unplanned  sin estimación o sin fechas
     * @param  array<int|string, list<array{task_id: int, remaining_minutes: int}>>  $unassigned  id de departamento ('' = sin departamento) → tareas
     * @param  list<int>  $overdue  tareas vencidas (su restante va a hoy)
     */
    public function __construct(
        public array $load = [],
        public array $contributions = [],
        public array $capacity = [],
        public array $unplanned = [],
        public array $unassigned = [],
        public array $overdue = [],
    ) {}

    public function loadOn(int $userId, string $date): int
    {
        return $this->load[$userId][$date] ?? 0;
    }

    /**
     * Carga de una persona en [from, to].
     */
    public function loadBetween(int $userId, string $from, string $to): int
    {
        $total = 0;
        foreach ($this->load[$userId] ?? [] as $date => $minutes) {
            if ($date >= $from && $date <= $to) {
                $total += $minutes;
            }
        }

        return $total;
    }

    public function capacityBetween(int $userId, string $from, string $to): int
    {
        $total = 0;
        foreach ($this->capacity[$userId] ?? [] as $date => $minutes) {
            if ($date >= $from && $date <= $to) {
                $total += $minutes;
            }
        }

        return $total;
    }
}
