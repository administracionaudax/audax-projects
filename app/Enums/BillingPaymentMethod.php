<?php

namespace App\Enums;

/**
 * Forma de pago por defecto del cliente (Fase 12, D-381; H-006). Las formas de pago configurables
 * llegan en F2; mientras, esta lista cerrada.
 */
enum BillingPaymentMethod: string
{
    case Transfer = 'transfer';
    case DirectDebit = 'direct_debit';
    case Card = 'card';
    case Cash = 'cash';
    case Other = 'other';

    public function label(): string
    {
        return __("billing.enums.payment_method.{$this->value}");
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
