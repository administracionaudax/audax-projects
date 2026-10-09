<?php

namespace App\Enums;

/** Unidad de un servicio del catálogo y de una línea (PLAN-EMISION §4.1; H-072): horas, unidades o meses. */
enum ServiceUnit: string
{
    case Hour = 'hour';
    case Unit = 'unit';
    case Month = 'month';

    public function label(): string
    {
        return __("invoicing.enums.unit.{$this->value}");
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
