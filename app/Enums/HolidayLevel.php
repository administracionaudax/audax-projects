<?php

namespace App\Enums;

/**
 * De dónde sale un festivo (Fase 11, R3; L-23; D-367): fiesta nacional (BOE), autonómica (DOGV),
 * local del municipio del centro de trabajo o de la empresa (convenio o decisión propia).
 */
enum HolidayLevel: string
{
    case National = 'national';
    case Regional = 'regional';
    case Local = 'local';
    case Company = 'company';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
