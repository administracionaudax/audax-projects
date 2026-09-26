<?php

namespace App\Enums;

/**
 * Alertas que cada gestor elige por proyecto (D-023). Se guardan en
 * project_members.alert_preferences como {clave: bool}; por defecto, todas activadas.
 */
enum ProjectAlert: string
{
    case HourBankThreshold = 'hour_bank_threshold';
    case HourBankOverage = 'hour_bank_overage';

    public function label(): string
    {
        return match ($this) {
            self::HourBankThreshold => 'Umbrales de consumo de las bolsas',
            self::HourBankOverage => 'Horas en exceso sobre una bolsa agotada',
        };
    }

    /**
     * Preferencias por defecto: todas activadas (D-023).
     *
     * @return array<string, bool>
     */
    public static function defaults(): array
    {
        return array_fill_keys(array_column(self::cases(), 'value'), true);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
