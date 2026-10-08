<?php

namespace App\Enums;

/**
 * Tipo de facturación de un proyecto (SPEC §4.2).
 */
enum BillingType: string
{
    case HourBank = 'hour_bank';
    case FixedPrice = 'fixed_price';
    case TimeAndMaterials = 'time_and_materials';
    case Internal = 'internal';
    /** Fee mensual (Fase 12, D-382): importe fijo al mes con N horas (monthly_fee_amount y monthly_minutes). */
    case MonthlyFee = 'monthly_fee';

    public function label(): string
    {
        return match ($this) {
            self::HourBank => 'Bolsas de horas',
            self::FixedPrice => 'Precio cerrado',
            self::TimeAndMaterials => 'Por horas',
            self::Internal => 'Interno',
            self::MonthlyFee => 'Fee mensual',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
