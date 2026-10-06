<?php

namespace App\Enums;

/**
 * Estado de una línea del plan del día (docs/PLAN-CARGAS.md §4.1, D-250): pendiente → hecha, no hecha
 * (con motivo opcional) o pasada a otro día (la copia vive en el día de destino, D-253).
 */
enum DayPlanItemStatus: string
{
    case Pending = 'pending';
    case Done = 'done';
    case NotDone = 'not_done';
    case Carried = 'carried';

    public function label(): string
    {
        return __("day_plan.enums.status.{$this->value}");
    }

    /** ¿Sigue abierta (se puede pasar a otro día o marcar)? */
    public function isOpen(): bool
    {
        return $this === self::Pending;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
