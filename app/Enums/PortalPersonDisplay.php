<?php

namespace App\Enums;

/**
 * Cómo ve el cliente a quien imputó cada entrada (SPEC §11): nombre (por defecto), iniciales o
 * «Equipo».
 */
enum PortalPersonDisplay: string
{
    case Name = 'name';
    case Initials = 'initials';
    case Team = 'team';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
