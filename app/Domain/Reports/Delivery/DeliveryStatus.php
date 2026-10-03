<?php

namespace App\Domain\Reports\Delivery;

/**
 * Estado de un envío (D-141): en cola, enviado, fallido (error al generar o enviar) u omitido
 * (quien lo programó ya no puede ver el informe o no quedan destinatarios).
 */
enum DeliveryStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Failed = 'failed';
    case Skipped = 'skipped';
}
