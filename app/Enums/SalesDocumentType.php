<?php

namespace App\Enums;

/**
 * Tipo de documento de la emisión propia (PLAN-EMISION §4.2, E1): factura o rectificativa (serie
 * aparte, RD 1619/2012, art. 6.1.a; D-244). Los mismos valores que HoldedDocumentKind, para que la
 * vista `billing_documents` los una sin traducir.
 */
enum SalesDocumentType: string
{
    case Invoice = 'invoice';
    case CreditNote = 'credit_note';

    public function label(): string
    {
        return __("invoicing.enums.type.{$this->value}");
    }

    /** Tipo de factura del registro (VeriFactu): F1, o la clave de la rectificativa (R1 por defecto). */
    public function recordType(?string $rectificationCode): string
    {
        return $this === self::Invoice ? 'F1' : ($rectificationCode ?? 'R1');
    }
}
