<?php

namespace App\Enums;

/**
 * Unidad de venta del informe «Vendido frente a real» (Fase 12, D-390; PLAN-FASE-12 §4.6). Los
 * valores van en la URL (?venta=bolsa), por eso en español.
 */
enum SaleKind: string
{
    case HourBank = 'bolsa';
    case FixedPrice = 'precio_cerrado';
    case MonthlyFee = 'fee';
    case Hourly = 'horas';

    public function label(): string
    {
        return __("billing.enums.sale_kind.{$this->value}");
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
