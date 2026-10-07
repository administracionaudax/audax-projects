<?php

namespace App\Enums;

/**
 * Tipo de las horas por encima de la jornada que se reconocen (borrador del RD: «tipo de hora»;
 * D-349): extraordinarias (art. 35 ET) o, a tiempo parcial, complementarias (art. 12.5 ET: quien
 * trabaja a tiempo parcial no hace horas extra salvo fuerza mayor).
 */
enum HourType: string
{
    case Overtime = 'overtime';
    case Complementary = 'complementary';

    public function label(): string
    {
        return __("people.overtime.hour_types.{$this->value}");
    }
}
