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

    public function label(): string
    {
        return match ($this) {
            self::HourBank => 'Bolsas de horas',
            self::FixedPrice => 'Precio cerrado',
            self::TimeAndMaterials => 'Por horas',
            self::Internal => 'Interno',
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
