<?php

namespace App\Enums;

/**
 * Estado de una factura propia (PLAN-EMISION §4.2, E1; D-421):
 * - `draft`: borrador, sin número; se edita y se borra.
 * - `issued`: emitida, con número, registro y PDF; ya no se cambia nada fiscal (*triggers*).
 * - `cancelled`: anulada con una rectificativa por el total (D-244).
 * - `voided`: con registro de anulación (V-03): la factura no debió existir. Solo un admin.
 */
enum SalesDocumentStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case Cancelled = 'cancelled';
    case Voided = 'voided';

    public function label(): string
    {
        return __("invoicing.enums.status.{$this->value}");
    }
}
