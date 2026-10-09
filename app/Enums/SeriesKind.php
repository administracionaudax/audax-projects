<?php

namespace App\Enums;

/**
 * Clase de serie de numeración (PLAN-EMISION §4.1; D-419): la normal (F, CN), la de pruebas (PRU y
 * PRUCN, que nunca cuentan: fuera de informes, libros y portal, con su propia cadena) y la externa
 * (lo importado de Holded, sin registros; reservada).
 */
enum SeriesKind: string
{
    case Regular = 'regular';
    case Test = 'test';
    case External = 'external';

    public function label(): string
    {
        return __("invoicing.enums.series_kind.{$this->value}");
    }
}
