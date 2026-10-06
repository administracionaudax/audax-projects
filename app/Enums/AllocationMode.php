<?php

namespace App\Enums;

/**
 * Cómo se expresa una asignación (docs/PLAN-CARGAS.md §6.2, D-282):
 * - total: minutos entre dos fechas, a partes iguales entre los días laborables,
 * - per_day: minutos cada día laborable,
 * - percent: % de la capacidad de cada día (de la persona; de un hueco, % de la jornada por defecto),
 * - monthly: minutos cada mes natural (repartidos como total; fin opcional, para fees).
 */
enum AllocationMode: string
{
    case Total = 'total';
    case PerDay = 'per_day';
    case Percent = 'percent';
    case Monthly = 'monthly';

    public function label(): string
    {
        return __("forecast.enums.mode.{$this->value}");
    }

    /** ¿Usa `minutes`? Todos menos el porcentaje (que usa `percent`). */
    public function usesMinutes(): bool
    {
        return $this !== self::Percent;
    }

    /** ¿Puede no tener fecha de fin? Solo el mensual (hasta el horizonte). */
    public function allowsOpenEnd(): bool
    {
        return $this === self::Monthly;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
