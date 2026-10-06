<?php

namespace App\Enums;

/**
 * Seguridad de un proyecto previsto (docs/PLAN-CARGAS.md §15, P5 b; D-281): solo «segura» o
 * «posible», sin probabilidad ni porcentaje. Cada una es su propia capa en la previsión.
 */
enum ForecastConfidence: string
{
    case Tentative = 'tentative';
    case Firm = 'firm';

    public function label(): string
    {
        return __("forecast.enums.confidence.{$this->value}");
    }

    /** La capa de la carga en la que cuentan sus asignaciones. */
    public function layer(): LoadLayer
    {
        return $this === self::Firm ? LoadLayer::Firm : LoadLayer::Tentative;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
