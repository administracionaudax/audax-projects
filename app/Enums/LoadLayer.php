<?php

namespace App\Enums;

/**
 * Capas de la carga de la previsión (docs/PLAN-CARGAS.md §6.4 y §6.5 con P5, P6 y P7; D-283):
 * - real: asignaciones de proyectos reales (planificados o activos),
 * - firm: asignaciones de proyectos previstos «seguros» (abiertos o confirmados),
 * - tentative: asignaciones de proyectos previstos «posibles».
 * Ni las tareas estimadas ni las bolsas ni los fees cuentan: solo asignaciones.
 */
enum LoadLayer: string
{
    case Real = 'real';
    case Firm = 'firm';
    case Tentative = 'tentative';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
