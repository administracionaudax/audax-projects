<?php

namespace App\Domain\DayPlan;

use App\Models\User;

/**
 * Tarjeta «Mi día» de Inicio (docs/PLAN-CARGAS.md §4.3, D-250): mis líneas de hoy, cuántas llevo
 * hechas, las pendientes de días anteriores y la línea con el temporizador en marcha. Null (sin
 * tarjeta) para quien no usa el plan del día o con el módulo apagado.
 */
final class HomeDayPlanCard
{
    public function __construct(private readonly MyDay $day) {}

    /**
     * @return array{date: string, deadline: string, items: list<array<string, mixed>>, done: int, total: int, pending: int, running_item_id: int|null, can_write: bool}|null
     */
    public function for(User $user): ?array
    {
        if (! DayPlanAccess::uses($user)) {
            return null;
        }

        $day = $this->day->for($user, DayPlanCalendar::today());

        return [
            'date' => $day['date'],
            'deadline' => $day['deadline'],
            'items' => $day['items'],
            'done' => $day['summary']['done'],
            'total' => $day['summary']['total'],
            'pending' => count($day['pending']),
            'running_item_id' => $day['running_item_id'],
            'can_write' => $day['can']['write'],
        ];
    }
}
