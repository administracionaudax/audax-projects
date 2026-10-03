<?php

namespace App\Domain\Reports\Delivery;

/**
 * Frecuencia de un envío programado (D-141), siempre en Europe/Madrid:
 * - Once: una vez, en una fecha y hora; se desactiva tras enviarse,
 * - Weekly: cada semana, un día (1 = lunes … 7 = domingo) a una hora,
 * - Monthly: cada mes, un día (1-28, o 0 = el último) a una hora.
 */
enum ScheduleFrequency: string
{
    case Once = 'once';
    case Weekly = 'weekly';
    case Monthly = 'monthly';
}
