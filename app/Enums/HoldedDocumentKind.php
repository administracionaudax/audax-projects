<?php

namespace App\Enums;

/**
 * Tipo de documento de venta leído de Holded (Fase 12, D-385): factura o rectificativa. Las
 * rectificativas se guardan con importes negativos para que sumar dé lo facturado neto.
 */
enum HoldedDocumentKind: string
{
    case Invoice = 'invoice';
    case CreditNote = 'credit_note';

    public function label(): string
    {
        return __("billing.enums.document_kind.{$this->value}");
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
