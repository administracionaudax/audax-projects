<?php

namespace App\Enums;

/**
 * De dónde sale una línea del plan del día (D-250): escrita a mano, añadida desde una tarea (Mis
 * tareas o «Desde mis tareas») o pasada desde otro día.
 */
enum DayPlanItemOrigin: string
{
    case Manual = 'manual';
    case Task = 'task';
    case Carried = 'carried';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
