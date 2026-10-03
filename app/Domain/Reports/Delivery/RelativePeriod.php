<?php

namespace App\Domain\Reports\Delivery;

/**
 * Periodo de un envío programado respecto al día del envío (D-141), sobre el `periodo` del
 * informe (semana, mes, trimestre o año):
 * - Fixed: el rango tal cual se guardó (p. ej. un informe de un proyecto cerrado),
 * - Current: el periodo en curso el día del envío (mes hasta la fecha),
 * - Previous: el periodo anterior completo (el «informe de inicio de mes» del mes pasado).
 */
enum RelativePeriod: string
{
    case Fixed = 'fixed';
    case Current = 'current';
    case Previous = 'previous';
}
