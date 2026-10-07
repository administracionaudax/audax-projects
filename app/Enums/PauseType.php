<?php

namespace App\Enums;

/**
 * Tipos de pausa que se fichan (PLAN-FASE-11 §14.1 P3: solo la comida; D-334). La comida no es
 * tiempo de trabajo. Un tipo nuevo que sí computara iría aquí con countsAsWork() = true.
 */
enum PauseType: string
{
    case Meal = 'meal';

    public function label(): string
    {
        return __("people.pauses.{$this->value}");
    }

    public function countsAsWork(): bool
    {
        return false;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
