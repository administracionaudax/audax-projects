<?php

namespace App\Enums;

/**
 * Destino de las horas extra de un día (art. 35.1 ET; convenio de publicidad, art. 22; D-349):
 * compensarlas con descanso (pasan al saldo de horas, 80 minutos por hora) o pagarlas.
 */
enum OvertimeDestination: string
{
    case Compensate = 'compensate';
    case Pay = 'pay';

    public function label(): string
    {
        return __("people.overtime.destinations.{$this->value}");
    }
}
