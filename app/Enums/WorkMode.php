<?php

namespace App\Enums;

/**
 * Modo de cada tramo de la jornada (Ley 10/2021, art. 14; L-03): presencial o a distancia. Se elige
 * al fichar la entrada y al volver de la pausa (D-333).
 */
enum WorkMode: string
{
    case OnSite = 'on_site';
    case Remote = 'remote';

    public function label(): string
    {
        return __("people.modes.{$this->value}");
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
